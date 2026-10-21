<?php

namespace Tests\Feature\Concurrency;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrencyTestCase;

/** La migración de la reserva se puede deshacer y volver a aplicar (sobre una base temporal, nunca la de desarrollo). */
final class ReserveMigrationTest extends ConcurrencyTestCase
{
    public function test_deshacer_y_volver_a_aplicar_la_migracion_de_la_reserva(): void
    {
        $this->assertTrue($this->tableExists('reserve_slots'));

        Artisan::call('migrate:rollback', ['--force' => true, '--database' => 'conc', '--step' => 1]);
        $this->assertFalse($this->tableExists('reserve_slots'));
        $this->assertFalse($this->tableExists('reserve_cursors'));
        $notNull = DB::selectOne("SELECT is_nullable FROM information_schema.columns WHERE table_name = 'redemptions' AND column_name = 'code_id'")->is_nullable;
        $this->assertSame('NO', $notNull, 'code_id vuelve a ser obligatorio');
        $this->assertNull(DB::selectOne("SELECT 1 FROM information_schema.columns WHERE table_name = 'batches' AND column_name = 'reserve_size'"));
        // El trigger original de las redenciones vuelve (ya no conoce la rama de giros hijos).
        $this->assertStringNotContainsString('reserve_slots', (string) DB::selectOne("SELECT prosrc FROM pg_proc WHERE proname = 'redemptions_guard'")->prosrc);

        Artisan::call('migrate', ['--force' => true, '--database' => 'conc']);
        $this->assertTrue($this->tableExists('reserve_slots'));
        $this->assertStringContainsString('reserve_slots', (string) DB::selectOne("SELECT prosrc FROM pg_proc WHERE proname = 'redemptions_guard'")->prosrc);
    }

    private function tableExists(string $table): bool
    {
        return DB::selectOne('SELECT to_regclass(?) AS t', [$table])->t !== null;
    }
}
