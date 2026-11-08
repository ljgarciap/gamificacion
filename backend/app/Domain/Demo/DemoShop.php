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
     * @param  string|null  $resume  sujeto de una sesión anterior (si existe se reanuda con el mismo saldo; si no, se abre una nueva)
     * @return array<string, mixed>
     */
    public function openSession(?string $resume, ?string $ip): array
    {
        $config = $this->config();
        $resumed = false;
        $subject = null;
        if ($resume !== null && preg_match(DemoAccess::SUBJECT_PATTERN, $resume) === 1
            && DB::selectOne('SELECT 1 AS x FROM demo_sessions WHERE subject = ?', [$resume]) !== null) {
            $subject = $resume;
            $resumed = true;
        }
        if ($subject === null) {
            $subject = $config['user']['hostUserPrefix'].'.'.bin2hex(random_bytes(12));
            DB::transaction(function () use ($subject, $ip, $config): void {
                DB::insert('INSERT INTO demo_sessions (subject, ip) VALUES (?, ?)', [$subject, $ip === null ? null : mb_substr($ip, 0, 64)]);
                if ($config['user']['initialBalance'] > 0) {
                    DB::insert("INSERT INTO demo_wallet_ledger (user_id, kind, amount) VALUES (?, 'initial', ?)", [DemoAccess::issuerPrefix().$subject, $config['user']['initialBalance']]);
                }
            });
        }
        // Nunca más largo que el máximo que el verificador acepta (parámetro del panel): si no, la sesión nacería con un token rechazado.
        $issued = DevTokenIssuer::issue($subject, min(self::SESSION_TOKEN_SECONDS, Settings::int('token.max_lifetime_seconds')));

        return [
            'token' => $issued['token'], 'expiresIn' => $issued['expiresIn'], 'userId' => $issued['userId'], 'subject' => $subject, 'resumed' => $resumed,
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
