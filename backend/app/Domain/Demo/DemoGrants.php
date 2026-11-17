<?php

namespace App\Domain\Demo;

use App\Domain\Admin\AdminException;
use App\Domain\Audit\AuditLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turnos fuera de una compra (SPEC-007 §3.2): el de bienvenida al crear la cuenta y el de reactivación cuando pasaron los días configurados sin
 * comprar. Ambos salen del pool del plan «welcome». Un ingreso NUNCA falla por falta de premios (A7): sin códigos no hay turno y queda anotado.
 *
 * Concurrencia (T-D25): la fila de la cuenta se bloquea (`FOR UPDATE`) mientras se decide y se entrega, y la compra actualiza la misma marca
 * bajo su propio bloqueo; el turno de bienvenida además lo hace único la base (índice parcial).
 */
final class DemoGrants
{
    public function __construct(
        private readonly DemoPoolDraw $draw = new DemoPoolDraw,
        private readonly AuditLog $audit = new AuditLog,
    ) {}

    /**
     * Decide y entrega el turno de un ingreso recién verificado.
     *
     * @param  array{id: string, subject: string}  $account
     * @param  array<string, mixed>  $config
     * @return array{plan: string, kind: string}|null
     */
    public function onLogin(array $account, bool $isNew, array $config): ?array
    {
        if (DemoConfig::mode($config) !== 'plans') {
            return null;
        }
        $welcome = DemoPlans::find($config, DemoConfig::WELCOME_PLAN);
        if ($welcome === null) {
            return null;
        }
        $triggers = $config['triggers'];
        $userId = DemoAccess::issuerPrefix().$account['subject'];

        try {
            return DB::transaction(function () use ($account, $isNew, $triggers, $welcome, $userId): ?array {
                $row = DB::selectOne(
                    'SELECT (clock_timestamp() - activity_at >= make_interval(days => ?)) AS idle FROM demo_accounts WHERE id = ? FOR UPDATE',
                    [$triggers['reactivationDays'], $account['id']],
                );
                if ($row === null) {
                    return null; // la cuenta se borró mientras tanto
                }
                $kind = null;
                if ($isNew) {
                    $kind = $triggers['welcomeOnFirstLogin'] ? 'welcome' : null;
                } elseif ((bool) $row->idle) {
                    $kind = 'reactivation';
                }
                if ($kind === null) {
                    return null;
                }

                $grantId = (int) DB::selectOne('INSERT INTO demo_grants (user_id, kind) VALUES (?, ?) RETURNING id', [$userId, $kind])->id;
                $this->draw->draw($userId, null, 1, $welcome['id'], $grantId);
                if ($kind === 'reactivation') {
                    DB::update('UPDATE demo_accounts SET activity_at = clock_timestamp() WHERE id = ?', [$account['id']]);
                }

                return ['plan' => $welcome['name'], 'kind' => $kind];
            });
        } catch (DemoPoolExhausted) {
            // La transacción se revirtió completa (ni turno ni marca): el ingreso sigue y se reintenta en el próximo ingreso. Queda anotado.
            $this->audit->append('system', 'demo.grant_skipped', 'demo', $account['id'], ['reason' => 'poolExhausted', 'plan' => DemoConfig::WELCOME_PLAN]);

            return null;
        } catch (UniqueConstraintViolationException) {
            return null; // otro ingreso de la misma cuenta ya otorgó el turno de bienvenida
        }
    }

    /**
     * Una compra reinicia la espera de reactivación. Debe llamarse DENTRO de la transacción de la compra.
     */
    public function markPurchase(string $userId): void
    {
        $prefix = DemoAccess::issuerPrefix();
        if (! str_starts_with($userId, $prefix)) {
            return;
        }
        DB::update('UPDATE demo_accounts SET activity_at = clock_timestamp() WHERE subject = ?', [substr($userId, strlen($prefix))]);
    }

    /**
     * Para ver la reactivación en la demo sin esperar días: mueve la marca de actividad HACIA ATRÁS (nunca hacia adelante). Queda en la bitácora.
     *
     * @param  array<string, mixed>  $config
     */
    public function simulateInactivity(string $accountId, string $actor, array $config): bool
    {
        if (DemoConfig::mode($config) !== 'plans') {
            throw new AdminException('wrongState', 'La reactivación solo existe en el modo «plans» de la muestra.', 409);
        }
        $days = $config['triggers']['reactivationDays'];
        $found = DB::update(
            'UPDATE demo_accounts SET activity_at = LEAST(activity_at, clock_timestamp() - make_interval(days => ?)) WHERE id = ?',
            [$days, $accountId],
        ) > 0;
        $found && $this->audit->append($actor, 'demo.inactivity_simulated', 'demo', $accountId, ['days' => $days]);

        return $found;
    }
}
