<?php

namespace App\Support;

/**
 * Interpreta `GAMIF_TRUSTED_PROXIES`. En producción NO se admite dejarla vacía: sin ella todas las peticiones parecen venir de la IP del
 * balanceador y el bloqueo por IP lo comparte todo el mundo (docs/seguridad-b8.md F1, revisión P10). «none» es la confirmación explícita de
 * que la app es alcanzable sin proxy; «*» exige además `GAMIF_TRUSTED_PROXIES_ALLOW_ALL=true`.
 */
final class TrustedProxies
{
    /** @return list<string>|'*'|null  null = no confiar en ninguna cabecera X-Forwarded-* */
    public static function resolve(string $raw, string $env, bool $allowAll = false): array|string|null
    {
        $list = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if ($list === ['none']) {
            return null;
        }
        if ($list === []) {
            if ($env === 'production') {
                throw new \RuntimeException('GAMIF_TRUSTED_PROXIES es obligatoria en producción: la red del balanceador (IP/CIDR) o «none» si no hay proxy.');
            }

            return null;
        }
        if ($list === ['*']) {
            if ($env === 'production' && ! $allowAll) {
                throw new \RuntimeException('GAMIF_TRUSTED_PROXIES=* en producción exige GAMIF_TRUSTED_PROXIES_ALLOW_ALL=true (cualquiera podría falsear su IP si la app es alcanzable sin pasar por el proxy).');
            }

            return '*';
        }

        return $list;
    }
}
