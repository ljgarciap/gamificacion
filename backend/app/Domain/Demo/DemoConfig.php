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

    public const MAX_CODES_PER_SESSION = 50;

    public const MAX_POOL = 500;

    /** Planes de premio (SPEC-007): techos duros; los valores de negocio los fija el superadmin dentro de ellos. */
    public const MODES = ['steps', 'plans'];

    public const WELCOME_PLAN = 'welcome';

    public const MAX_AMOUNT_PLANS = 5;

    public const MAX_REACTIVATION_DAYS = 60;

    public const MAX_PLAN_NAME = 40;

    public const MAX_TTL_HOURS = 168;

    /** Techos duros de las cuentas (SPEC-006A): los valores los fija el superadmin dentro de estos rangos. */
    public const MAX_RETENTION_DAYS = 365;

    public const MAX_ACCOUNTS = 5000;

    public const MIN_CODE_TTL_MINUTES = 5;

    public const MAX_CODE_TTL_MINUTES = 30;

    /** Escenario inicial editable. Son datos de muestra, no reglas del sistema. */
    public static function defaults(): array
    {
        return [
            'user' => ['displayName' => 'Cliente de muestra', 'hostUserPrefix' => 'muestra', 'initialBalance' => 0],
            'products' => [
                ['id' => 'recarga-20', 'name' => 'Recarga $20.000', 'price' => 20_000],
                ['id' => 'recarga-50', 'name' => 'Recarga $50.000', 'price' => 50_000],
            ],
            'credit' => ['balancePercent' => 10, 'minPurchase' => 10_000, 'codeStepAmount' => 20_000, 'maxCodesPerPurchase' => 3, 'maxCodesPerSession' => 10],
            'mix' => [
                'roulette' => ['prizes' => [['type' => 'money', 'amount' => 5_000, 'count' => 2]], 'noPrizeCount' => 8],
            ],
            'ttlHours' => 24,
            'accounts' => self::defaultAccounts(),
        ];
    }

    /** @return array{retentionDays: int, codeTtlMinutes: int, maxAccounts: int} */
    public static function defaultAccounts(): array
    {
        return ['retentionDays' => 30, 'codeTtlMinutes' => 10, 'maxAccounts' => 500];
    }

    /** Una configuración guardada ANTES de SPEC-006A no trae `accounts`: se completa con los valores por defecto. @param array<string, mixed> $config */
    public static function withDefaults(array $config): array
    {
        $config['accounts'] ??= self::defaultAccounts();

        return $config;
    }

    /**
     * @param  mixed  $raw
     * @return array<string, mixed>
     */
    public static function validate(mixed $raw): array
    {
        $fail = static fn (string $m) => throw new \InvalidArgumentException($m);
        is_array($raw) && ! array_is_list($raw) || $fail('La configuración debe ser un objeto.');
        $extra = array_diff(array_keys($raw), ['user', 'products', 'credit', 'mix', 'ttlHours', 'accounts', 'mode', 'plans', 'triggers']);
        $extra === [] || $fail('Claves desconocidas: '.implode(', ', array_map('strval', $extra)).'.');
        // Sin `mode` es el modo anterior («steps»): las muestras configuradas antes de SPEC-007 no cambian (CA-79).
        $mode = $raw['mode'] ?? 'steps';
        in_array($mode, self::MODES, true) || $fail('mode: '.implode(' o ', self::MODES).'.');
        if ($mode === 'steps') {
            foreach (['plans', 'triggers'] as $k) {
                ! array_key_exists($k, $raw) || $fail("$k: solo existe en el modo «plans».");
            }
        }

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
            // `participates` es opcional y solo del modo «plans» (SPEC-007 §3.3); omitido vale «participa».
            $hasFlag = is_array($item) && array_key_exists('participates', $item);
            $flag = $hasFlag ? $item['participates'] : true;
            if ($hasFlag) {
                $mode === 'plans' || $fail("products[$i].participates: solo existe en el modo «plans».");
                is_bool($flag) || $fail("products[$i].participates: verdadero o falso.");
                unset($item['participates']);
            }
            $item = $obj($item, "products[$i]", ['id', 'name', 'price']);
            is_string($item['id']) && preg_match('/^[a-z0-9_-]{1,32}$/', $item['id']) === 1 || $fail("products[$i].id: minúsculas, dígitos, guion y guion bajo, hasta 32.");
            ! isset($seen[$item['id']]) || $fail("products[$i].id: repetido.");
            $seen[$item['id']] = true;
            is_string($item['name']) && mb_strlen(trim($item['name'])) >= 1 && mb_strlen($item['name']) <= 80 || $fail("products[$i].name: de 1 a 80 caracteres.");
            $products[] = ['id' => $item['id'], 'name' => trim($item['name']), 'price' => $int($item['price'], "products[$i].price", 1, self::MAX_AMOUNT)]
                + ($mode === 'plans' ? ['participates' => $flag] : []);
        }

        $c = $obj($raw['credit'] ?? null, 'credit', ['balancePercent', 'minPurchase', 'codeStepAmount', 'maxCodesPerPurchase', 'maxCodesPerSession']);
        $credit = [
            'balancePercent' => $int($c['balancePercent'], 'credit.balancePercent', 0, 100),
            'minPurchase' => $int($c['minPurchase'], 'credit.minPurchase', 0, self::MAX_AMOUNT),
            'codeStepAmount' => $int($c['codeStepAmount'], 'credit.codeStepAmount', 1, self::MAX_AMOUNT),
            'maxCodesPerPurchase' => $int($c['maxCodesPerPurchase'], 'credit.maxCodesPerPurchase', 1, self::MAX_CODES_PER_PURCHASE),
            'maxCodesPerSession' => $int($c['maxCodesPerSession'], 'credit.maxCodesPerSession', 1, self::MAX_CODES_PER_SESSION),
        ];

        $plansMode = $mode === 'plans';
        $mix = [];
        $plans = [];
        $total = 0;
        if ($plansMode) {
            [$plans, $total] = self::validatePlans($raw['plans'] ?? null, $int, $obj, $fail);
        } else {
            [$mix, $total] = self::validateMix($raw['mix'] ?? null, 'mix', $int, $obj, $fail);
        }
        $total <= self::MAX_POOL || $fail(($plansMode ? 'plans' : 'mix').': el pool total no puede superar '.self::MAX_POOL.' códigos (hay '.$total.').');

        $triggers = null;
        if ($plansMode) {
            $t = $obj($raw['triggers'] ?? null, 'triggers', ['reactivationDays', 'turnsPerPurchase', 'welcomeOnFirstLogin']);
            is_bool($t['welcomeOnFirstLogin']) || $fail('triggers.welcomeOnFirstLogin: verdadero o falso.');
            $triggers = [
                'reactivationDays' => $int($t['reactivationDays'], 'triggers.reactivationDays', 1, self::MAX_REACTIVATION_DAYS),
                'turnsPerPurchase' => $int($t['turnsPerPurchase'], 'triggers.turnsPerPurchase', 1, self::MAX_CODES_PER_PURCHASE),
                'welcomeOnFirstLogin' => $t['welcomeOnFirstLogin'],
            ];
        }

        $ttl = $raw['ttlHours'] ?? null;
        $ttl === null || $ttl = $int($ttl, 'ttlHours', 1, self::MAX_TTL_HOURS);

        // Cuentas (SPEC-006A): opcional al guardar (una configuración anterior no la trae) y con los mismos techos duros.
        $a = array_key_exists('accounts', $raw) ? $obj($raw['accounts'], 'accounts', ['retentionDays', 'codeTtlMinutes', 'maxAccounts']) : self::defaultAccounts();
        $accounts = [
            'retentionDays' => $int($a['retentionDays'], 'accounts.retentionDays', 1, self::MAX_RETENTION_DAYS),
            'codeTtlMinutes' => $int($a['codeTtlMinutes'], 'accounts.codeTtlMinutes', self::MIN_CODE_TTL_MINUTES, self::MAX_CODE_TTL_MINUTES),
            'maxAccounts' => $int($a['maxAccounts'], 'accounts.maxAccounts', 1, self::MAX_ACCOUNTS),
        ];

        $out = ['user' => $user, 'products' => $products, 'credit' => $credit, 'mix' => $mix, 'ttlHours' => $ttl, 'accounts' => $accounts];
        if ($plansMode) {
            $out = ['mode' => 'plans'] + $out + ['plans' => $plans, 'triggers' => $triggers];
        }

        return $out;
    }

    public static function mode(array $config): string
    {
        return ($config['mode'] ?? 'steps') === 'plans' ? 'plans' : 'steps';
    }

    /**
     * Mezcla de premios por juego (la misma forma en el modo «steps» y dentro de cada plan). Devuelve la mezcla normalizada y el tamaño del pool.
     *
     * @return array{0: array<string, mixed>, 1: int}
     */
    private static function validateMix(mixed $m, string $path, \Closure $int, \Closure $obj, \Closure $fail, ?int $maxMoneyPrize = null): array
    {
        is_array($m) && ! array_is_list($m) && $m !== [] || $fail("$path: al menos un juego.");
        $mix = [];
        $total = 0;
        foreach ($m as $game => $entry) {
            in_array($game, self::GAMES, true) || $fail("$path: juego desconocido «".(string) $game.'».');
            $entry = $obj($entry, "$path.$game", ['prizes', 'noPrizeCount']);
            is_array($entry['prizes']) && array_is_list($entry['prizes']) || $fail("$path.$game.prizes: debe ser una lista.");
            $prizes = [];
            foreach ($entry['prizes'] as $j => $prize) {
                $prize = $obj($prize, "$path.$game.prizes[$j]", ['type', 'amount', 'count']);
                in_array($prize['type'], self::PRIZE_TYPES, true) || $fail("$path.$game.prizes[$j].type: ".implode(', ', self::PRIZE_TYPES).'.');
                $amount = $int($prize['amount'], "$path.$game.prizes[$j].amount", $prize['type'] === 'coupon' ? 0 : 1, self::MAX_AMOUNT);
                $maxMoneyPrize === null || $prize['type'] !== 'money' || $amount <= $maxMoneyPrize
                    || $fail("$path.$game.prizes[$j].amount: supera el premio máximo en dinero del plan ($maxMoneyPrize).");
                $count = $int($prize['count'], "$path.$game.prizes[$j].count", 1, self::MAX_POOL);
                $prizes[] = ['type' => $prize['type'], 'amount' => $amount, 'count' => $count];
                $total += $count;
            }
            $none = $int($entry['noPrizeCount'], "$path.$game.noPrizeCount", 0, self::MAX_POOL);
            $total += $none;
            $prizes !== [] || $none > 0 || $fail("$path.$game: sin premios ni códigos sin premio.");
            $mix[$game] = ['prizes' => $prizes, 'noPrizeCount' => $none];
            DemoGameTemplates::assertPlayable($game, $mix[$game]);
        }

        return [$mix, $total];
    }

    /**
     * Planes de la muestra (SPEC-007 §3.1): uno de evento («welcome») y de 1 a MAX_AMOUNT_PLANS por monto, con rangos contiguos y sin huecos.
     *
     * @return array{0: list<array<string, mixed>>, 1: int} los planes normalizados y el pool total
     */
    private static function validatePlans(mixed $raw, \Closure $int, \Closure $obj, \Closure $fail): array
    {
        is_array($raw) && array_is_list($raw) && count($raw) >= 2 && count($raw) <= self::MAX_AMOUNT_PLANS + 1
            || $fail('plans: el plan de bienvenida y de 1 a '.self::MAX_AMOUNT_PLANS.' planes por monto.');
        $plans = [];
        $total = 0;
        $ids = [];
        $events = 0;
        $expectedMin = null;
        $closed = false;
        foreach ($raw as $i => $p) {
            is_array($p) && ($p['kind'] ?? null) !== null || $fail("plans[$i].kind: «event» o «amount».");
            $kind = $p['kind'];
            in_array($kind, ['event', 'amount'], true) || $fail("plans[$i].kind: «event» o «amount».");
            $p = $obj($p, "plans[$i]", $kind === 'event' ? ['id', 'kind', 'name', 'maxMoneyPrize', 'mix'] : ['id', 'kind', 'name', 'minAmount', 'maxAmount', 'maxMoneyPrize', 'mix']);
            is_string($p['id']) && preg_match('/^[a-z0-9_-]{1,32}$/D', $p['id']) === 1 || $fail("plans[$i].id: minúsculas, dígitos, guion y guion bajo, hasta 32.");
            ! isset($ids[$p['id']]) || $fail("plans[$i].id: repetido.");
            $ids[$p['id']] = true;
            is_string($p['name']) && mb_strlen(trim($p['name'])) >= 1 && mb_strlen($p['name']) <= self::MAX_PLAN_NAME || $fail("plans[$i].name: de 1 a ".self::MAX_PLAN_NAME.' caracteres.');
            $max = $int($p['maxMoneyPrize'], "plans[$i].maxMoneyPrize", 1, self::MAX_AMOUNT);
            [$mix, $n] = self::validateMix($p['mix'], "plans[$i].mix", $int, $obj, $fail, $max);
            $total += $n;
            $plan = ['id' => $p['id'], 'kind' => $kind, 'name' => trim($p['name'])];
            if ($kind === 'event') {
                $p['id'] === self::WELCOME_PLAN || $fail("plans[$i].id: el plan de evento se llama «".self::WELCOME_PLAN.'».');
                $events++;
                $events === 1 || $fail('plans: solo un plan de bienvenida.');
            } else {
                $p['id'] !== self::WELCOME_PLAN || $fail("plans[$i].id: «".self::WELCOME_PLAN.'» está reservado.');
                $closed && $fail("plans[$i]: un plan por monto sin tope debe ser el último.");
                $min = $int($p['minAmount'], "plans[$i].minAmount", 1, self::MAX_AMOUNT);
                $expectedMin === null || $min === $expectedMin || $fail("plans[$i].minAmount: debe ser $expectedMin (el tope del plan anterior + 1), sin huecos ni solapes.");
                if ($p['maxAmount'] === null) {
                    $closed = true;
                    $maxAmount = null;
                } else {
                    $maxAmount = $int($p['maxAmount'], "plans[$i].maxAmount", $min, self::MAX_AMOUNT);
                    $expectedMin = $maxAmount + 1;
                }
                $plan += ['minAmount' => $min, 'maxAmount' => $maxAmount];
            }
            $plans[] = $plan + ['maxMoneyPrize' => $max, 'mix' => $mix];
        }
        $events === 1 || $fail('plans: falta el plan de bienvenida («'.self::WELCOME_PLAN.'»).');
        $closed || $fail('plans: el último plan por monto no debe tener tope (maxAmount nulo), así todo monto grande cae en algún plan.');

        return [$plans, $total];
    }

    /** @param array<string, mixed> $config */
    public static function poolSize(array $config): int
    {
        $n = 0;
        $mixes = self::mode($config) === 'plans' ? array_column($config['plans'], 'mix') : [$config['mix']];
        foreach ($mixes as $mix) {
            foreach ($mix as $entry) {
                $n += $entry['noPrizeCount'];
                foreach ($entry['prizes'] as $prize) {
                    $n += $prize['count'];
                }
            }
        }

        return $n;
    }
}
