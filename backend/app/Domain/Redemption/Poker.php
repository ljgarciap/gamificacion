<?php

namespace App\Domain\Redemption;

use App\Domain\Batches\InvalidBatch;
use Closure;

/**
 * Póker del crupier (SPEC-005 §5). El premio ya está decidido; aquí solo se arman las 7 cartas que se VEN: 2 del jugador y 5 comunes que el
 * crupier abre una a una. Cuenta la mejor mano de 5 entre las 7. La campaña dice qué categoría de mano da qué premio (`hands`: categoría →
 * premio; varias categorías pueden dar el mismo premio). Todo se construye con el CSPRNG y se VERIFICA con el evaluador antes de devolverlo.
 *
 * Cartas: rango `2 3 4 5 6 7 8 9 T J Q K A` + palo `S H D C` (p. ej. «TD» = diez de diamantes).
 */
final class Poker
{
    /** De menor a mayor. El índice es el «rango» de la categoría. */
    public const CATEGORIES = ['high_card', 'pair', 'two_pair', 'three_of_a_kind', 'straight', 'flush', 'full_house', 'four_of_a_kind', 'straight_flush'];

    /** Las que pueden pagar (de tres iguales en adelante). */
    public const PAYABLE = ['three_of_a_kind', 'straight', 'flush', 'full_house', 'four_of_a_kind', 'straight_flush'];

    public const RANKS = '23456789TJQKA';

    public const SUITS = 'SHDC';

    public const DEFAULT_INTERVAL_MS = 900;

    private const ATTEMPTS = 500;

    /** @param Closure(int, int): int $randomInt CSPRNG (inyectable solo en pruebas) */
    public function __construct(private readonly Closure $randomInt) {}

    /**
     * @param  array<string, mixed>  $cfg
     * @param  list<string>  $winPrizeIds  premios de la campaña que valen algo (type distinto de none)
     * @param  list<string>  $winInPool  de esos, los que están en el pool con cantidad > 0
     *
     * @throws InvalidBatch
     */
    public static function assert(array $cfg, array $winPrizeIds, array $winInPool): void
    {
        $interval = $cfg['revealIntervalMs'] ?? self::DEFAULT_INTERVAL_MS;
        if (! is_int($interval) || $interval < 400 || $interval > 3000) {
            throw new InvalidBatch('revealIntervalMs del Póker debe estar entre 400 y 3000.');
        }
        $hands = $cfg['hands'] ?? null;
        if (! is_array($hands) || $hands === [] || array_is_list($hands)) {
            throw new InvalidBatch('El Póker necesita game_config.hands: un mapa categoría de mano → premio.');
        }
        foreach ($hands as $category => $prize) {
            if (! in_array($category, self::PAYABLE, true)) {
                throw new InvalidBatch('Categoría de mano que no puede pagar: '.json_encode($category).'. Use: '.implode(', ', self::PAYABLE).'.');
            }
            if (! is_string($prize) || ! in_array($prize, $winPrizeIds, true)) {
                throw new InvalidBatch("El Póker asocia «{$category}» a un premio que no existe en la campaña o que no vale nada: ".json_encode($prize).'.');
            }
        }
        // Sin saltos: si una categoría paga, todas las mejores también (una mano mejor no puede pagar menos que... nada).
        $lowest = min(array_map(static fn (string $c): int => array_search($c, self::PAYABLE, true), array_keys($hands)));
        $missing = array_values(array_filter(array_slice(self::PAYABLE, $lowest), static fn (string $c): bool => ! isset($hands[$c])));
        if ($missing !== []) {
            throw new InvalidBatch('Si «'.self::PAYABLE[$lowest].'» paga, todas las manos mejores también: faltan '.implode(', ', $missing).'.');
        }
        $missingPrizes = array_values(array_diff($winInPool, array_values($hands)));
        if ($missingPrizes !== []) {
            throw new InvalidBatch('El Póker no tiene ninguna mano para: '.implode(', ', $missingPrizes).'. Un usuario que lo gane no vería su resultado.');
        }
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, array<string, mixed>>  $prizeDefs
     * @return array{gameResult: array<string, mixed>, gameConfig: array<string, mixed>}
     */
    public function build(array $cfg, array $prizeDefs, string $prizeId): array
    {
        $hands = $cfg['hands'];
        $wins = ($prizeDefs[$prizeId]['type'] ?? 'none') !== 'none';
        $targets = [];
        if ($wins) {
            $targets = array_keys(array_filter($hands, static fn ($p): bool => $p === $prizeId));
            if ($targets === []) {
                throw new \LogicException("El premio $prizeId no tiene mano de Póker (debió rechazarse al comprometer el lote).");
            }
        }

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $category = $wins ? $targets[($this->randomInt)(0, count($targets) - 1)] : null;
            $cards = $category !== null ? $this->construct($category) : $this->dealN(7, []);
            if ($cards === null) {
                continue;
            }
            $ev = self::evaluate($cards);
            // Verificación: con premio, la mejor mano es EXACTAMENTE la categoría elegida; sin premio, no es una que pague.
            if ($wins ? $ev['category'] !== $category : isset($hands[$ev['category']])) {
                continue;
            }
            $order = $this->shuffle($cards);
            $hole = array_slice($order, 0, 2);
            $board = array_slice($order, 2, 5);

            $legend = [];
            foreach (array_reverse(self::PAYABLE) as $c) {
                if (isset($hands[$c])) {
                    $legend[] = ['hand' => $c, 'prize' => $prizeDefs[$hands[$c]]];
                }
            }

            return [
                'gameResult' => [
                    'hole' => array_map(self::name(...), $hole),
                    'board' => array_map(self::name(...), $board),
                    // La mejor mano tras el flop (5 cartas), el turn (6) y el river (7): lo que el jugador va viendo mientras se abren.
                    'stages' => [
                        self::evaluate([...$hole, ...array_slice($board, 0, 3)])['category'],
                        self::evaluate([...$hole, ...array_slice($board, 0, 4)])['category'],
                        $ev['category'],
                    ],
                    'category' => $ev['category'],
                    'hand' => isset($hands[$ev['category']]) ? $ev['category'] : null,
                    'best' => array_map(self::name(...), $ev['best']),
                ],
                'gameConfig' => [
                    'revealIntervalMs' => (int) ($cfg['revealIntervalMs'] ?? self::DEFAULT_INTERVAL_MS),
                    'legend' => $legend,
                ],
            ];
        }
        throw new \LogicException('No se pudo construir una mano de Póker coherente con el premio (revisar game_config).');
    }

    // --- Cartas ----------------------------------------------------------------------------------------------------------

    /** Una carta es un entero 0..51: rango (0 = «2» … 12 = «A») × 4 + palo. */
    public static function name(int $card): string
    {
        return self::RANKS[intdiv($card, 4)].self::SUITS[$card % 4];
    }

    public static function parse(string $name): int
    {
        $r = strpos(self::RANKS, $name[0]);
        $s = strpos(self::SUITS, $name[1]);
        if (strlen($name) !== 2 || $r === false || $s === false) {
            throw new \InvalidArgumentException("Carta inválida: $name");
        }

        return $r * 4 + $s;
    }

    // --- Evaluador -------------------------------------------------------------------------------------------------------

    /**
     * La mejor mano de 5 entre las cartas dadas (5 a 7).
     *
     * @param  list<int>  $cards
     * @return array{category: string, rank: int, best: list<int>}
     */
    public static function evaluate(array $cards): array
    {
        $best = null;
        $bestScore = null;
        foreach (self::combinations($cards, 5) as $five) {
            $score = self::score($five);
            if ($bestScore === null || self::compare($score, $bestScore) > 0) {
                $bestScore = $score;
                $best = $five;
            }
        }

        return ['category' => self::CATEGORIES[$bestScore[0]], 'rank' => $bestScore[0], 'best' => $best];
    }

    /**
     * Puntaje de 5 cartas: [categoría, desempates…] (se comparan en orden).
     *
     * @param  list<int>  $five
     * @return list<int>
     */
    private static function score(array $five): array
    {
        $ranks = array_map(static fn (int $c): int => intdiv($c, 4), $five);
        $flush = count(array_unique(array_map(static fn (int $c): int => $c % 4, $five))) === 1;
        $counts = array_count_values($ranks);
        // Rangos ordenados por cuántas veces están y, a igualdad, de mayor a menor: así comparan los desempates.
        $byCount = array_keys($counts);
        usort($byCount, static fn (int $a, int $b): int => $counts[$b] <=> $counts[$a] ?: $b <=> $a);
        $shape = array_values($counts);
        rsort($shape);

        $sorted = array_unique($ranks);
        rsort($sorted);
        $straightHigh = null;
        if (count($sorted) === 5) {
            if ($sorted[0] - $sorted[4] === 4) {
                $straightHigh = $sorted[0];
            } elseif ($sorted === [12, 3, 2, 1, 0]) {
                $straightHigh = 3; // la rueda A-2-3-4-5: el As vale 1 y la carta alta es el 5
            }
        }

        if ($straightHigh !== null && $flush) {
            return [8, $straightHigh];
        }
        if ($shape === [4, 1]) {
            return [7, ...$byCount];
        }
        if ($shape === [3, 2]) {
            return [6, ...$byCount];
        }
        if ($flush) {
            return [5, ...$sorted];
        }
        if ($straightHigh !== null) {
            return [4, $straightHigh];
        }
        if ($shape === [3, 1, 1]) {
            return [3, ...$byCount];
        }
        if ($shape === [2, 2, 1]) {
            return [2, ...$byCount];
        }
        if ($shape === [2, 1, 1, 1]) {
            return [1, ...$byCount];
        }

        return [0, ...$sorted];
    }

    /** @param list<int> $a @param list<int> $b */
    private static function compare(array $a, array $b): int
    {
        // Lexicográfico: `<=>` entre arreglos compara primero el TAMAÑO, y aquí unos puntajes tienen menos elementos que otros.
        $n = max(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $d = ($a[$i] ?? -1) <=> ($b[$i] ?? -1);
            if ($d !== 0) {
                return $d;
            }
        }

        return 0;
    }

    /**
     * @param  list<int>  $items
     * @return \Generator<list<int>>
     */
    private static function combinations(array $items, int $k, int $start = 0, array $current = []): \Generator
    {
        if (count($current) === $k) {
            yield $current;

            return;
        }
        for ($i = $start; $i <= count($items) - ($k - count($current)); $i++) {
            yield from self::combinations($items, $k, $i + 1, [...$current, $items[$i]]);
        }
    }

    // --- Construcción ----------------------------------------------------------------------------------------------------

    /**
     * Siete cartas cuya mejor mano PUEDE ser la categoría pedida (la verificación final la confirma).
     *
     * @return list<int>|null
     */
    private function construct(string $category): ?array
    {
        $r = fn (): int => ($this->randomInt)(0, 12);
        $suit = fn (): int => ($this->randomInt)(0, 3);
        switch ($category) {
            case 'straight_flush':
                $top = ($this->randomInt)(3, 12); // 3 = rueda (5 alto) … 12 = escalera real
                $s = $suit();
                $core = [];
                for ($i = 0; $i < 5; $i++) {
                    $rank = $top - $i;
                    $core[] = ($rank < 0 ? 12 : $rank) * 4 + $s; // con la rueda el As cierra la escalera
                }
                break;
            case 'four_of_a_kind':
                $rank = $r();
                $core = [$rank * 4, $rank * 4 + 1, $rank * 4 + 2, $rank * 4 + 3];
                break;
            case 'full_house':
                $a = $r();
                do {
                    $b = $r();
                } while ($b === $a);
                $trio = array_slice($this->shuffle([0, 1, 2, 3]), 0, 3);
                $pair = array_slice($this->shuffle([0, 1, 2, 3]), 0, 2);
                $core = [...array_map(static fn (int $s): int => $a * 4 + $s, $trio), ...array_map(static fn (int $s): int => $b * 4 + $s, $pair)];
                break;
            case 'flush':
                $s = $suit();
                $core = array_map(static fn (int $rank): int => $rank * 4 + $s, array_slice($this->shuffle(range(0, 12)), 0, 5));
                break;
            case 'straight':
                $top = ($this->randomInt)(3, 12);
                $core = [];
                for ($i = 0; $i < 5; $i++) {
                    $rank = $top - $i;
                    $core[] = ($rank < 0 ? 12 : $rank) * 4 + $suit();
                }
                break;
            case 'three_of_a_kind':
                $rank = $r();
                $core = array_map(static fn (int $s): int => $rank * 4 + $s, array_slice($this->shuffle([0, 1, 2, 3]), 0, 3));
                break;
            default:
                return null;
        }
        if (count(array_unique($core)) !== count($core)) {
            return null;
        }

        return [...$core, ...$this->dealN(7 - count($core), $core)];
    }

    /**
     * @param  list<int>  $exclude
     * @return list<int>
     */
    private function dealN(int $n, array $exclude): array
    {
        $deck = array_values(array_diff(range(0, 51), $exclude));

        return array_slice($this->shuffle($deck), 0, $n);
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
