<?php

namespace Tests\Support;

use App\Domain\Demo\DemoConfig;

/** Configuración de la muestra por planes con mezclas CHICAS (la siembra espera lotes reales): Bienvenida 6, Bronce 2, Plata 6 y Oro 6 códigos. */
final class PlansFixture
{
    /**
     * @param  array<string, mixed>  $triggers
     * @param  array<string, mixed>  $credit
     * @return array<string, mixed>
     */
    public static function config(array $triggers = [], array $credit = []): array
    {
        $c = DemoConfig::defaults();
        $c['mode'] = 'plans';
        $c['user']['initialBalance'] = 1000;
        $c['credit'] = array_replace($c['credit'], $credit);
        $c['products'] = [
            ['id' => 'a999', 'name' => 'Producto 999', 'price' => 999, 'participates' => true],
            ['id' => 'b1000', 'name' => 'Producto 1.000', 'price' => 1_000, 'participates' => true],
            ['id' => 'c10001', 'name' => 'Producto 10.001', 'price' => 10_001, 'participates' => true],
            ['id' => 'd50001', 'name' => 'Producto 50.001', 'price' => 50_001, 'participates' => true],
            ['id' => 'pasa', 'name' => 'Pasa Plata', 'price' => 20_000, 'participates' => false],
        ];
        $money = static fn (int $amount, int $count): array => ['type' => 'money', 'amount' => $amount, 'count' => $count];
        $mix = static fn (array $prizes, int $none): array => ['roulette' => ['prizes' => $prizes, 'noPrizeCount' => $none]];
        $c['plans'] = [
            ['id' => 'welcome', 'kind' => 'event', 'name' => 'Bienvenida', 'maxMoneyPrize' => 5_000, 'mix' => $mix([$money(2_000, 3), $money(3_000, 3)], 0)],
            ['id' => 'bronce', 'kind' => 'amount', 'name' => 'Bronce', 'minAmount' => 1_000, 'maxAmount' => 10_000, 'maxMoneyPrize' => 10_000, 'mix' => $mix([$money(10_000, 1)], 1)],
            ['id' => 'plata', 'kind' => 'amount', 'name' => 'Plata', 'minAmount' => 10_001, 'maxAmount' => 50_000, 'maxMoneyPrize' => 20_000, 'mix' => $mix([$money(20_000, 1)], 5)],
            ['id' => 'oro', 'kind' => 'amount', 'name' => 'Oro', 'minAmount' => 50_001, 'maxAmount' => null, 'maxMoneyPrize' => 30_000, 'mix' => $mix([$money(30_000, 2), ['type' => 'coupon', 'amount' => 0, 'count' => 1]], 3)],
        ];
        $c['triggers'] = array_replace(['reactivationDays' => 7, 'turnsPerPurchase' => 1, 'welcomeOnFirstLogin' => true], $triggers);
        unset($c['mix']);

        return DemoConfig::validate($c);
    }
}
