<?php

namespace Tests\Support;

use App\Domain\Audit\AuditLog;
use App\Domain\Codes\CodeFormat;
use App\Domain\Codes\CodeGenerator;
use App\Domain\Crypto\CodeHasher;
use App\Domain\Redemption\Presentation;
use App\Domain\Redemption\RedeemService;
use App\Domain\Redemption\RedeemThrottle;
use App\Domain\Redemption\RedemptionWriter;
use App\Domain\Redemption\SpinService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Lotes ACTIVOS con códigos reales (los que generaría un operador), listos para redimir. */
final class RedeemFixtures
{
    public readonly Fixtures $f;

    public function __construct(?Fixtures $f = null)
    {
        $this->f = $f ?? new Fixtures;
    }

    /**
     * @param  list<array{prize_id: string, quantity: int}>|null  $pool
     * @return array{batch: string, campaign: string, codes: list<string>, prizeOf: array<string, string>}
     *                                                                                                     codes: como los imprimiría el operador (PREFIJO-XXXX-XXXX-XXX); prizeOf: código => id de premio
     */
    public function activeBatch(
        ?array $pool = null,
        string $gameType = 'roulette',
        string $usedCodeBehavior = 'rejectOnly',
        ?Carbon $redeemableUntil = null,
    ): array {
        $pool ??= Fixtures::pool();
        $campaign = $this->f->campaign($gameType, $usedCodeBehavior);
        $batch = $this->f->lifecycle()->createDraft($campaign, 'Lote real', $pool, 'tester', $redeemableUntil);

        $codes = $this->generateCodes($batch);
        $this->f->lifecycle()->commit($batch, 'tester');
        $this->f->activate($batch);

        return ['batch' => $batch, 'campaign' => $campaign, 'codes' => $codes, 'prizeOf' => $this->prizeMap($batch, $codes)];
    }

    /**
     * Lote activo CON reserva, en una campaña con el premio «giro extra» (`extra`). Los códigos se generan con el generador real.
     *
     * @param  list<array{prize_id: string, quantity: int}>  $pool  N + R posiciones
     * @return array{batch: string, campaign: string, codes: list<string>, prizeOf: array<string, string>}
     */
    public function activeReserveBatch(array $pool, int $reserve, int $extraSpins = 1, string $usedCodeBehavior = 'rejectOnly', ?Carbon $redeemableUntil = null, bool $asMoney = false): array
    {
        $campaign = $this->f->campaign('roulette', $usedCodeBehavior);
        // `$asMoney`: el premio nace como dinero para poder armar un pool que la validación de la reserva no admitiría; se convierte al activar.
        DB::table('prizes')->insert($asMoney
            ? ['campaign_id' => $campaign, 'prize_id' => 'extra', 'type' => 'money', 'label' => 'Gira de nuevo', 'amount' => 1, 'meta' => '{}']
            : ['campaign_id' => $campaign, 'prize_id' => 'extra', 'type' => 'extraSpin', 'label' => 'Gira de nuevo', 'amount' => null, 'meta' => json_encode(['extra_spins' => $extraSpins])]);
        DB::table('campaigns')->where('id', $campaign)->update(['game_config' => json_encode(['segments' => ['p50k', 'none', 'p10k', 'extra', 'none', 'p10k', 'none', 'p50k']])]);
        $batch = $this->f->lifecycle()->createDraft($campaign, 'Lote con reserva', $pool, 'tester', $redeemableUntil, $reserve);

        $codes = $this->generateCodes($batch);
        $this->f->lifecycle()->commit($batch, 'tester');
        $this->f->activate($batch);
        if ($asMoney) {
            $this->turnIntoExtraSpin($campaign, 'extra', $extraSpins);
        }

        return ['batch' => $batch, 'campaign' => $campaign, 'codes' => $codes, 'prizeOf' => $this->prizeMap($batch, $codes)];
    }

    /** Genera con el generador real y devuelve los códigos exportados (el archivo se borra). @return list<string> */
    public function generateCodes(string $batchId): array
    {
        $path = sys_get_temp_dir().'/redeem-fixture-'.bin2hex(random_bytes(6)).'.csv';
        $generator = new CodeGenerator($this->f->lifecycle(), new CodeHasher, $this->f->audit);
        $generator->generate($batchId, 'RULETA', $path, 'tester');
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        @unlink($path);
        array_shift($lines); // cabecera

        return $lines;
    }

    /** @param list<string> $codes @return array<string, string> */
    public function prizeMap(string $batchId, array $codes): array
    {
        $hasher = new CodeHasher;
        $map = [];
        foreach ($codes as $code) {
            $row = DB::selectOne(
                "SELECT idx, encode(prize_enc, 'hex') AS p FROM codes WHERE code_hash = decode(?, 'hex')",
                [bin2hex($hasher->hash(CodeFormat::normalize($code)))],
            );
            $map[$code] = $this->f->vault->decryptPrize($batchId, (int) $row->idx, hex2bin($row->p));
        }

        return $map;
    }

    /** Servicio con las piezas reales. El generador de presentación se puede inyectar para forzar fallos. */
    public function service(?Presentation $presentation = null, ?RedeemThrottle $throttle = null): RedeemService
    {
        return new RedeemService(
            $this->f->vault, new CodeHasher, new AuditLog, $throttle ?? new RedeemThrottle, $presentation ?? new Presentation,
        );
    }

    /**
     * Simula un lote comprometido ANTES de que se prohibiera el giro extra sin reserva: el premio pasa a ser de giro extra con el guardián
     * de premios apagado (un premio usado por un lote comprometido ya no se puede modificar). Solo para pruebas.
     */
    public function turnIntoExtraSpin(string $campaignId, string $prizeId, int $spins = 1): void
    {
        DB::statement('ALTER TABLE prizes DISABLE TRIGGER prizes_guard_trg');
        DB::table('prizes')->where('campaign_id', $campaignId)->where('prize_id', $prizeId)
            ->update(['type' => 'extraSpin', 'amount' => null, 'meta' => json_encode(['extra_spins' => $spins])]);
        DB::statement('ALTER TABLE prizes ENABLE TRIGGER prizes_guard_trg');
    }

    public function spinService(?RedeemThrottle $throttle = null): SpinService
    {
        return new SpinService(
            $this->f->vault, $throttle ?? new RedeemThrottle, new RedemptionWriter(new AuditLog, new Presentation),
        );
    }

    /** Código con formato y dígito de verificación válidos que NO existe en ninguna tabla. */
    public static function unknownCode(string $seed = 'desconocido'): string
    {
        return CodeFormat::display(CodeFormat::canonicalFromBytes(substr(hash('sha256', $seed, true), 0, CodeFormat::BYTES_NEEDED)), 'RULETA');
    }
}
