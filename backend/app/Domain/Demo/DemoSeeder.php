<?php

namespace App\Domain\Demo;

use App\Domain\Admin\SecretBox;
use App\Domain\Audit\AuditLog;
use App\Domain\Batches\BatchLifecycle;
use App\Domain\Batches\BeaconNotReady;
use App\Domain\Beacon\BeaconUnavailable;
use App\Domain\Codes\CodeFormat;
use App\Domain\Codes\CodeGenerator;
use App\Domain\Crypto\CodeHasher;
use App\Domain\Crypto\PrizeVault;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Siembra del pool de la muestra (SPEC-006 M2): por cada juego de la mezcla crea una campaña `is_demo`, un lote con el pool EXACTO que el
 * superadmin configuró (códigos reales del generador, compromiso y baliza reales) y guarda cada código en claro CIFRADO en `demo_pool`, ligado
 * a su huella (copiarlo a otra fila no se puede abrir). Va en tres fases para que la espera de la baliza se solape entre los juegos:
 * (1) crear, generar y comprometer todos los lotes, (2) activarlos esperando la baliza, (3) llenar el pool.
 *
 * Reiniciar = `retire`: los lotes comprometidos son inmutables y no se borran, así que se cierran y su pool se marca retirado.
 */
final class DemoSeeder
{
    public function __construct(
        private readonly BatchLifecycle $lifecycle,
        private readonly CodeGenerator $generator,
        private readonly SecretBox $box = new SecretBox,
        private readonly AuditLog $audit = new AuditLog,
        private readonly int $beaconTimeoutSeconds = 240,
        private readonly int $beaconPollSeconds = 3,
    ) {}

    public static function sealContext(string $codeHashHex): string
    {
        return "demo_pool|{$codeHashHex}|code_enc";
    }

    /**
     * @param  array<string, mixed>  $config  ya validada (`DemoConfig::validate`)
     * @return array{games: int, codes: int, generation: int}
     */
    public function seed(array $config, string $actor): array
    {
        $generation = 1 + (int) DB::selectOne('SELECT COALESCE(MAX(generation), 0) AS g FROM demo_pool')->g;

        $built = [];
        foreach (DemoConfig::GAMES as $game) {
            if (isset($config['mix'][$game])) {
                $built[] = $this->build($game, $config['mix'][$game], $actor);
            }
        }
        foreach ($built as $b) {
            $this->activateWhenReady($b['batch'], $actor);
        }
        $codes = 0;
        foreach ($built as $b) {
            $codes += $this->fillPool($b, $generation);
        }
        $this->audit->append($actor, 'demo.seeded', 'demo', 'pool', ['generation' => $generation, 'games' => count($built), 'codes' => $codes]);

        return ['games' => count($built), 'codes' => $codes, 'generation' => $generation];
    }

    /** Cierra los lotes demo activos y retira todo el pool. Los lotes quedan para auditoría (inmutables). */
    public function retire(string $actor): int
    {
        $batches = DB::select("SELECT b.id FROM batches b JOIN campaigns c ON c.id = b.campaign_id WHERE c.is_demo AND b.state = 'active'");
        foreach ($batches as $b) {
            $this->lifecycle->close($b->id, $actor);
        }
        $retired = DB::transaction(function () use ($actor): int {
            $n = DB::update("UPDATE demo_pool SET state = 'retired' WHERE state <> 'retired'");
            $this->audit->append($actor, 'demo.pool_retired', 'demo', 'pool', ['codes' => $n]);

            return $n;
        });

        return $retired;
    }

    /**
     * @param  array{prizes: list<array{type: string, amount: int, count: int}>, noPrizeCount: int}  $entry
     * @return array{game: string, batch: string, campaign: string, codes: list<string>}
     */
    private function build(string $game, array $entry, string $actor): array
    {
        $plan = DemoGameTemplates::plan($game, $entry);

        $campaign = (string) Str::uuid();
        DB::table('campaigns')->insert([
            'id' => $campaign, 'name' => $plan['name'], 'game_type' => $game, 'used_code_behavior' => $plan['behavior'],
            'title' => $plan['name'], 'game_config' => json_encode($plan['config']), 'is_demo' => true,
        ]);
        foreach ($plan['prizes'] as [$prizeId, $type, $label, $amount, $meta]) {
            DB::table('prizes')->insert([
                'campaign_id' => $campaign, 'prize_id' => $prizeId, 'type' => $type, 'label' => $label, 'amount' => $amount,
                'meta' => json_encode((object) $meta),
            ]);
        }

        $batch = $this->lifecycle->createDraft($campaign, 'Muestra '.$plan['prefix'], $plan['pool'], $actor);
        $csv = tempnam(sys_get_temp_dir(), 'demo-muestra-');
        unlink($csv); // el generador crea el archivo (0600) y no sobrescribe uno existente
        try {
            $this->generator->generate($batch, $plan['prefix'], $csv, $actor);
            $codes = file($csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        } finally {
            @unlink($csv);
        }
        array_shift($codes); // encabezado
        $this->lifecycle->commit($batch, $actor);

        return ['game' => $game, 'batch' => $batch, 'campaign' => $campaign, 'codes' => $codes];
    }

    /** @param array{game: string, batch: string, campaign: string, codes: list<string>} $b */
    private function fillPool(array $b, int $generation): int
    {
        $vault = new PrizeVault;
        $hasher = new CodeHasher;
        $labels = DB::table('prizes')->where('campaign_id', $b['campaign'])->pluck('label', 'prize_id')->all();

        return DB::transaction(function () use ($b, $generation, $vault, $hasher, $labels): int {
            foreach ($b['codes'] as $code) {
                $hash = $hasher->hash(CodeFormat::normalize($code));
                $row = DB::selectOne("SELECT idx, encode(prize_enc, 'hex') AS p FROM codes WHERE code_hash = decode(?, 'hex')", [bin2hex($hash)]);
                $prize = $vault->decryptPrize($b['batch'], (int) $row->idx, hex2bin($row->p));
                DB::insert(
                    "INSERT INTO demo_pool (batch_id, code_enc, code_hash, game, description, generation) VALUES (?, decode(?, 'hex'), decode(?, 'hex'), ?, ?, ?)",
                    [
                        $b['batch'], bin2hex($this->box->seal($code, self::sealContext(bin2hex($hash)))), bin2hex($hash), $b['game'],
                        DemoGameTemplates::SHORT[$b['game']].': '.($labels[$prize] ?? $prize), $generation,
                    ],
                );
            }

            return count($b['codes']);
        });
    }

    private function activateWhenReady(string $batch, string $actor): void
    {
        $started = time();
        while (true) {
            try {
                $this->lifecycle->activate($batch, $actor);

                return;
            } catch (BeaconNotReady|BeaconUnavailable $e) {
                if (time() - $started > $this->beaconTimeoutSeconds) {
                    throw new \RuntimeException('La baliza no publicó la ronda a tiempo: '.$e->getMessage());
                }
                sleep($this->beaconPollSeconds);
            }
        }
    }
}
