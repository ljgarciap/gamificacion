<?php

namespace App\Domain\Demo;

use App\Domain\Crypto\Secrets;
use App\Mail\DemoLoginCodeMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Códigos de ingreso de un solo uso (SPEC-006A, T-D11…T-D14). Pedir un código NO consulta si la cuenta existe (misma ruta para un correo
 * registrado y uno nuevo: no hay oráculo). Se guarda HMAC(pepper, código); el consumo es atómico; hay límites de envío por correo, por IP y
 * globales. Los límites son TECHOS DE SEGURIDAD de la muestra (como los de `DemoConfig`), no reglas de negocio configurables.
 */
final class DemoLoginCodes
{
    public const CODE_DIGITS = 6;

    public const MAX_ATTEMPTS = 5;

    /** Espera mínima entre dos envíos al mismo correo. */
    public const COOLDOWN_SECONDS = 60;

    public const PER_EMAIL_PER_HOUR = 5;

    public const PER_IP_PER_HOUR = 10;

    public const GLOBAL_PER_HOUR = 300;

    /**
     * Pide un código para el correo (ya normalizado). Siempre devuelve la misma forma; si un límite impide enviar, solo cambia `retryAfter`.
     *
     * @param  array<string, mixed>  $config  configuración vigente (vigencia del código)
     * @return array{sent: true, retryAfter: int}
     */
    public function request(string $email, ?string $ip, array $config): array
    {
        $hash = DemoAccounts::emailHash($email);
        $hex = bin2hex($hash);
        $minutes = (int) $config['accounts']['codeTtlMinutes'];
        $ip = $ip === null ? null : mb_substr($ip, 0, 64);

        $logId = null;
        $codeId = null;
        $code = null;
        $wait = DB::transaction(function () use ($hex, $ip, $minutes, &$logId, &$codeId, &$code): int {
            // Un candado por correo: dos peticiones simultáneas no se saltan la espera ni dejan dos códigos activos.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 6008))', [$hex]);

            $wait = $this->secondsUntilAllowed($hex, $ip);
            if ($wait > 0) {
                return $wait;
            }
            $code = str_pad((string) random_int(0, 10 ** self::CODE_DIGITS - 1), self::CODE_DIGITS, '0', STR_PAD_LEFT);
            DB::update("UPDATE demo_login_codes SET consumed_at = now() WHERE email_hash = decode(?, 'hex') AND consumed_at IS NULL", [$hex]);
            $codeId = (int) DB::selectOne(
                "INSERT INTO demo_login_codes (email_hash, code_hash, expires_at) VALUES (decode(?, 'hex'), decode(?, 'hex'), now() + make_interval(mins => ?)) RETURNING id",
                [$hex, bin2hex(self::codeHash($hex, $code)), $minutes],
            )->id;
            $logId = (int) DB::selectOne("INSERT INTO demo_mail_log (email_hash, ip) VALUES (decode(?, 'hex'), ?) RETURNING id", [$hex, $ip])->id;

            return 0;
        });
        if ($wait > 0) {
            return ['sent' => true, 'retryAfter' => $wait];
        }

        try {
            Mail::to($email)->send(new DemoLoginCodeMail($code, $minutes));
        } catch (\Throwable $e) {
            // El correo no salió: no se gasta el cupo ni queda un código que nadie recibió. Sin el correo ni el código en el registro (T-D16).
            DB::delete('DELETE FROM demo_login_codes WHERE id = ?', [$codeId]);
            DB::delete('DELETE FROM demo_mail_log WHERE id = ?', [$logId]);
            Log::warning('demo: no se pudo enviar el código de ingreso', ['error' => class_basename($e)]);
            throw new DemoShopException('mailUnavailable', 'No pudimos enviar el correo. Inténtalo de nuevo en un momento.', 503);
        }

        return ['sent' => true, 'retryAfter' => self::COOLDOWN_SECONDS];
    }

    /** Segundos que faltan para poder enviar a este correo desde esta IP (0 = ya se puede). */
    private function secondsUntilAllowed(string $hex, ?string $ip): int
    {
        $wait = 0;
        $last = DB::selectOne("SELECT extract(epoch FROM (created_at + make_interval(secs => ?)) - clock_timestamp()) AS s FROM demo_mail_log WHERE email_hash = decode(?, 'hex') ORDER BY id DESC LIMIT 1", [self::COOLDOWN_SECONDS, $hex]);
        $last !== null && $wait = max($wait, (int) ceil((float) $last->s));

        $hourWait = static function (string $where, array $bindings, int $limit): int {
            $rows = DB::select("SELECT extract(epoch FROM (created_at + interval '1 hour') - clock_timestamp()) AS s FROM demo_mail_log WHERE $where AND created_at > now() - interval '1 hour' ORDER BY id DESC LIMIT ?", [...$bindings, $limit]);

            // Con `limit` envíos en la última hora, vuelve a poder enviar cuando salga el más antiguo de esos `limit`.
            return count($rows) >= $limit ? max(1, (int) ceil((float) end($rows)->s)) : 0;
        };
        $wait = max($wait, $hourWait("email_hash = decode('{$hex}', 'hex')", [], self::PER_EMAIL_PER_HOUR));
        $ip !== null && $wait = max($wait, $hourWait('ip = ?', [$ip], self::PER_IP_PER_HOUR));
        $wait = max($wait, $hourWait('true', [], self::GLOBAL_PER_HOUR));

        return max(0, $wait);
    }

    /**
     * Verifica y CONSUME el código. true solo para quien gana la carrera; cualquier otra causa (errado, vencido, consumido, sin pedido, intentos
     * agotados) es el mismo `false` (T-D11). Cada intento cuenta; con MAX_ATTEMPTS fallidos el código se quema.
     */
    public function verify(string $email, string $code): bool
    {
        $hex = bin2hex(DemoAccounts::emailHash($email));
        $given = self::codeHash($hex, preg_match('/^\d{'.self::CODE_DIGITS.'}$/', $code) === 1 ? $code : str_repeat('x', self::CODE_DIGITS));

        return DB::transaction(function () use ($hex, $given): bool {
            $row = DB::selectOne(
                "UPDATE demo_login_codes SET attempts = attempts + 1
                 WHERE email_hash = decode(?, 'hex') AND consumed_at IS NULL AND expires_at > now() AND attempts < ?
                 RETURNING id, encode(code_hash, 'hex') AS code_hash",
                [$hex, self::MAX_ATTEMPTS],
            );
            // Sin código vivo se compara igual contra un valor ficticio: el trabajo no delata si el correo pidió código.
            $stored = $row === null ? str_repeat('0', 64) : $row->code_hash;
            $matches = hash_equals($stored, bin2hex($given));
            if ($row === null || ! $matches) {
                return false;
            }

            return DB::selectOne('UPDATE demo_login_codes SET consumed_at = now() WHERE id = ? AND consumed_at IS NULL RETURNING id', [$row->id]) !== null;
        });
    }

    /** Borra lo vencido: códigos y registro de envíos (los límites miran la última hora). @return int filas borradas */
    public function purge(): int
    {
        return DB::delete("DELETE FROM demo_login_codes WHERE expires_at < now() - interval '1 day'")
            + DB::delete("DELETE FROM demo_mail_log WHERE created_at < now() - interval '2 hours'");
    }

    private static function codeHash(string $emailHashHex, string $code): string
    {
        return hash_hmac('sha256', 'gamif/v1/demo-otp|'.$emailHashHex.'|'.$code, Secrets::key('code_pepper'), true);
    }
}
