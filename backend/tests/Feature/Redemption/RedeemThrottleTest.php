<?php

namespace Tests\Feature\Redemption;

use App\Domain\Redemption\RedeemFailure;
use App\Domain\Redemption\RedeemThrottle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class RedeemThrottleTest extends TestCase
{
    private RedeemThrottle $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = new RedeemThrottle;
        Carbon::setTestNow('2026-10-20 12:00:00');
        config(['gamification.redeem' => [
            'user' => ['max_failures' => 3, 'window_seconds' => 600, 'lockout_base_seconds' => 60, 'lockout_max_seconds' => 500],
            'ip' => ['max_failures' => 5, 'window_seconds' => 600, 'lockout_base_seconds' => 120, 'lockout_max_seconds' => 3600],
            'requests_per_minute' => 60,
        ]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function failures(string $user = 'u1', ?string $ip = '1.1.1.1', int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->t->recordFailure($user, $ip);
        }
    }

    private function blockedFor(string $user = 'u1', ?string $ip = '1.1.1.1'): ?int
    {
        try {
            $this->t->assertAllowed($user, $ip);
        } catch (RedeemFailure $e) {
            $this->assertSame(RedeemFailure::RATE_LIMITED, $e->reason);

            return $e->retryAfter;
        }

        return null;
    }

    public function test_por_debajo_del_maximo_no_hay_bloqueo(): void
    {
        $this->failures(times: 2);
        $this->assertNull($this->blockedFor());
    }

    public function test_al_llegar_al_maximo_se_bloquea_por_el_tiempo_base(): void
    {
        $this->failures(times: 3);
        $this->assertSame(60, $this->blockedFor());
    }

    public function test_el_bloqueo_cuenta_regresiva_y_se_levanta(): void
    {
        $this->failures(times: 3);
        Carbon::setTestNow('2026-10-20 12:00:40');
        $this->assertSame(20, $this->blockedFor());
        Carbon::setTestNow('2026-10-20 12:01:00');
        $this->assertNull($this->blockedFor());
    }

    public function test_el_bloqueo_se_duplica_en_cada_reincidencia_hasta_el_tope(): void
    {
        $now = Carbon::now()->getTimestamp();
        $esperados = [60, 120, 240, 480, 500, 500]; // base 60, doble cada vez, tope 500
        foreach ($esperados as $i => $segundos) {
            $this->failures(times: 3);
            $this->assertSame($segundos, $this->blockedFor(), 'bloqueo número '.($i + 1));
            $now += $segundos + 1;
            Carbon::setTestNow(Carbon::createFromTimestamp($now));
        }
    }

    public function test_la_ventana_de_fallos_expira(): void
    {
        $this->failures(times: 2);
        Carbon::setTestNow('2026-10-20 12:11:00'); // pasada la ventana de 600 s
        $this->failures(times: 2); // los 2 anteriores ya no cuentan: 2 < 3
        $this->assertNull($this->blockedFor());
    }

    public function test_un_canje_exitoso_reinicia_los_fallos_pero_no_el_nivel(): void
    {
        // Sin IP: se aísla el límite por usuario (el contador de una IP compartida no se reinicia con el acierto de uno).
        $this->failures('u1', null, 2);
        $this->t->recordSuccess('u1');
        $this->failures('u1', null, 2);
        $this->assertNull($this->blockedFor('u1', null), 'los fallos se reiniciaron con el acierto');

        // El nivel de reincidencia se conserva: tras un primer bloqueo, el segundo dura el doble aunque haya aciertos en medio.
        $this->failures('u1', null, 1);
        $this->assertSame(60, $this->blockedFor('u1', null));
        Carbon::setTestNow('2026-10-20 12:02:00');
        $this->t->recordSuccess('u1');
        $this->failures('u1', null, 3);
        $this->assertSame(120, $this->blockedFor('u1', null));
    }

    public function test_un_acierto_no_reinicia_el_contador_de_la_ip(): void
    {
        // Una IP compartida (oficina, red móvil): que un usuario acierte no borra los intentos de quien está probando códigos.
        $this->failures('atacante', '5.5.5.5', 4);
        $this->t->recordSuccess('otro-usuario-legitimo');
        $this->failures('atacante2', '5.5.5.5', 1);
        $this->assertSame(120, $this->blockedFor('cualquiera', '5.5.5.5'));
    }

    public function test_usuarios_distintos_son_independientes(): void
    {
        $this->failures('ana', '1.1.1.1', 3);
        $this->assertNotNull($this->blockedFor('ana', '9.9.9.9'));
        $this->assertNull($this->blockedFor('beto', '9.9.9.9'));
    }

    public function test_la_ip_tiene_su_propio_umbral_mas_alto(): void
    {
        // 4 usuarios distintos desde la misma IP, 1 fallo cada uno: ninguno se bloquea por usuario, pero la IP llega a 5.
        foreach (['a', 'b', 'c', 'd'] as $u) {
            $this->failures($u, '2.2.2.2');
        }
        $this->assertNull($this->blockedFor('e', '2.2.2.2'));
        $this->failures('e', '2.2.2.2');
        $this->assertSame(120, $this->blockedFor('f', '2.2.2.2'), 'la IP quedó bloqueada: afecta incluso a un usuario nuevo');
        $this->assertNull($this->blockedFor('f', '3.3.3.3'), 'desde otra IP, no');
    }

    public function test_sin_ip_solo_se_aplica_el_limite_por_usuario(): void
    {
        $this->failures('solo', null, 3);
        $this->assertSame(60, $this->blockedFor('solo', null));
        $this->assertNull($this->blockedFor('otro', null));
    }

    public function test_se_informa_el_mayor_de_los_bloqueos_aplicables(): void
    {
        $this->failures('ana', '4.4.4.4', 3);       // usuario: 60 s
        $this->failures('x1', '4.4.4.4', 2);         // IP: 3 + 2 = 5 fallos -> 120 s
        $this->assertSame(120, $this->blockedFor('ana', '4.4.4.4'));
    }

    public function test_la_cache_no_guarda_identificadores_en_claro(): void
    {
        $this->failures('host-a:persona-con-nombre', '203.0.113.77', 3);
        $keys = array_keys($this->arrayStoreContents());
        $this->assertNotEmpty($keys);
        foreach ($keys as $k) {
            $this->assertStringNotContainsString('persona-con-nombre', $k);
            $this->assertStringNotContainsString('203.0.113.77', $k);
        }
    }

    /** @return array<string, mixed> */
    private function arrayStoreContents(): array
    {
        $store = Cache::getStore();
        $prop = new \ReflectionProperty($store, 'storage');

        return $prop->getValue($store);
    }
}
