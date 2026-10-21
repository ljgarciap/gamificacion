<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminAccounts;
use App\Domain\Admin\SecretBox;
use App\Domain\Admin\Totp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\DbTestCase;

/**
 * Base de las pruebas del panel. Las peticiones llevan el token CSRF (la protección está activa también en pruebas) y el reloj es controlable:
 * cada código TOTP que se necesita corresponde a un paso NUEVO (un paso ya usado se rechaza, y las pruebas lo comprueban).
 */
abstract class AdminTestCase extends DbTestCase
{
    protected const PREFIX = '/admin/api/v1';

    protected const GOOD_PASSWORD = 'Tr3n-Azul-Cafetera-91';

    protected AdminAccounts $accounts;

    private int $clock = 1_900_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounts = new AdminAccounts;
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->clock));
        $this->withSession(['_token' => 'token-csrf-de-prueba']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Avanza el reloj (no menos de un paso TOTP) y devuelve el código vigente de ese secreto. */
    protected function nextCode(string $secret): string
    {
        $this->advance(31);

        return Totp::code($secret, Totp::step(Carbon::now()->getTimestamp()));
    }

    protected function advance(int $seconds): void
    {
        $this->clock += $seconds;
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->clock));
    }

    /** @param array<string, mixed> $data */
    protected function api(string $method, string $path, array $data = [], bool $csrf = true): TestResponse
    {
        // Iniciar sesión renueva el token CSRF (lo correcto): el cliente real lo recibe en la cookie XSRF-TOKEN; aquí se lee el vigente.
        return $this->json($method, self::PREFIX.$path, $data, $csrf ? ['X-CSRF-TOKEN' => (string) session()->token()] : []);
    }

    /**
     * Crea un administrador ya listo para entrar (sin cambio de contraseña pendiente y con el 2FA enrolado).
     *
     * @return array{id: int, email: string, password: string, secret: string}
     */
    protected function makeAdmin(string $role = 'operator', ?string $email = null, bool $enrolled = true, ?string $password = null): array
    {
        $email ??= $role.'-'.bin2hex(random_bytes(3)).'@ejemplo.com';
        $password ??= self::GOOD_PASSWORD;
        $created = $this->accounts->create($email, 'Usuario '.$role, $role, 'test');
        $secret = Totp::newSecret();
        DB::update('UPDATE admin_users SET password_hash = ?, must_change_password = false WHERE id = ?', [Hash::make($password), $created['id']]);
        if ($enrolled) {
            DB::update(
                "UPDATE admin_users SET totp_secret_enc = decode(?, 'hex'), totp_confirmed_at = now() WHERE id = ?",
                [bin2hex((new SecretBox)->seal($secret, "admin_users|{$created['id']}|totp_secret_enc")), $created['id']],
            );
        }

        return ['id' => $created['id'], 'email' => $email, 'password' => $password, 'secret' => $secret];
    }

    /** Hace el inicio de sesión completo por HTTP (contraseña + código) y deja la sesión autenticada. */
    protected function login(array $admin): void
    {
        $this->api('POST', '/auth/login', ['email' => $admin['email'], 'password' => $admin['password']])->assertOk()->assertJson(['next' => 'mfa']);
        $this->api('POST', '/auth/mfa', ['code' => $this->nextCode($admin['secret'])])->assertOk();
    }

    /** @return array<string, mixed> */
    protected function row(int $id): array
    {
        return (array) DB::selectOne('SELECT * FROM admin_users WHERE id = ?', [$id]);
    }
}
