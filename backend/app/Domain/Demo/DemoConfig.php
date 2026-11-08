<?php

namespace App\Domain\Demo;

/**
 * Configuración del escenario de la muestra (SPEC-006 §3.2–3.3). El superadmin fija los valores de negocio (precios, porcentajes, mezcla de
 * premios) DENTRO de techos de seguridad que viven aquí: nada de esto es un umbral de negocio hardcodeado, son los límites duros de la
 * demostración (como los rangos de `SettingsRegistry`). `validate` normaliza y rechaza todo lo demás con `InvalidArgumentException`.
 */
final class DemoConfig
{
    public const GAMES = ['roulette', 'scratch', 'memory', 'dice', 'bomb', 'bingo', 'poker'];

    public const PRIZE_TYPES = ['money', 'coupon', 'points'];

    public const MAX_PRODUCTS = 5;

    public const MAX_AMOUNT = 10_000_000;

    public const MAX_CODES_PER_PURCHASE = 5;

    public const MAX_POOL = 500;

    public const MAX_TTL_HOURS = 168;

    /** Escenario inicial editable. Son datos de muestra, no reglas del sistema. */
    public static function defaults(): array
    {
        return [
            'user' => ['displayName' => 'Cliente de muestra', 'hostUserPrefix' => 'muestra', 'initialBalance' => 0],
            'products' => [
                ['id' => 'recarga-20', 'name' => 'Recarga $20.000', 'price' => 20_000],
                ['id' => 'recarga-50', 'name' => 'Recarga $50.000', 'price' => 50_000],
            ],
            'credit' => ['balancePercent' => 10, 'minPurchase' => 10_000, 'codeStepAmount' => 20_000, 'maxCodesPerPurchase' => 3],
            'mix' => [
                'roulette' => ['prizes' => [['type' => 'money', 'amount' => 5_000, 'count' => 2]], 'noPrizeCount' => 8],
            ],
            'ttlHours' => 24,
        ];
    }

    /**
     * @param  mixed  $raw
     * @return array<string, mixed>
     */
    public static function validate(mixed $raw): array
    {
        $fail = static fn (string $m) => throw new \InvalidArgumentException($m);
        is_array($raw) && ! array_is_list($raw) || $fail('La configuración debe ser un objeto.');
        $extra = array_diff(array_keys($raw), ['user', 'products', 'credit', 'mix', 'ttlHours']);
        $extra === [] || $fail('Claves desconocidas: '.implode(', ', array_map('strval', $extra)).'.');

        $int = static function (mixed $v, string $name, int $min, int $max) use ($fail): int {
            is_int($v) && $v >= $min && $v <= $max || $fail("$name: entero entre $min y $max.");

            return $v;
        };
        $obj = static function (mixed $v, string $name, array $keys) use ($fail): array {
            is_array($v) && ! array_is_list($v) || $fail("$name: debe ser un objeto.");
            $missing = array_diff($keys, array_keys($v));
            $extra = array_diff(array_keys($v), $keys);
            $missing === [] && $extra === [] || $fail("$name: claves esperadas ".implode(', ', $keys).'.');

            return $v;
        };

        $u = $obj($raw['user'] ?? null, 'user', ['displayName', 'hostUserPrefix', 'initialBalance']);
        is_string($u['displayName']) && mb_strlen(trim($u['displayName'])) >= 1 && mb_strlen($u['displayName']) <= 60 || $fail('user.displayName: de 1 a 60 caracteres.');
        is_string($u['hostUserPrefix']) && preg_match('/^[a-z0-9-]{1,32}$/', $u['hostUserPrefix']) === 1 || $fail('user.hostUserPrefix: minúsculas, dígitos y guiones, hasta 32.');
        $user = [
            'displayName' => trim($u['displayName']), 'hostUserPrefix' => $u['hostUserPrefix'],
            'initialBalance' => $int($u['initialBalance'], 'user.initialBalance', 0, self::MAX_AMOUNT),
        ];

        $p = $raw['products'] ?? null;
        is_array($p) && array_is_list($p) && count($p) >= 1 && count($p) <= self::MAX_PRODUCTS || $fail('products: de 1 a '.self::MAX_PRODUCTS.' productos.');
        $products = [];
        $seen = [];
        foreach ($p as $i => $item) {
            $item = $obj($item, "products[$i]", ['id', 'name', 'price']);
            is_string($item['id']) && preg_match('/^[a-z0-9_-]{1,32}$/', $item['id']) === 1 || $fail("products[$i].id: minúsculas, dígitos, guion y guion bajo, hasta 32.");
            ! isset($seen[$item['id']]) || $fail("products[$i].id: repetido.");
            $seen[$item['id']] = true;
            is_string($item['name']) && mb_strlen(trim($item['name'])) >= 1 && mb_strlen($item['name']) <= 80 || $fail("products[$i].name: de 1 a 80 caracteres.");
            $products[] = ['id' => $item['id'], 'name' => trim($item['name']), 'price' => $int($item['price'], "products[$i].price", 1, self::MAX_AMOUNT)];
        }

        $c = $obj($raw['credit'] ?? null, 'credit', ['balancePercent', 'minPurchase', 'codeStepAmount', 'maxCodesPerPurchase']);
        $credit = [
            'balancePercent' => $int($c['balancePercent'], 'credit.balancePercent', 0, 100),
            'minPurchase' => $int($c['minPurchase'], 'credit.minPurchase', 0, self::MAX_AMOUNT),
            'codeStepAmount' => $int($c['codeStepAmount'], 'credit.codeStepAmount', 1, self::MAX_AMOUNT),
            'maxCodesPerPurchase' => $int($c['maxCodesPerPurchase'], 'credit.maxCodesPerPurchase', 1, self::MAX_CODES_PER_PURCHASE),
        ];

        $m = $raw['mix'] ?? null;
        is_array($m) && ! array_is_list($m) && $m !== [] || $fail('mix: al menos un juego.');
        $mix = [];
        $total = 0;
        foreach ($m as $game => $entry) {
            in_array($game, self::GAMES, true) || $fail('mix: juego desconocido «'.(string) $game.'».');
            $entry = $obj($entry, "mix.$game", ['prizes', 'noPrizeCount']);
            is_array($entry['prizes']) && array_is_list($entry['prizes']) || $fail("mix.$game.prizes: debe ser una lista.");
            $prizes = [];
            foreach ($entry['prizes'] as $j => $prize) {
                $prize = $obj($prize, "mix.$game.prizes[$j]", ['type', 'amount', 'count']);
                in_array($prize['type'], self::PRIZE_TYPES, true) || $fail("mix.$game.prizes[$j].type: ".implode(', ', self::PRIZE_TYPES).'.');
                $amount = $int($prize['amount'], "mix.$game.prizes[$j].amount", $prize['type'] === 'coupon' ? 0 : 1, self::MAX_AMOUNT);
                $count = $int($prize['count'], "mix.$game.prizes[$j].count", 1, self::MAX_POOL);
                $prizes[] = ['type' => $prize['type'], 'amount' => $amount, 'count' => $count];
                $total += $count;
            }
            $none = $int($entry['noPrizeCount'], "mix.$game.noPrizeCount", 0, self::MAX_POOL);
            $total += $none;
            $prizes !== [] || $none > 0 || $fail("mix.$game: sin premios ni códigos sin premio.");
            $mix[$game] = ['prizes' => $prizes, 'noPrizeCount' => $none];
            DemoGameTemplates::assertPlayable($game, $mix[$game]);
        }
        $total <= self::MAX_POOL || $fail('mix: el pool total no puede superar '.self::MAX_POOL.' códigos (hay '.$total.').');

        $ttl = $raw['ttlHours'] ?? null;
        $ttl === null || $ttl = $int($ttl, 'ttlHours', 1, self::MAX_TTL_HOURS);

        return ['user' => $user, 'products' => $products, 'credit' => $credit, 'mix' => $mix, 'ttlHours' => $ttl];
    }

    /** @param array<string, mixed> $config */
    public static function poolSize(array $config): int
    {
        $n = 0;
        foreach ($config['mix'] as $entry) {
            $n += $entry['noPrizeCount'];
            foreach ($entry['prizes'] as $prize) {
                $n += $prize['count'];
            }
        }

        return $n;
    }
}
