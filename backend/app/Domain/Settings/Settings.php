<?php

namespace App\Domain\Settings;

use App\Domain\Audit\AuditLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lectura y escritura de parámetros (SPEC-003 P4). Lectura: la sobrescritura de la tabla (caché de 60 s: un cambio aplica en menos de 1 minuto
 * sin reiniciar) o, si no hay fila, el valor de `config('gamification.…')`. Un valor guardado fuera de rango o mal formado NO se usa: se lee el
 * valor por defecto y se registra (un parámetro roto no debe tumbar el canje). Escritura: valida tipo y rango, guarda historial y bitácora.
 */
final class Settings
{
    private const CACHE_KEY = 'gamif:settings:overrides';

    private const TTL_SECONDS = 60;

    public function __construct(private readonly AuditLog $audit = new AuditLog) {}

    public static function int(string $key): int
    {
        return (new self)->get($key);
    }

    public function get(string $key): int
    {
        $default = (int) config("gamification.$key");
        $def = SettingsRegistry::get($key);
        $override = $this->overrides()[$key] ?? null;
        if ($def === null || $override === null) {
            return $default;
        }
        if (! is_int($override) || $override < $def['min'] || $override > $def['max']) {
            Log::warning('Parámetro inválido en la base; se usa el valor por defecto.', ['key' => $key]);

            return $default;
        }

        return $override;
    }

    /** Subárbol de config (p. ej. `admin.login`) con las sobrescrituras aplicadas. @return array<string, mixed> */
    public static function group(string $prefix): array
    {
        $values = (array) config("gamification.$prefix");
        $settings = new self;
        foreach (SettingsRegistry::all() as $key => $_) {
            if (str_starts_with($key, $prefix.'.') && ! str_contains(substr($key, strlen($prefix) + 1), '.')) {
                $values[substr($key, strlen($prefix) + 1)] = $settings->get($key);
            }
        }

        return $values;
    }

    /** @return list<array<string, mixed>> */
    public function describe(): array
    {
        $overrides = DB::select('SELECT key, updated_by, updated_at FROM settings');
        $meta = [];
        foreach ($overrides as $o) {
            $meta[$o->key] = $o;
        }
        $out = [];
        foreach (SettingsRegistry::all() as $key => $def) {
            $out[] = [
                'key' => $key, 'label' => $def['label'], 'unit' => $def['unit'], 'min' => $def['min'], 'max' => $def['max'],
                'security' => $def['security'], 'default' => (int) config("gamification.$key"), 'value' => $this->get($key),
                'overridden' => isset($meta[$key]), 'updatedBy' => $meta[$key]->updated_by ?? null, 'updatedAt' => $meta[$key]->updated_at ?? null,
            ];
        }

        return $out;
    }

    /** @throws \InvalidArgumentException clave desconocida, valor que no es entero, fuera del rango duro o incoherente con su pareja */
    public function validate(string $key, mixed $value): void
    {
        $def = SettingsRegistry::get($key) ?? throw new \InvalidArgumentException('Parámetro desconocido.');
        if (! is_int($value)) {
            throw new \InvalidArgumentException('El valor debe ser un número entero.');
        }
        if ($value < $def['min'] || $value > $def['max']) {
            throw new \InvalidArgumentException("El valor debe estar entre {$def['min']} y {$def['max']}.");
        }
        $this->assertConsistent($key, $value);
    }

    /** @throws \InvalidArgumentException ver [validate] */
    public function set(string $key, mixed $value, string $actor): void
    {
        $this->validate($key, $value);
        DB::transaction(function () use ($key, $value, $actor): void {
            $old = DB::selectOne('SELECT value::text AS v FROM settings WHERE key = ? FOR UPDATE', [$key]);
            $oldValue = $old === null ? null : json_decode($old->v);
            DB::insert(
                'INSERT INTO settings (key, value, updated_by, updated_at) VALUES (?, ?::jsonb, ?, clock_timestamp())
                 ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_by = EXCLUDED.updated_by, updated_at = EXCLUDED.updated_at',
                [$key, json_encode($value), $actor],
            );
            DB::insert('INSERT INTO settings_history (key, old_value, new_value, actor) VALUES (?, ?::jsonb, ?::jsonb, ?)',
                [$key, $oldValue === null ? null : json_encode($oldValue), json_encode($value), $actor]);
            $this->audit->append($actor, 'settings.changed', 'setting', $key, ['old' => $oldValue, 'new' => $value]);
            // Después del commit EXTERNO: si se invalidara antes, otro lector repoblaría la caché con el valor viejo.
            DB::afterCommit(fn () => Cache::forget(self::CACHE_KEY));
        });
    }

    /** El bloqueo/espera inicial (`*_base_seconds`) no puede superar al máximo (`*_max_seconds`) del mismo grupo. */
    private function assertConsistent(string $key, int $value): void
    {
        if (str_ends_with($key, '_base_seconds')) {
            $other = substr($key, 0, -strlen('_base_seconds')).'_max_seconds';
            if (SettingsRegistry::get($other) !== null && $value > $this->get($other)) {
                throw new \InvalidArgumentException('El valor inicial no puede superar al máximo ('.$this->get($other).').');
            }
        } elseif (str_ends_with($key, '_max_seconds')) {
            $other = substr($key, 0, -strlen('_max_seconds')).'_base_seconds';
            if (SettingsRegistry::get($other) !== null && $value < $this->get($other)) {
                throw new \InvalidArgumentException('El máximo no puede ser menor que el valor inicial ('.$this->get($other).').');
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function history(string $key, int $limit = 100): array
    {
        return array_map(fn (object $r): array => [
            'id' => (int) $r->id, 'old' => $r->old_value === null ? null : json_decode($r->old_value), 'new' => json_decode($r->new_value),
            'actor' => $r->actor, 'changedAt' => $r->changed_at,
        ], DB::select('SELECT id, old_value::text AS old_value, new_value::text AS new_value, actor, changed_at
                       FROM settings_history WHERE key = ? ORDER BY id DESC LIMIT ?', [$key, $limit]));
    }

    /** @return array<string, mixed> */
    private function overrides(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, function (): array {
            $map = [];
            foreach (DB::select('SELECT key, value::text AS v FROM settings') as $r) {
                $map[$r->key] = json_decode($r->v);
            }

            return $map;
        });
    }
}
