<?php

namespace App\Console\Commands;

use App\Domain\Batches\BatchLifecycle;
use App\Domain\Batches\BeaconNotReady;
use App\Domain\Beacon\BeaconUnavailable;
use App\Domain\Codes\CodeFormat;
use App\Domain\Codes\CodeGenerator;
use App\Domain\Crypto\CodeHasher;
use App\Domain\Crypto\PrizeVault;
use App\Domain\Dev\DemoCodes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea campañas de demostración con códigos REALES (los genera el generador de verdad, el sorteo es el del motor), para probar el flujo completo
 * desde la app. SOLO en local/testing. Con GAMIF_BEACON=local no espera a drand; con drand espera ~1 minuto por lote (es la baliza real).
 *
 * Los lotes comprometidos son inmutables y no se pueden borrar: para empezar de cero, `php artisan migrate:fresh`.
 */
class SeedDemo extends Command
{
    protected $signature = 'gamification:demo:seed {--actor=demo : Quién lo ejecuta, para la bitácora}';

    protected $description = 'Crea campañas de demostración (ruleta y raspa) con códigos reales, solo en desarrollo local';

    public function handle(): int
    {
        if (! $this->laravel->environment('local', 'testing')) {
            $this->error('Este comando solo se ejecuta en entornos local/testing.');

            return self::FAILURE;
        }
        // Las dependencias se resuelven DESPUÉS de comprobar el entorno: al construir la baliza el contenedor ya se niega a usar la local
        // fuera de desarrollo, y el usuario debe ver este mensaje claro y no una traza.
        $lifecycle = $this->laravel->make(BatchLifecycle::class);
        $generator = $this->laravel->make(CodeGenerator::class);
        $actor = (string) $this->option('actor');

        $entries = DemoCodes::load();
        $entries = array_merge($entries, $this->roulette($lifecycle, $generator, $actor), $this->scratch($lifecycle, $generator, $actor));
        DemoCodes::save($entries);

        $this->info(count($entries).' códigos de demostración disponibles en '.DemoCodes::path());
        $this->line('La app independiente los muestra en su panel "Códigos de prueba" (GET /v1/dev/demo-codes).');

        return self::SUCCESS;
    }

    /** @return list<array{code: string, game: string, description: string, batch_id: string}> */
    private function roulette(BatchLifecycle $lifecycle, CodeGenerator $generator, string $actor): array
    {
        $campaign = $this->campaign('Gira y Gana', 'roulette', 'rejectOnly', [
            'segments' => ['p50k', 'none', 'p10k', 'pts', 'none', 'cupon', 'extra', 'p100k'],
        ], [
            ['p50k', 'money', '$ 50.000', 50000, []],
            ['none', 'none', 'Sin premio', null, []],
            ['p10k', 'money', '$ 10.000', 10000, []],
            ['pts', 'points', '250 puntos', 250, []],
            ['cupon', 'coupon', 'Cupón 20%', null, ['coupon_code' => 'GIRA20']],
            ['extra', 'extraSpin', 'Gira de nuevo', null, ['extra_spins' => 1]],
            ['p100k', 'money', '$ 100.000', 100000, []],
        ]);

        return $this->batch($lifecycle, $generator, $actor, $campaign, 'roulette', 'RULETA', [
            ['prize_id' => 'none', 'quantity' => 18], ['prize_id' => 'p10k', 'quantity' => 10], ['prize_id' => 'pts', 'quantity' => 5],
            ['prize_id' => 'cupon', 'quantity' => 3], ['prize_id' => 'extra', 'quantity' => 1], ['prize_id' => 'p50k', 'quantity' => 2],
            ['prize_id' => 'p100k', 'quantity' => 1],
        ]);
    }

    /** @return list<array{code: string, game: string, description: string, batch_id: string}> */
    private function scratch(BatchLifecycle $lifecycle, CodeGenerator $generator, string $actor): array
    {
        $campaign = $this->campaign('Raspa y Gana', 'scratch', 'showPrevious', ['rows' => 3, 'cols' => 3], [
            ['rnone', 'none', 'Sin premio', null, []],
            ['r10k', 'money', '$ 10.000', 10000, []],
            ['r50k', 'money', '$ 50.000', 50000, []],
        ]);

        return $this->batch($lifecycle, $generator, $actor, $campaign, 'scratch', 'RASPA', [
            ['prize_id' => 'rnone', 'quantity' => 30], ['prize_id' => 'r10k', 'quantity' => 8], ['prize_id' => 'r50k', 'quantity' => 2],
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int|null, 4: array<string, mixed>}>  $prizes
     */
    private function campaign(string $name, string $game, string $behavior, array $config, array $prizes): string
    {
        $id = (string) Str::uuid();
        DB::table('campaigns')->insert([
            'id' => $id, 'name' => $name, 'game_type' => $game, 'used_code_behavior' => $behavior, 'title' => $name,
            'game_config' => json_encode($config),
        ]);
        foreach ($prizes as [$prizeId, $type, $label, $amount, $meta]) {
            DB::table('prizes')->insert([
                'campaign_id' => $id, 'prize_id' => $prizeId, 'type' => $type, 'label' => $label, 'amount' => $amount,
                'meta' => json_encode((object) $meta),
            ]);
        }

        return $id;
    }

    /**
     * @param  list<array{prize_id: string, quantity: int}>  $pool
     * @return list<array{code: string, game: string, description: string, batch_id: string}>
     */
    private function batch(BatchLifecycle $lifecycle, CodeGenerator $generator, string $actor, string $campaign, string $game, string $prefix, array $pool): array
    {
        $batch = $lifecycle->createDraft($campaign, "Demo $prefix", $pool, $actor);

        $csv = tempnam(sys_get_temp_dir(), 'demo-codes-');
        unlink($csv); // el generador crea el archivo y no sobrescribe uno existente
        $generator->generate($batch, $prefix, $csv, $actor);
        $codes = file($csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        array_shift($codes);
        unlink($csv);

        $lifecycle->commit($batch, $actor);
        $this->activateWhenReady($lifecycle, $batch, $actor);

        // La descripción de cada código sale del mapa cifrado (en desarrollo se puede descifrar: se tiene la clave).
        $vault = new PrizeVault;
        $hasher = new CodeHasher;
        $labels = DB::table('prizes')->where('campaign_id', $campaign)->pluck('label', 'prize_id')->all();
        $entries = [];
        foreach ($codes as $code) {
            $row = DB::selectOne(
                "SELECT idx, encode(prize_enc, 'hex') AS p FROM codes WHERE code_hash = decode(?, 'hex')",
                [bin2hex($hasher->hash(CodeFormat::normalize($code)))],
            );
            $prize = $vault->decryptPrize($batch, (int) $row->idx, hex2bin($row->p));
            $entries[] = [
                'code' => $code, 'game' => $game, 'batch_id' => $batch,
                'description' => ($game === 'roulette' ? 'Ruleta' : 'Raspa').': '.($labels[$prize] ?? $prize),
            ];
        }
        $this->info("Lote $prefix activo con ".count($codes).' códigos.');

        return $entries;
    }

    private function activateWhenReady(BatchLifecycle $lifecycle, string $batch, string $actor): void
    {
        $started = time();
        while (true) {
            try {
                $lifecycle->activate($batch, $actor);

                return;
            } catch (BeaconNotReady|BeaconUnavailable $e) {
                if (time() - $started > 240) {
                    throw new \RuntimeException('La baliza no publicó la ronda a tiempo: '.$e->getMessage());
                }
                $this->output->write('.');
                sleep(3);
            }
        }
    }
}
