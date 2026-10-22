<?php
// SOLO DESARROLLO: deja listas las cuentas del panel para los recorridos E2E de Playwright (admin-panel/e2e). Crea o restablece tres cuentas
// con contraseña conocida y el 2FA enrolado con un secreto que imprime, y una cuenta nueva con contraseña temporal (primer ingreso). Imprime un
// JSON por la salida estándar. Se niega a correr fuera de APP_ENV=local.
//
//   docker compose exec -T backend php /var/www/html/scripts/e2e_admin_users.php

use App\Domain\Admin\AdminAccounts;
use App\Domain\Admin\SecretBox;
use App\Domain\Admin\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (! in_array(config('app.env'), ['local'], true)) {
    fwrite(STDERR, "Solo con APP_ENV=local.\n");
    exit(1);
}

// Los fallos de acceso que las propias pruebas provocan a propósito se acumulan por IP entre archivos y terminan bloqueando a las siguientes
// (revisión P10, hallazgo 13): cada corrida empieza sin bloqueos ni contadores de acceso del panel.
DB::table('cache')->where('key', 'like', '%gamif:admin-login:%')->delete();

$accounts = new AdminAccounts;
$password = 'Tr3n-Azul-Cafetera-91';
$out = ['password' => $password, 'users' => []];

foreach (['superadmin', 'operator', 'auditor'] as $role) {
    $email = "e2e-$role@ejemplo.com";
    $user = $accounts->findByEmail($email) ?? $accounts->find($accounts->create($email, "E2E $role", $role, 'cli')['id']);
    $secret = Totp::newSecret();
    DB::update(
        "UPDATE admin_users SET password_hash = ?, must_change_password = false, active = true, role = ?, failed_attempts = 0, locked_until = NULL,
                totp_secret_enc = decode(?, 'hex'), totp_confirmed_at = now(), totp_last_step = NULL WHERE id = ?",
        [Hash::make($password), $role, bin2hex((new SecretBox)->seal($secret, "admin_users|{$user->id}|totp_secret_enc")), $user->id],
    );
    $out['users'][$role] = ['email' => $email, 'secretBase32' => \App\Domain\Admin\Base32::encode($secret)];
}

// Primer ingreso: cuenta nueva con contraseña temporal, sin 2FA.
$fresh = 'e2e-nuevo-'.bin2hex(random_bytes(3)).'@ejemplo.com';
$created = $accounts->create($fresh, 'E2E nuevo', 'operator', 'cli');
$out['fresh'] = ['email' => $fresh, 'tempPassword' => $created['tempPassword']];

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
