<?php

namespace Tests\Unit\Hosts;

use App\Domain\Hosts\HostUrlGuard;
use App\Domain\Hosts\HostUrlRejected;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Defensa SSRF (SPEC-003 RF-54, CA-37, amenaza T18). */
final class HostUrlGuardTest extends TestCase
{
    private function guard(array $map = [], bool $local = false, array $ports = []): HostUrlGuard
    {
        return new HostUrlGuard($local, $ports, fn (string $h): array => $map[$h] ?? []);
    }

    /** @return array<string, array{0: string}> */
    public static function forbiddenIps(): array
    {
        $ips = [
            '127.0.0.1', '127.1.2.3', '0.0.0.0', '10.0.0.5', '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.169.254', '169.254.0.1',
            '100.64.0.1', '100.127.255.255', '192.0.2.1', '198.18.0.1', '198.51.100.7', '203.0.113.9', '224.0.0.1', '240.0.0.1', '255.255.255.255',
            '::1', '::', 'fe80::1', 'fc00::1', 'fd00:ec2::254', 'ff02::1', '2001:db8::1', 'fec0::1',
            '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '::ffff:7f00:1', '64:ff9b::7f00:1', '64:ff9b::a9fe:a9fe', '2002:7f00:1::1', '2002:a9fe:a9fe::1', '::127.0.0.1',
            'no-es-una-ip',
        ];

        return array_combine($ips, array_map(static fn (string $i): array => [$i], $ips));
    }

    #[DataProvider('forbiddenIps')]
    public function test_rechaza_las_ip_no_publicas(string $ip): void
    {
        $this->assertTrue(HostUrlGuard::isForbidden($ip), $ip);
    }

    /** @return array<string, array{0: string}> */
    public static function publicIps(): array
    {
        $ips = ['8.8.8.8', '1.1.1.1', '172.15.255.255', '172.32.0.1', '100.63.255.255', '100.128.0.1', '169.253.0.1', '193.0.0.1', '2606:4700:4700::1111', '2001:4860:4860::8888', '::ffff:8.8.8.8', '2002:0808:0808::1'];

        return array_combine($ips, array_map(static fn (string $i): array => [$i], $ips));
    }

    #[DataProvider('publicIps')]
    public function test_acepta_las_ip_publicas_en_el_borde(string $ip): void
    {
        $this->assertFalse(HostUrlGuard::isForbidden($ip), $ip);
    }

    /** @return array<string, array{0: string}> */
    public static function badUrls(): array
    {
        $urls = [
            'http://host.example.com/cb', 'ftp://host.example.com/', 'javascript:alert(1)', '//host.example.com/', 'host.example.com/cb', '', 'https://',
            'https://user:pass@host.example.com/cb', 'https://user@host.example.com/cb', 'https://host.example.com/cb#frag',
            'https://host.example.com:8443/cb', 'https://host.example.com:80/cb',
            'https://localhost/cb', 'https://metadata/cb', 'https://intranet/cb', 'https://127.0.0.1/cb', 'https://[::1]/cb', 'https://169.254.169.254/latest/meta-data',
            'https://2130706433/cb', 'https://0x7f.0.0.1/cb', 'https://0177.0.0.1/cb', 'https://127.1/cb', 'https://[::ffff:127.0.0.1]/cb',
            "https://host.example.com/cb\n", 'https://host.example.com/c b',
            'https://interno.example.com/cb', 'https://mixto.example.com/cb', 'https://sinip.example.com/cb', 'https://-malo.example.com/cb',
            'https://'.str_repeat('a', 64).'.example.com/cb', 'https://example.com/'.str_repeat('x', 2100),
        ];

        return array_combine(array_map(static fn (string $u): string => substr(addcslashes($u, "\n"), 0, 60), $urls), array_map(static fn (string $u): array => [$u], $urls));
    }

    #[DataProvider('badUrls')]
    public function test_rechaza_las_urls_peligrosas(string $url): void
    {
        $g = $this->guard(['interno.example.com' => ['10.0.0.8'], 'mixto.example.com' => ['8.8.8.8', '127.0.0.1'], 'host.example.com' => ['8.8.8.8']]);
        $this->expectException(HostUrlRejected::class);
        $g->check($url);
    }

    public function test_acepta_https_publico_y_devuelve_las_ip_para_fijar_la_conexion(): void
    {
        $r = $this->guard(['host.example.com' => ['8.8.8.8', '2606:4700:4700::1111']])->check('https://Host.Example.com./callback?x=1');
        $this->assertSame(['https', 'host.example.com', 443, ['8.8.8.8', '2606:4700:4700::1111']], [$r['scheme'], $r['host'], $r['port'], $r['ips']]);
        $this->assertSame(['8.8.8.8'], $this->guard()->check('https://8.8.8.8/cb')['ips']);
        $this->assertSame(['2606:4700:4700::1111'], $this->guard()->check('https://[2606:4700:4700::1111]/cb')['ips']);
    }

    public function test_los_puertos_extra_solo_si_estan_en_la_lista(): void
    {
        $g = $this->guard(['h.example.com' => ['8.8.8.8']], false, [8443]);
        $this->assertSame(8443, $g->check('https://h.example.com:8443/cb')['port']);
        $this->expectException(HostUrlRejected::class);
        $g->check('https://h.example.com:9443/cb');
    }

    public function test_en_local_se_admite_http_y_redes_internas_pero_no_basura(): void
    {
        $g = $this->guard(['host-sim' => ['172.20.0.5']], true);
        $this->assertSame(['http', 'host-sim', 8081], [$g->check('http://host-sim:8081/cb')['scheme'], $g->check('http://host-sim:8081/cb')['host'], $g->check('http://host-sim:8081/cb')['port']]);
        $g->check('http://127.0.0.1:9000/cb');
        foreach (['https://user:p@x.example.com/', 'ftp://x.example.com/', 'https://x.example.com/#f', "http://host-sim/\x00"] as $bad) {
            try {
                $g->check($bad);
                $this->fail("debía rechazar $bad");
            } catch (HostUrlRejected) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
