<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminException;
use App\Domain\Demo\DemoAccounts;
use App\Domain\Demo\DemoConfig;
use App\Domain\Demo\DemoEmail;
use App\Domain\Demo\DemoRuns;
use App\Domain\Demo\DemoSwitch;
use App\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

/** Muestra real / modo demo (SPEC-006): configuración del escenario e interruptor. Solo superadmin (`demo.manage`). */
final class DemoController
{
    public function __construct(private readonly DemoSwitch $switch, private readonly DemoRuns $runs, private readonly DemoAccounts $accounts) {}

    public function show(): JsonResponse
    {
        $row = $this->switch->row();
        $pool = ['available' => 0, 'assigned' => 0, 'retired' => 0];
        foreach (DB::select('SELECT state, count(*) AS n FROM demo_pool GROUP BY state') as $r) {
            $pool[$r->state] = (int) $r->n;
        }

        return response()->json([
            'configured' => $row['config'] !== null,
            'config' => $row['config'] ?? DemoConfig::defaults(),
            'version' => $row['version'],
            'status' => [
                'enabled' => $row['enabled'],
                'enabledUntil' => $row['enabled_until'] === null ? null : \Illuminate\Support\Carbon::parse($row['enabled_until'])->toIso8601String(),
                'active' => $this->switch->isActive(),
                'serverAllowed' => (bool) config('gamification.demo.allowed'),
            ],
            'pool' => $pool,
            'lastRun' => $this->runs->last(),
            'accounts' => $this->accounts->counts(),
            'mail' => $this->mailInfo(),
            'limits' => [
                'maxProducts' => DemoConfig::MAX_PRODUCTS, 'maxAmount' => DemoConfig::MAX_AMOUNT,
                'maxCodesPerPurchase' => DemoConfig::MAX_CODES_PER_PURCHASE, 'maxPool' => DemoConfig::MAX_POOL,
                'maxTtlHours' => DemoConfig::MAX_TTL_HOURS, 'maxRetentionDays' => DemoConfig::MAX_RETENTION_DAYS, 'maxAccounts' => DemoConfig::MAX_ACCOUNTS,
                'minCodeTtlMinutes' => DemoConfig::MIN_CODE_TTL_MINUTES, 'maxCodeTtlMinutes' => DemoConfig::MAX_CODE_TTL_MINUTES, 'games' => DemoConfig::GAMES, 'prizeTypes' => DemoConfig::PRIZE_TYPES,
            ],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function updateConfig(Request $request): JsonResponse|Response
    {
        try {
            $config = DemoConfig::validate($request->json()->all());
        } catch (\InvalidArgumentException $e) {
            return ApiError::response('validationFailed', $e->getMessage(), 422);
        }
        $version = $this->switch->saveConfig($config, $this->actor($request));

        return response()->json(['version' => $version, 'config' => $config], 200, ['Cache-Control' => 'no-store']);
    }

    public function enable(Request $request): JsonResponse|Response
    {
        return $this->guarded(fn () => $this->switch->enable($this->actor($request)));
    }

    public function disable(Request $request): JsonResponse|Response
    {
        return $this->guarded(fn () => $this->switch->disable($this->actor($request)));
    }

    /** Pide sembrar (solo si no hay códigos disponibles). La corrida la ejecuta el worker. */
    public function seed(Request $request): JsonResponse|Response
    {
        return $this->requestRun($request, 'seed');
    }

    /** Pide reiniciar: apaga la muestra, retira el pool y siembra uno nuevo. */
    public function reset(Request $request): JsonResponse|Response
    {
        // D27: la elección es OBLIGATORIA y explícita (nada de un valor por defecto que borre o conserve sin que nadie lo haya decidido).
        $choice = $request->json('accounts');
        if (! in_array($choice, ['keep', 'delete'], true)) {
            return ApiError::response('validationFailed', 'Elige qué hacer con las cuentas: «keep» (conservar) o «delete» (borrar).', 422);
        }

        return $this->requestRun($request, 'reset', ['accounts' => $choice]);
    }

    /** Cuentas de la muestra, con el correo ENMASCARADO (SPEC-006A). */
    public function accounts(Request $request): JsonResponse
    {
        return response()->json($this->accounts->list((int) $request->query('page', 1), (int) $request->query('perPage', 50)), 200, ['Cache-Control' => 'no-store']);
    }

    public function deleteAccount(Request $request, string $id): JsonResponse|Response
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1 || ! $this->accounts->delete($id, $this->actor($request))) {
            return ApiError::response('notFound', 'Cuenta desconocida.', 404);
        }

        return response()->noContent();
    }

    /** Correo de prueba para comprobar el envío (SMTP o Mailpit). El destinatario queda en la bitácora enmascarado. */
    public function mailTest(Request $request): JsonResponse|Response
    {
        $to = DemoEmail::normalize($request->json('to'));
        if ($to === null) {
            return ApiError::response('validationFailed', 'Escribe un correo válido.', 422);
        }
        try {
            Mail::to($to)->send(new \App\Mail\DemoTestMail);
        } catch (\Throwable $e) {
            Log::warning('demo: falló el correo de prueba', ['error' => class_basename($e)]);

            return ApiError::response('mailUnavailable', 'No se pudo enviar el correo de prueba. Revisa el servidor de correo (MAIL_*).', 503);
        }
        (new \App\Domain\Audit\AuditLog)->append($this->actor($request), 'demo.mail_test', 'demo', 'mail', ['to' => DemoEmail::mask($to)]);

        return response()->noContent();
    }

    /** @return array{mailer: string, host: string|null, port: int|null, from: string|null} sin credenciales */
    private function mailInfo(): array
    {
        $mailer = (string) config('mail.default');
        $conf = (array) config("mail.mailers.$mailer", []);

        return ['mailer' => $mailer, 'host' => $conf['host'] ?? null, 'port' => isset($conf['port']) ? (int) $conf['port'] : null, 'from' => config('mail.from.address')];
    }

    public function run(int $id): JsonResponse
    {
        $run = $this->runs->find($id);

        return $run === null
            ? ApiError::response('notFound', 'Corrida desconocida.', 404)
            : response()->json($run, 200, ['Cache-Control' => 'no-store']);
    }

    /** @param array<string, mixed> $options */
    private function requestRun(Request $request, string $kind, array $options = []): JsonResponse|Response
    {
        try {
            if (! (bool) config('gamification.demo.allowed')) {
                throw new AdminException('wrongState', 'Este servidor no es de demostración (falta GAMIF_DEMO_ALLOWED=true).', 409);
            }
            if ($this->switch->row()['config'] === null) {
                throw new AdminException('wrongState', 'Guarda primero la configuración de la muestra.', 409);
            }
            if ($kind === 'seed' && (int) DB::selectOne("SELECT count(*) AS n FROM demo_pool WHERE state = 'available'")->n > 0) {
                throw new AdminException('wrongState', 'Ya hay códigos disponibles: usa «Reiniciar» para empezar de cero.', 409);
            }
            $id = $this->runs->request($kind, $this->actor($request), $options);
        } catch (AdminException $e) {
            return ApiError::response($e->errorCode, $e->getMessage(), $e->status);
        }

        return response()->json(['runId' => $id], 202, ['Cache-Control' => 'no-store']);
    }

    private function guarded(\Closure $action): JsonResponse|Response
    {
        try {
            $action();
        } catch (AdminException $e) {
            return ApiError::response($e->errorCode, $e->getMessage(), $e->status);
        }

        return response()->noContent();
    }

    private function actor(Request $request): string
    {
        return 'admin:'.$request->attributes->get('admin_user')->id;
    }
}
