<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\AuditExporter;
use Illuminate\Support\Facades\DB;
use Tests\DbTestCase;
use Tests\Support\Fixtures;
use Tests\Support\RedeemFixtures;

/** Exportación de un lote CON reserva (SPEC-003 P6d): reserva, versión del compromiso y giros hijos dentro de la cadena. */
final class ReserveExportTest extends DbTestCase
{
    private RedeemFixtures $rf;

    /** @return array{batch: string, spins: int} lote revelado tras canjear todos los códigos y jugar todos los giros */
    private function revealedWithSpins(): array
    {
        $this->rf = new RedeemFixtures;
        // 6 none + 3 p10k + 2 extra = 11 posiciones: 8 códigos + 3 de reserva (los 2 «extra» piden a lo sumo 2).
        $b = $this->rf->activeReserveBatch(
            [['prize_id' => 'none', 'quantity' => 6], ['prize_id' => 'p10k', 'quantity' => 3], ['prize_id' => 'extra', 'quantity' => 2]], 3,
        );
        $redeem = $this->rf->service();
        $spin = $this->rf->spinService();
        $spins = 0;
        foreach ($b['codes'] as $i => $code) {
            $user = 'host-a:u'.($i + 1);
            $r = $redeem->redeem($user, 'canje-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), $code);
            $pending = [[$r->redemptionId, $r->toArray()['outcome']['extraSpins']]];
            while ($pending !== []) {
                [$parent, $granted] = array_pop($pending);
                for ($n = 0; $n < $granted; $n++) {
                    $child = $spin->spin($user, 'giro-'.(++$spins).'-'.$n.'xxxx', $parent);
                    $pending[] = [$child->redemptionId, $child->toArray()['outcome']['extraSpins']];
                }
            }
        }
        $this->rf->f->lifecycle()->close($b['batch'], 'ana');
        $this->rf->f->lifecycle()->reveal($b['batch'], 'ana');

        return ['batch' => $b['batch'], 'spins' => $spins];
    }

    public function test_la_exportacion_trae_la_reserva_la_version_y_los_giros_en_la_cadena(): void
    {
        ['batch' => $id, 'spins' => $spins] = $this->revealedWithSpins();
        $export = (new AuditExporter)->export($id);

        $this->assertSame(2, $export['commit_version']);
        $this->assertCount(3, $export['reserve_assignments']);
        $this->assertCount(8, $export['assignments']);
        $events = array_values(array_filter($export['audit_log'], fn ($e) => $e['action'] === 'spin.redeemed'));
        $this->assertCount($spins, $events);
        foreach ($events as $k => $e) {
            $d = json_decode($e['data'], true);
            $this->assertSame($k, $d['reserve_idx'], 'las casillas se consumen en orden');
            $this->assertSame($export['reserve_assignments'][$k], $d['prize_id']);
        }
        $closed = collect($export['audit_log'])->first(fn ($e) => $e['action'] === 'batch.closed' && $e['subject_id'] === $id);
        $this->assertSame(3, json_decode($closed['data'], true)['reserve']);
    }

    public function test_sin_reserva_la_exportacion_no_trae_claves_nuevas(): void
    {
        $f = new Fixtures;
        $id = $f->active();
        $f->lifecycle()->close($id, 'ana');
        $f->lifecycle()->reveal($id, 'ana');
        $export = (new AuditExporter)->export($id);
        $this->assertArrayNotHasKey('reserve_assignments', $export);
        $this->assertArrayNotHasKey('commit_version', $export);
    }

    public function test_cada_giro_quedo_en_la_bitacora_con_los_giros_concedidos_de_su_padre(): void
    {
        ['batch' => $id] = $this->revealedWithSpins();
        $granted = DB::table('redemptions')->where('batch_id', $id)->where('spins_granted', '>', 0)->count();
        $audited = DB::table('audit_log')->whereIn('action', ['code.redeemed', 'spin.redeemed'])->whereRaw("jsonb_exists(data, 'spins_granted')")->count();
        $this->assertSame($granted, $audited);
    }

    /** Regenera el fixture del verificador Python (una exportación REAL con giros): UPDATE_VERIFIER_FIXTURE=1, ver AuditExportTest. */
    public function test_regenera_el_fixture_con_reserva_si_se_pide(): void
    {
        if (getenv('UPDATE_VERIFIER_FIXTURE') !== '1') {
            $this->markTestSkipped('Solo con UPDATE_VERIFIER_FIXTURE=1.');
        }
        $export = (new AuditExporter)->export($this->revealedWithSpins()['batch']);
        $path = storage_path('app/export-reserve-sample.json');
        file_put_contents($path, json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $this->assertFileExists($path);
    }
}
