<?php

namespace Tests\Feature\Api;

use Tests\DbTestCase;
use Tests\Support\HostTokens;

final class CorsTest extends DbTestCase
{
    private const ALLOWED = 'https://app.supergiros.test';

    protected function setUp(): void
    {
        parent::setUp();
        HostTokens::configure();
    }

    private function preflight(string $origin, string $path = '/v1/codes/redeem'): \Illuminate\Testing\TestResponse
    {
        return $this->call('OPTIONS', $path, [], [], [], $this->transformHeadersToServerVars([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type,idempotency-key',
        ]));
    }

    public function test_por_defecto_ningun_origen_externo_esta_permitido(): void
    {
        $this->assertSame([], config('cors.allowed_origins'), 'sin GAMIF_CORS_ORIGINS la lista es vacía: no hay comodín');
        $r = $this->preflight(self::ALLOWED);
        $this->assertNull($r->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_un_origen_listado_puede_hacer_el_preflight(): void
    {
        config(['cors.allowed_origins' => [self::ALLOWED]]);
        $r = $this->preflight(self::ALLOWED);

        $r->assertNoContent();
        $this->assertSame(self::ALLOWED, $r->headers->get('Access-Control-Allow-Origin'), 'devuelve el origen exacto, no un comodín');
        $headers = strtolower((string) $r->headers->get('Access-Control-Allow-Headers'));
        foreach (['authorization', 'content-type', 'idempotency-key'] as $h) {
            $this->assertStringContainsString($h, $headers);
        }
        $this->assertSame('600', $r->headers->get('Access-Control-Max-Age'));
    }

    public function test_un_origen_no_listado_nunca_recibe_su_propio_origen_como_permitido(): void
    {
        $intrusos = ['https://evil.test', 'https://app.supergiros.test.evil.test', 'http://app.supergiros.test', 'null', 'https://sub.app.supergiros.test'];

        // Con UN solo origen configurado la biblioteca devuelve siempre ese origen (el navegador lo compara y bloquea al intruso). Lo que
        // jamás debe pasar es que se le devuelva al intruso SU propio origen, que es lo que lo dejaría entrar.
        config(['cors.allowed_origins' => [self::ALLOWED]]);
        foreach ($intrusos as $origin) {
            $this->assertNotSame($origin, $this->preflight($origin)->headers->get('Access-Control-Allow-Origin'), "un solo origen: $origin");
        }

        // Con varios orígenes se comprueba la pertenencia: el intruso no recibe NINGUNA cabecera de permiso.
        config(['cors.allowed_origins' => [self::ALLOWED, 'https://otra.supergiros.test']]);
        foreach ($intrusos as $origin) {
            $this->assertNull($this->preflight($origin)->headers->get('Access-Control-Allow-Origin'), "varios orígenes: $origin");
        }
        $this->assertSame('https://otra.supergiros.test', $this->preflight('https://otra.supergiros.test')->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_no_se_permiten_credenciales(): void
    {
        config(['cors.allowed_origins' => [self::ALLOWED]]);
        $this->assertNull($this->preflight(self::ALLOWED)->headers->get('Access-Control-Allow-Credentials'));
        $this->assertFalse(config('cors.supports_credentials'));
    }

    public function test_la_respuesta_real_lleva_el_origen_y_expone_retry_after(): void
    {
        config(['cors.allowed_origins' => [self::ALLOWED], 'gamification.redeem.requests_per_minute' => 1]);
        $headers = HostTokens::bearer() + ['Idempotency-Key' => 'clave-0000001', 'Origin' => self::ALLOWED];
        $this->postJson('/v1/codes/redeem', ['code' => 'x'], $headers);
        $r = $this->postJson('/v1/codes/redeem', ['code' => 'x'], $headers);

        $r->assertStatus(429);
        $this->assertSame(self::ALLOWED, $r->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('Retry-After', (string) $r->headers->get('Access-Control-Expose-Headers'), 'el navegador solo deja leer Retry-After si se expone');
    }

    public function test_un_error_de_autenticacion_tambien_lleva_cors_para_que_el_cliente_pueda_leerlo(): void
    {
        config(['cors.allowed_origins' => [self::ALLOWED]]);
        $r = $this->postJson('/v1/codes/redeem', ['code' => 'x'], ['Origin' => self::ALLOWED]);
        $r->assertStatus(401);
        $this->assertSame(self::ALLOWED, $r->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_solo_aplica_a_la_api_v1(): void
    {
        config(['cors.allowed_origins' => [self::ALLOWED]]);
        $r = $this->get('/up', ['Origin' => self::ALLOWED]);
        $this->assertNull($r->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_solo_se_permiten_los_metodos_necesarios(): void
    {
        $this->assertSame(['GET', 'POST', 'OPTIONS'], config('cors.allowed_methods'));
    }
}
