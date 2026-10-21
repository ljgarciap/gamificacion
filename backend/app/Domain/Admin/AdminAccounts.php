<?php

namespace App\Domain\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Settings\Settings;
use App\Domain\Crypto\Secrets;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Cuentas del panel: autenticación, segundo factor y administración de usuarios (SPEC-003 P1). Todo lo que importa queda en la bitácora
 * encadenada con actor `admin:<id>` y SIN datos sensibles: el correo y la IP van como referencia (HMAC), nunca en claro.
 */
final class AdminAccounts
{
    /** Hash de una contraseña que nadie conoce: se compara cuando la cuenta no existe, para que el tiempo de respuesta sea el mismo. */
    private const DECOY_PASSWORD = 'cuenta-inexistente-sin-contrasena-posible';

    public function __construct(
        private readonly AuditLog $audit = new AuditLog,
        private readonly SecretBox $box = new SecretBox,
    ) {}

    // --- Consultas ---------------------------------------------------------------------------------------------------------

    public function find(int $id): ?object
    {
        return DB::selectOne("SELECT *, encode(totp_secret_enc, 'hex') AS totp_secret_hex FROM admin_users WHERE id = ?", [$id]);
    }

    public function findByEmail(string $email): ?object
    {
        return DB::selectOne("SELECT *, encode(totp_secret_enc, 'hex') AS totp_secret_hex FROM admin_users WHERE email = ?", [mb_strtolower(trim($email))]);
    }

    /** @return list<object> */
    public function all(): array
    {
        return DB::select('SELECT id, email, name, role, active, must_change_password, totp_confirmed_at, last_login_at, created_at FROM admin_users ORDER BY id');
    }

    // --- Alta -------------------------------------------------------------------------------------------------------------

    /**
     * Crea una cuenta con una contraseña TEMPORAL que hay que cambiar en el primer inicio de sesión. Se devuelve una sola vez.
     *
     * @return array{id: int, tempPassword: string}
     *
     * @throws \InvalidArgumentException correo, nombre o rol inválidos, o correo repetido
     */
    public function create(string $email, string $name, string $role, string $actor): array
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190) {
            throw new \InvalidArgumentException('Correo inválido.');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('El nombre debe tener de 1 a 120 caracteres.');
        }
        if (! in_array($role, AdminPermissions::ROLES, true)) {
            throw new \InvalidArgumentException('Rol inválido.');
        }
        if ($this->findByEmail($email) !== null) {
            throw new \InvalidArgumentException('Ya existe un usuario con ese correo.');
        }

        $temp = $this->temporaryPassword();

        return DB::transaction(function () use ($email, $name, $role, $actor, $temp): array {
            $row = DB::selectOne(
                'INSERT INTO admin_users (email, name, role, password_hash, must_change_password) VALUES (?, ?, ?, ?, true) RETURNING id',
                [$email, $name, $role, Hash::make($temp)],
            );
            $this->audit->append($actor, 'admin.user_created', 'admin_user', (string) $row->id, ['role' => $role, 'email_ref' => $this->ref($email)]);

            return ['id' => (int) $row->id, 'tempPassword' => $temp];
        });
    }

    // --- Inicio de sesión ---------------------------------------------------------------------------------------------------

    /**
     * Verifica correo y contraseña. NUNCA dice por qué falla (no existe, contraseña errónea, bloqueada, desactivada) y tarda lo mismo en todos los
     * casos: siempre se compara contra un hash, el real o el señuelo.
     *
     * @throws LoginFailed
     */
    public function authenticate(string $email, string $password, ?string $ip): object
    {
        $user = $this->findByEmail($email);
        // El señuelo se calcula UNA vez (se guarda en la caché): hacerlo en cada petición con un correo inexistente la haría tardar el doble.
        $hash = $user->password_hash ?? Cache::rememberForever('gamif:admin:decoy-hash', fn (): string => Hash::make(self::DECOY_PASSWORD));
        $valid = Hash::check($password, $hash);

        $locked = $user !== null && $user->locked_until !== null && Carbon::parse($user->locked_until)->isFuture();
        if ($user === null || ! $valid || $locked || ! $user->active) {
            if ($user !== null && $user->active && ! $locked) {
                $this->recordFailure($user, $ip, 'password');
            }

            throw new LoginFailed;
        }

        return $user;
    }

    /** Cuenta un fallo (contraseña o segundo factor) y, al llegar al máximo, bloquea con espera creciente. */
    public function recordFailure(object $user, ?string $ip, string $factor): void
    {
        $cfg = Settings::group('admin.login');
        DB::transaction(function () use ($user, $ip, $factor, $cfg): void {
            $row = DB::selectOne('SELECT failed_attempts FROM admin_users WHERE id = ? FOR UPDATE', [$user->id]);
            $failures = (int) $row->failed_attempts + 1;
            $max = (int) $cfg['max_failures'];
            $lockSeconds = 0;
            if ($failures % $max === 0) {
                $level = intdiv($failures, $max);
                $lockSeconds = (int) min($cfg['lockout_base_seconds'] * (2 ** ($level - 1)), $cfg['lockout_max_seconds']);
            }
            // El bloqueo se escribe y se compara con el MISMO reloj (el de la aplicación): mezclar el de la base con el de la app lo haría frágil.
            $until = $lockSeconds > 0 ? Carbon::now()->addSeconds($lockSeconds)->format('Y-m-d H:i:s.uP') : null;
            DB::update(
                'UPDATE admin_users SET failed_attempts = ?, locked_until = COALESCE(?::timestamptz, locked_until), updated_at = now() WHERE id = ?',
                [$failures, $until, $user->id],
            );
            $this->audit->append('admin:'.$user->id, $lockSeconds > 0 ? 'admin.account_locked' : 'admin.login_failed', 'admin_user', (string) $user->id,
                ['factor' => $factor, 'ip_ref' => $this->ref((string) $ip)] + ($lockSeconds > 0 ? ['seconds' => $lockSeconds] : []));
        });
    }

    /** Cierra el inicio de sesión: reinicia el contador de fallos (solo tras el SEGUNDO factor) y anota la entrada. */
    public function completeLogin(object $user, ?string $ip): void
    {
        DB::update('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL, last_login_at = now(), updated_at = now() WHERE id = ?', [$user->id]);
        $this->audit->append('admin:'.$user->id, 'admin.login_succeeded', 'admin_user', (string) $user->id, ['ip_ref' => $this->ref((string) $ip)]);
    }

    // --- Contraseña -------------------------------------------------------------------------------------------------------

    /**
     * @param  string|null  $keepSession  id de la sesión actual, que se conserva; las demás del usuario se cierran
     * @return list<string> motivos si la nueva contraseña no sirve (vacío = cambiada)
     *
     * @throws LoginFailed si la contraseña actual no es correcta
     */
    public function changePassword(object $user, string $current, string $new, ?string $keepSession): array
    {
        if (! Hash::check($current, $user->password_hash)) {
            $this->recordFailure($user, null, 'password_change');

            throw new LoginFailed;
        }
        $errors = PasswordPolicy::errors($new, $user->email);
        if (Hash::check($new, $user->password_hash)) {
            $errors[] = 'Debe ser distinta de la actual.';
        }
        if ($errors !== []) {
            return $errors;
        }

        DB::transaction(function () use ($user, $new, $keepSession): void {
            DB::update('UPDATE admin_users SET password_hash = ?, must_change_password = false, updated_at = now() WHERE id = ?', [Hash::make($new), $user->id]);
            $this->closeSessions((int) $user->id, $keepSession);
            $this->audit->append('admin:'.$user->id, 'admin.password_changed', 'admin_user', (string) $user->id, []);
        });

        return [];
    }

    // --- Segundo factor ---------------------------------------------------------------------------------------------------

    /**
     * Empieza (o reinicia) el alta del TOTP: guarda un secreto NUEVO sin confirmar. Devuelve el secreto para mostrarlo UNA vez (QR / texto).
     *
     * @return array{secret: string, uri: string}
     */
    public function beginEnrollment(object $user): array
    {
        if ($user->totp_confirmed_at !== null) {
            throw new \LogicException('La cuenta ya tiene segundo factor.');
        }
        $secret = Totp::newSecret();
        DB::update("UPDATE admin_users SET totp_secret_enc = decode(?, 'hex'), totp_last_step = NULL, updated_at = now() WHERE id = ?", [bin2hex($this->box->seal($secret, $this->context((int) $user->id))), $user->id]);

        return ['secret' => Base32::encode($secret), 'uri' => Totp::uri($secret, $user->email, (string) config('gamification.admin.issuer'))];
    }

    /**
     * Confirma el alta con un código vigente y entrega los códigos de recuperación (UNA vez; en la base queda solo su huella).
     *
     * @return list<string>|null los códigos de recuperación, o null si el código no vale
     */
    public function confirmEnrollment(object $user, string $code, int $now): ?array
    {
        $fresh = $this->find((int) $user->id);
        if ($fresh === null || $fresh->totp_secret_hex === null || $fresh->totp_confirmed_at !== null) {
            return null;
        }
        $secret = $this->box->open(hex2bin($fresh->totp_secret_hex), $this->context((int) $user->id));
        $step = Totp::verify($secret, $code, $now, null, Settings::int('admin.totp_window'));
        if ($step === null) {
            return null;
        }

        $codes = [];
        for ($i = 0; $i < (int) config('gamification.admin.recovery_codes'); $i++) {
            $codes[] = $this->newRecoveryCode();
        }
        DB::transaction(function () use ($user, $step, $codes): void {
            DB::update(
                'UPDATE admin_users SET totp_confirmed_at = now(), totp_last_step = ?, recovery_codes = ?::jsonb, updated_at = now() WHERE id = ?',
                [$step, json_encode(array_map(fn (string $c): string => $this->recoveryHash($c), $codes)), $user->id],
            );
            $this->audit->append('admin:'.$user->id, 'admin.mfa_enrolled', 'admin_user', (string) $user->id, []);
        });

        return $codes;
    }

    /**
     * Verifica el segundo factor: un código TOTP vigente (cada paso se acepta UNA vez) o un código de recuperación (de un solo uso).
     * Un fallo cuenta contra la cuenta, igual que una contraseña errónea.
     *
     * @throws LoginFailed
     */
    public function verifyMfa(object $user, string $code, int $now, ?string $ip): void
    {
        $fresh = $this->find((int) $user->id);
        if ($fresh === null || $fresh->totp_secret_hex === null || $fresh->totp_confirmed_at === null || ! $fresh->active
            || ($fresh->locked_until !== null && Carbon::parse($fresh->locked_until)->isFuture())) {
            throw new LoginFailed;
        }

        $code = trim($code);
        if (preg_match('/^\d{6}$/', $code) === 1) {
            $secret = $this->box->open(hex2bin($fresh->totp_secret_hex), $this->context((int) $user->id));
            $step = Totp::verify($secret, $code, $now, $fresh->totp_last_step === null ? null : (int) $fresh->totp_last_step, Settings::int('admin.totp_window'));
            // Atómico: dos peticiones con el mismo código a la vez → solo una cambia la fila.
            if ($step !== null && DB::update('UPDATE admin_users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)', [$step, $user->id, $step]) === 1) {
                return;
            }
        } elseif ($this->consumeRecoveryCode((int) $user->id, $code)) {
            $this->audit->append('admin:'.$user->id, 'admin.recovery_code_used', 'admin_user', (string) $user->id, []);

            return;
        }

        $this->recordFailure($user, $ip, 'mfa');

        throw new LoginFailed;
    }

    // --- Administración de usuarios (superadmin) -------------------------------------------------------------------

    /** @param array{role?: string, active?: bool, name?: string} $changes */
    public function update(int $id, array $changes, string $actor): void
    {
        $user = $this->find($id) ?? throw new \InvalidArgumentException('Usuario inexistente.');
        $sets = [];
        $params = [];
        $log = [];
        if (isset($changes['role'])) {
            if (! in_array($changes['role'], AdminPermissions::ROLES, true)) {
                throw new \InvalidArgumentException('Rol inválido.');
            }
            $sets[] = 'role = ?';
            $params[] = $changes['role'];
            $log['role'] = $changes['role'];
        }
        if (isset($changes['active'])) {
            $sets[] = 'active = ?';
            $params[] = $changes['active'] ? 'true' : 'false';
            $log['active'] = (bool) $changes['active'];
        }
        if (isset($changes['name'])) {
            $name = trim((string) $changes['name']);
            if ($name === '' || mb_strlen($name) > 120) {
                throw new \InvalidArgumentException('El nombre debe tener de 1 a 120 caracteres.');
            }
            $sets[] = 'name = ?';
            $params[] = $name;
            $log['name_changed'] = true;
        }
        if ($sets === []) {
            return;
        }

        DB::transaction(function () use ($id, $sets, $params, $log, $actor, $changes): void {
            DB::update('UPDATE admin_users SET '.implode(', ', $sets).', updated_at = now() WHERE id = ?', [...$params, $id]);
            // Desactivar o cambiar de rol cierra sus sesiones: no puede seguir actuando con los permisos anteriores.
            if ((isset($changes['active']) && ! $changes['active']) || isset($changes['role'])) {
                $this->closeSessions($id, null);
            }
            $this->audit->append($actor, 'admin.user_updated', 'admin_user', (string) $id, $log);
        });
    }

    /** Quita el segundo factor (y sus códigos de recuperación): el usuario tendrá que volver a enrolarlo. Cierra sus sesiones. */
    public function resetMfa(int $id, string $actor): void
    {
        $this->find($id) ?? throw new \InvalidArgumentException('Usuario inexistente.');
        DB::transaction(function () use ($id, $actor): void {
            DB::update("UPDATE admin_users SET totp_secret_enc = NULL, totp_confirmed_at = NULL, totp_last_step = NULL, recovery_codes = '[]'::jsonb, updated_at = now() WHERE id = ?", [$id]);
            $this->closeSessions($id, null);
            $this->audit->append($actor, 'admin.mfa_reset', 'admin_user', (string) $id, []);
        });
    }

    /** Nueva contraseña TEMPORAL de un solo uso (el usuario debe cambiarla). Cierra sus sesiones y levanta el bloqueo. Se devuelve una vez. */
    public function resetPassword(int $id, string $actor): string
    {
        $this->find($id) ?? throw new \InvalidArgumentException('Usuario inexistente.');
        $temp = $this->temporaryPassword();
        DB::transaction(function () use ($id, $actor, $temp): void {
            DB::update('UPDATE admin_users SET password_hash = ?, must_change_password = true, failed_attempts = 0, locked_until = NULL, updated_at = now() WHERE id = ?', [Hash::make($temp), $id]);
            $this->closeSessions($id, null);
            $this->audit->append($actor, 'admin.password_reset', 'admin_user', (string) $id, []);
        });

        return $temp;
    }

    // --- Internos ---------------------------------------------------------------------------------------------------------

    private function closeSessions(int $adminId, ?string $keep): void
    {
        $sql = 'DELETE FROM sessions WHERE user_id = ?'.($keep !== null ? ' AND id <> ?' : '');
        DB::delete($sql, $keep !== null ? [$adminId, $keep] : [$adminId]);
    }

    private function temporaryPassword(): string
    {
        return Base32::encode(random_bytes(13)); // 21 caracteres, ~104 bits
    }

    private function newRecoveryCode(): string
    {
        return implode('-', str_split(Base32::encode(random_bytes(10)), 4)); // 16 caracteres (80 bits): XXXX-XXXX-XXXX-XXXX
    }

    private function recoveryHash(string $code): string
    {
        return bin2hex(hash_hmac('sha256', 'gamif/v1/admin-recovery'.strtoupper(str_replace(['-', ' '], '', $code)), Secrets::key('code_pepper'), true));
    }

    private function consumeRecoveryCode(int $id, string $code): bool
    {
        if (preg_match('/^[A-Za-z2-7]{4}(-?[A-Za-z2-7]{4}){3}$/', $code) !== 1) {
            return false;
        }

        return DB::transaction(function () use ($id, $code): bool {
            $row = DB::selectOne('SELECT recovery_codes::text AS c FROM admin_users WHERE id = ? FOR UPDATE', [$id]);
            $hashes = json_decode($row->c, true, 512, JSON_THROW_ON_ERROR);
            $given = $this->recoveryHash($code);
            $found = null;
            foreach ($hashes as $i => $h) {
                if (hash_equals($h, $given) && $found === null) { // se recorren todos: el tiempo no revela la posición
                    $found = $i;
                }
            }
            if ($found === null) {
                return false;
            }
            unset($hashes[$found]);
            DB::update('UPDATE admin_users SET recovery_codes = ?::jsonb WHERE id = ?', [json_encode(array_values($hashes)), $id]);

            return true;
        });
    }

    private function context(int $id): string
    {
        return "admin_users|$id|totp_secret_enc";
    }

    /** Referencia corta e irreversible (HMAC) de un dato personal, para la bitácora. */
    private function ref(string $value): string
    {
        return substr(hash_hmac('sha256', 'gamif/v1/admin-ref'.mb_strtolower($value), Secrets::key('code_pepper')), 0, 16);
    }
}
