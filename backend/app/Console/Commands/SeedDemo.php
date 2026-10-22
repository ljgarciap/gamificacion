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
 * desde la app. Solo en local/testing o con GAMIF_DEMO_MODE=true. Con GAMIF_BEACON=local no espera a drand; con drand espera ~1 minuto por lote (es la baliza real).
 *
 * Los lotes comprometidos son inmutables y no se pueden borrar: para empezar de cero, `php artisan migrate:fresh`.
 */
class SeedDemo extends Command
{
    /** Juegos de la demo, con su nombre visible en la descripción de cada código. */
    private const GAMES = ['roulette' => 'Ruleta', 'scratch' => 'Raspa', 'memory' => 'Concéntrese', 'dice' => 'Dados', 'bomb' => 'Bomba 8', 'bingo' => 'Bingo en vivo'];

    protected $signature = 'gamification:demo:seed
        {--actor=demo : Quién lo ejecuta, para la bitácora}
        {--only= : Solo estos juegos, separados por comas (roulette, scratch, memory, dice, bomb, bingo); por defecto todos}';

    protected $description = 'Crea campañas de demostración (ruleta, raspa, Concéntrese, dados, Bomba 8 y Bingo) con códigos reales, solo en desarrollo local';

    public function handle(): int
    {
        if (! $this->laravel->environment('local', 'testing') && ! config('gamification.demo.enabled')) {
            $this->error('Este comando solo se ejecuta en local/testing o con GAMIF_DEMO_MODE=true (servidor de demostración).');

            return self::FAILURE;
        }
        // Las dependencias se resuelven DESPUÉS de comprobar el entorno: al construir la baliza el contenedor ya se niega a usar la local
        // fuera de desarrollo, y el usuario debe ver este mensaje claro y no una traza.
        $lifecycle = $this->laravel->make(BatchLifecycle::class);
        $generator = $this->laravel->make(CodeGenerator::class);
        $actor = (string) $this->option('actor');

        $only = $this->option('only') === null || $this->option('only') === ''
            ? array_keys(self::GAMES)
            : array_values(array_filter(array_map('trim', explode(',', (string) $this->option('only')))));
        $unknown = array_values(array_diff($only, array_keys(self::GAMES)));
        if ($unknown !== []) {
            $this->error('Juego desconocido: '.implode(', ', $unknown).'. Use: '.implode(', ', array_keys(self::GAMES)).'.');

            return self::INVALID;
        }

        $entries = DemoCodes::load();
        foreach ($only as $game) {
            $entries = array_merge($entries, $this->{$game}($lifecycle, $generator, $actor));
        }
        DemoCodes::save($entries);

        $this->info(count($entries).' códigos de demostración disponibles en '.DemoCodes::path());
        $this->line('La app independiente los muestra en su panel "Códigos de prueba" (GET /v1/dev/demo-codes o /v1/demo/codes).');

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
            ['prize_id' => 'none', 'quantity' => 17], ['prize_id' => 'p10k', 'quantity' => 10], ['prize_id' => 'pts', 'quantity' => 5],
            ['prize_id' => 'cupon', 'quantity' => 3], ['prize_id' => 'extra', 'quantity' => 2], ['prize_id' => 'p50k', 'quantity' => 2],
            ['prize_id' => 'p100k', 'quantity' => 1],
            // 40 posiciones: 38 códigos y 2 casillas de reserva para los giros de los dos premios «Gira de nuevo» (1 giro cada uno).
        ], reserve: 2);
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

    /** @return list<array{code: string, game: string, description: string, batch_id: string}> */
    private function memory(BatchLifecycle $lifecycle, CodeGenerator $generator, string $actor): array
    {
        $campaign = $this->campaign('Concéntrese', 'memory', 'showPrevious', ['pairs' => 6, 'columns' => 4, 'rival' => true], [
            ['mnone', 'none', 'Sin premio', null, []],
            ['m10k', 'money', '$ 10.000', 10000, []],
            ['mpts', 'points', '250 puntos', 250, []],
            ['m50k', 'money', '$ 50.000', 50000, []],
        ]);

        return $this->batch($lifecycle, $generator, $actor, $campaign, 'memory', 'CONCENTRESE', [
            ['prize_id' => 'mnone', 'quantity' => 18], ['prize_id' => 'm10k', 'quantity' => 7],
            ['prize_id' => 'mpts', 'quantity' => 4], ['prize_id' => 'm50k', 'quantity' => 1],
        ]);
    }

    /**
     * Dados: los dobles pagan (1-1 y 6-6 = $ 50.000; 2-2 a 5-5 = $ 10.000) y el resto de combinaciones es "Sin premio".
     *
     * @return list<array{code: string, game: string, description: string, batch_id: string}>
     */
    private function dice(BatchLifecycle $lifecycle, CodeGenerator $generator, string $actor): array
    {
        $none = [];
        for ($a = 1; $a <= 6; $a++) {
            for ($b = $a + 1; $b <= 6; $b++) {
                $none[] = [$a, $b];
            }
        }
        $campaign = $this->campaign('Dados de la suerte', 'dice', 'showPrevious', [
            'rolls' => ['dnone' => $none, 'd10k' => [[2, 2], [3, 3], [4, 4], [5, 5]], 'd50k' => [[1, 1], [6, 6]]],
        ], [
            ['dnone', 'none', 'Sin premio', null, []],
            ['d10k', 'money', '$ 10.000', 10000, []],
            ['d50k', 'money', '$ 50.000', 50000, []],
        ]);

        return $this->batch($lifecycle, $generator, $actor, $campaign, 'dice', 'DADOS', [
            ['prize_id' => 'dnone', 'quantity' => 20], ['prize_id' => 'd10k', 'quantity' => 8], ['prize_id' => 'd50k', 'quantity' => 2],
        ]);
    }

    /**
     * Bomba 8: tres 8 desactivan la bomba (los 8 salen en orden de volteo, el tercero al final); sin ellos la mecha se acaba.
     *
     * @return list<array{code: string, game: string, description: string, batch_id: string}>
     */
    private function bomb(BatchLifecycle $lifecycle, CodeGenerator $generator, string $actor): array
    {
        $campaign = $this->campaign('Bomba 8', 'bomb', 'showPrevious', ['cards' => 12, 'columns' => 4], [
            ['bnone', 'none', 'Sin premio', null, []],
            ['b10k', 'money', '$ 10.000', 10000, []],
            ['b50k', 'money', '$ 50.000', 50000, []],
        ]);

        return $this->batch($lifecycle, $generator, $actor, $campaign, 'bomb', 'BOMBA', [
            ['prize_id' => 'bnone', 'quantity' => 18], ['prize_id' => 'b10k', 'quantity' => 10], ['prize_id' => 'b50k', 'quantity' => 2],
        ]);
    }

    /**
     * Bingo en vivo: gana el patrón de mayor premio que se complete (cartón lleno > cuatro esquinas > línea).
     *
     * @return list<array{code: string, game: string, description: string, batch_id: string}>
     */
    private function bingo(BatchLifecycle $lifecycle, CodeGenerator $generator, string $actor): array
    {
        $campaign = $this->campaign('Bingo en vivo', 'bingo', 'showPrevious', [
            'patterns' => [['pattern' => 'full', 'prize' => 'g100k'], ['pattern' => 'corners', 'prize' => 'g50k'], ['pattern' => 'line', 'prize' => 'g10k']],
        ], [
            ['gnone', 'none', 'Sin premio', null, []],
            ['g10k', 'money', '$ 10.000', 10000, []],
            ['g50k', 'money', '$ 50.000', 50000, []],
            ['g100k', 'money', '$ 100.000', 100000, []],
        ]);

        return $this->batch($lifecycle, $generator, $actor, $campaign, 'bingo', 'BINGO', [
            ['prize_id' => 'gnone', 'quantity' => 14], ['prize_id' => 'g10k', 'quantity' => 10],
            ['prize_id' => 'g50k', 'quantity' => 5], ['prize_id' => 'g100k', 'quantity' => 1],
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
    private function batch(BatchLifecycle $lifecycle, CodeGenerator $generator, string $actor, string $campaign, string $game, string $prefix, array $pool, int $reserve = 0): array
    {
        $batch = $lifecycle->createDraft($campaign, "Demo $prefix", $pool, $actor, null, $reserve);

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
                'description' => self::GAMES[$game].': '.($labels[$prize] ?? $prize),
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
