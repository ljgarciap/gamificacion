<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminPermissions;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * PRUEBA DE ARQUITECTURA: ninguna ruta del panel puede quedar sin autenticación ni sin permiso declarado. Si alguien agrega una ruta
 * `/admin/*` y se olvida de `admin.auth` o de `admin.can:`, esta prueba falla y dice cuál.
 */
final class AdminRoutesTest extends TestCase
{
    /** Las únicas rutas que no exigen sesión completa: son las del propio inicio de sesión. */
    private const PRE_AUTH = [
        'GET admin/api/v1/auth/csrf', 'POST admin/api/v1/auth/login', 'POST admin/api/v1/auth/password',
        'POST admin/api/v1/auth/mfa/enroll', 'POST admin/api/v1/auth/mfa/enroll/confirm', 'POST admin/api/v1/auth/mfa',
    ];

    /** @return list<LaravelRoute> */
    private function adminRoutes(): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (LaravelRoute $r): bool => str_starts_with($r->uri(), 'admin/')));
    }

    private function label(LaravelRoute $r): string
    {
        return $r->methods()[0].' '.$r->uri();
    }

    public function test_hay_rutas_del_panel(): void
    {
        $this->assertGreaterThanOrEqual(13, count($this->adminRoutes()));
    }

    public function test_toda_ruta_fuera_del_inicio_de_sesion_exige_sesion_completa_y_declara_su_permiso(): void
    {
        foreach ($this->adminRoutes() as $route) {
            if (in_array($this->label($route), self::PRE_AUTH, true)) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $this->assertContains('admin.auth', $middleware, $this->label($route).' no exige sesión del panel');
            $perms = array_values(array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'admin.can:')));
            $this->assertCount(1, $perms, $this->label($route).' debe declarar UN permiso (admin.can:…)');
            $this->assertContains(substr($perms[0], strlen('admin.can:')), AdminPermissions::all(), $this->label($route).' usa un permiso que no existe en la matriz');
        }
    }

    public function test_las_rutas_pre_autenticacion_son_exactamente_las_esperadas(): void
    {
        $withoutAuth = [];
        foreach ($this->adminRoutes() as $route) {
            if (! in_array('admin.auth', $route->gatherMiddleware(), true)) {
                $withoutAuth[] = $this->label($route);
            }
        }
        sort($withoutAuth);
        $expected = self::PRE_AUTH;
        sort($expected);
        $this->assertSame($expected, $withoutAuth, 'una ruta nueva sin admin.auth debe agregarse a PRE_AUTH a conciencia (y revisarse)');
    }

    public function test_todas_las_rutas_del_panel_llevan_sesion_y_csrf(): void
    {
        foreach ($this->adminRoutes() as $route) {
            $m = $route->gatherMiddleware();
            $this->assertContains(\Illuminate\Session\Middleware\StartSession::class, $m, $this->label($route));
            $this->assertContains(\App\Http\Middleware\AdminForgery::class, $m, $this->label($route));
        }
    }

    public function test_las_dos_superficies_no_comparten_autenticacion(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $m = $route->gatherMiddleware();
            if (str_starts_with($route->uri(), 'admin/')) {
                $this->assertNotContains('host.auth', $m, $this->label($route).' no puede usar el JWT de los hosts');
            } elseif (str_starts_with($route->uri(), 'v1/') && ! str_starts_with($route->uri(), 'v1/dev/')) {
                $this->assertContains('host.auth', $m, $this->label($route));
                $this->assertNotContains('admin.auth', $m, $this->label($route));
            }
        }
    }

    public function test_ninguna_ruta_acepta_cualquier_metodo_ni_usa_comodines_de_ruta(): void
    {
        foreach ($this->adminRoutes() as $route) {
            $this->assertNotContains('ANY', $route->methods(), $this->label($route));
            $this->assertStringNotContainsString('{any', $route->uri(), $this->label($route));
        }
    }
}
