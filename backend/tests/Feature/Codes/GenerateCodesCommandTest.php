<?php

namespace Tests\Feature\Codes;

use Illuminate\Support\Facades\DB;
use Tests\DbTestCase;
use Tests\Support\Fixtures;

final class GenerateCodesCommandTest extends DbTestCase
{
    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = sys_get_temp_dir().'/cmd-codes-'.bin2hex(random_bytes(4)).'.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->out);
        @unlink($this->out.'.partial');
        parent::tearDown();
    }

    public function test_genera_exporta_e_informa(): void
    {
        $id = (new Fixtures)->draft();

        $this->artisan('gamification:codes:generate', ['batch' => $id, '--prefix' => 'RULETA', '--out' => $this->out, '--actor' => 'ana'])
            ->expectsOutputToContain('Generados 10 códigos')
            ->expectsOutputToContain('SHA-256: ')
            ->expectsOutputToContain('ÚNICA copia en claro')
            ->assertSuccessful();
        $this->assertFileExists($this->out);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->out)), -4));
    }

    public function test_el_sha256_informado_es_el_del_archivo(): void
    {
        $id = (new Fixtures)->draft();
        $this->artisan('gamification:codes:generate', ['batch' => $id, '--prefix' => 'RULETA', '--out' => $this->out])->assertSuccessful();

        $logged = json_decode(DB::selectOne("SELECT data::text AS d FROM audit_log WHERE action = 'batch.codes_generated'")->d, true);
        $this->assertSame(hash_file('sha256', $this->out), $logged['export_sha256']);
    }

    public function test_exige_prefijo_y_archivo(): void
    {
        $id = (new Fixtures)->draft();
        $this->artisan('gamification:codes:generate', ['batch' => $id])->expectsOutputToContain('Indique --prefix y --out')->assertExitCode(2);
        $this->artisan('gamification:codes:generate', ['batch' => $id, '--prefix' => 'RULETA'])->assertExitCode(2);
    }

    public function test_errores_de_negocio_dan_un_mensaje_claro_y_codigo_de_error(): void
    {
        $id = (new Fixtures)->draft();
        $this->artisan('gamification:codes:generate', ['batch' => $id, '--prefix' => 'malo', '--out' => $this->out])
            ->expectsOutputToContain('Prefijo inválido')
            ->assertFailed();
        $this->assertFileDoesNotExist($this->out);
    }

    public function test_no_sobrescribe_un_archivo_existente(): void
    {
        file_put_contents($this->out, 'importante');
        $id = (new Fixtures)->draft();
        $this->artisan('gamification:codes:generate', ['batch' => $id, '--prefix' => 'RULETA', '--out' => $this->out])->assertFailed();
        $this->assertSame('importante', file_get_contents($this->out));
    }
}
