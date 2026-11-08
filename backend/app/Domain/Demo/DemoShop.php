<?php

namespace App\Domain\Demo;

use App\Domain\Balance\StubBalanceProvider;
use App\Domain\Dev\DevTokenIssuer;
use App\Domain\Settings\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * La tienda de la muestra (SPEC-006 §3.2–3.3): abre la sesión del usuario simulado y procesa la compra en línea. TODO lo que importa se calcula
 * aquí, en el servidor (T-D3): el cliente solo manda ids de producto y cantidades; precios, porcentaje de saldo y número de códigos salen de la
 * configuración que fijó el superadmin. Las compras de un mismo usuario se serializan con un bloqueo consultivo (tope por sesión sin carreras)
 * y son idempotentes por (usuario, `Idempotency-Key`).
 */
final class DemoShop
{
    public const SESSION_TOKEN_SECONDS = 600;

    private const MAX_QUANTITY = 10;

    public function __construct(
        private readonly DemoSwitch $switch = new DemoSwitch,
        private readonly DemoPoolDraw $draw = new DemoPoolDraw,
        private readonly StubBalanceProvider $balances = new StubBalanceProvider,
        private readonly DemoAccounts $accounts = new DemoAccounts,
    ) {}

    /** @return array<string, mixed> configuración vigente (la muestra debe estar activa y configurada) */
    private function config(): array
    {
        $config = $this->switch->row()['config'];
        if ($config === null) {
            throw new DemoShopException('notFound', 'La muestra no está configurada.', 404);
        }

        return $config;
    }

    /**
     * Renueva el token de una sesión EXISTENTE con el `subject` que recibió quien verificó su código (ya no hay sesiones anónimas, SPEC-006A).
     * El sujeto solo vale si su cuenta sigue existiendo: borrada la cuenta, nadie vuelve a llegar a ese saldo.
     *
     * @return array<string, mixed>
     */
    public function resume(string $resume): array
    {
        $config = $this->config();
        if (preg_match(DemoAccess::SUBJECT_PATTERN, $resume) !== 1 || ! $this->accounts->existsForSubject($resume)) {
            throw new DemoShopException('demoSessionInvalid', 'Tu sesión de la muestra venció. Vuelve a ingresar con tu correo.', 401);
        }

        return $this->session($resume, $config, true, false);
    }

    /**
     * Ingreso con un código YA verificado: entra a la cuenta de ese correo o la crea (con el saldo inicial del escenario).
     *
     * @return array<string, mixed>
     */
    public function login(string $normalizedEmail, ?string $ip): array
    {
        $config = $this->config();
        $account = $this->accounts->findByEmail($normalizedEmail);
        $isNew = $account === null;
        if ($isNew) {
            try {
                $account = $this->accounts->create($normalizedEmail, $config, $ip);
            } catch (UniqueConstraintViolationException) {
                // Dos verificaciones del mismo correo a la vez: gana una; la otra entra a la cuenta recién creada.
                $account = $this->accounts->findByEmail($normalizedEmail) ?? throw new \LogicException('Cuenta duplicada sin su original.');
                $isNew = false;
            }
        }
        $this->accounts->touch($account['id']);

        return $this->session($account['subject'], $config, ! $isNew, $isNew);
    }

    /**
     * Saldo y códigos de la cuenta (para volver a ver lo acumulado). Los códigos se descifran solo para su dueño.
     *
     * @return array{balance: int, codes: list<array{code: string, game: string, played: bool}>}
     */
    public function account(string $userId): array
    {
        $box = new \App\Domain\Admin\SecretBox;
        $codes = [];
        $rows = DB::select(
            "SELECT p.game, encode(p.code_enc, 'hex') AS enc, encode(p.code_hash, 'hex') AS hash, (c.redeemed_at IS NOT NULL) AS played
             FROM demo_pool p LEFT JOIN codes c ON c.code_hash = p.code_hash
             WHERE p.assigned_to = ? AND p.state = 'assigned' ORDER BY p.assigned_at, p.id",
            [$userId],
        );
        foreach ($rows as $r) {
            $codes[] = ['code' => $box->open(hex2bin($r->enc), DemoSeeder::sealContext($r->hash)), 'game' => $r->game, 'played' => (bool) $r->played];
        }

        return ['balance' => $this->balances->balanceFor($userId), 'codes' => $codes];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function session(string $subject, array $config, bool $resumed, bool $isNew): array
    {
        // Nunca más largo que el máximo que el verificador acepta (parámetro del panel): si no, la sesión nacería con un token rechazado.
        $issued = DevTokenIssuer::issue($subject, min(self::SESSION_TOKEN_SECONDS, Settings::int('token.max_lifetime_seconds')));

        return [
            'token' => $issued['token'], 'expiresIn' => $issued['expiresIn'], 'userId' => $issued['userId'], 'subject' => $subject, 'resumed' => $resumed,
            'isNew' => $isNew,
            'displayName' => $config['user']['displayName'], 'currency' => 'COP',
            'balance' => $this->balances->balanceFor($issued['userId']),
            'products' => array_map(static fn (array $p): array => ['id' => $p['id'], 'name' => $p['name'], 'price' => $p['price']], $config['products']),
            'rules' => $config['credit'],
        ];
    }

    /**
     * @param  mixed  $items  lista de {productId, quantity}
     * @return array<string, mixed>
     */
    public function purchase(string $userId, string $key, mixed $items): array
    {
        $config = $this->config();
        $lines = $this->normalize($items, $config);
        $requestHash = hash('sha256', json_encode($lines));

        try {
            return DB::transaction(fn (): array => $this->purchaseLocked($userId, $key, $lines, $requestHash, $config));
        } catch (UniqueConstraintViolationException) {
            // Otra petición con la misma clave ganó la carrera: se repite SU resultado.
            $existing = DB::selectOne('SELECT id, request_hash FROM demo_orders WHERE user_id = ? AND idempotency_key = ?', [$userId, $key]);
            if ($existing === null) {
                throw new \LogicException('Violación de unicidad sin la orden que la causó.');
            }

            return $this->replay($userId, $existing, $requestHash);
        }
    }

    /**
     * @param  list<array{productId: string, quantity: int}>  $lines
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function purchaseLocked(string $userId, string $key, array $lines, string $requestHash, array $config): array
    {
        // Las compras de UN usuario van en fila: el tope por sesión se calcula sobre lo ya comprado sin carreras entre dos peticiones.
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 6006))', [$userId]);

        $existing = DB::selectOne('SELECT id, request_hash FROM demo_orders WHERE user_id = ? AND idempotency_key = ?', [$userId, $key]);
        if ($existing !== null) {
            return $this->replay($userId, $existing, $requestHash);
        }

        $prices = array_column($config['products'], 'price', 'id');
        $total = 0;
        foreach ($lines as $l) {
            $total += $prices[$l['productId']] * $l['quantity'];
        }
        $rules = $config['credit'];
        if ($total < $rules['minPurchase'] || $total < 1) {
            throw new DemoShopException('invalidPurchase', 'La compra no alcanza el mínimo de la muestra ($ '.number_format($rules['minPurchase'], 0, ',', '.').').', 422);
        }

        $credited = intdiv($total * $rules['balancePercent'], 100);
        $already = (int) DB::selectOne('SELECT COALESCE(SUM(codes_granted), 0) AS n FROM demo_orders WHERE user_id = ?', [$userId])->n;
        $room = max(0, $rules['maxCodesPerSession'] - $already);
        $wanted = min(intdiv($total, $rules['codeStepAmount']), $rules['maxCodesPerPurchase']);
        $granted = min($wanted, $room);

        $orderId = (string) Str::uuid();
        DB::insert(
            'INSERT INTO demo_orders (id, user_id, items, total, credited, codes_granted, idempotency_key, request_hash) VALUES (?, ?, ?::jsonb, ?, ?, ?, ?, ?)',
            [$orderId, $userId, json_encode($lines), $total, $credited, $granted, $key, $requestHash],
        );
        if ($credited > 0) {
            DB::insert("INSERT INTO demo_wallet_ledger (user_id, kind, amount, order_id) VALUES (?, 'purchase_credit', ?, ?)", [$userId, $credited, $orderId]);
        }
        try {
            $codes = $this->draw->draw($userId, $orderId, $granted);
        } catch (DemoPoolExhausted) {
            throw new DemoShopException('demoPoolExhausted', 'La muestra se quedó sin códigos. Avisa al organizador.', 409);
        }

        return [
            'orderId' => $orderId, 'total' => $total, 'credited' => $credited, 'codes' => $codes,
            'capReached' => $granted < $wanted, 'balance' => $this->balances->balanceFor($userId), 'replayed' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function replay(string $userId, object $existing, string $requestHash): array
    {
        if (! hash_equals($existing->request_hash, $requestHash)) {
            throw new DemoShopException('idempotencyKeyReuse', 'Esa clave ya se usó con otra compra.', 422);
        }
        $order = DB::selectOne('SELECT id, total, credited, codes_granted FROM demo_orders WHERE id = ?', [$existing->id]);
        $box = new \App\Domain\Admin\SecretBox;
        $codes = [];
        foreach (DB::select("SELECT game, encode(code_enc, 'hex') AS enc, encode(code_hash, 'hex') AS hash FROM demo_pool WHERE order_id = ? ORDER BY assigned_at, id", [$order->id]) as $r) {
            $codes[] = ['code' => $box->open(hex2bin($r->enc), DemoSeeder::sealContext($r->hash)), 'game' => $r->game];
        }

        return [
            'orderId' => $order->id, 'total' => (int) $order->total, 'credited' => (int) $order->credited, 'codes' => $codes,
            'capReached' => false, 'balance' => $this->balances->balanceFor($userId), 'replayed' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{productId: string, quantity: int}> líneas ordenadas por producto y sin repetidos (la huella no depende del orden)
     */
    private function normalize(mixed $items, array $config): array
    {
        $known = array_column($config['products'], 'price', 'id');
        $bad = static fn (string $m) => throw new DemoShopException('invalidPurchase', $m, 422);
        is_array($items) && array_is_list($items) && $items !== [] && count($items) <= DemoConfig::MAX_PRODUCTS || $bad('items: de 1 a '.DemoConfig::MAX_PRODUCTS.' líneas.');
        $byProduct = [];
        foreach ($items as $i) {
            is_array($i) && array_keys($i) !== [] && ! array_diff(array_keys($i), ['productId', 'quantity']) || $bad('items: cada línea es {productId, quantity}.');
            is_string($i['productId'] ?? null) && isset($known[$i['productId']]) || $bad('items: producto desconocido.');
            is_int($i['quantity'] ?? null) && $i['quantity'] >= 1 && $i['quantity'] <= self::MAX_QUANTITY || $bad('items: la cantidad es un entero de 1 a '.self::MAX_QUANTITY.'.');
            ! isset($byProduct[$i['productId']]) || $bad('items: producto repetido.');
            $byProduct[$i['productId']] = $i['quantity'];
        }
        ksort($byProduct);

        return array_map(static fn (string $id, int $q): array => ['productId' => $id, 'quantity' => $q], array_keys($byProduct), array_values($byProduct));
    }
}
