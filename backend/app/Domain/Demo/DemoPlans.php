<?php

namespace App\Domain\Demo;

/**
 * Planes de premio de la muestra (SPEC-007). PURO (sin base ni red): elige el plan por el monto que participa y calcula la probabilidad de
 * cada plan. El plan lo decide SOLO el servidor con los precios del catálogo guardado (T-D21); el cliente nunca manda plan ni monto.
 */
final class DemoPlans
{
    /**
     * Monto de la compra que participa: suma solo las líneas de productos con `participates` (T-D23: lo excluido no sube de plan).
     *
     * @param  list<array{productId: string, quantity: int}>  $lines
     * @param  array<string, mixed>  $config
     */
    public static function eligibleTotal(array $lines, array $config): int
    {
        $products = [];
        foreach ($config['products'] as $p) {
            $products[$p['id']] = $p;
        }
        $total = 0;
        foreach ($lines as $l) {
            $p = $products[$l['productId']] ?? null;
            if ($p !== null && ($p['participates'] ?? true) === true) {
                $total += $p['price'] * $l['quantity'];
            }
        }

        return $total;
    }

    /**
     * Plan por monto que corresponde a `$amount`, o null si el monto no alcanza el primer plan (SPEC-007 D32: sin turno).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    public static function forAmount(array $config, int $amount): ?array
    {
        if ($amount < 1) {
            return null;
        }
        foreach ($config['plans'] ?? [] as $plan) {
            if ($plan['kind'] === 'amount' && $amount >= $plan['minAmount'] && ($plan['maxAmount'] === null || $amount <= $plan['maxAmount'])) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    public static function find(array $config, string $id): ?array
    {
        foreach ($config['plans'] ?? [] as $plan) {
            if ($plan['id'] === $id) {
                return $plan;
            }
        }

        return null;
    }

    /** Probabilidad exacta de premio de un plan = premios / (premios + sin premio) de su mezcla (SPEC-007 §3.1, CA-74). Un solo lugar. */
    public static function probability(array $plan): float
    {
        $wins = 0;
        $total = 0;
        foreach ($plan['mix'] as $entry) {
            $none = $entry['noPrizeCount'];
            $won = array_sum(array_column($entry['prizes'], 'count'));
            $wins += $won;
            $total += $won + $none;
        }

        return $total === 0 ? 0.0 : $wins / $total;
    }
}
