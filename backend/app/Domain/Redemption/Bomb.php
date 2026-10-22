<?php

namespace App\Domain\Redemption;

use App\Domain\Batches\InvalidBatch;
use Closure;

/**
 * Bomba 8 (SPEC-005 §3). El premio ya está decidido; aquí solo se arma lo que se VE: la lista de caras EN ORDEN DE VOLTEO (la n-ésima carta
 * que el jugador toque muestra `faces[n]`, sea cual sea su posición). Con premio hay exactamente tres «8» y el tercero cae entre las últimas
 * `lateWindow` cartas; sin premio hay 0, 1 o 2 «8». Los símbolos de relleno no se repiten más de dos veces (nada parece una combinación
 * ganadora) y nunca igualan a un premio.
 */
final class Bomb
{
    public const DEFAULT_CARDS = 12;

    public const DEFAULT_COLUMNS = 4;

    public const DEFAULT_LATE_WINDOW = 4;

    public const EIGHT = '8';

    /** Cuántos «8» hacen falta para desactivar la bomba. */
    public const NEEDED = 3;

    /** @param Closure(int, int): int $randomInt CSPRNG (inyectable solo en pruebas) */
    public function __construct(private readonly Closure $randomInt) {}

    /**
     * @param  array<string, mixed>  $cfg
     * @param  list<string>  $prizeIds
     *
     * @throws InvalidBatch
     */
    public static function assert(array $cfg, array $prizeIds): void
    {
        $cards = $cfg['cards'] ?? self::DEFAULT_CARDS;
        $columns = $cfg['columns'] ?? self::DEFAULT_COLUMNS;
        $late = $cfg['lateWindow'] ?? self::DEFAULT_LATE_WINDOW;
        if (! is_int($cards) || $cards < 9 || $cards > 16) {
            throw new InvalidBatch('La Bomba admite de 9 a 16 cartas (game_config.cards).');
        }
        if (! is_int($columns) || $columns < 2 || $columns > 5) {
            throw new InvalidBatch('La Bomba admite de 2 a 5 columnas (game_config.columns).');
        }
        if (! is_int($late) || $late < 1 || $late > $cards - 3) {
            throw new InvalidBatch('lateWindow de la Bomba debe ser un entero de 1 a '.($cards - 3).' (game_config.lateWindow).');
        }
        $symbols = $cfg['symbols'] ?? Presentation::DEFAULT_SYMBOLS;
        if (! is_array($symbols) || ! array_is_list($symbols) || count(array_unique($symbols, SORT_REGULAR)) !== count($symbols)) {
            throw new InvalidBatch('Los símbolos de la Bomba deben ser una lista sin repetidos (game_config.symbols).');
        }
        foreach ($symbols as $s) {
            if (! is_string($s) || $s === '') {
                throw new InvalidBatch('Cada símbolo de la Bomba debe ser un texto no vacío.');
            }
        }
        if (in_array(self::EIGHT, $symbols, true)) {
            throw new InvalidBatch('Un símbolo de relleno de la Bomba no puede ser «8»: se confundiría con la combinación ganadora.');
        }
        $clash = array_values(array_intersect($symbols, $prizeIds));
        if ($clash !== []) {
            throw new InvalidBatch('Un símbolo de relleno de la Bomba coincide con un premio ('.implode(', ', $clash).') y se vería como ganador.');
        }
        // Peor caso: sin ningún «8» todas las cartas son relleno y cada símbolo entra a lo sumo dos veces.
        if (count($symbols) * 2 < $cards) {
            throw new InvalidBatch('Hacen falta al menos '.(int) ceil($cards / 2)." símbolos de relleno para {$cards} cartas en la Bomba.");
        }
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, array<string, mixed>>  $prizeDefs
     * @return array{gameResult: array<string, mixed>, gameConfig: array<string, mixed>}
     */
    public function build(array $cfg, array $prizeDefs, string $prizeId): array
    {
        $cards = (int) ($cfg['cards'] ?? self::DEFAULT_CARDS);
        $late = (int) ($cfg['lateWindow'] ?? self::DEFAULT_LATE_WINDOW);
        $symbols = $cfg['symbols'] ?? Presentation::DEFAULT_SYMBOLS;
        $wins = ($prizeDefs[$prizeId]['type'] ?? 'none') !== 'none';

        $eights = $wins ? self::NEEDED : ($this->randomInt)(0, 2);
        $positions = $this->eightPositions($cards, $late, $eights, $wins);

        $bag = [];
        foreach ($symbols as $s) {
            $bag[] = $s;
            $bag[] = $s;
        }
        $filler = $cards - $eights;
        if (count($bag) < $filler) {
            throw new \LogicException('Faltan símbolos de relleno (debió rechazarse al comprometer el lote).');
        }
        $filler = array_slice($this->shuffle($bag), 0, $filler);

        $faces = [];
        $next = 0;
        for ($i = 0; $i < $cards; $i++) {
            $faces[] = in_array($i, $positions, true) ? self::EIGHT : $filler[$next++];
        }

        return [
            'gameResult' => ['faces' => $faces],
            'gameConfig' => ['cards' => $cards, 'columns' => (int) ($cfg['columns'] ?? self::DEFAULT_COLUMNS)],
        ];
    }

    /**
     * Posiciones (en orden de volteo) de los «8». Con premio el tercero va entre las últimas `$late` y los otros dos antes que él.
     *
     * @return list<int>
     */
    private function eightPositions(int $cards, int $late, int $eights, bool $wins): array
    {
        if ($wins) {
            $third = ($this->randomInt)($cards - $late, $cards - 1);
            $before = range(0, $third - 1);

            return array_merge(array_slice($this->shuffle($before), 0, self::NEEDED - 1), [$third]);
        }

        return array_slice($this->shuffle(range(0, $cards - 1)), 0, $eights);
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
