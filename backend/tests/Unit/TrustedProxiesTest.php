<?php

namespace Tests\Unit;

use App\Support\TrustedProxies;
use PHPUnit\Framework\TestCase;

final class TrustedProxiesTest extends TestCase
{
    public function test_en_produccion_vacia_es_un_error_que_dice_que_hacer(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GAMIF_TRUSTED_PROXIES');
        TrustedProxies::resolve('', 'production');
    }

    public function test_none_es_la_confirmacion_explicita_de_que_no_hay_proxy(): void
    {
        $this->assertNull(TrustedProxies::resolve('none', 'production'));
    }

    public function test_el_comodin_en_produccion_exige_confirmacion(): void
    {
        $this->assertSame('*', TrustedProxies::resolve('*', 'production', true));
        $this->expectException(\RuntimeException::class);
        TrustedProxies::resolve('*', 'production');
    }

    public function test_fuera_de_produccion_vacia_no_confia_en_nadie_y_una_lista_se_respeta(): void
    {
        $this->assertNull(TrustedProxies::resolve('', 'local'));
        $this->assertSame(['10.0.0.0/8', '172.16.0.0/12'], TrustedProxies::resolve(' 10.0.0.0/8 , 172.16.0.0/12 ', 'production'));
        $this->assertSame('*', TrustedProxies::resolve('*', 'local'));
    }
}
