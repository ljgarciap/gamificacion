<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\DbTestCase;
use Tests\Support\HostTokens;
use Tests\Support\RedeemFixtures;

/** POST /v1/spins por HTTP: autenticación, clave de idempotencia y forma de la respuesta. */
final class SpinApiTest extends DbTestCase
{
    private string $batch;

    private string $parent;

    protected function setUp(): void
    {
        parent::setUp();
        HostTokens::configure();
        $rf = new RedeemFixtures;
        $b = $rf->activeReserveBatch([['prize_id' => 'none', 'quantity' => 8], ['prize_id' => 'p10k', 'quantity' => 2]], 2);
        $this->batch = $b['batch'];
        $code = DB::selectOne('SELECT id FROM codes WHERE batch_id = ? AND redeemed_at IS NULL ORDER BY idx LIMIT 1', [$this->batch])->id;
        DB::update("UPDATE codes SET redeemed_at = now(), redeemed_by = 'host-a:ana' WHERE id = ?", [$code]);
        $this->parent = (string) Str::uuid();
        DB::insert(
            "INSERT INTO redemptions (id, code_id, batch_id, user_id, idempotency_key, prize_id, response, spins_granted) VALUES (?, ?, ?, 'host-a:ana', 'padre-0000001', 'extra', '{}'::json, 1)",
            [$this->parent, $code, $this->batch],
        );
    }

    private function spin(array $body, ?array $headers = null): TestResponse
    {
        return $this->postJson('/v1/spins', $body, $headers ?? HostTokens::bearer('ana') + ['Idempotency-Key' => 'giro-0000001']);
    }

    public function test_un_giro_devuelve_200_con_la_forma_de_una_redencion_y_nunca_se_cachea(): void
    {
        $r = $this->spin(['parentRedemptionId' => $this->parent]);
        $r->assertOk()->assertJsonPath('parentRedemptionId', $this->parent)->assertJsonPath('spinNo', 1)->assertJsonPath('alreadyPlayed', false);
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $r->assertJsonStructure(['redemptionId', 'playedAt', 'campaign' => ['id', 'gameType'], 'outcome' => ['prize', 'extraSpins', 'gameResult']]);
        // Repetir con la misma clave: el mismo cuerpo exacto.
        $this->assertSame($r->getContent(), $this->spin(['parentRedemptionId' => $this->parent])->getContent());
    }

    public function test_sin_token_es_401_y_sin_clave_es_400(): void
    {
        $this->postJson('/v1/spins', ['parentRedemptionId' => $this->parent], ['Idempotency-Key' => 'giro-0000001'])->assertStatus(401);
        $this->spin(['parentRedemptionId' => $this->parent], HostTokens::bearer('ana'))->assertStatus(400)->assertJsonPath('error.code', 'idempotencyKeyRequired');
    }

    public function test_los_errores_de_negocio_tienen_su_estado_y_codigo(): void
    {
        $this->spin(['parentRedemptionId' => $this->parent])->assertOk();
        $this->spin(['parentRedemptionId' => $this->parent], HostTokens::bearer('ana') + ['Idempotency-Key' => 'giro-0000002'])
            ->assertStatus(409)->assertJsonPath('error.code', 'noSpinsLeft');
        $this->spin(['parentRedemptionId' => $this->parent], HostTokens::bearer('beto') + ['Idempotency-Key' => 'giro-0000003'])
            ->assertStatus(404)->assertJsonPath('error.code', 'spinNotFound');
        $this->spin([], HostTokens::bearer('ana') + ['Idempotency-Key' => 'giro-0000004'])->assertStatus(404)->assertJsonPath('error.code', 'spinNotFound');
    }
}
