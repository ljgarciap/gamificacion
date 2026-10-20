<?php

namespace Tests\Feature\Api;

use Tests\DbTestCase;
use Tests\Support\ForwardedIp;

/** Sin proxies de confianza (el valor por defecto), X-Forwarded-For se ignora: cualquiera podría falsear su IP para esquivar el bloqueo. */
final class WithoutTrustedProxiesTest extends DbTestCase
{
    use ForwardedIp;

    public function test_sin_proxies_de_confianza_se_ignora_x_forwarded_for(): void
    {
        $this->failFrom(self::PROXY, self::CLIENT);

        $this->assertSame(1, $this->failuresFor(self::PROXY), 'se cuenta contra quien realmente se conectó');
        $this->assertSame(0, $this->failuresFor(self::CLIENT), 'una IP declarada por el cliente no vale');
    }
}
