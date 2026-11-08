<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminException;
use App\Domain\Demo\DemoConfig;
use App\Domain\Demo\DemoSwitch;
use App\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Muestra real / modo demo (SPEC-006): configuración del escenario e interruptor. Solo superadmin (`demo.manage`). */
final class DemoController
{
    public function __construct(private readonly DemoSwitch $switch) {}

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
            'limits' => [
                'maxProducts' => DemoConfig::MAX_PRODUCTS, 'maxAmount' => DemoConfig::MAX_AMOUNT,
                'maxCodesPerPurchase' => DemoConfig::MAX_CODES_PER_PURCHASE, 'maxPool' => DemoConfig::MAX_POOL,
                'maxTtlHours' => DemoConfig::MAX_TTL_HOURS, 'games' => DemoConfig::GAMES, 'prizeTypes' => DemoConfig::PRIZE_TYPES,
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
