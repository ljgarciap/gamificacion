<?php

namespace App\Domain\Redemption;

use App\Domain\Batches\InvalidBatch;
use Closure;

/**
 * Bingo en vivo (SPEC-005 §4). El premio ya está decidido; aquí solo se arma lo que se VE: un cartón de 5×5 (B-I-N-G-O, centro libre) y la
 * secuencia de bolas. Todo se construye con el CSPRNG y se VERIFICA simulando la partida antes de devolverlo: si no logra una secuencia válida
 * es un error de programación, nunca un resultado inventado.
 *
 * Reglas de la partida: salen `draws` bolas, una por vez, y cada patrón que paga se «completa» en alguna bola. Gana el patrón de MAYOR prioridad
 * (el primero de la lista de la campaña) que se complete en la partida; los de menor prioridad pueden completarse antes y solo avisan («sigue
 * jugando»). La partida termina en la última bola, o antes si se completa el patrón de mayor prioridad (no hay nada mejor que esperar).
 * Con premio, el patrón del premio se completa en la bola `winAt` y NINGÚN patrón de mayor prioridad se completa nunca; sin premio no se
 * completa ninguno.
 */
final class Bingo
{
    public const SIZE = 5;

    public const CENTER = 12;

    public const PATTERNS = ['line', 'corners', 'full'];

    public const DEFAULT_DRAWS = 30;

    public const DEFAULT_INTERVAL_MS = 1200;

    public const DEFAULT_MIN_WIN_DRAW = 10;

    /** Intentos de construcción antes de rendirse (con una configuración válida basta el primero casi siempre). */
    private const ATTEMPTS = 300;

    /** @param Closure(int, int): int $randomInt CSPRNG (inyectable solo en pruebas) */
    public function __construct(private readonly Closure $randomInt) {}

    /**
     * Cuántas bolas del cartón hacen falta como mínimo para completar el patrón (sin contar el centro libre).
     */
    public static function requiredCells(string $pattern): int
    {
        return min(array_map(
            static fn (array $alt): int => count(array_diff($alt, [self::CENTER])),
            self::alternatives($pattern),
        ));
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  list<string>  $winPrizeIds  premios de la campaña que valen algo (type distinto de none)
     * @param  list<string>  $winInPool  de esos, los que están en el pool con cantidad > 0
     *
     * @throws InvalidBatch
     */
    public static function assert(array $cfg, array $winPrizeIds, array $winInPool): void
    {
        $draws = $cfg['draws'] ?? self::DEFAULT_DRAWS;
        $interval = $cfg['drawIntervalMs'] ?? self::DEFAULT_INTERVAL_MS;
        $minWin = $cfg['minWinDraw'] ?? self::DEFAULT_MIN_WIN_DRAW;
        if (! is_int($draws) || $draws < 24 || $draws > 60) {
            throw new InvalidBatch('El Bingo admite de 24 a 60 bolas por partida (game_config.draws).');
        }
        if (! is_int($interval) || $interval < 400 || $interval > 3000) {
            throw new InvalidBatch('drawIntervalMs del Bingo debe estar entre 400 y 3000.');
        }
        if (! is_int($minWin) || $minWin < 5 || $minWin > $draws) {
            throw new InvalidBatch("minWinDraw del Bingo debe ser un entero de 5 a {$draws} (game_config.minWinDraw).");
        }
        $patterns = $cfg['patterns'] ?? null;
        if (! is_array($patterns) || ! array_is_list($patterns) || $patterns === []) {
            throw new InvalidBatch('El Bingo necesita game_config.patterns: una lista de {pattern, prize}, del mayor al menor.');
        }

        $seenPattern = [];
        $seenPrize = [];
        foreach ($patterns as $p) {
            if (! is_array($p) || array_keys($p) !== ['pattern', 'prize'] && array_keys($p) !== ['prize', 'pattern']) {
                throw new InvalidBatch('Cada elemento de patterns debe ser {"pattern": …, "prize": …}.');
            }
            $pattern = $p['pattern'];
            $prize = $p['prize'];
            if (! is_string($pattern) || ! in_array($pattern, self::PATTERNS, true)) {
                throw new InvalidBatch('Patrón de Bingo desconocido; use: '.implode(', ', self::PATTERNS).'.');
            }
            if (! is_string($prize) || ! in_array($prize, $winPrizeIds, true)) {
                throw new InvalidBatch('El Bingo asocia un patrón a un premio que no existe en la campaña o que no vale nada: '.json_encode($prize).'.');
            }
            if (isset($seenPattern[$pattern])) {
                throw new InvalidBatch("El patrón «{$pattern}» está repetido en el Bingo.");
            }
            if (isset($seenPrize[$prize])) {
                throw new InvalidBatch("El premio «{$prize}» tiene dos patrones en el Bingo: el resultado sería ambiguo.");
            }
            $seenPattern[$pattern] = true;
            $seenPrize[$prize] = true;
            if ($draws < self::requiredCells($pattern)) {
                throw new InvalidBatch("Con {$draws} bolas no se puede completar el patrón «{$pattern}» (hacen falta al menos ".self::requiredCells($pattern).').');
            }
        }
        // El cartón lleno completa también todas las líneas y las esquinas: si no es el de mayor prioridad, nunca podría ganar.
        $order = array_column($patterns, 'pattern');
        $fullAt = array_search('full', $order, true);
        if ($fullAt !== false && $fullAt !== 0) {
            throw new InvalidBatch('En el Bingo el cartón lleno debe ir primero en patterns: completa también las líneas y las esquinas, así que con menor prioridad nunca podría ganar.');
        }
        $missing = array_values(array_diff($winInPool, array_keys($seenPrize)));
        if ($missing !== []) {
            throw new InvalidBatch('El Bingo no tiene ningún patrón para: '.implode(', ', $missing).'. Un usuario que lo gane no vería su resultado.');
        }
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, array<string, mixed>>  $prizeDefs
     * @return array{gameResult: array<string, mixed>, gameConfig: array<string, mixed>}
     */
    public function build(array $cfg, array $prizeDefs, string $prizeId): array
    {
        $draws = (int) ($cfg['draws'] ?? self::DEFAULT_DRAWS);
        $minWin = (int) ($cfg['minWinDraw'] ?? self::DEFAULT_MIN_WIN_DRAW);
        /** @var list<array{pattern: string, prize: string}> $patterns */
        $patterns = array_map(static fn (array $p): array => ['pattern' => $p['pattern'], 'prize' => $p['prize']], $cfg['patterns']);
        $order = array_column($patterns, 'pattern');
        $wins = ($prizeDefs[$prizeId]['type'] ?? 'none') !== 'none';

        $target = null;
        if ($wins) {
            foreach ($patterns as $p) {
                if ($p['prize'] === $prizeId) {
                    $target = $p['pattern'];
                }
            }
            if ($target === null) {
                throw new \LogicException("El premio $prizeId no tiene patrón de Bingo (debió rechazarse al comprometer el lote).");
            }
        }

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $built = $wins ? $this->buildWin($order, $target, $draws, $minWin) : $this->buildNone($order, $draws);
            if ($built === null) {
                continue;
            }
            [$card, $balls, $expectedAt] = $built;
            $events = self::events($card, $balls, $order);
            $best = self::best($events, $order);
            // Verificación: la partida simulada tiene que dar EXACTAMENTE lo esperado.
            if ($wins ? ($best === null || $best['at'] !== $expectedAt || $best['pattern'] !== $target) : $events !== []) {
                continue;
            }

            return [
                'gameResult' => [
                    'card' => $card,
                    'balls' => $balls,
                    'events' => array_map(static fn (array $e): array => ['at' => $e['at'], 'pattern' => $e['pattern'], 'cells' => self::asCoords($e['cells'])], $events),
                    'winAt' => $best['at'] ?? null,
                    'pattern' => $best['pattern'] ?? null,
                    'cells' => $best !== null ? self::asCoords($best['cells']) : null,
                    // Con el patrón mayor la partida termina ahí; si no, se juegan todas las bolas.
                    'endAt' => $best !== null && $best['pattern'] === $order[0] ? $best['at'] : $draws,
                ],
                'gameConfig' => [
                    'draws' => $draws,
                    'drawIntervalMs' => (int) ($cfg['drawIntervalMs'] ?? self::DEFAULT_INTERVAL_MS),
                    'legend' => array_map(static fn (array $p): array => ['pattern' => $p['pattern'], 'prize' => $prizeDefs[$p['prize']]], $patterns),
                ],
            ];
        }
        throw new \LogicException('No se pudo construir una partida de Bingo coherente con el premio (revisar game_config).');
    }

    /**
     * Simula la partida: cada vez que una bola completa un patrón que paga por primera vez, es un evento.
     *
     * @param  list<list<int|null>>  $card
     * @param  list<int>  $balls
     * @param  list<string>  $order  patrones que pagan, del mayor al menor
     * @return list<array{at: int, pattern: string, cells: list<int>}>
     */
    public static function events(array $card, array $balls, array $order): array
    {
        $cellOf = [];
        foreach ($card as $r => $row) {
            foreach ($row as $c => $n) {
                if ($n !== null) {
                    $cellOf[$n] = $r * self::SIZE + $c;
                }
            }
        }
        $marked = [self::CENTER => true];
        $done = [];
        $events = [];
        foreach ($balls as $i => $ball) {
            if (! isset($cellOf[$ball])) {
                continue;
            }
            $marked[$cellOf[$ball]] = true;
            foreach ($order as $pattern) {
                if (isset($done[$pattern])) {
                    continue;
                }
                $alt = self::completeAlternative($pattern, $marked);
                if ($alt !== null) {
                    $done[$pattern] = true;
                    $events[] = ['at' => $i + 1, 'pattern' => $pattern, 'cells' => $alt];
                }
            }
        }

        return $events;
    }

    /**
     * El evento del patrón de mayor prioridad (el que paga), o null si no hubo ninguno.
     *
     * @param  list<array{at: int, pattern: string, cells: list<int>}>  $events
     * @param  list<string>  $order
     * @return array{at: int, pattern: string, cells: list<int>}|null
     */
    public static function best(array $events, array $order): ?array
    {
        $best = null;
        $bestRank = PHP_INT_MAX;
        foreach ($events as $e) {
            $rank = (int) array_search($e['pattern'], $order, true);
            if ($rank < $bestRank) {
                $best = $e;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /** @param list<int> $cells @return list<array{0: int, 1: int}> */
    private static function asCoords(array $cells): array
    {
        return array_map(static fn (int $c): array => [intdiv($c, self::SIZE), $c % self::SIZE], $cells);
    }

    /**
     * Si con esas casillas marcadas el patrón está completo, la forma en que lo está.
     *
     * @param  array<int, bool>  $marked
     * @return list<int>|null
     */
    private static function completeAlternative(string $pattern, array $marked): ?array
    {
        foreach (self::alternatives($pattern) as $alt) {
            if (count(array_filter($alt, static fn (int $c): bool => isset($marked[$c]))) === count($alt)) {
                return $alt;
            }
        }

        return null;
    }

    /**
     * ¿Alguno de esos patrones está completo con esas casillas?
     *
     * @param  array<int, bool>  $marked
     * @param  list<string>  $patterns
     */
    private static function anyComplete(array $patterns, array $marked): bool
    {
        foreach ($patterns as $pattern) {
            if (self::completeAlternative($pattern, $marked) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Las formas de completar un patrón (como listas de casillas, 0 a 24 por filas).
     *
     * @return list<list<int>>
     */
    private static function alternatives(string $pattern): array
    {
        $n = self::SIZE;

        return match ($pattern) {
            'corners' => [[0, $n - 1, $n * ($n - 1), $n * $n - 1]],
            'full' => [range(0, $n * $n - 1)],
            'line' => array_merge(
                array_map(static fn (int $r): array => range($r * $n, $r * $n + $n - 1), range(0, $n - 1)),
                array_map(static fn (int $c): array => array_map(static fn (int $r): int => $r * $n + $c, range(0, $n - 1)), range(0, $n - 1)),
                [array_map(static fn (int $i): int => $i * ($n + 1), range(0, $n - 1)), array_map(static fn (int $i): int => $i * ($n - 1) + ($n - 1), range(0, $n - 1))],
            ),
            default => throw new \LogicException("Patrón desconocido: $pattern"),
        };
    }

    /**
     * Un cartón: cada columna B-I-N-G-O toma 5 números distintos de su rango (la N, 4: el centro es libre).
     *
     * @return list<list<int|null>>
     */
    private function newCard(): array
    {
        $card = array_fill(0, self::SIZE, array_fill(0, self::SIZE, null));
        for ($c = 0; $c < self::SIZE; $c++) {
            $pool = $this->shuffle(range($c * 15 + 1, $c * 15 + 15));
            for ($r = 0; $r < self::SIZE; $r++) {
                if ($r * self::SIZE + $c === self::CENTER) {
                    continue;
                }
                $card[$r][$c] = array_pop($pool);
            }
        }

        return $card;
    }

    /** @param list<list<int|null>> $card @return array<int, int> número => casilla */
    private function cellOf(array $card): array
    {
        $out = [];
        foreach ($card as $r => $row) {
            foreach ($row as $c => $n) {
                if ($n !== null) {
                    $out[$n] = $r * self::SIZE + $c;
                }
            }
        }

        return $out;
    }

    /**
     * Partida SIN premio: se marcan algunas casillas del cartón (las que el azar elija mientras no completen ningún patrón) y el resto de las
     * bolas no están en el cartón.
     *
     * @param  list<string>  $order
     * @return array{0: list<list<int|null>>, 1: list<int>, 2: null}|null
     */
    private function buildNone(array $order, int $draws): ?array
    {
        $card = $this->newCard();
        $cellOf = $this->cellOf($card);
        $numbers = array_keys($cellOf);
        $off = array_values(array_diff(range(1, 75), $numbers));
        $minCard = max(0, $draws - count($off));
        $want = min(24, max($minCard, (int) round($draws * 24 / 75) + $this->range(-2, 2)));

        $marked = [self::CENTER => true];
        $picked = [];
        foreach ($this->shuffle($numbers) as $n) {
            if (count($picked) >= $want) {
                break;
            }
            $try = $marked + [$cellOf[$n] => true];
            if (self::anyComplete($order, $try)) {
                continue;
            }
            $marked = $try;
            $picked[] = $n;
        }
        if (count($picked) < $minCard) {
            return null;
        }
        $fillers = array_slice($this->shuffle($off), 0, $draws - count($picked));

        return [$card, $this->shuffle([...$picked, ...$fillers]), null];
    }

    /**
     * Partida CON premio. El patrón del premio se completa en la bola `w` (la última de sus casillas) y no antes; ningún patrón de MAYOR
     * prioridad se completa jamás (ni en las bolas de después de `w`). Los de menor prioridad pueden completarse cuando sea.
     *
     * @param  list<string>  $order
     * @return array{0: list<list<int|null>>, 1: list<int>, 2: int}|null
     */
    private function buildWin(array $order, string $target, int $draws, int $minWin): ?array
    {
        $card = $this->newCard();
        $cellOf = $this->cellOf($card);
        $numberAt = array_flip($cellOf);
        $rank = (int) array_search($target, $order, true);
        $higher = array_slice($order, 0, $rank); // prohibidos en toda la partida
        $alts = self::alternatives($target);
        $alt = $alts[$this->range(0, count($alts) - 1)];
        $core = [];
        foreach ($alt as $cell) {
            if ($cell !== self::CENTER) {
                $core[] = $numberAt[$cell];
            }
        }
        $s = count($core);
        $lo = max($minWin, $s);
        if ($lo > $draws) {
            return null;
        }
        $w = $this->range($lo, $draws);
        $last = $core[$this->range(0, $s - 1)];
        $others = array_values(array_diff($core, [$last]));

        $off = array_values(array_diff(range(1, 75), array_keys($cellOf)));
        $minExtra = max(0, $w - $s - count($off));
        $wantExtra = max($minExtra, max(0, (int) round($w * 24 / 75) - $s + $this->range(-1, 1)));

        // Antes de la bola w: ni el patrón del premio (con ninguna de sus formas) ni uno de mayor prioridad pueden estar completos.
        $marked = [self::CENTER => true];
        foreach ($others as $n) {
            $marked[$cellOf[$n]] = true;
        }
        $extras = [];
        foreach ($this->shuffle(array_values(array_diff(array_keys($cellOf), $core))) as $n) {
            if (count($extras) >= $wantExtra) {
                break;
            }
            $try = $marked + [$cellOf[$n] => true];
            if (self::anyComplete([$target, ...$higher], $try)) {
                continue;
            }
            $marked = $try;
            $extras[] = $n;
        }
        if (count($extras) < $minExtra) {
            return null;
        }
        $fillers = array_slice($this->shuffle($off), 0, $w - $s - count($extras));
        $prefix = [...$this->shuffle([...$others, ...$extras, ...$fillers]), $last];

        // En la bola w se completa el patrón del premio, y ahí tampoco puede estar completo uno de mayor prioridad.
        $marked[$cellOf[$last]] = true;
        if ($higher !== [] && self::anyComplete($higher, $marked)) {
            return null;
        }

        // Las bolas de después: cualquiera, salvo las que completarían un patrón de mayor prioridad.
        $suffix = [];
        foreach ($this->shuffle(array_values(array_diff(range(1, 75), $prefix))) as $n) {
            if (count($suffix) >= $draws - $w) {
                break;
            }
            if (isset($cellOf[$n])) {
                $try = $marked + [$cellOf[$n] => true];
                if ($higher !== [] && self::anyComplete($higher, $try)) {
                    continue;
                }
                $marked = $try;
            }
            $suffix[] = $n;
        }
        if (count($suffix) < $draws - $w) {
            return null;
        }

        return [$card, [...$prefix, ...$suffix], $w];
    }

    private function range(int $min, int $max): int
    {
        return ($this->randomInt)($min, $max);
    }

    /** @param list<mixed> $items @return list<mixed> */
    private function shuffle(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = ($this->randomInt)(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }
}
