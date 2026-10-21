<?php

namespace Tests\Feature\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Hosts\HostService;
use App\Domain\Hosts\HostUrlGuard;
use Illuminate\Support\Facades\DB;

/** Hosts registrados y sus secretos (SPEC-003 RF-49, amenazas T18 y T20). */
final class HostsTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['gamification.hosts' => ['superapp' => ['alg' => 'HS256', 'key' => 'x'], 'otra' => ['alg' => 'HS256', 'key' => 'y']]]);
        $this->app->instance(HostUrlGuard::class, new HostUrlGuard(false, [], fn (string $h): array => ['host.example.com' => ['8.8.8.8'], 'interno.example.com' => ['10.1.1.1']][$h] ?? []));
    }

    private function body(array $over = []): array
    {
        return $over + ['issuer' => 'superapp', 'callbackUrl' => 'https://host.example.com/gamif/callback', 'balanceUrl' => 'https://host.example.com/gamif/balance'];
    }

    private function create(array $over = []): array
    {
        $r = $this->api('POST', '/hosts', $this->body($over));
        $this->assertSame(201, $r->status(), $r->getContent());

        return $r->json();
    }

    public function test_crear_muestra_el_secreto_una_vez_y_se_guarda_cifrado(): void
    {
        $this->login($this->makeAdmin('superadmin'));
        $h = $this->create();
        $this->assertSame(43, strlen($h['secret']));

        $list = $this->api('GET', '/hosts')->assertOk();
        $this->assertStringNotContainsString($h['secret'], $list->getContent());
        $this->assertSame(['superapp', true, false], [$list->json('hosts.0.issuer'), $list->json('hosts.0.active'), $list->json('hosts.0.hasPreviousSecret')]);
        $this->assertArrayNotHasKey('secret', $list->json('hosts.0'));

        $stored = DB::selectOne("SELECT encode(secret_current_enc, 'hex') AS s FROM hosts WHERE id = ?", [$h['id']])->s;
        $this->assertStringNotContainsString(bin2hex($h['secret']), $stored);
        $this->assertSame($h['secret'], app(HostService::class)->secretsFor($h['id'])['current']);
        $this->assertStringNotContainsString($h['secret'], json_encode(DB::table('audit_log')->pluck('data')->all()), 'el secreto no va a la bitácora');
        $this->assertNull((new AuditLog)->verifyChain());
    }

    public function test_el_secreto_cifrado_esta_ligado_a_su_fila(): void
    {
        $this->login($this->makeAdmin('superadmin'));
        $a = $this->create();
        $b = $this->create(['issuer' => 'otra']);
        // Copiar el secreto cifrado de un host a otro no sirve: no abre.
        DB::update('UPDATE hosts SET secret_current_enc = (SELECT secret_current_enc FROM hosts WHERE id = ?) WHERE id = ?', [$a['id'], $b['id']]);
        $this->expectException(\RuntimeException::class);
        app(HostService::class)->secretsFor($b['id']);
    }

    public function test_rotar_deja_dos_secretos_y_retirar_deja_uno(): void
    {
        $this->login($this->makeAdmin('superadmin'));
        $h = $this->create();

        $new = $this->api('POST', "/hosts/{$h['id']}/rotate-secret")->assertOk()->json('secret');
        $this->assertNotSame($h['secret'], $new);
        $s = app(HostService::class)->secretsFor($h['id']);
        $this->assertSame([$new, $h['secret']], [$s['current'], $s['previous']]);
        $this->assertTrue($this->api('GET', '/hosts')->json('hosts.0.hasPreviousSecret'));

        // Una segunda rotación descarta el más viejo.
        $third = $this->api('POST', "/hosts/{$h['id']}/rotate-secret")->json('secret');
        $s = app(HostService::class)->secretsFor($h['id']);
        $this->assertSame([$third, $new], [$s['current'], $s['previous']]);

        $this->api('POST', "/hosts/{$h['id']}/retire-previous-secret")->assertNoContent();
        $this->assertNull(app(HostService::class)->secretsFor($h['id'])['previous']);
        $this->assertSame(409, $this->api('POST', "/hosts/{$h['id']}/retire-previous-secret")->status());
    }

    public function test_urls_peligrosas_se_rechazan_al_crear_y_al_editar(): void
    {
        $this->login($this->makeAdmin('superadmin'));
        foreach (['http://host.example.com/cb', 'https://interno.example.com/cb', 'https://127.0.0.1/cb', 'https://169.254.169.254/x', 'https://u:p@host.example.com/cb', 'https://localhost/cb'] as $bad) {
            $this->assertSame(422, $this->api('POST', '/hosts', $this->body(['callbackUrl' => $bad]))->status(), $bad);
            $this->assertSame(422, $this->api('POST', '/hosts', $this->body(['balanceUrl' => $bad]))->status(), $bad);
        }
        $this->assertSame(0, DB::table('hosts')->count());

        $h = $this->create();
        $this->assertSame(422, $this->api('PATCH', "/hosts/{$h['id']}", ['callbackUrl' => 'https://interno.example.com/cb'])->status());
        $this->assertSame('https://host.example.com/gamif/callback', DB::table('hosts')->where('id', $h['id'])->value('callback_url'));
        $this->api('PATCH', "/hosts/{$h['id']}", ['callbackUrl' => 'https://host.example.com/nuevo'])->assertNoContent();
    }

    public function test_validacion_del_emisor_y_duplicados(): void
    {
        $this->login($this->makeAdmin('superadmin'));
        $this->assertSame(422, $this->api('POST', '/hosts', $this->body(['issuer' => 'desconocido']))->status(), 'debe existir en GAMIF_HOSTS');
        $this->assertSame(422, $this->api('POST', '/hosts', $this->body(['issuer' => 'con espacio']))->status());
        $this->assertSame(422, $this->api('POST', '/hosts', ['issuer' => 'superapp'])->status());
        $this->create();
        $this->assertSame(409, $this->api('POST', '/hosts', $this->body())->status());
        $this->assertSame(404, $this->api('POST', '/hosts/9999/rotate-secret')->status());
        $this->assertSame(422, $this->api('PATCH', '/hosts/1', [])->status());
    }

    public function test_la_base_impide_borrar_o_renombrar_un_host(): void
    {
        $this->login($this->makeAdmin('superadmin'));
        $h = $this->create();
        $this->assertDbRejects(fn () => DB::delete('DELETE FROM hosts WHERE id = ?', [$h['id']]), 'se desactiva');
        $this->assertDbRejects(fn () => DB::update("UPDATE hosts SET issuer = 'otra' WHERE id = ?", [$h['id']]), 'no cambia');
        $this->api('PATCH', "/hosts/{$h['id']}", ['active' => false])->assertNoContent();
        $this->assertFalse($this->api('GET', '/hosts')->json('hosts.0.active'));
    }

    public function test_solo_el_superadmin_toca_los_hosts(): void
    {
        $this->login($this->makeAdmin('superadmin'));
        $h = $this->create();
        foreach (['operator', 'auditor'] as $role) {
            $this->api('POST', '/auth/logout');
            $this->login($this->makeAdmin($role));
            $this->api('GET', '/hosts')->assertStatus(403);
            $this->api('POST', '/hosts', $this->body(['issuer' => 'otra']))->assertStatus(403);
            $this->api('POST', "/hosts/{$h['id']}/rotate-secret")->assertStatus(403);
            $this->api('PATCH', "/hosts/{$h['id']}", ['active' => false])->assertStatus(403);
        }
    }
}
