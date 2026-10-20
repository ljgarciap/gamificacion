<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\HostTokenVerifier;
use App\Domain\Auth\HostUser;
use App\Domain\Auth\TokenInvalid;
use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;
use Tests\Support\HostTokens;
use Tests\TestCase;

final class HostTokenVerifierTest extends TestCase
{
    private function verifier(): HostTokenVerifier
    {
        return HostTokenVerifier::fromConfig();
    }

    protected function setUp(): void
    {
        parent::setUp();
        HostTokens::configure();
        Carbon::setTestNow('2026-10-20 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rejects(string $jwt, string $fragment): void
    {
        try {
            $this->verifier()->verify($jwt);
            $this->fail("debía rechazar el token (esperaba «$fragment»)");
        } catch (TokenInvalid $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    /** Un par de claves RSA/EC de prueba. @return array{private: string, public: string} */
    private function keypair(string $type): array
    {
        $res = openssl_pkey_new($type === 'rsa'
            ? ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]
            : ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($res, $private);

        return ['private' => $private, 'public' => openssl_pkey_get_details($res)['key']];
    }

    // --- Camino feliz -----------------------------------------------------------------------------------------------

    public function test_un_token_valido_identifica_al_usuario_prefijado_con_su_emisor(): void
    {
        $user = $this->verifier()->verify(HostTokens::make('persona-42'));
        $this->assertInstanceOf(HostUser::class, $user);
        $this->assertSame('host-a', $user->issuer);
        $this->assertSame('persona-42', $user->subject);
        $this->assertSame('host-a:persona-42', $user->id());
    }

    public function test_la_audiencia_puede_ser_una_lista(): void
    {
        $this->assertSame('host-a:u', $this->verifier()->verify(HostTokens::make('u', ['aud' => ['otro', 'gamification']]))->id());
    }

    public function test_acepta_rs256_y_es256_cuando_el_emisor_los_configura(): void
    {
        $rsa = $this->keypair('rsa');
        $ec = $this->keypair('ec');
        HostTokens::configure([
            'host-rsa' => ['alg' => 'RS256', 'key' => $rsa['public']],
            'host-ec' => ['alg' => 'ES256', 'key' => $ec['public']],
        ]);

        $now = Carbon::now()->getTimestamp();
        $claims = fn (string $iss) => ['iss' => $iss, 'sub' => 'u1', 'aud' => 'gamification', 'iat' => $now, 'exp' => $now + 300];

        $this->assertSame('host-rsa:u1', $this->verifier()->verify(JWT::encode($claims('host-rsa'), $rsa['private'], 'RS256'))->id());
        $this->assertSame('host-ec:u1', $this->verifier()->verify(JWT::encode($claims('host-ec'), $ec['private'], 'ES256'))->id());
    }

    public function test_dos_emisores_no_comparten_usuarios_aunque_tengan_el_mismo_sujeto(): void
    {
        HostTokens::configure(['host-b' => ['alg' => 'HS256', 'key' => base64_encode(str_repeat('b', 32))]]);
        $a = $this->verifier()->verify(HostTokens::make('juan'))->id();
        $b = $this->verifier()->verify(HostTokens::make('juan', ['iss' => 'host-b'], str_repeat('b', 32)))->id();
        $this->assertNotSame($a, $b);
    }

    // --- Tiempo -------------------------------------------------------------------------------------------------------

    public function test_un_token_vencido_se_rechaza_pero_se_tolera_el_desfase_de_reloj(): void
    {
        $now = Carbon::now()->getTimestamp();
        $this->rejects(HostTokens::make('u', ['iat' => $now - 400, 'exp' => $now - 100]), 'token rechazado');
        $this->assertSame('host-a:u', $this->verifier()->verify(HostTokens::make('u', ['iat' => $now - 400, 'exp' => $now - 10]))->id(), 'dentro de los 30 s de tolerancia');
    }

    public function test_un_token_emitido_en_el_futuro_se_rechaza(): void
    {
        $now = Carbon::now()->getTimestamp();
        $this->rejects(HostTokens::make('u', ['iat' => $now + 120, 'exp' => $now + 400]), 'token rechazado');
    }

    public function test_la_vida_del_token_esta_acotada(): void
    {
        $now = Carbon::now()->getTimestamp();
        $this->rejects(HostTokens::make('u', ['iat' => $now, 'exp' => $now + 601]), 'vida del token');
        $this->assertSame('host-a:u', $this->verifier()->verify(HostTokens::make('u', ['iat' => $now, 'exp' => $now + 600]))->id());
        $this->rejects(HostTokens::make('u', ['iat' => $now, 'exp' => $now]), 'vida del token'); // exp == iat: vida cero
    }

    // --- Claims obligatorios ---------------------------------------------------------------------------------------------

    public function test_faltan_claims_obligatorios(): void
    {
        $this->rejects(HostTokens::make('u', ['exp' => null]), 'iat y exp');
        $this->rejects(HostTokens::make('u', ['iat' => null]), 'iat y exp');
        $this->rejects(HostTokens::make('u', ['sub' => null]), 'sub');
        $this->rejects(HostTokens::make('u', ['aud' => null]), 'audiencia');
    }

    public function test_sujeto_invalido(): void
    {
        foreach (['', str_repeat('x', 129), 'con espacio', "con\nsalto", 'ñandú'] as $bad) {
            $this->rejects(HostTokens::make($bad), 'sub');
        }
    }

    public function test_audiencia_incorrecta(): void
    {
        $this->rejects(HostTokens::make('u', ['aud' => 'otra-app']), 'audiencia');
        $this->rejects(HostTokens::make('u', ['aud' => ['a', 'b']]), 'audiencia');
    }

    public function test_exp_e_iat_deben_ser_enteros(): void
    {
        $now = Carbon::now()->getTimestamp();
        $this->rejects(HostTokens::make('u', ['exp' => (string) ($now + 300)]), 'iat y exp');
    }

    // --- Emisor y firma ---------------------------------------------------------------------------------------------------

    public function test_un_emisor_desconocido_se_rechaza(): void
    {
        $this->rejects(HostTokens::make('u', ['iss' => 'intruso']), 'emisor desconocido');
        $this->rejects(HostTokens::make('u', ['iss' => null]), 'sin emisor');
    }

    public function test_una_firma_incorrecta_se_rechaza(): void
    {
        $this->rejects(HostTokens::make('u', [], str_repeat('x', 32)), 'token rechazado');
    }

    public function test_un_payload_alterado_se_rechaza(): void
    {
        [$h, $p, $s] = explode('.', HostTokens::make('u'));
        $evil = rtrim(strtr(base64_encode(json_encode(['iss' => 'host-a', 'sub' => 'administrador', 'aud' => 'gamification', 'iat' => Carbon::now()->getTimestamp(), 'exp' => Carbon::now()->getTimestamp() + 300])), '+/', '-_'), '=');
        $this->rejects("$h.$evil.$s", 'token rechazado');
        $this->assertNotSame($p, $evil);
    }

    public function test_alg_none_se_rechaza(): void
    {
        $now = Carbon::now()->getTimestamp();
        $b64 = fn (array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
        $jwt = $b64(['alg' => 'none', 'typ' => 'JWT']).'.'.$b64(['iss' => 'host-a', 'sub' => 'u', 'aud' => 'gamification', 'iat' => $now, 'exp' => $now + 300]).'.';
        $this->rejects($jwt, 'token rechazado');
    }

    public function test_confusion_de_algoritmo_rs256_a_hs256_se_rechaza(): void
    {
        // Ataque clásico: el emisor usa RS256; el atacante firma con HS256 usando la CLAVE PÚBLICA como secreto.
        $rsa = $this->keypair('rsa');
        HostTokens::configure(['host-rsa' => ['alg' => 'RS256', 'key' => $rsa['public']]]);
        $now = Carbon::now()->getTimestamp();
        $forged = JWT::encode(['iss' => 'host-rsa', 'sub' => 'admin', 'aud' => 'gamification', 'iat' => $now, 'exp' => $now + 300], $rsa['public'], 'HS256');
        $this->rejects($forged, 'token rechazado');
    }

    public function test_el_algoritmo_del_encabezado_no_manda_aunque_la_firma_sea_valida_para_otro(): void
    {
        // El emisor está configurado como HS256 con un secreto de 64 bytes. Un token HS512 firmado con ESE MISMO secreto tiene una
        // firma criptográficamente válida, pero el algoritmo lo fija la configuración, no el token: se rechaza.
        $secret = str_repeat('k', 64);
        HostTokens::configure(['host-largo' => ['alg' => 'HS256', 'key' => base64_encode($secret)]]);
        $now = Carbon::now()->getTimestamp();
        $claims = ['iss' => 'host-largo', 'sub' => 'u', 'aud' => 'gamification', 'iat' => $now, 'exp' => $now + 300];

        $this->assertSame('host-largo:u', $this->verifier()->verify(JWT::encode($claims, $secret, 'HS256'))->id(), 'control: con HS256 sí entra');
        $this->rejects(JWT::encode($claims, $secret, 'HS512'), 'token rechazado');
    }

    public function test_formatos_rotos(): void
    {
        foreach (['', 'abc', 'a.b', 'a.b.c.d', '...', 'eyJ.eyJ.sig', 'a..c'] as $bad) {
            try {
                $this->verifier()->verify($bad);
                $this->fail("«$bad» debía rechazarse");
            } catch (TokenInvalid) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_configuracion_incompleta_de_un_emisor_se_rechaza_sin_romperse(): void
    {
        HostTokens::configure([
            'sin-clave' => ['alg' => 'HS256'],
            'alg-raro' => ['alg' => 'none', 'key' => 'x'],
            'clave-mala' => ['alg' => 'HS256', 'key' => '***no base64***'],
            'vacio' => [],
        ]);
        $esperado = ['sin-clave' => 'configuración', 'alg-raro' => 'configuración', 'clave-mala' => 'clave del emisor', 'vacio' => 'configuración'];
        foreach ($esperado as $iss => $fragment) {
            $this->rejects(HostTokens::make('u', ['iss' => $iss]), $fragment);
        }
    }

    public function test_una_clave_hs256_demasiado_corta_se_rechaza(): void
    {
        // La biblioteca se niega a verificar con una clave HS256 de menos de 32 bytes: un secreto débil no se acepta en silencio.
        HostTokens::configure(['host-corto' => ['alg' => 'HS256', 'key' => base64_encode('corta')]]);
        $this->rejects(HostTokens::make('u', ['iss' => 'host-corto']), 'token rechazado');
    }
}
