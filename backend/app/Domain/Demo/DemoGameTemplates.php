<?php

namespace App\Domain\Demo;

use App\Domain\Redemption\Poker;

/**
 * Traduce la mezcla de premios que el superadmin configura para un juego (`mix.<juego>`) a la campaña que el motor entiende: premios con id,
 * `game_config` (la presentación: segmentos, combinaciones de dados, manos de póker…) y el pool del lote. Es PURO (sin base ni red).
 *
 * Los premios se ordenan de menor a mayor (monto, luego tipo): en Bingo y Póker «más alto» significa mejor patrón/mano. Las plantillas de
 * presentación (tamaño de la cuadrícula, número de cartas…) son datos de la demostración, no umbrales de negocio: los límites duros que
 * cada juego impone a la mezcla están en `assertPlayable`, que `DemoConfig::validate` llama al guardar para que el error salga en el panel
 * y no al sembrar.
 */
final class DemoGameTemplates
{
    public const NAMES = [
        'roulette' => 'Gira y Gana', 'scratch' => 'Raspa y Gana', 'memory' => 'Concéntrese', 'dice' => 'Dados de la suerte',
        'bomb' => 'Bomba 8', 'bingo' => 'Bingo en vivo', 'poker' => 'Póker del crupier',
    ];

    /** Nombre corto con el que empieza la descripción de cada código («Ruleta: $ 10.000»). */
    public const SHORT = ['roulette' => 'Ruleta', 'scratch' => 'Raspa', 'memory' => 'Concéntrese', 'dice' => 'Dados', 'bomb' => 'Bomba 8', 'bingo' => 'Bingo en vivo', 'poker' => 'Póker del crupier'];

    public const CODE_PREFIX = ['roulette' => 'RULETA', 'scratch' => 'RASPA', 'memory' => 'CONCENTRESE', 'dice' => 'DADOS', 'bomb' => 'BOMBA', 'bingo' => 'BINGO', 'poker' => 'POKER'];

    private const LETTER = ['roulette' => 'r', 'scratch' => 's', 'memory' => 'm', 'dice' => 'd', 'bomb' => 'b', 'bingo' => 'g', 'poker' => 'k'];

    private const TYPE_RANK = ['money' => 0, 'points' => 1, 'coupon' => 2];

    /** Combinaciones de dados: los dobles son los premios; el resto de combinaciones (y los dobles sin usar) es «sin premio». */
    private const DICE_DOUBLES = [[2, 2], [3, 3], [4, 4], [5, 5], [1, 1], [6, 6]];

    /**
     * Límites que impone el juego a la mezcla. Lanza `InvalidArgumentException` con un mensaje para el panel.
     *
     * @param  array{prizes: list<array{type: string, amount: int, count: int}>, noPrizeCount: int}  $entry
     */
    public static function assertPlayable(string $game, array $entry): void
    {
        $seen = [];
        foreach ($entry['prizes'] as $p) {
            $key = $p['type'].':'.$p['amount'];
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException("mix.$game: el premio ".self::label($p).' está repetido; sume sus cantidades en una sola fila.');
            }
            $seen[$key] = true;
        }
        $winners = count($entry['prizes']);
        $withNone = $entry['noPrizeCount'] > 0 ? 1 : 0;
        $limit = static fn (string $text) => throw new \InvalidArgumentException("mix.$game: $text");
        match ($game) {
            'roulette' => $winners + $withNone <= 12 || $limit('la ruleta admite hasta 12 premios distintos (incluido «sin premio»).'),
            'dice' => $winners <= count(self::DICE_DOUBLES) || $limit('los dados admiten hasta '.count(self::DICE_DOUBLES).' premios distintos (uno por doble).'),
            'bingo' => ($winners >= 1 && $winners <= 3) || $limit('el bingo necesita de 1 a 3 premios distintos (cartón lleno, cuatro esquinas, línea).'),
            'poker' => ($winners >= 1 && $winners <= count(Poker::PAYABLE)) || $limit('el póker necesita de 1 a '.count(Poker::PAYABLE).' premios distintos (uno por categoría de mano o agrupados).'),
            default => true,
        };
    }

    /**
     * @param  array{prizes: list<array{type: string, amount: int, count: int}>, noPrizeCount: int}  $entry
     * @return array{name: string, behavior: string, config: array<string, mixed>, prizes: list<array{0: string, 1: string, 2: string, 3: int|null, 4: array<string, mixed>}>, pool: list<array{prize_id: string, quantity: int}>, prefix: string}
     */
    public static function plan(string $game, array $entry): array
    {
        self::assertPlayable($game, $entry);
        $l = self::LETTER[$game];

        $winners = $entry['prizes'];
        usort($winners, static fn (array $a, array $b): int => [$a['amount'], self::TYPE_RANK[$a['type']]] <=> [$b['amount'], self::TYPE_RANK[$b['type']]]);

        $prizes = [];
        $pool = [];
        $winIds = [];
        $none = null;
        if ($entry['noPrizeCount'] > 0) {
            $none = $l.'none';
            $prizes[] = [$none, 'none', 'Sin premio', null, []];
            $pool[] = ['prize_id' => $none, 'quantity' => $entry['noPrizeCount']];
        }
        foreach ($winners as $i => $w) {
            $id = $l.'p'.($i + 1);
            $winIds[] = $id;
            $prizes[] = [$id, $w['type'], self::label($w), $w['type'] === 'coupon' ? null : $w['amount'], $w['type'] === 'coupon' ? ['coupon_code' => 'MUESTRA'.($i + 1)] : []];
            $pool[] = ['prize_id' => $id, 'quantity' => $w['count']];
        }

        return [
            'name' => self::NAMES[$game],
            'behavior' => $game === 'roulette' ? 'rejectOnly' : 'showPrevious',
            'config' => self::config($game, $winIds, $none),
            'prizes' => $prizes,
            'pool' => $pool,
            'prefix' => self::CODE_PREFIX[$game],
        ];
    }

    /**
     * @param  list<string>  $winIds  premios con valor, de menor a mayor
     * @return array<string, mixed>
     */
    private static function config(string $game, array $winIds, ?string $none): array
    {
        switch ($game) {
            case 'roulette':
                // Cada premio distinto una vez (y «sin premio» si lo hay); si son pocos se repite el ciclo hasta 8 segmentos (el motor admite 4–12).
                $base = $winIds;
                if ($none !== null) {
                    $base[] = $none;
                }
                $segments = $base;
                while (count($segments) < 8) {
                    $segments = array_merge($segments, $base);
                }

                return ['segments' => array_slice($segments, 0, max(8, count($base)))];
            case 'scratch':
                return ['rows' => 3, 'cols' => 3];
            case 'memory':
                return ['pairs' => 6, 'columns' => 4, 'rival' => true];
            case 'dice':
                $rolls = [];
                $used = [];
                foreach ($winIds as $i => $w) {
                    $rolls[$w] = [self::DICE_DOUBLES[$i]];
                    $used[implode('-', self::DICE_DOUBLES[$i])] = true;
                }
                if ($none !== null) {
                    $rest = [];
                    for ($a = 1; $a <= 6; $a++) {
                        for ($b = $a; $b <= 6; $b++) {
                            if (! isset($used["$a-$b"])) {
                                $rest[] = [$a, $b];
                            }
                        }
                    }
                    $rolls[$none] = $rest;
                }

                return ['rolls' => $rolls];
            case 'bomb':
                return ['cards' => 12, 'columns' => 4];
            case 'bingo':
                // De mayor a menor premio. El cartón lleno, si existe, va primero (el motor lo exige).
                $order = array_reverse($winIds);
                $patterns = match (count($order)) {
                    1 => ['line'], 2 => ['corners', 'line'], default => ['full', 'corners', 'line'],
                };

                return ['patterns' => array_map(static fn (string $pattern, string $prize): array => ['pattern' => $pattern, 'prize' => $prize], $patterns, $order)];
            case 'poker':
                // Categorías de la más baja a la más alta repartidas en bloques entre los premios (de menor a mayor): sin saltos.
                $k = count($winIds);
                $hands = [];
                foreach (Poker::PAYABLE as $i => $category) {
                    $hands[$category] = $winIds[intdiv($i * $k, count(Poker::PAYABLE))];
                }

                return ['hands' => $hands];
        }
        throw new \InvalidArgumentException("Juego desconocido: $game.");
    }

    /** @param array{type: string, amount: int} $p */
    private static function label(array $p): string
    {
        return match ($p['type']) {
            'money' => '$ '.number_format($p['amount'], 0, ',', '.'),
            'points' => number_format($p['amount'], 0, ',', '.').' puntos',
            default => $p['amount'] > 0 ? 'Cupón '.$p['amount'].'%' : 'Cupón',
        };
    }
}
