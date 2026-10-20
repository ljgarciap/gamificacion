<?php

namespace Tests\Feature\Api;

use Tests\DbTestCase;
use Tests\Support\ForwardedIp;

/** Con el balanceador configurado, el límite de intentos cuenta la IP del cliente real (si no, todos compartirían la del proxy). */
final class WithTrustedProxiesTest extends DbTestCase
{
    use ForwardedIp;

    protected function extraEnv(): array
    {
        return ['GAMIF_TRUSTED_PROXIES' => '10.0.0.0/8'];
    }

    public function test_desde_un_proxy_de_confianza_se_usa_la_ip_del_cliente(): void
    {
        $this->failFrom(self::PROXY, self::CLIENT);

        $this->assertSame(1, $this->failuresFor(self::CLIENT));
        $this->assertSame(0, $this->failuresFor(self::PROXY), 'la IP del balanceador no se acumula');
    }

    public function test_desde_fuera_del_proxy_x_forwarded_for_sigue_sin_valer(): void
    {
        $this->failFrom('198.51.100.7', self::CLIENT);

        $this->assertSame(1, $this->failuresFor('198.51.100.7'));
        $this->assertSame(0, $this->failuresFor(self::CLIENT));
    }
}
