<?php

namespace Tests\Feature\Beacon;

use App\Domain\Beacon\BeaconUnavailable;
use App\Domain\Beacon\DrandBeacon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cliente de drand contra respuestas simuladas, con los valores y comportamientos comprobados contra la API real
 * (quicknet: génesis 1692803367, una ronda cada 3 s; una ronda futura responde HTTP 425).
 */
final class DrandBeaconTest extends TestCase
{
    private const CHAIN = '52db9ba70e0cc0f6eaf7803dd07447a1f5477735fd3f661792ba94600c84e971';

    private const GENESIS = 1692803367;

    private function beacon(): DrandBeacon
    {
        return new DrandBeacon('https://api.test', self::CHAIN, 5);
    }

    private function info(array $override = []): array
    {
        return $override + ['hash' => self::CHAIN, 'genesis_time' => self::GENESIS, 'period' => 3];
    }

    /** Una respuesta de ronda coherente: randomness = SHA-256(signature). */
    private function roundBody(int $round, ?string $signatureHex = null): array
    {
        $sig = $signatureHex ?? bin2hex(random_bytes(48));

        return ['round' => $round, 'randomness' => hash('sha256', hex2bin($sig)), 'signature' => $sig];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_el_hash_de_cadena_debe_ser_hex_de_64(): void
    {
        foreach (['', 'abc', strtoupper(self::CHAIN), self::CHAIN.'00'] as $bad) {
            try {
                new DrandBeacon('https://x', $bad);
                $this->fail("debía rechazar «$bad»");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_chain_hash_en_binario(): void
    {
        $this->assertSame(hex2bin(self::CHAIN), $this->beacon()->chainHash());
    }

    public function test_la_ronda_es_la_primera_estrictamente_posterior_al_instante(): void
    {
        Http::fake(['*/info' => Http::response($this->info())]);
        $b = $this->beacon();
        $at = fn (int $secondsAfterGenesis) => new \DateTimeImmutable('@'.(self::GENESIS + $secondsAfterGenesis));

        // La ronda r se emite en génesis + (r-1)*3. En +30 se emite la 11; la primera POSTERIOR es la 12.
        $this->assertSame(12, $b->roundAfter($at(30)));
        $this->assertSame(12, $b->roundAfter($at(31)));
        $this->assertSame(12, $b->roundAfter($at(32)));
        $this->assertSame(13, $b->roundAfter($at(33)), 'en +33 se emite la 12: la primera posterior es la 13');
        $this->assertSame(2, $b->roundAfter($at(0)), 'la ronda 1 se emite en el génesis mismo');
    }

    public function test_una_hora_anterior_al_genesis_es_un_error(): void
    {
        Http::fake(['*/info' => Http::response($this->info())]);
        $this->expectException(BeaconUnavailable::class);
        $this->beacon()->roundAfter(new \DateTimeImmutable('@'.(self::GENESIS - 1)));
    }

    public function test_la_informacion_de_la_cadena_se_consulta_una_sola_vez(): void
    {
        Http::fake(['*/info' => Http::response($this->info())]);
        $b = $this->beacon();
        $b->roundAfter(new \DateTimeImmutable('@'.(self::GENESIS + 100)));
        $b->roundAfter(new \DateTimeImmutable('@'.(self::GENESIS + 200)));
        Http::assertSentCount(1);
    }

    public function test_se_rechaza_una_respuesta_de_otra_cadena(): void
    {
        Http::fake(['*/info' => Http::response($this->info(['hash' => str_repeat('0', 64)]))]);
        $this->expectException(BeaconUnavailable::class);
        $this->beacon()->roundAfter(new \DateTimeImmutable('@'.(self::GENESIS + 100)));
    }

    public function test_se_rechaza_una_info_mal_formada(): void
    {
        foreach ([$this->info(['period' => 0]), $this->info(['period' => '3']), $this->info(['genesis_time' => null])] as $bad) {
            Http::fake(['*/info' => Http::response($bad)]);
            try {
                $this->beacon()->roundAfter(new \DateTimeImmutable('@'.(self::GENESIS + 100)));
                $this->fail('info mal formada aceptada');
            } catch (BeaconUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // --- Valor de una ronda --------------------------------------------------------------------------------------

    public function test_devuelve_el_valor_de_una_ronda_valida_en_binario(): void
    {
        $body = $this->roundBody(777);
        Http::fake(['*/public/777' => Http::response($body)]);

        $value = $this->beacon()->valueForRound(777);
        $this->assertSame(hex2bin($body['randomness']), $value);
        $this->assertSame(32, strlen($value));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.test/'.self::CHAIN.'/public/777');
    }

    public function test_una_ronda_futura_425_o_inexistente_404_es_todavia_no(): void
    {
        foreach ([425, 404] as $status) {
            Http::fake(['*/public/*' => Http::response('Requested future beacon', $status)]);
            $this->assertNull($this->beacon()->valueForRound(999), "HTTP $status");
        }
    }

    public function test_un_425_no_se_reintenta(): void
    {
        Http::fake(['*/public/*' => Http::response('', 425)]);
        $this->beacon()->valueForRound(999);
        Http::assertSentCount(1);
    }

    public function test_un_500_transitorio_se_reintenta_y_la_ronda_se_obtiene(): void
    {
        // Caso real observado: un 500 aislado de drand en medio de la espera. No debe abortar un proceso paciente.
        $body = $this->roundBody(42);
        Http::fake(['*/public/42' => Http::sequence()->push('boom', 500)->push('boom', 503)->push($body, 200)]);

        $this->assertSame(hex2bin($body['randomness']), $this->beacon()->valueForRound(42));
        Http::assertSentCount(3);
    }

    public function test_un_5xx_persistente_se_reintenta_un_numero_acotado_de_veces_y_luego_falla(): void
    {
        Http::fake(['*/public/*' => Http::response('boom', 500)]);
        try {
            $this->beacon()->valueForRound(42);
            $this->fail('debía fallar tras los reintentos');
        } catch (BeaconUnavailable) {
            Http::assertSentCount(3);
        }
    }

    public function test_los_errores_4xx_distintos_de_404_y_425_no_se_reintentan(): void
    {
        foreach ([401, 403, 429] as $status) {
            Http::fake(['*/public/*' => Http::response('no', $status)]);
            try {
                $this->beacon()->valueForRound(42);
                $this->fail("HTTP $status debía fallar");
            } catch (BeaconUnavailable) {
                Http::assertSentCount(1);
            }
        }
    }

    public function test_un_error_del_servidor_es_baliza_no_disponible(): void
    {
        foreach ([500, 502, 503, 429, 401] as $status) {
            Http::fake(['*/public/*' => Http::response('boom', $status)]);
            try {
                $this->beacon()->valueForRound(5);
                $this->fail("HTTP $status debía ser un error");
            } catch (BeaconUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_respuestas_incoherentes_se_rechazan(): void
    {
        $good = $this->roundBody(5);
        $casos = [
            'ronda distinta a la pedida' => [...$good, 'round' => 6],
            'randomness que no es SHA-256 de la firma' => [...$good, 'randomness' => str_repeat('a', 64)],
            'randomness de largo incorrecto' => [...$good, 'randomness' => 'abcd'],
            'randomness con mayúsculas' => [...$good, 'randomness' => strtoupper($good['randomness'])],
            'firma no hex' => [...$good, 'signature' => 'zzzz'],
            'sin firma' => ['round' => 5, 'randomness' => $good['randomness']],
            'ronda como texto' => [...$good, 'round' => '5'],
        ];
        foreach ($casos as $nombre => $body) {
            Http::fake(['*/public/5' => Http::response($body)]);
            try {
                $this->beacon()->valueForRound(5);
                $this->fail("debía rechazar: $nombre");
            } catch (BeaconUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_un_fallo_de_conexion_es_baliza_no_disponible(): void
    {
        Http::fake(function () {
            throw new ConnectionException('sin red');
        });
        $this->expectException(BeaconUnavailable::class);
        $this->beacon()->valueForRound(5);
    }

    public function test_ronda_invalida(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->beacon()->valueForRound(0);
    }
}
