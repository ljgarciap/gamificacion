<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;

/**
 * CA-24 — el motor no usa funciones de azar de propósito general.
 *
 * `rand`, `mt_rand` y `shuffle` usan Mersenne Twister, que es predecible; `random_int`/`random_bytes` son seguras pero no
 * reproducibles. El motor debe ser una función pura de (pool, semilla, baliza, lote): el azar entra solo a través de la
 * semilla, que se genera FUERA de este módulo con el CSPRNG del sistema operativo. Así cualquiera puede recalcular el resultado.
 *
 * Se analiza el código con el tokenizador de PHP (no con búsqueda de texto) para no confundir comentarios ni nombres de método.
 */
final class NoGeneralPurposeRandomTest extends TestCase
{
    private const FORBIDDEN = [
        'rand', 'mt_rand', 'mt_srand', 'srand', 'random_int', 'random_bytes', 'shuffle', 'array_rand',
        'str_shuffle', 'lcg_value', 'mt_getrandmax', 'getrandmax', 'uniqid', 'openssl_random_pseudo_bytes',
        'time', 'microtime', 'hrtime', 'date', 'file_get_contents', 'curl_init', 'fopen',
    ];

    /** @return list<string> */
    private static function engineFiles(): array
    {
        $files = glob(dirname(__DIR__, 3).'/app/Engine/*.php') ?: [];
        self::assertNotEmpty($files, 'no se encontraron archivos del motor');

        return $files;
    }

    public function test_el_motor_no_llama_a_funciones_de_azar_reloj_ni_red(): void
    {
        $violations = [];
        foreach (self::engineFiles() as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                $t = $tokens[$i];
                if (! is_array($t) || $t[0] !== T_STRING || ! in_array(strtolower($t[1]), self::FORBIDDEN, true)) {
                    continue;
                }
                // ¿Es una llamada a función (siguiente token significativo = "(")?
                $j = $i + 1;
                while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                $isCall = $j < $count && $tokens[$j] === '(';
                // ¿Es un método propio (->nombre / ::nombre) o su declaración (function nombre)? No cuenta.
                $k = $i - 1;
                while ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                    $k--;
                }
                $prev = $k >= 0 && is_array($tokens[$k]) ? $tokens[$k][0] : null;
                $isMember = in_array($prev, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR], true);

                if ($isCall && ! $isMember) {
                    $violations[] = basename($file).':'.$t[2].' llama a '.$t[1].'()';
                }
            }
        }
        $this->assertSame([], $violations, "el motor debe ser una función pura:\n".implode("\n", $violations));
    }

    public function test_el_detector_realmente_detecta(): void
    {
        // Control de la prueba: si el tokenizador dejara de ver estas llamadas, el test de arriba pasaría en falso.
        $code = '<?php function f() { return mt_rand(1, 2) + random_int(1, 2); } // shuffle( en un comentario no cuenta';
        $found = [];
        $tokens = token_get_all($code);
        foreach ($tokens as $i => $t) {
            if (is_array($t) && $t[0] === T_STRING && in_array(strtolower($t[1]), self::FORBIDDEN, true)) {
                $found[] = $t[1];
            }
        }
        $this->assertSame(['mt_rand', 'random_int'], $found);
    }

    public function test_los_archivos_del_motor_no_dependen_de_laravel_ni_de_la_base(): void
    {
        foreach (self::engineFiles() as $file) {
            $src = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/\buse\s+(Illuminate|App\\\\(Models|Domain|Http))\b/', $src, basename($file));
        }
    }
}
