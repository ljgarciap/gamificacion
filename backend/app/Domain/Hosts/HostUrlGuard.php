<?php

namespace App\Domain\Hosts;

use Closure;

/**
 * Defensa SSRF de las URL de host (SPEC-003 RF-54, amenazas T18/T23). Se usa al GUARDAR la URL (falla pronto) y de nuevo en CADA llamada, y
 * devuelve las IP ya validadas para que la conexión se FIJE a ellas: así un DNS que cambie de respuesta entre la validación y la conexión
 * (*DNS rebinding*) no puede llevar la llamada a otra parte. Quien llama además NO sigue redirecciones.
 *
 * Reglas: `https` (y `http` solo en `local`); sin usuario/contraseña ni fragmento; sin caracteres de control; puerto 443 o uno de la lista
 * configurada (en `local`, cualquiera); nombre de dominio completo (no `localhost`, ni una sola etiqueta, ni números disfrazados de IP como
 * `2130706433` o `0x7f.1`); y TODAS las IP a las que resuelva deben ser públicas: se rechaza bucle, privadas, enlace local, metadatos de
 * nube, CGNAT, documentación, multicast y reservadas, en IPv4 e IPv6, incluidas las formas que esconden una IPv4 (`::ffff:`, NAT64, 6to4).
 */
final class HostUrlGuard
{
    private const V4_BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
        '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    private const V6_BLOCKED = [
        '::/128', '::1/128', '100::/64', '2001:db8::/32', '2001::/32', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    private readonly Closure $resolver;

    /**
     * @param  list<int>  $ports  puertos permitidos además del 443 (fuera de `local`)
     * @param  (callable(string): list<string>)|null  $resolver  nombre → IP; inyectable SOLO para pruebas
     */
    public function __construct(private readonly bool $allowLocal = false, private readonly array $ports = [], ?callable $resolver = null)
    {
        $this->resolver = $resolver !== null ? Closure::fromCallable($resolver) : $this->systemResolver(...);
    }

    public static function fromConfig(): self
    {
        // Relajar la defensa exige las DOS cosas: entorno `local` y el interruptor explícito (un APP_ENV=local olvidado en producción no basta).
        return new self(app()->environment('local') && (bool) config('gamification.host.allow_local'), array_map('intval', (array) config('gamification.host_ports', [])));
    }

    /**
     * @return array{scheme: string, host: string, port: int, ips: list<string>} lo validado; conectar a una de `ips` con el nombre en la cabecera Host/SNI
     *
     * @throws HostUrlRejected
     */
    public function check(string $url): array
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new HostUrlRejected('La URL está vacía, es demasiado larga o tiene espacios o caracteres de control.');
        }
        $p = parse_url($url);
        if ($p === false || ! isset($p['scheme'], $p['host'])) {
            throw new HostUrlRejected('La URL no es válida.');
        }
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'https' && ! ($scheme === 'http' && $this->allowLocal)) {
            throw new HostUrlRejected('Solo se admite https.');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new HostUrlRejected('La URL no puede llevar usuario ni contraseña.');
        }
        if (isset($p['fragment'])) {
            throw new HostUrlRejected('La URL no puede llevar fragmento (#).');
        }
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (! $this->allowLocal && $port !== 443 && ! in_array($port, $this->ports, true)) {
            throw new HostUrlRejected("El puerto $port no está permitido.");
        }

        $host = strtolower(rtrim($p['host'], '.'));
        if (str_starts_with($host, '[')) {
            $host = trim($host, '[]');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            if ($this->allowLocal && ! str_contains($host, '.')) {
                // En local se acepta un nombre de servicio de Docker (p. ej. `host-sim`).
                $ok = preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $host) === 1;
            } else {
                $ok = preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1;
            }
            if (! $ok) {
                throw new HostUrlRejected('El nombre del servidor no es un dominio válido.');
            }
            $ips = ($this->resolver)($host);
            if ($ips === []) {
                throw new HostUrlRejected('El nombre del servidor no resuelve a ninguna dirección.');
            }
        }

        if (! $this->allowLocal) {
            foreach ($ips as $ip) {
                if (self::isForbidden($ip)) {
                    throw new HostUrlRejected("La dirección $ip no es pública: no se permite llamar a redes internas.");
                }
            }
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => array_values($ips)];
    }

    /** true si la IP NO es un destino público válido (o no es una IP). */
    public static function isForbidden(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return true;
        }
        if (strlen($bin) === 4) {
            return self::inAny($bin, self::V4_BLOCKED);
        }
        // IPv6 que esconde una IPv4: se juzga por la IPv4.
        if (str_starts_with($bin, str_repeat("\0", 10)."\xff\xff")) {      // ::ffff:a.b.c.d
            return self::isForbidden(inet_ntop(substr($bin, 12)));
        }
        if (str_starts_with($bin, "\x00\x64\xff\x9b".str_repeat("\0", 8))) { // 64:ff9b::/96 (NAT64)
            return self::isForbidden(inet_ntop(substr($bin, 12)));
        }
        if (str_starts_with($bin, "\x20\x02")) {                              // 2002::/16 (6to4): IPv4 en los bytes 2-5
            return self::isForbidden(inet_ntop(substr($bin, 2, 4)));
        }
        if (str_starts_with($bin, str_repeat("\0", 12))) {                    // ::a.b.c.d (compatible, obsoleto)
            return true;
        }

        return self::inAny($bin, self::V6_BLOCKED);
    }

    /** @param list<string> $cidrs */
    private static function inAny(string $bin, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $netBin = inet_pton($net);
            if (strlen($netBin) !== strlen($bin)) {
                continue;
            }
            $bits = (int) $bits;
            $full = intdiv($bits, 8);
            if (substr($bin, 0, $full) !== substr($netBin, 0, $full)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($bin[$full]) & $mask) === (ord($netBin[$full]) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function systemResolver(string $host): array
    {
        $ips = [];
        foreach (['A' => DNS_A, 'AAAA' => DNS_AAAA] as $type => $flag) {
            foreach (@dns_get_record($host, $flag) ?: [] as $r) {
                $ips[] = (string) ($r['ip'] ?? $r['ipv6'] ?? '');
            }
        }

        return array_values(array_filter(array_unique($ips)));
    }
}
