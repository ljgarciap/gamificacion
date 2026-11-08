<?php

namespace App\Domain\Demo;

use App\Domain\Admin\AdminException;
use App\Domain\Audit\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Interruptor de la muestra (SPEC-006 §3.1, diseño A4). Encender exige TODAS las condiciones en la misma transacción, con la fila de
 * configuración bloqueada: el servidor declara ser de demostración (`GAMIF_DEMO_ALLOWED`), hay escenario guardado, el emisor demo está bien
 * configurado, el pool tiene códigos y no hay campañas reales activas. Apagar es inmediato. La caducidad (`enabled_until`) se comprueba en
 * cada lectura (`isActive`) y `expireIfDue` la persiste.
 */
final class DemoSwitch
{
    public function __construct(private readonly AuditLog $audit = new AuditLog) {}

    /** @return array{enabled: bool, enabled_until: ?string, config: ?array<string, mixed>, version: int} */
    public function row(): array
    {
        $r = DB::selectOne('SELECT enabled, enabled_until, config, version FROM demo_config WHERE id = 1');

        return [
            'enabled' => (bool) $r->enabled,
            'enabled_until' => $r->enabled_until,
            'config' => $r->config === null ? null : json_decode($r->config, true),
            'version' => (int) $r->version,
        ];
    }

    public function isActive(): bool
    {
        $r = $this->row();
        if (! $r['enabled']) {
            return false;
        }

        return $r['enabled_until'] === null || Carbon::parse($r['enabled_until'])->isFuture();
    }

    /** Apaga lo que ya caducó. @return bool true si apagó */
    public function expireIfDue(): bool
    {
        $r = $this->row();
        if (! $r['enabled'] || $r['enabled_until'] === null || Carbon::parse($r['enabled_until'])->isFuture()) {
            return false;
        }
        $this->disable('system', 'expired');

        return true;
    }

    /** @param array<string, mixed> $config ya validada */
    public function saveConfig(array $config, string $actor): int
    {
        return DB::transaction(function () use ($config, $actor): int {
            $old = DB::selectOne('SELECT version FROM demo_config WHERE id = 1 FOR UPDATE');
            $version = (int) $old->version + 1;
            DB::update('UPDATE demo_config SET config = ?::jsonb, version = ?, updated_by = ?, updated_at = now() WHERE id = 1', [json_encode($config), $version, $actor]);
            $this->audit->append($actor, 'demo.config_changed', 'demo', 'config', ['version' => $version, 'config' => $config]);

            return $version;
        });
    }

    public function enable(string $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $r = DB::selectOne('SELECT config FROM demo_config WHERE id = 1 FOR UPDATE');
            if (! (bool) config('gamification.demo.allowed')) {
                throw new AdminException('wrongState', 'Este servidor no es de demostración (falta GAMIF_DEMO_ALLOWED=true).', 409);
            }
            if (config('gamification.host.balance') !== 'stub') {
                throw new AdminException('wrongState', 'La muestra necesita el saldo de demostración (GAMIF_BALANCE=stub): con un host real no puede cargarse saldo.', 409);
            }
            if ($r->config === null) {
                throw new AdminException('wrongState', 'Guarda primero la configuración de la muestra.', 409);
            }
            $config = json_decode($r->config, true);
            if (! $this->issuerConfigured()) {
                throw new AdminException('wrongState', 'El emisor de la demostración no está configurado en GAMIF_HOSTS con alg HS256.', 409);
            }
            $available = (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE state = 'available'")->n;
            if ($available === 0) {
                throw new AdminException('wrongState', 'El pool de códigos está vacío: siembra la muestra antes de encenderla.', 409);
            }
            $real = (int) DB::selectOne("SELECT count(DISTINCT c.id) AS n FROM campaigns c JOIN batches b ON b.campaign_id = c.id WHERE NOT c.is_demo AND b.state = 'active'")->n;
            if ($real > 0) {
                throw new AdminException('wrongState', "Hay $real campaña(s) real(es) activa(s): la muestra no puede convivir con campañas reales.", 409);
            }
            $ttl = $config['ttlHours'] ?? null;
            $until = $ttl === null ? null : Carbon::now()->addHours((int) $ttl)->toIso8601String();
            DB::update('UPDATE demo_config SET enabled = true, enabled_until = ?, updated_by = ?, updated_at = now() WHERE id = 1', [$until, $actor]);
            $this->audit->append($actor, 'demo.enabled', 'demo', 'switch', ['ttlHours' => $ttl, 'available' => $available]);
        });
    }

    public function disable(string $actor, string $reason = 'manual'): void
    {
        DB::transaction(function () use ($actor, $reason): void {
            DB::selectOne('SELECT 1 FROM demo_config WHERE id = 1 FOR UPDATE');
            DB::update('UPDATE demo_config SET enabled = false, enabled_until = NULL, updated_by = ?, updated_at = now() WHERE id = 1', [$actor]);
            $this->audit->append($actor, 'demo.disabled', 'demo', 'switch', ['reason' => $reason]);
        });
    }

    private function issuerConfigured(): bool
    {
        $issuer = (string) config('gamification.dev.issuer');
        $host = config("gamification.hosts.$issuer");
        if (! is_array($host) || ($host['alg'] ?? null) !== 'HS256' || ! is_string($host['key'] ?? null)) {
            return false;
        }
        $key = base64_decode($host['key'], true);

        return $key !== false && $key !== '';
    }
}
