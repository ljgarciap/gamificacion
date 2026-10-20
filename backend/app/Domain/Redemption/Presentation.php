<?php

namespace App\Domain\Redemption;

use App\Domain\Batches\InvalidBatch;
use App\Engine\Pool;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Cómo se REPRESENTA un resultado ya decidido (SPEC-002 §5). El premio lo decidió el sorteo; aquí solo se elige lo cosmético:
 *  - Ruleta: en qué segmento se muestra (un premio puede aparecer en varios, p. ej. dos "Sin premio").
 *  - Raspa: la cuadrícula (premio = 3 símbolos del premio; relleno sin ningún otro símbolo repetido 3 veces).
 *
 * Esas elecciones usan el CSPRNG del sistema operativo (no `rand`) y se GUARDAN con la redención: un reintento devuelve exactamente
 * lo mismo. Y se valida AL COMPROMETER el lote que la campaña pueda representar todos sus premios: es mejor rechazar entonces que
 * descubrir, con un usuario jugando, que un premio no cabe en la ruleta.
 */
final class Presentation
{
    /** Símbolos de relleno por defecto del raspa (los mismos que dibuja el cliente). */
    public const DEFAULT_SYMBOLS = ['star', 'moon', 'sun', 'coin', 'gem', 'heart', 'bell', 'flash'];

    private const ROULETTE_EXTRA_KEYS = ['durationMs', 'fullTurns', 'offsetSpread'];

    private const SCRATCH_EXTRA_KEYS = ['revealThreshold', 'brushWidth'];

    /** @var Closure(int, int): int */
    private readonly Closure $randomInt;

    /** @param (callable(int, int): int)|null $randomInt inyectable SOLO para pruebas; por defecto random_int (CSPRNG) */
    public function __construct(?callable $randomInt = null)
    {
        $this->randomInt = $randomInt !== null ? Closure::fromCallable($randomInt) : random_int(...);
    }

    // --- Validación (al comprometer) ---------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $gameConfig
     *
     * @throws InvalidBatch si la campaña no puede representar algún premio del pool
     */
    public static function assertRepresentable(string $campaignId, string $gameType, array $gameConfig, Pool $pool): void
    {
        $prizeIds = array_map('strval', DB::table('prizes')->where('campaign_id', $campaignId)->pluck('prize_id')->all());
        $inPool = array_column(array_filter($pool->entries(), static fn (array $e): bool => $e[1] > 0), 0);

        match ($gameType) {
            'roulette' => self::assertRoulette($gameConfig, $prizeIds, $inPool),
            'scratch' => self::assertScratch($gameConfig, $prizeIds),
            default => throw new InvalidBatch("No hay presentación para el juego «{$gameType}»."),
        };
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  list<string>  $prizeIds
     * @param  list<string>  $inPool
     */
    private static function assertRoulette(array $cfg, array $prizeIds, array $inPool): void
    {
        $segments = $cfg['segments'] ?? null;
        if (! is_array($segments) || ! array_is_list($segments) || count($segments) < 4 || count($segments) > 12) {
            throw new InvalidBatch('La ruleta de la campaña debe definir entre 4 y 12 segmentos (game_config.segments).');
        }
        foreach ($segments as $s) {
            if (! is_string($s) || ! in_array($s, $prizeIds, true)) {
                throw new InvalidBatch('La ruleta usa un premio que la campaña no define: '.json_encode($s));
            }
        }
        $missing = array_values(array_diff($inPool, $segments));
        if ($missing !== []) {
            throw new InvalidBatch('La ruleta no tiene ningún segmento para: '.implode(', ', $missing).'. Un usuario que lo gane no vería su resultado.');
        }
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  list<string>  $prizeIds
     */
    private static function assertScratch(array $cfg, array $prizeIds): void
    {
        $rows = $cfg['rows'] ?? 3;
        $cols = $cfg['cols'] ?? 3;
        if (! is_int($rows) || ! is_int($cols) || $rows < 2 || $rows > 5 || $cols < 2 || $cols > 5) {
            throw new InvalidBatch('La cuadrícula del raspa admite de 2 a 5 filas y columnas.');
        }
        $symbols = $cfg['symbols'] ?? self::DEFAULT_SYMBOLS;
        if (! is_array($symbols) || ! array_is_list($symbols) || array_unique($symbols) !== $symbols
            || array_filter($symbols, static fn ($s): bool => ! is_string($s) || $s === '') !== []) {
            throw new InvalidBatch('Los símbolos de relleno del raspa deben ser una lista de textos distintos.');
        }
        $clash = array_values(array_intersect($symbols, $prizeIds));
        if ($clash !== []) {
            throw new InvalidBatch('Un símbolo de relleno del raspa coincide con un premio ('.implode(', ', $clash).') y se vería como ganador.');
        }
        // Sin premio, cada símbolo puede aparecer como máximo 2 veces: se necesitan al menos celdas/2 símbolos distintos.
        if (count($symbols) * 2 < $rows * $cols) {
            throw new InvalidBatch('Hacen falta al menos '.(int) ceil($rows * $cols / 2).' símbolos de relleno para una cuadrícula de '."{$rows}x{$cols}.");
        }
    }

    // --- Construcción de lo que se muestra --------------------------------------------------------------------------------

    /**
     * Prueba que el relleno elegido es válido y devuelve el `gameResult` y el `gameConfig` listos para el cliente.
     *
     * @param  array<string, mixed>  $gameConfig  config de la campaña
     * @param  array<string, array<string, mixed>>  $prizeDefs  definición de TODOS los premios de la campaña, por id
     * @return array{gameResult: array<string, mixed>, gameConfig: array<string, mixed>}
     */
    public function build(string $gameType, array $gameConfig, array $prizeDefs, string $prizeId): array
    {
        return match ($gameType) {
            'roulette' => $this->buildRoulette($gameConfig, $prizeDefs, $prizeId),
            'scratch' => $this->buildScratch($gameConfig, $prizeDefs, $prizeId),
            default => throw new \LogicException("Sin presentación para el juego «{$gameType}»"),
        };
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, array<string, mixed>>  $prizeDefs
     * @return array{gameResult: array<string, mixed>, gameConfig: array<string, mixed>}
     */
    private function buildRoulette(array $cfg, array $prizeDefs, string $prizeId): array
    {
        $segments = $cfg['segments'];
        $candidates = array_keys(array_filter($segments, static fn (string $s): bool => $s === $prizeId));
        if ($candidates === []) {
            throw new \LogicException("El premio $prizeId no está en la ruleta (debió rechazarse al comprometer el lote).");
        }
        $index = $candidates[($this->randomInt)(0, count($candidates) - 1)];

        return [
            'gameResult' => ['segmentIndex' => $index],
            'gameConfig' => array_intersect_key($cfg, array_flip(self::ROULETTE_EXTRA_KEYS))
                + ['segments' => array_map(static fn (string $s): array => $prizeDefs[$s], $segments)],
        ];
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, array<string, mixed>>  $prizeDefs
     * @return array{gameResult: array<string, mixed>, gameConfig: array<string, mixed>}
     */
    private function buildScratch(array $cfg, array $prizeDefs, string $prizeId): array
    {
        $rows = (int) ($cfg['rows'] ?? 3);
        $cols = (int) ($cfg['cols'] ?? 3);
        $symbols = $cfg['symbols'] ?? self::DEFAULT_SYMBOLS;
        $cells = $rows * $cols;
        $wins = ($prizeDefs[$prizeId]['type'] ?? 'none') !== 'none';

        // Relleno: cada símbolo entra como máximo 2 veces (así nunca forma un trío que parezca premio).
        $fillerNeeded = $cells - ($wins ? 3 : 0);
        $bag = [];
        foreach ($symbols as $s) {
            $bag[] = $s;
            $bag[] = $s;
        }
        if (count($bag) < $fillerNeeded) {
            throw new \LogicException('Faltan símbolos de relleno (debió rechazarse al comprometer el lote).');
        }
        $grid = array_merge(array_slice($this->shuffle($bag), 0, $fillerNeeded), $wins ? [$prizeId, $prizeId, $prizeId] : []);

        return [
            'gameResult' => ['grid' => $this->shuffle($grid)],
            'gameConfig' => array_intersect_key($cfg, array_flip(self::SCRATCH_EXTRA_KEYS)) + ['rows' => $rows, 'cols' => $cols],
        ];
    }

    /**
     * Fisher-Yates con el CSPRNG. Aquí no hace falta reproducibilidad (el resultado se guarda), solo impredecibilidad.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    private function shuffle(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = ($this->randomInt)(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }
}
