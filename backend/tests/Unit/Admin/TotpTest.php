<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\Totp;
use PHPUnit\Framework\TestCase;

/** TOTP/HOTP contra los VECTORES OFICIALES de los RFC: es lo que demuestra que la implementación propia es correcta. */
final class TotpTest extends TestCase
{
    private const RFC_SECRET = '12345678901234567890'; // Apéndice B de RFC 6238 y Apéndice D de RFC 4226 (SHA-1)

    public function test_rfc4226_apendice_d_hotp_de_6_digitos(): void
    {
        $esperado = ['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'];
        foreach ($esperado as $counter => $code) {
            $this->assertSame($code, Totp::code(self::RFC_SECRET, $counter), "contador $counter");
        }
    }

    public function test_rfc6238_apendice_b_totp_sha1_de_8_digitos(): void
    {
        $vectores = [59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'];
        foreach ($vectores as $time => $code) {
            $this->assertSame($code, Totp::code(self::RFC_SECRET, Totp::step($time), 8), "t=$time");
        }
    }

    public function test_acepta_el_codigo_vigente_y_devuelve_su_paso(): void
    {
        $now = 1_700_000_000;
        $code = Totp::code(self::RFC_SECRET, Totp::step($now));
        $this->assertSame(Totp::step($now), Totp::verify(self::RFC_SECRET, $code, $now, null));
    }

    public function test_tolera_un_paso_de_desfase_en_cada_sentido_pero_no_dos(): void
    {
        $now = 1_700_000_000;
        $step = Totp::step($now);
        foreach ([-1, 1] as $d) {
            $this->assertSame($step + $d, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + $d), $now, null), "desfase $d");
        }
        foreach ([-2, 2] as $d) {
            $this->assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + $d), $now, null), "desfase $d");
        }
    }

    public function test_un_paso_ya_usado_no_se_acepta_de_nuevo_ni_uno_anterior(): void
    {
        $now = 1_700_000_000;
        $step = Totp::step($now);
        $code = Totp::code(self::RFC_SECRET, $step);
        $this->assertNull(Totp::verify(self::RFC_SECRET, $code, $now, $step), 'mismo paso');
        $this->assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 1), $now, $step), 'paso anterior al último usado');
        $this->assertSame($step + 1, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + 1), $now, $step), 'uno posterior sí');
    }

    public function test_rechaza_formatos_que_no_son_seis_digitos(): void
    {
        foreach (['', '12345', '1234567', 'abcdef', '12 456', '１２３４５６', " 123456", "123456\n"] as $bad) {
            $this->assertNull(Totp::verify(self::RFC_SECRET, $bad, 1_700_000_000, null), json_encode($bad));
        }
    }

    public function test_un_codigo_de_otro_secreto_no_vale(): void
    {
        $now = 1_700_000_000;
        $this->assertNull(Totp::verify('otro-secreto-distinto!!', Totp::code(self::RFC_SECRET, Totp::step($now)), $now, null));
    }

    public function test_el_secreto_nuevo_tiene_160_bits_y_la_uri_es_la_que_leen_las_apps(): void
    {
        $this->assertSame(20, strlen(Totp::newSecret()));
        $this->assertNotSame(Totp::newSecret(), Totp::newSecret());
        $uri = Totp::uri('12345678901234567890', 'ana@ejemplo.com', 'Gamificacion');
        $this->assertSame('otpauth://totp/Gamificacion%3Aana%40ejemplo.com?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&issuer=Gamificacion&algorithm=SHA1&digits=6&period=30', $uri);
    }
}
