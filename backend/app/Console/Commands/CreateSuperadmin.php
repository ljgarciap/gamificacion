<?php

namespace App\Console\Commands;

use App\Domain\Admin\AdminAccounts;
use App\Domain\Admin\AdminPermissions;
use Illuminate\Console\Command;

/**
 * Crea un superadmin con una contraseña TEMPORAL (se imprime una sola vez; debe cambiarse al entrar y enrolar el segundo factor). Es la ÚNICA forma
 * de crear el primer superadmin: no hay usuarios por defecto ni una ruta pública de registro.
 */
class CreateSuperadmin extends Command
{
    protected $signature = 'admin:create-superadmin {email : Correo del usuario} {--name=Administrador : Nombre visible}';

    protected $description = 'Crea un superadmin del panel con una contraseña temporal de un solo uso';

    public function handle(AdminAccounts $accounts): int
    {
        try {
            $created = $accounts->create((string) $this->argument('email'), (string) $this->option('name'), AdminPermissions::SUPERADMIN, 'cli');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Superadmin creado (id '.$created['id'].').');
        $this->line('Contraseña temporal: '.$created['tempPassword']);
        $this->warn('Se muestra UNA sola vez. Al iniciar sesión deberá cambiarla y enrolar el segundo factor.');

        return self::SUCCESS;
    }
}
