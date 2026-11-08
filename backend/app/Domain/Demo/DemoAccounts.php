<?php

namespace App\Domain\Demo;

use App\Domain\Admin\SecretBox;
use App\Domain\Audit\AuditLog;
use App\Domain\Balance\StubBalanceProvider;
use App\Domain\Crypto\Secrets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cuentas persistentes de la muestra (SPEC-006A). La cuenta es la LLAVE a un `subject` (saldo, pedidos, códigos y barrera de canje no cambian).
 * El correo se guarda cifrado y con una huella HMAC para buscarlo; borrar la cuenta destruye ambos y el saldo queda huérfano e inalcanzable.
 */
final class DemoAccounts
{
    public function __construct(
        private readonly SecretBox $box = new SecretBox,
        private readonly AuditLog $audit = new AuditLog,
        private readonly StubBalanceProvider $balances = new StubBalanceProvider,
    ) {}

    /** Huella (binaria) del correo ya normalizado. Con un pepper propio: una fuga de la base no permite buscar correos conocidos sin él. */
    public static function emailHash(string $normalizedEmail): string
    {
        return hash_hmac('sha256', 'gamif/v1/demo-email|'.$normalizedEmail, Secrets::key('code_pepper'), true);
    }

    private static function sealContext(string $accountId): string
    {
        return "demo_accounts|{$accountId}|email_enc";
    }

    /** @return array{id: string, subject: string}|null */
    public function findByEmail(string $normalizedEmail): ?array
    {
        $r = DB::selectOne('SELECT id, subject FROM demo_accounts WHERE email_hash = decode(?, \'hex\')', [bin2hex(self::emailHash($normalizedEmail))]);

        return $r === null ? null : ['id' => $r->id, 'subject' => $r->subject];
    }

    public function existsForSubject(string $subject): bool
    {
        return DB::selectOne('SELECT 1 AS x FROM demo_accounts WHERE subject = ?', [$subject]) !== null;
    }

    /**
     * Crea la cuenta (con su sujeto y el saldo inicial del escenario). Respeta el tope de cuentas (T-D18) con un candado: dos registros a la vez
     * no pueden pasarse del tope.
     *
     * @param  array<string, mixed>  $config  configuración vigente de la muestra
     * @return array{id: string, subject: string}
     */
    public function create(string $normalizedEmail, array $config, ?string $ip): array
    {
        $hash = self::emailHash($normalizedEmail);
        $id = (string) Str::uuid();
        $subject = $config['user']['hostUserPrefix'].'.'.bin2hex(random_bytes(12));

        return DB::transaction(function () use ($normalizedEmail, $hash, $id, $subject, $config, $ip): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 6007))', ['demo-accounts-cap']);
            if ((int) DB::selectOne('SELECT count(*) AS n FROM demo_accounts')->n >= $config['accounts']['maxAccounts']) {
                throw new DemoShopException('accountsFull', 'La muestra llegó a su tope de cuentas. Avisa al organizador.', 503);
            }
            DB::insert('INSERT INTO demo_sessions (subject, ip) VALUES (?, ?)', [$subject, $ip === null ? null : mb_substr($ip, 0, 64)]);
            if ($config['user']['initialBalance'] > 0) {
                DB::insert("INSERT INTO demo_wallet_ledger (user_id, kind, amount) VALUES (?, 'initial', ?)", [DemoAccess::issuerPrefix().$subject, $config['user']['initialBalance']]);
            }
            DB::insert(
                "INSERT INTO demo_accounts (id, email_hash, email_enc, subject) VALUES (?, decode(?, 'hex'), decode(?, 'hex'), ?)",
                [$id, bin2hex($hash), bin2hex($this->box->seal($normalizedEmail, self::sealContext($id))), $subject],
            );

            return ['id' => $id, 'subject' => $subject];
        });
    }

    public function touch(string $id): void
    {
        DB::update('UPDATE demo_accounts SET last_login_at = now() WHERE id = ?', [$id]);
    }

    /** Borra una cuenta (correo y huella). El saldo y los pedidos quedan sin dueño alcanzable. */
    public function delete(string $id, string $actor): bool
    {
        $deleted = DB::delete('DELETE FROM demo_accounts WHERE id = ?', [$id]) > 0;
        $deleted && $this->audit->append($actor, 'demo.account_deleted', 'demo', $id, []);

        return $deleted;
    }

    public function deleteAll(string $actor): int
    {
        $n = DB::delete('DELETE FROM demo_accounts');
        $this->audit->append($actor, 'demo.accounts_deleted_all', 'demo', 'accounts', ['count' => $n]);

        return $n;
    }

    /** Retención (D28): borra las cuentas sin ingreso en `$days` días. @return int cuántas */
    public function purgeStale(int $days, string $actor = 'system'): int
    {
        $n = DB::delete('DELETE FROM demo_accounts WHERE last_login_at < now() - make_interval(days => ?)', [$days]);
        $n > 0 && $this->audit->append($actor, 'demo.accounts_purged', 'demo', 'accounts', ['count' => $n, 'retentionDays' => $days]);

        return $n;
    }

    /** @return array{total: int, active7d: int} */
    public function counts(): array
    {
        $r = DB::selectOne("SELECT count(*) AS total, count(*) FILTER (WHERE last_login_at > now() - interval '7 days') AS active FROM demo_accounts");

        return ['total' => (int) $r->total, 'active7d' => (int) $r->active];
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int} correos ENMASCARADOS */
    public function list(int $page, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $rows = DB::select(
            "SELECT id, subject, encode(email_enc, 'hex') AS enc, created_at, last_login_at FROM demo_accounts ORDER BY last_login_at DESC, id LIMIT ? OFFSET ?",
            [$perPage, ($page - 1) * $perPage],
        );
        $items = [];
        foreach ($rows as $r) {
            $email = $this->box->open(hex2bin($r->enc), self::sealContext($r->id));
            $items[] = [
                'id' => $r->id,
                'email' => DemoEmail::mask($email),
                'balance' => $this->balances->balanceFor(DemoAccess::issuerPrefix().$r->subject),
                'createdAt' => \Illuminate\Support\Carbon::parse($r->created_at)->toIso8601String(),
                'lastLoginAt' => \Illuminate\Support\Carbon::parse($r->last_login_at)->toIso8601String(),
            ];
        }

        return ['items' => $items, 'total' => (int) DB::selectOne('SELECT count(*) AS n FROM demo_accounts')->n, 'page' => $page, 'perPage' => $perPage];
    }
}
