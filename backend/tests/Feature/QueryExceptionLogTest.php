<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\DbTestCase;

/** RNF-30 / T32 (revisión P10): un error SQL no debe llevar al log los valores de la consulta (hashes, texto cifrado, códigos). */
final class QueryExceptionLogTest extends DbTestCase
{
    public function test_un_error_sql_se_registra_sin_los_valores_enlazados(): void
    {
        $logged = [];
        Log::shouldReceive('error')->andReturnUsing(function (...$args) use (&$logged): void {
            $logged[] = json_encode($args);
        });
        Log::shouldReceive('debug', 'info', 'warning', 'notice')->andReturnNull();

        try {
            DB::update('update admin_users set password_hash = ? where columna_que_no_existe = ?', ['HASH-SECRETO-123', 1]);
            $this->fail('la consulta debía fallar');
        } catch (QueryException $e) {
            $this->assertStringContainsString('HASH-SECRETO-123', $e->getMessage(), 'el mensaje de Laravel SÍ trae el valor: por eso se filtra');
            report($e);
        }

        $this->assertNotEmpty($logged, 'el error se registra');
        $this->assertStringNotContainsString('HASH-SECRETO-123', implode("\n", $logged));
        $this->assertStringContainsString('password_hash = ?', implode("\n", $logged), 'la consulta queda con marcadores');
    }
}
