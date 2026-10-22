<?php

namespace Tests\Feature\Contract;

use App\Domain\Admin\AdminPermissions;
use App\Domain\Audit\AuditLog;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Admin\AdminTestCase;

/**
 * El contrato del panel (docs/openapi-admin.yaml) se COMPARA con las rutas reales, con el permiso que exige cada una y con respuestas reales
 * de las pantallas que más dependen de él. Si alguien agrega una ruta o un permiso sin documentarlo, falla aquí.
 */
final class AdminOpenApiContractTest extends AdminTestCase
{
    /** @var array<string, mixed> */
    private array $doc;

    protected function setUp(): void
    {
        parent::setUp();
        $file = (getenv('CONTRACT_DIR') ?: '/docs').'/openapi-admin.yaml';
        is_file($file) || $this->fail("No se encuentra $file (defina CONTRACT_DIR o monte ./docs).");
        $this->doc = Yaml::parseFile($file);
    }

    /** @return array<string, string|null> "MÉTODO /ruta" => permiso exigido */
    private function realRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $r) {
            if (! str_starts_with($r->uri(), 'admin/api/v1/')) {
                continue;
            }
            $perm = null;
            foreach ($r->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'admin.can:')) {
                    $perm = substr($m, 10);
                }
            }
            foreach (array_diff($r->methods(), ['HEAD']) as $method) {
                $routes[strtolower($method).' '.substr($r->uri(), strlen('admin/api/v1'))] = $perm;
            }
        }
        ksort($routes);

        return $routes;
    }

    public function test_las_operaciones_documentadas_son_exactamente_las_rutas_del_panel_con_su_permiso(): void
    {
        $doc = [];
        foreach ($this->doc['paths'] as $path => $ops) {
            foreach ($ops as $method => $op) {
                $this->assertArrayHasKey('x-permission', $op, "$method $path no declara su permiso");
                $doc["$method $path"] = $op['x-permission'];
            }
        }
        ksort($doc);
        $this->assertSame($this->realRoutes(), $doc);
    }

    public function test_los_permisos_documentados_existen_en_la_matriz(): void
    {
        foreach ($this->realRoutes() as $route => $perm) {
            $perm === null || $this->assertContains($perm, AdminPermissions::all(), $route);
        }
    }

    public function test_los_codigos_de_error_que_emite_el_panel_estan_en_el_contrato(): void
    {
        $found = [];
        foreach (['Http/Controllers/Admin', 'Domain'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path($dir), \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->getExtension() !== 'php' || str_contains($f->getPathname(), 'Domain/Redemption')) {
                    continue;
                }
                preg_match_all("/(?:AdminException\(|ApiError::response\()\s*'([A-Za-z]+)'/", (string) file_get_contents($f->getPathname()), $m);
                array_push($found, ...$m[1]);
            }
        }
        $documented = $this->doc['components']['schemas']['ErrorCode']['enum'];
        $this->assertSame([], array_values(array_diff(array_unique($found), $documented)), 'códigos de error sin documentar');
    }

    public function test_las_respuestas_reales_tienen_las_claves_del_contrato(): void
    {
        (new AuditLog)->append('admin:1', 'prueba.contrato', 'cosa', 'c-1', ['a' => 1]);
        $this->login($this->makeAdmin('auditor'));

        $me = $this->api('GET', '/auth/me')->assertOk()->json();
        $this->assertSame(
            $this->sorted([...$this->doc['components']['schemas']['Me']['required'], 'session']),
            $this->sorted(array_keys($me)),
        );
        $this->assertSame(['idleMinutes'], array_keys($me['session']));

        $d = $this->api('GET', '/dashboard')->assertOk()->json();
        $this->assertSame($this->sorted($this->doc['components']['schemas']['Dashboard']['required']), $this->sorted(array_keys($d)));
        $this->assertSame(['day', 'count'], array_keys($d['redemptionsByDay'][0]));

        $page = $this->api('GET', '/audit?perPage=1')->assertOk()->json();
        $this->assertSame($this->sorted($this->doc['components']['schemas']['AuditPage']['required']), $this->sorted(array_keys($page)));
        $this->assertSame($this->sorted($this->doc['components']['schemas']['AuditEntry']['required']), $this->sorted(array_keys($page['items'][0])));

        $v = $this->api('GET', '/audit/verify')->assertOk()->json();
        $this->assertSame($this->sorted($this->doc['components']['schemas']['VerifySegment']['required']), $this->sorted(array_keys($v)));

        $a = $this->api('GET', '/audit/anchor')->assertOk()->json();
        $this->assertSame(['anchor'], array_keys($a));
        $this->assertSame($this->sorted(array_keys($this->doc['components']['schemas']['AnchorResponse']['properties']['anchor']['properties'])), $this->sorted(array_keys($a['anchor'])));
    }

    /** @param array<mixed> $a @return array<mixed> */
    private function sorted(array $a): array
    {
        sort($a);

        return $a;
    }
}
