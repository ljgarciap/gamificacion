<?php

namespace Tests\Feature\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Settings\Settings;
use App\Domain\Settings\SettingsRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Parámetros administrables (SPEC-003 P4, RF-47/RF-48, CA-34, amenazas T16–T17). */
final class SettingsTest extends AdminTestCase
{
    private const KEY = 'outbox.max_attempts';

    private function superadmin(): array
    {
        $a = $this->makeAdmin('superadmin');
        $this->login($a);

        return $a;
    }

    public function test_sin_fila_vale_el_valor_por_defecto_de_config(): void
    {
        $this->assertSame((int) config('gamification.outbox.max_attempts'), Settings::int(self::KEY));
    }

    public function test_cambiar_un_parametro_lo_aplica_deja_historial_y_bitacora(): void
    {
        $a = $this->superadmin();
        $this->assertSame(204, $this->api('PUT', '/settings/'.self::KEY, ['value' => 12])->status());
        $this->assertSame(12, Settings::int(self::KEY));
        $this->assertSame(204, $this->api('PUT', '/settings/'.self::KEY, ['value' => 15])->status());

        $h = $this->api('GET', '/settings/'.self::KEY.'/history')->json('history');
        $this->assertCount(2, $h);
        $this->assertSame([15, 12], [$h[0]['new'], $h[1]['new']]);
        $this->assertSame([12, null], [$h[0]['old'], $h[1]['old']]);
        $this->assertSame('admin:'.$a['id'], $h[0]['actor']);

        $this->assertNotNull(DB::selectOne("SELECT 1 AS x FROM audit_log WHERE action = 'settings.changed' AND subject_id = ?", [self::KEY]));
        $this->assertNull((new AuditLog)->verifyChain());
    }

    public function test_el_cambio_aplica_en_menos_de_un_minuto_sin_invalidar_a_mano(): void
    {
        // Otro proceso escribe directo en la base: este ve el cambio cuando vence la caché de 60 s.
        $this->assertSame(8, Settings::int(self::KEY));
        DB::insert("INSERT INTO settings (key, value, updated_by) VALUES (?, '20'::jsonb, 'otro')", [self::KEY]);
        $this->assertSame(8, Settings::int(self::KEY), 'dentro de la ventana de caché sigue el valor viejo');
        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $this->assertSame(20, Settings::int(self::KEY));
    }

    public function test_fuera_de_rango_o_tipo_incorrecto_se_rechaza_y_no_se_guarda(): void
    {
        $this->superadmin();
        foreach ([0, 31, -1, '10', 2.5, null, true] as $bad) {
            $r = $this->api('PUT', '/settings/'.self::KEY, ['value' => $bad]);
            $this->assertSame(422, $r->status(), 'valor '.json_encode($bad));
        }
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM settings')->n);
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM settings_history')->n);
    }

    public function test_un_valor_invalido_en_la_base_cae_al_valor_por_defecto(): void
    {
        foreach (['0', '999', '"diez"', 'null'] as $json) {
            DB::delete('DELETE FROM settings');
            DB::insert('INSERT INTO settings (key, value, updated_by) VALUES (?, ?::jsonb, ?)', [self::KEY, $json, 'x']);
            Cache::forget('gamif:settings:overrides');
            $this->assertSame((int) config('gamification.outbox.max_attempts'), Settings::int(self::KEY), $json);
        }
    }

    public function test_un_parametro_de_seguridad_no_se_cambia_sin_control_dual(): void
    {
        $this->superadmin();
        $r = $this->api('PUT', '/settings/token.max_lifetime_seconds', ['value' => 3600]);
        $this->assertSame(409, $r->status());
        $this->assertSame('approvalRequired', $r->json('error.code'));
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM settings')->n);
    }

    public function test_parametro_desconocido_es_404(): void
    {
        $this->superadmin();
        $this->assertSame(404, $this->api('PUT', '/settings/no.existe', ['value' => 1])->status());
        $this->assertSame(404, $this->api('GET', '/settings/no.existe/history')->status());
    }

    public function test_solo_el_superadmin_ve_y_cambia_parametros(): void
    {
        foreach (['operator', 'auditor'] as $role) {
            $this->login($this->makeAdmin($role));
            $this->assertSame(403, $this->api('GET', '/settings')->status(), $role);
            $this->assertSame(403, $this->api('PUT', '/settings/'.self::KEY, ['value' => 10])->status(), $role);
            $this->assertSame(403, $this->api('GET', '/settings/'.self::KEY.'/history')->status(), $role);
            $this->api('POST', '/auth/logout');
        }
    }

    public function test_el_listado_describe_cada_parametro_del_registro(): void
    {
        $this->superadmin();
        $list = $this->api('GET', '/settings')->json('settings');
        $this->assertSame(array_keys(SettingsRegistry::all()), array_column($list, 'key'));
        $this->assertSame(['key', 'label', 'unit', 'min', 'max', 'security', 'default', 'value', 'overridden', 'updatedBy', 'updatedAt'], array_keys($list[0]));
    }

    public function test_el_historial_es_de_solo_agregar_en_la_base(): void
    {
        DB::insert("INSERT INTO settings_history (key, old_value, new_value, actor) VALUES ('k', NULL, '1'::jsonb, 'a')");
        $this->assertDbRejects(fn () => DB::update("UPDATE settings_history SET actor = 'b'"), 'solo agregar');
        $this->assertDbRejects(fn () => DB::delete('DELETE FROM settings_history'), 'solo agregar');
        $this->assertDbRejects(fn () => DB::unprepared('TRUNCATE settings_history'), 'solo agregar');
    }

    public function test_cada_clave_del_registro_existe_en_config_y_su_default_esta_en_rango(): void
    {
        foreach (SettingsRegistry::all() as $key => $def) {
            $default = config("gamification.$key");
            $this->assertIsInt($default, $key);
            $this->assertGreaterThanOrEqual($def['min'], $default, $key);
            $this->assertLessThanOrEqual($def['max'], $default, $key);
            $this->assertLessThan($def['max'] + 1, $def['min'] + 1, $key);
        }
    }

    public function test_los_suelos_de_seguridad_impiden_las_configuraciones_peligrosas(): void
    {
        $this->assertGreaterThanOrEqual(1, SettingsRegistry::get('redeem.user.max_failures')['min']);
        $this->assertGreaterThanOrEqual(1, SettingsRegistry::get('admin.login.max_failures')['min']);
        $this->assertLessThanOrEqual(3600, SettingsRegistry::get('token.max_lifetime_seconds')['max']);
        $this->assertGreaterThanOrEqual(12, SettingsRegistry::get('admin.password_min_length')['min']);
    }

    public function test_los_lectores_usan_la_sobrescritura(): void
    {
        DB::insert("INSERT INTO settings (key, value, updated_by) VALUES ('redeem.user.max_failures', '7'::jsonb, 'x')");
        Cache::forget('gamif:settings:overrides');
        $this->assertSame(7, Settings::group('redeem.user')['max_failures']);
        $this->assertSame(600, Settings::group('redeem.user')['window_seconds'], 'lo no sobrescrito queda como en config');
    }

    public function test_el_valor_inicial_no_puede_superar_al_maximo_del_grupo(): void
    {
        $settings = new Settings;
        $settings->set('outbox.backoff_max_seconds', 100, 'cli');
        $this->expectException(\InvalidArgumentException::class);
        $settings->set('outbox.backoff_base_seconds', 101, 'cli');
    }

    public function test_el_maximo_no_puede_quedar_bajo_el_valor_inicial(): void
    {
        $settings = new Settings;
        $settings->set('outbox.backoff_base_seconds', 500, 'cli');
        $this->expectException(\InvalidArgumentException::class);
        $settings->set('outbox.backoff_max_seconds', 499, 'cli');
    }
}
