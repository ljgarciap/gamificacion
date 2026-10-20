<?php

namespace Tests\Unit\Domain;

use App\Domain\Codes\CodeFormat;
use App\Domain\Codes\InvalidCodeFormat;
use App\Domain\Crypto\CodeHasher;
use PHPUnit\Framework\TestCase;

final class CodeFormatTest extends TestCase
{
    /** Flujo de bytes determinista para pruebas reproducibles (no es el generador de producción). */
    private static function stream(string $label, int $i): string
    {
        return substr(hash_hmac('sha256', (string) $i, $label, true), 0, CodeFormat::BYTES_NEEDED);
    }

    private static function bytesFor(int $group, int $value): string
    {
        $n = $value << (56 - 5 * ($group + 1));
        $b = '';
        for ($i = 6; $i >= 0; $i--) {
            $b .= chr(($n >> (8 * $i)) & 0xFF);
        }

        return $b;
    }

    /** Implementación de referencia, escrita aparte y a partir de la definición (Luhn mod N). */
    private static function referenceCheck(string $payload): string
    {
        $alphabet = CodeFormat::ALPHABET;
        $n = strlen($alphabet);
        $factor = 2;
        $sum = 0;
        foreach (array_reverse(str_split($payload)) as $ch) {
            $addend = $factor * strpos($alphabet, $ch);
            $factor = $factor === 2 ? 1 : 2;
            $sum += intdiv($addend, $n) + $addend % $n;
        }

        return $alphabet[($n - $sum % $n) % $n];
    }

    // --- Alfabeto -------------------------------------------------------------------------------------------------

    public function test_el_alfabeto_tiene_32_simbolos_unicos_y_sin_los_ambiguos(): void
    {
        $a = CodeFormat::ALPHABET;
        $this->assertSame(32, strlen($a));
        $this->assertSame(32, count(array_unique(str_split($a))));
        foreach (['I', 'L', 'O', 'U'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $a);
        }
    }

    public function test_diez_simbolos_de_32_son_exactamente_50_bits(): void
    {
        $this->assertSame(50.0, CodeFormat::PAYLOAD_LENGTH * log(strlen(CodeFormat::ALPHABET), 2), 'RF-21 exige al menos 50 bits');
    }

    // --- De bytes a símbolos --------------------------------------------------------------------------------------

    public function test_cada_grupo_de_5_bits_se_convierte_al_simbolo_correcto(): void
    {
        for ($g = 0; $g < 10; $g++) {
            for ($v = 0; $v < 32; $v++) {
                $expected = str_repeat('0', 10);
                $expected[$g] = CodeFormat::ALPHABET[$v];
                $this->assertSame($expected, CodeFormat::payloadFromBytes(self::bytesFor($g, $v)), "grupo $g valor $v");
            }
        }
    }

    public function test_los_6_bits_sobrantes_no_afectan_al_resultado(): void
    {
        $base = "\x12\x34\x56\x78\x9A\xBC\xC0";
        foreach ([0x00, 0x01, 0x20, 0x3F] as $low) {
            $b = $base;
            $b[6] = chr((ord($base[6]) & 0xC0) | $low);
            $this->assertSame(CodeFormat::payloadFromBytes($base), CodeFormat::payloadFromBytes($b));
        }
    }

    public function test_se_exigen_siete_bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CodeFormat::payloadFromBytes("\x00\x00\x00\x00\x00\x00");
    }

    public function test_los_simbolos_salen_uniformes_por_posicion_y_en_total(): void
    {
        // 20.000 códigos con un flujo de bytes fijo. chi-cuadrado con 31 grados de libertad; el umbral (80, p ≈ 1e-7) se fijó antes de medir.
        $runs = 20_000;
        $total = array_fill(0, 32, 0);
        $first = array_fill(0, 32, 0);
        $last = array_fill(0, 32, 0);
        for ($i = 0; $i < $runs; $i++) {
            $p = CodeFormat::payloadFromBytes(self::stream('uniformidad', $i));
            foreach (str_split($p) as $ch) {
                $total[strpos(CodeFormat::ALPHABET, $ch)]++;
            }
            $first[strpos(CodeFormat::ALPHABET, $p[0])]++;
            $last[strpos(CodeFormat::ALPHABET, $p[9])]++;
        }
        foreach (['total' => [$total, $runs * 10], 'primera posición' => [$first, $runs], 'última posición' => [$last, $runs]] as $name => [$counts, $n]) {
            $expected = $n / 32;
            $chi2 = array_sum(array_map(fn (int $c): float => (($c - $expected) ** 2) / $expected, $counts));
            $this->assertLessThan(80.0, $chi2, "$name: chi2=".round($chi2, 1));
        }
    }

    public function test_cien_mil_codigos_sin_ninguna_colision(): void
    {
        $seen = [];
        for ($i = 0; $i < 100_000; $i++) {
            $c = CodeFormat::canonicalFromBytes(self::stream('colisiones', $i));
            $this->assertArrayNotHasKey($c, $seen, "colisión en $i");
            $seen[$c] = true;
        }
        $this->assertCount(100_000, $seen);
    }

    // --- Símbolo de verificación ----------------------------------------------------------------------------------

    public function test_vectores_calculados_con_una_implementacion_independiente_en_python(): void
    {
        $vectors = [
            '0000000000' => '0', '0000000001' => 'Y', '1000000000' => 'Z', 'ABCDEFGHJK' => '2',
            'ZZZZZZZZZZ' => 'A', 'K3J9QW2X7H' => 'C', '0123456789' => 'T',
        ];
        foreach ($vectors as $payload => $check) {
            $this->assertSame($check, CodeFormat::checkSymbol($payload), $payload);
        }
    }

    public function test_coincide_con_la_implementacion_de_referencia_en_5000_casos(): void
    {
        for ($i = 0; $i < 5000; $i++) {
            $p = CodeFormat::payloadFromBytes(self::stream('referencia', $i));
            $this->assertSame(self::referenceCheck($p), CodeFormat::checkSymbol($p), $p);
        }
    }

    public function test_toda_sustitucion_de_un_solo_simbolo_se_detecta(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $payload = CodeFormat::payloadFromBytes(self::stream('sustituciones', $i));
            $code = $payload.CodeFormat::checkSymbol($payload);
            for ($pos = 0; $pos < CodeFormat::LENGTH; $pos++) {
                foreach (str_split(CodeFormat::ALPHABET) as $other) {
                    if ($other === $code[$pos]) {
                        continue;
                    }
                    $typo = $code;
                    $typo[$pos] = $other;
                    try {
                        CodeFormat::normalize($typo);
                        $this->fail("sustitución no detectada: $code -> $typo");
                    } catch (InvalidCodeFormat $e) {
                        $this->assertSame(InvalidCodeFormat::CHECKSUM, $e->reason);
                    }
                }
            }
        }
    }

    public function test_las_transposiciones_de_adyacentes_se_detectan_salvo_el_par_0_Z(): void
    {
        // Exhaustivo sobre TODOS los pares de símbolos, en distintas posiciones. El único que no se detecta es 0<->Z
        // (los extremos del alfabeto); se documenta en vez de ocultarlo.
        $undetected = [];
        for ($i = 0; $i < 40; $i++) {
            $payload = CodeFormat::payloadFromBytes(self::stream('transposiciones', $i));
            $pos = $i % 9;
            foreach (str_split(CodeFormat::ALPHABET) as $x) {
                foreach (str_split(CodeFormat::ALPHABET) as $y) {
                    if ($x === $y) {
                        continue;
                    }
                    $a = $payload;
                    $a[$pos] = $x;
                    $a[$pos + 1] = $y;
                    $b = $payload;
                    $b[$pos] = $y;
                    $b[$pos + 1] = $x;
                    if (CodeFormat::checkSymbol($a) === CodeFormat::checkSymbol($b)) {
                        $undetected["$x$y"] = true;
                    }
                }
            }
        }
        ksort($undetected);
        $this->assertSame(['0Z', 'Z0'], array_keys($undetected));
    }

    public function test_el_simbolo_de_verificacion_rechaza_simbolos_fuera_del_alfabeto(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CodeFormat::checkSymbol('ABCDEFGHIJ'); // la I no existe en el alfabeto
    }

    // --- Presentación y prefijo ------------------------------------------------------------------------------------

    public function test_presentacion_prefijo_grupos_4_4_3(): void
    {
        $this->assertSame('RULETA-K3J9-QW2X-7HC', CodeFormat::display('K3J9QW2X7HC', 'RULETA'));
        $this->assertSame(strlen('RULETA-')+11+2, strlen(CodeFormat::display('K3J9QW2X7HC', 'RULETA')));
    }

    public function test_prefijos_validos_e_invalidos(): void
    {
        foreach (['AB', 'RULETA', 'RASPA2026', 'A1B2C3D4E5F6'] as $ok) {
            $this->assertTrue(CodeFormat::isValidPrefix($ok), $ok);
        }
        foreach (['', 'A', 'ruleta', '1ABC', 'RULE-TA', 'RULE TA', 'AÑO', str_repeat('A', 13)] as $bad) {
            $this->assertFalse(CodeFormat::isValidPrefix($bad), $bad);
        }
    }

    public function test_display_rechaza_prefijo_o_largo_invalidos(): void
    {
        foreach ([['K3J9QW2X7HC', 'x'], ['K3J9', 'RULETA']] as [$code, $prefix]) {
            try {
                CodeFormat::display($code, $prefix);
                $this->fail('debía rechazar');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // --- Normalización de lo que escribe el usuario -----------------------------------------------------------------

    private static function validCode(int $i = 0): string
    {
        return CodeFormat::canonicalFromBytes(self::stream('normalizar', $i));
    }

    public function test_el_codigo_mostrado_se_normaliza_al_canonico(): void
    {
        $c = self::validCode();
        $this->assertSame($c, CodeFormat::normalize(CodeFormat::display($c, 'RULETA')));
    }

    public function test_tolera_minusculas_espacios_guiones_bajos_y_la_ausencia_de_prefijo_o_de_guiones(): void
    {
        $c = self::validCode(3);
        $shown = CodeFormat::display($c, 'RULETA');
        $variants = [
            strtolower($shown), str_replace('-', ' ', $shown), str_replace('-', '_', $shown), str_replace('-', '', $shown),
            "  $shown  ", "\t$shown\n", substr($shown, 7), strtolower(substr($shown, 7)), str_replace('-', ' - ', $shown),
            'OTRAMARCA-'.substr($shown, 7), // el prefijo es decorativo
        ];
        foreach ($variants as $v) {
            $this->assertSame($c, CodeFormat::normalize($v), json_encode($v));
        }
    }

    public function test_corrige_las_confusiones_O_0_e_I_L_1(): void
    {
        // Busca un código que tenga 0 y 1 en su cuerpo y los reescribe como O, I y L.
        for ($i = 0; $i < 5000; $i++) {
            $c = self::validCode($i);
            if (! str_contains($c, '0') || ! str_contains($c, '1')) {
                continue;
            }
            $this->assertSame($c, CodeFormat::normalize(strtr($c, ['0' => 'O'])), 'O por 0');
            $this->assertSame($c, CodeFormat::normalize(strtr($c, ['1' => 'I'])), 'I por 1');
            $this->assertSame($c, CodeFormat::normalize(strtr($c, ['1' => 'L'])), 'L por 1');
            $this->assertSame($c, CodeFormat::normalize(strtolower(strtr($c, ['0' => 'o', '1' => 'l']))), 'minúsculas');

            return;
        }
        $this->fail('no se encontró un código con 0 y 1');
    }

    public function test_el_prefijo_con_letras_prohibidas_no_estorba(): void
    {
        $c = self::validCode(7);
        $this->assertSame($c, CodeFormat::normalize('ULTRA-'.CodeFormat::display($c, 'RULETA')), 'la U del prefijo se ignora');
    }

    public function test_rechaza_lo_que_no_es_un_codigo(): void
    {
        $c = self::validCode(1);
        $casos = [
            'vacío' => '', 'solo espacios' => '   ', 'demasiado corto' => substr($c, 0, 10),
            'demasiado largo' => str_repeat('A', 30).$c,
            'con U en el cuerpo' => 'U'.substr($c, 1),
            'con acento' => 'Ñ'.substr($c, 1), 'con símbolo' => '#'.substr($c, 1), 'con emoji' => '😀'.substr($c, 1),
            'bytes inválidos' => "\xFF\xFE".$c, 'con punto' => 'K3J9.QW2X.7HC',
        ];
        foreach ($casos as $nombre => $input) {
            try {
                CodeFormat::normalize($input);
                $this->fail("debía rechazar: $nombre");
            } catch (InvalidCodeFormat) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_un_digito_de_verificacion_incorrecto_se_distingue_de_un_formato_roto(): void
    {
        $c = self::validCode(2);
        $wrong = $c;
        $wrong[10] = $c[10] === '0' ? '1' : '0';
        try {
            CodeFormat::normalize($wrong);
            $this->fail('debía fallar');
        } catch (InvalidCodeFormat $e) {
            $this->assertSame(InvalidCodeFormat::CHECKSUM, $e->reason);
        }
        try {
            CodeFormat::normalize('abc');
            $this->fail('debía fallar');
        } catch (InvalidCodeFormat $e) {
            $this->assertSame(InvalidCodeFormat::MALFORMED, $e->reason);
        }
    }

    public function test_fuzzing_nunca_lanza_otra_cosa_que_codigo_invalido(): void
    {
        for ($i = 0; $i < 3000; $i++) {
            $len = $i % 40;
            $input = substr(hash_hmac('sha512', (string) $i, 'fuzz', true).hash_hmac('sha512', "x$i", 'fuzz', true), 0, $len);
            try {
                $out = CodeFormat::normalize($input);
                $this->assertSame(CodeFormat::LENGTH, strlen($out)); // si por azar es válido, es canónico
            } catch (InvalidCodeFormat) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_normalizar_es_idempotente(): void
    {
        $c = self::validCode(4);
        $this->assertSame($c, CodeFormat::normalize(CodeFormat::normalize($c)));
    }

    public function test_todas_las_formas_de_escribir_un_codigo_dan_la_misma_huella(): void
    {
        $hasher = new CodeHasher(str_repeat('P', 32));
        $c = self::validCode(5);
        $shown = CodeFormat::display($c, 'RASPA');
        $hashes = array_map(
            fn (string $v) => bin2hex($hasher->hash(CodeFormat::normalize($v))),
            [$shown, strtolower($shown), substr($shown, 6), str_replace('-', '', $shown), 'OTRO-'.substr($shown, 6)],
        );
        $this->assertCount(1, array_unique($hashes));
    }

    public function test_la_normalizacion_no_acepta_codigos_ajenos_por_el_prefijo(): void
    {
        // Dos códigos distintos con el mismo prefijo no se confunden entre sí.
        $a = self::validCode(10);
        $b = self::validCode(11);
        $this->assertNotSame(CodeFormat::normalize(CodeFormat::display($a, 'X1')), CodeFormat::normalize(CodeFormat::display($b, 'X1')));
    }
}
