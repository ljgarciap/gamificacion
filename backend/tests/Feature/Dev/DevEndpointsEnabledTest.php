<?php

namespace Tests\Feature\Dev;

use App\Domain\Auth\HostTokenVerifier;
use App\Domain\Dev\DemoCodes;
use Illuminate\Support\Facades\File;
use Tests\DbTestCase;
use Tests\Support\HostTokens;
use Tests\Support\RedeemFixtures;

final class DevEndpointsEnabledTest extends DbTestCase
{
    private string $storage;

    protected function extraEnv(): array
    {
        return ['GAMIF_DEV_ENDPOINTS' => 'true'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['gamification.hosts' => ['standalone-dev' => ['alg' => 'HS256', 'key' => base64_encode(HostTokens::SECRET)]]]);
        config(['gamification.token.audience' => 'gamification']);

        // Los códigos de demostración se guardan en storage/app: se redirige a una carpeta temporal para no tocar la real.
        $this->storage = sys_get_temp_dir().'/dev-endpoints-'.bin2hex(random_bytes(4));
        mkdir($this->storage.'/app', 0775, true);
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    // --- Token ---------------------------------------------------------------------------------------------------------

    public function test_emite_un_token_corto_que_el_verificador_real_acepta(): void
    {
        $r = $this->postJson('/v1/dev/token', ['userId' => 'maria']);
        $r->assertOk()->assertJsonPath('expiresIn', 300)->assertJsonPath('userId', 'standalone-dev:maria');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));

        $user = HostTokenVerifier::fromConfig()->verify($r->json('token'));
        $this->assertSame('standalone-dev:maria', $user->id());
    }

    public function test_el_token_de_desarrollo_sirve_en_la_api_real(): void
    {
        config(['gamification.demo.initial_balance' => 120000]);
        $token = $this->postJson('/v1/dev/token')->json('token'); // userId por defecto: demo-user
        $this->getJson('/v1/balance', ['Authorization' => "Bearer $token"])->assertOk()->assertJsonPath('amount', 120000);
    }

    public function test_userId_invalido(): void
    {
        foreach (['', 'con espacio', str_repeat('x', 129), 'ñandú', "salto\nlinea"] as $bad) {
            $this->postJson('/v1/dev/token', ['userId' => $bad])->assertStatus(422)->assertJsonPath('error.code', 'invalidUserId');
        }
        $this->postJson('/v1/dev/token', ['userId' => ['a']])->assertStatus(422);
        $this->postJson('/v1/dev/token', ['userId' => 123])->assertStatus(422);
    }

    public function test_sin_emisor_de_desarrollo_configurado_responde_503_claro(): void
    {
        config(['gamification.hosts' => []]);
        $this->postJson('/v1/dev/token')->assertStatus(503)->assertJsonPath('error.code', 'devIssuerNotConfigured');

        config(['gamification.hosts' => ['standalone-dev' => ['alg' => 'RS256', 'key' => 'pem']]]);
        $this->postJson('/v1/dev/token')->assertStatus(503);
    }

    public function test_un_token_de_desarrollo_para_otro_emisor_no_se_puede_pedir(): void
    {
        // No hay forma de elegir el emisor desde la petición: sale de la configuración.
        $token = $this->postJson('/v1/dev/token', ['userId' => 'x', 'iss' => 'host-a', 'issuer' => 'host-a'])->json('token');
        $this->assertSame('standalone-dev:x', HostTokenVerifier::fromConfig()->verify($token)->id());
    }

    // --- Códigos de demostración ---------------------------------------------------------------------------------------

    public function test_sin_archivo_no_hay_codigos(): void
    {
        $this->getJson('/v1/dev/demo-codes')->assertOk()->assertExactJson(['codes' => []]);
    }

    public function test_lista_solo_los_codigos_que_todavia_se_pueden_canjear_y_no_filtra_datos_internos(): void
    {
        $rf = new RedeemFixtures;
        $b = $rf->activeBatch();
        DemoCodes::save([
            ['code' => $b['codes'][0], 'game' => 'roulette', 'description' => 'Ruleta: A', 'batch_id' => $b['batch']],
            ['code' => $b['codes'][1], 'game' => 'roulette', 'description' => 'Ruleta: B', 'batch_id' => $b['batch']],
            ['code' => RedeemFixtures::unknownCode('inexistente'), 'game' => 'roulette', 'description' => 'No existe', 'batch_id' => $b['batch']],
            ['code' => 'basura', 'game' => 'roulette', 'description' => 'Mal formado', 'batch_id' => $b['batch']],
        ]);

        $r = $this->getJson('/v1/dev/demo-codes')->assertOk();
        $this->assertSame([$b['codes'][0], $b['codes'][1]], array_column($r->json('codes'), 'code'));
        $this->assertSame(['code', 'description', 'game'], array_keys($r->json('codes.0')) === ['code', 'game', 'description'] ? ['code', 'description', 'game'] : []);
        $this->assertArrayNotHasKey('batch_id', $r->json('codes.0'));

        // Al canjear uno, desaparece de la lista.
        $rf->service()->redeem('host-a:ana', 'clave-0000001', $b['codes'][0]);
        $this->assertSame([$b['codes'][1]], array_column($this->getJson('/v1/dev/demo-codes')->json('codes'), 'code'));

        // Y al cerrar el lote, ya ninguno.
        $rf->f->lifecycle()->close($b['batch'], 'tester');
        $this->assertSame([], $this->getJson('/v1/dev/demo-codes')->json('codes'));
    }

    public function test_un_archivo_corrupto_no_rompe_la_respuesta(): void
    {
        file_put_contents(DemoCodes::path(), '{esto no es json');
        $this->getJson('/v1/dev/demo-codes')->assertOk()->assertExactJson(['codes' => []]);
    }
}
