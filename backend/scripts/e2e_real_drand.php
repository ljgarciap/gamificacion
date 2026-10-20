<?php
// Recorrido de punta a punta con la baliza drand REAL (necesita red y tarda ~1 minuto: espera a que drand publique la ronda).
// Usa la base configurada (la de desarrollo) pero TODO dentro de una transacción que se revierte al final: un lote comprometido
// es inmutable y no se podría borrar después. Escribe la exportación de auditoría en el archivo indicado.
//
//   docker compose run --rm -v "$PWD/backend/scripts:/scripts" -v "$PWD/tmp:/out" backend php /scripts/e2e_real_drand.php /out/export.json
//   python3 -I verifier/gamif_verify.py verify tmp/export.json

use App\Domain\Batches\BatchLifecycle;
use App\Domain\Batches\BeaconNotReady;
use App\Domain\Crypto\CodeHasher;
use App\Domain\Crypto\PrizeVault;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo 'BD: '.DB::connection()->getDatabaseName().' | baliza: '.get_class(app(App\Domain\Beacon\BeaconClient::class)).PHP_EOL;

DB::beginTransaction();
try {
    $campaign = (string) Str::uuid();
    DB::table('campaigns')->insert(['id' => $campaign, 'name' => 'E2E', 'game_type' => 'roulette']);
    DB::table('prizes')->insert([
        ['campaign_id' => $campaign, 'prize_id' => 'none', 'type' => 'none', 'label' => 'Sin premio', 'amount' => null],
        ['campaign_id' => $campaign, 'prize_id' => 'p10k', 'type' => 'money', 'label' => '$ 10.000', 'amount' => 10000],
        ['campaign_id' => $campaign, 'prize_id' => 'p50k', 'type' => 'money', 'label' => '$ 50.000', 'amount' => 50000],
    ]);

    $lc = app(BatchLifecycle::class);
    $pool = [['prize_id' => 'none', 'quantity' => 14], ['prize_id' => 'p10k', 'quantity' => 5], ['prize_id' => 'p50k', 'quantity' => 1]];
    $id = $lc->createDraft($campaign, 'Lote E2E', $pool, 'e2e');

    $hasher = new CodeHasher;
    $hashes = [];
    for ($i = 0; $i < 20; $i++) {
        $hashes[] = $hasher->hash(strtoupper(bin2hex(random_bytes(6)))); // códigos aleatorios sintéticos
    }
    $lc->addCodeHashes($id, $hashes, 'e2e');

    $receipt = $lc->commit($id, 'e2e');
    echo "Comprometido. Ronda de drand comprometida: {$receipt->beaconRound}\n";

    $t0 = time();
    while (true) {
        try {
            $lc->activate($id, 'e2e');
            break;
        } catch (BeaconNotReady|App\Domain\Beacon\BeaconUnavailable $e) {
            // "Todavía no" y los fallos transitorios de la baliza se esperan y reintentan; nunca se omite la baliza.
            echo $e instanceof BeaconNotReady ? '.' : '!';
            sleep(3);
            if (time() - $t0 > 180) {
                throw new RuntimeException('drand no publicó la ronda a tiempo');
            }
        }
    }
    echo "\nActivado tras ".(time() - $t0)." s (valor real de drand).\n";

    $lc->close($id, 'e2e');
    $lc->reveal($id, 'e2e');

    $b = DB::selectOne("SELECT encode(chain_hash,'hex') chain, beacon_round, encode(beacon_value,'hex') beacon, pool::text pool,
                               encode(codes_digest,'hex') digest, encode(commitment,'hex') commitment, encode(seed_revealed,'hex') seed
                        FROM batches WHERE id = ?", [$id]);
    $vault = app(PrizeVault::class);
    $assign = [];
    $codeHashes = [];
    foreach (DB::select("SELECT idx, encode(code_hash,'hex') h, encode(prize_enc,'hex') p FROM codes WHERE batch_id = ? ORDER BY idx", [$id]) as $r) {
        $assign[] = $vault->decryptPrize($id, (int) $r->idx, hex2bin($r->p));
        $codeHashes[] = $r->h;
    }
    $export = [
        'version' => 1, 'batch_id' => $id, 'chain_hash' => $b->chain, 'beacon_round' => (int) $b->beacon_round,
        'beacon_value' => $b->beacon, 'pool' => json_decode($b->pool, true), 'codes_digest' => $b->digest,
        'commitment' => $b->commitment, 'seed' => $b->seed, 'code_hashes' => $codeHashes, 'assignments' => $assign,
    ];
    file_put_contents($argv[1] ?? '/tmp/export.json', json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo 'Bitácora íntegra: '.(app(App\Domain\Audit\AuditLog::class)->verifyChain() === null ? 'sí' : 'NO')."\n";
    echo 'Asignación: '.json_encode(array_count_values($assign))."\n";
} finally {
    DB::rollBack();
    echo 'Revertido. Lotes en la base de desarrollo: '.DB::table('batches')->count()."\n";
}
