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
 *  - Concéntrese: la disposición de las cartas (premio = una pareja con el símbolo del premio; sin premio, ninguna).
 *  - Dados: la combinación de los dos dados (la campaña dice qué combinaciones corresponden a cada premio).
 *  - Bingo en vivo: el cartón y la secuencia de bolas, verificados por simulación (el patrón del premio se completa en `winAt`). Ver `Bingo`.
 *  - Bomba 8: las caras en orden de volteo (premio = tres «8», el último entre las finales; sin premio, 0 a 2 «8»). Ver `Bomb`.
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

    private const MEMORY_DEFAULT_PAIRS = 6;

    private const MEMORY_DEFAULT_COLUMNS = 4;

    private const MEMORY_DEFAULT_FLIP_BACK_MS = 900;

    private const DICE_DEFAULT_ROLL_MS = 1800;

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
        $winPrizeIds = array_map('strval', DB::table('prizes')->where('campaign_id', $campaignId)->where('type', '!=', 'none')->pluck('prize_id')->all());

        match ($gameType) {
            'roulette' => self::assertRoulette($gameConfig, $prizeIds, $inPool),
            'scratch' => self::assertScratch($gameConfig, $prizeIds),
            'memory' => self::assertMemory($gameConfig, $prizeIds),
            'dice' => self::assertDice($gameConfig, $prizeIds, $inPool),
            'bomb' => Bomb::assert($gameConfig, $prizeIds),
            'bingo' => Bingo::assert($gameConfig, $winPrizeIds, array_values(array_intersect($inPool, $winPrizeIds))),
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

    /**
     * @param  array<string, mixed>  $cfg
     * @param  list<string>  $prizeIds
     */
    private static function assertMemory(array $cfg, array $prizeIds): void
    {
        $pairs = $cfg['pairs'] ?? self::MEMORY_DEFAULT_PAIRS;
        $columns = $cfg['columns'] ?? self::MEMORY_DEFAULT_COLUMNS;
        $flipBack = $cfg['flipBackMs'] ?? self::MEMORY_DEFAULT_FLIP_BACK_MS;
        if (! is_int($pairs) || $pairs < 3 || $pairs > 8) {
            throw new InvalidBatch('Concéntrese admite de 3 a 8 parejas (game_config.pairs).');
        }
        if (! is_int($columns) || $columns < 2 || $columns > 6) {
            throw new InvalidBatch('Concéntrese admite de 2 a 6 columnas (game_config.columns).');
        }
        if (! is_int($flipBack) || $flipBack < 300 || $flipBack > 3000) {
            throw new InvalidBatch('flipBackMs de Concéntrese debe estar entre 300 y 3000.');
        }
        if (isset($cfg['rival']) && ! is_bool($cfg['rival'])) {
            throw new InvalidBatch('rival de Concéntrese debe ser true o false (game_config.rival).');
        }
        $symbols = $cfg['symbols'] ?? self::DEFAULT_SYMBOLS;
        if (! is_array($symbols) || ! array_is_list($symbols) || array_unique($symbols) !== $symbols
            || array_filter($symbols, static fn ($s): bool => ! is_string($s) || $s === '') !== []) {
            throw new InvalidBatch('Los símbolos de relleno de Concéntrese deben ser una lista de textos distintos.');
        }
        $clash = array_values(array_intersect($symbols, $prizeIds));
        if ($clash !== []) {
            throw new InvalidBatch('Un símbolo de relleno de Concéntrese coincide con un premio ('.implode(', ', $clash).') y se vería como ganador.');
        }
        // Sin premio, todas las parejas son de relleno y cada símbolo entra una sola vez como pareja.
        if (count($symbols) < $pairs) {
            throw new InvalidBatch("Hacen falta al menos {$pairs} símbolos de relleno para {$pairs} parejas.");
        }
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  list<string>  $prizeIds
     * @param  list<string>  $inPool
     */
    private static function assertDice(array $cfg, array $prizeIds, array $inPool): void
    {
        if (($cfg['diceCount'] ?? 2) !== 2) {
            throw new InvalidBatch('Dados se juega con 2 dados (game_config.diceCount).');
        }
        $roll = $cfg['rollDurationMs'] ?? self::DICE_DEFAULT_ROLL_MS;
        if (! is_int($roll) || $roll < 500 || $roll > 5000) {
            throw new InvalidBatch('rollDurationMs de Dados debe estar entre 500 y 5000.');
        }
        $rolls = $cfg['rolls'] ?? null;
        if (! is_array($rolls) || $rolls === [] || array_is_list($rolls)) {
            throw new InvalidBatch('Dados necesita game_config.rolls: un mapa premio → lista de combinaciones [a, b].');
        }

        $owner = [];
        foreach ($rolls as $prize => $list) {
            $prize = (string) $prize;
            if (! in_array($prize, $prizeIds, true)) {
                throw new InvalidBatch("Dados: las combinaciones usan un premio que la campaña no define: {$prize}.");
            }
            if (! is_array($list) || ! array_is_list($list) || $list === []) {
                throw new InvalidBatch("Dados: el premio {$prize} debe tener una lista de combinaciones.");
            }
            foreach ($list as $combo) {
                $key = self::comboKey($combo);
                if (isset($owner[$key]) && $owner[$key] !== $prize) {
                    throw new InvalidBatch("Dados: la combinación {$key} está asignada a dos premios ({$owner[$key]} y {$prize}): el resultado sería ambiguo.");
                }
                $owner[$key] = $prize;
            }
        }

        $missing = array_values(array_filter($inPool, static fn (string $p): bool => ! isset($rolls[$p])));
        if ($missing !== []) {
            throw new InvalidBatch('Dados no tiene ninguna combinación para: '.implode(', ', $missing).'. Un usuario que lo gane no vería su resultado.');
        }
    }

    /**
     * Clave canónica de una combinación: [a, b] y [b, a] son la misma.
     *
     * @throws InvalidBatch
     */
    private static function comboKey(mixed $combo): string
    {
        if (! is_array($combo) || ! array_is_list($combo) || count($combo) !== 2) {
            throw new InvalidBatch('Dados: cada combinación debe ser una lista [a, b].');
        }
        foreach ($combo as $face) {
            if (! is_int($face) || $face < 1 || $face > 6) {
                throw new InvalidBatch('Dados: las caras deben ser enteros entre 1 y 6.');
            }
        }
        sort($combo);

        return $combo[0].'-'.$combo[1];
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
            'memory' => $this->buildMemory($gameConfig, $prizeDefs, $prizeId),
            'dice' => $this->buildDice($gameConfig, $prizeDefs, $prizeId),
            'bomb' => (new Bomb($this->randomInt))->build($gameConfig, $prizeDefs, $prizeId),
            'bingo' => (new Bingo($this->randomInt))->build($gameConfig, $prizeDefs, $prizeId),
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
     * Concéntrese: cada símbolo aparece exactamente dos veces. Con premio, una de las parejas es la del premio; sin premio, no hay ninguna.
     *
     * @param  array<string, mixed>  $cfg
     * @param  array<string, array<string, mixed>>  $prizeDefs
     * @return array{gameResult: array<string, mixed>, gameConfig: array<string, mixed>}
     */
    private function buildMemory(array $cfg, array $prizeDefs, string $prizeId): array
    {
        $pairs = (int) ($cfg['pairs'] ?? self::MEMORY_DEFAULT_PAIRS);
        $symbols = $cfg['symbols'] ?? self::DEFAULT_SYMBOLS;
        $wins = ($prizeDefs[$prizeId]['type'] ?? 'none') !== 'none';

        $fillerPairs = $pairs - ($wins ? 1 : 0);
        if (count($symbols) < $fillerPairs) {
            throw new \LogicException('Faltan símbolos de relleno (debió rechazarse al comprometer el lote).');
        }
        $cards = [];
        foreach (array_slice($this->shuffle($symbols), 0, $fillerPairs) as $symbol) {
            $cards[] = $symbol;
            $cards[] = $symbol;
        }
        if ($wins) {
            $cards[] = $prizeId;
            $cards[] = $prizeId;
        }

        return [
            'gameResult' => ['cards' => $this->shuffle($cards)],
            'gameConfig' => [
                'pairs' => $pairs,
                'columns' => (int) ($cfg['columns'] ?? self::MEMORY_DEFAULT_COLUMNS),
                'flipBackMs' => (int) ($cfg['flipBackMs'] ?? self::MEMORY_DEFAULT_FLIP_BACK_MS),
            // Rival simulado (solo animación; el resultado ya está decidido). Solo se envía si se activa: las campañas anteriores no cambian.
            ] + (($cfg['rival'] ?? false) === true ? ['rival' => true] : []),
        ];
    }

    /**
     * Dados: una de las combinaciones del premio, y el orden de los dos dados también al azar (es cosmético: [a, b] y [b, a] son lo mismo).
     *
     * @param  array<string, mixed>  $cfg
     * @param  array<string, array<string, mixed>>  $prizeDefs
     * @return array{gameResult: array<string, mixed>, gameConfig: array<string, mixed>}
     */
    private function buildDice(array $cfg, array $prizeDefs, string $prizeId): array
    {
        $rolls = $cfg['rolls'];
        $candidates = $rolls[$prizeId] ?? [];
        if ($candidates === []) {
            throw new \LogicException("El premio $prizeId no tiene combinaciones de dados (debió rechazarse al comprometer el lote).");
        }
        $combo = $candidates[($this->randomInt)(0, count($candidates) - 1)];
        $dice = ($this->randomInt)(0, 1) === 1 ? array_reverse($combo) : $combo;

        $legend = [];
        foreach ($rolls as $id => $list) {
            $legend[] = ['prize' => $prizeDefs[(string) $id], 'rolls' => array_map(static function (array $c): array {
                sort($c);

                return $c;
            }, $list)];
        }

        return [
            'gameResult' => ['dice' => array_values($dice)],
            'gameConfig' => ['diceCount' => 2, 'rollDurationMs' => (int) ($cfg['rollDurationMs'] ?? self::DICE_DEFAULT_ROLL_MS), 'legend' => $legend],
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
