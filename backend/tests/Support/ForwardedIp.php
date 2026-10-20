<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Cache;

/** Ayudas para comprobar con qué IP se cuenta el límite de intentos (ver las pruebas de proxies de confianza). */
trait ForwardedIp
{
    private const CLIENT = '203.0.113.9';

    private const PROXY = '10.1.2.3';

    /** Un canje con código inválido (cuenta un fallo contra la IP) llegando desde $remote con X-Forwarded-For. */
    private function failFrom(string $remote, string $forwarded): void
    {
        HostTokens::configure();
        $this->withServerVariables(['REMOTE_ADDR' => $remote])
            ->postJson('/v1/codes/redeem', ['code' => 'NOPE'], HostTokens::bearer('ana') + ['Idempotency-Key' => 'clave-0000001', 'X-Forwarded-For' => $forwarded])
            ->assertStatus(422);
    }

    private function failuresFor(string $ip): int
    {
        return (int) Cache::get('gamif:throttle:fail:ip:'.hash('sha256', $ip), 0);
    }
}
