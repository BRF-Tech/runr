<?php

namespace Tests\Unit\Observability;

use App\Services\Dns\DnsResolver;
use App\Services\Observability\OutboundTarget;
use App\Services\Observability\OutboundUrlGuard;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RunR's outbound guard (GHSA-2c93-rcvw-qpq2): which addresses are public — the IPv6 transition
 * forms judged by the IPv4 address they carry — and the pin of a request to the addresses the
 * guard checked, so a name cannot answer a public address to the check and an internal one to
 * the connection.
 */
class OutboundUrlGuardTest extends TestCase
{
    /** @var array<string, list<list<string>>> host → its answers, one per lookup (the last one repeats) */
    private array $answers = [];

    /** @var list<string> the names looked up, in order */
    private array $lookups = [];

    private function guard(): OutboundUrlGuard
    {
        $test = $this;

        return new OutboundUrlGuard(new class($test) extends DnsResolver
        {
            public function __construct(private readonly OutboundUrlGuardTest $test) {}

            public function addresses(string $name): array
            {
                return $this->test->answer($name);
            }
        });
    }

    /** @return list<string> */
    public function answer(string $name): array
    {
        $this->lookups[] = $name;
        $queue = $this->answers[$name] ?? [[]];
        $answer = $queue[0];
        if (count($queue) > 1) {
            array_shift($this->answers[$name]);
        }

        return $answer;
    }

    /** @return array<string, array{0: string}> */
    public static function blockedAddresses(): array
    {
        $out = [];
        foreach ([
            // IPv4
            '0.0.0.0', '0.1.2.3', '10.0.0.1', '100.64.0.1', '100.127.255.255', '127.0.0.1', '169.254.169.254',
            '172.16.0.1', '172.31.255.255', '192.0.0.170', '192.168.1.1', '198.18.0.1', '198.19.255.255',
            '224.0.0.1', '240.0.0.1', '255.255.255.255',
            // IPv6
            '::', '::1', 'fc00::1', 'fd00::1', 'fe80::1', 'fec0::1', 'feff::1', 'ff02::1', '100::1',
            '64:ff9b:1::a9fe:a9fe', '2001::1', '2001:0:4136:e378:8000:63bf:3fff:fdd2', '2001:2::1', '2001:10::1',
            '2001:20::1', '2001:db8::1', '3fff::1', '::ffff:0:a9fe:a9fe',
            // IPv6 transition forms carrying a private, loopback or link-local IPv4 address
            '::ffff:127.0.0.1', '::ffff:169.254.169.254', '::ffff:10.0.0.1', '::ffff:192.168.1.1',
            '::a9fe:a9fe', '::7f00:1', '::10.0.0.1',
            '2002:a9fe:a9fe::', '2002:a9fe:a9fe:1::1', '2002:7f00:1::', '2002:0a00:0001::', '2002:c0a8:0101::',
            '64:ff9b::a9fe:a9fe', '64:ff9b::7f00:1', '64:ff9b::10.0.0.1', '64:ff9b::100.64.0.1',
            // not an address at all
            'not-an-ip', 'fe80::1%eth0', '',
        ] as $ip) {
            $out[$ip === '' ? '(empty)' : $ip] = [$ip];
        }

        return $out;
    }

    /** @return array<string, array{0: string}> */
    public static function publicAddresses(): array
    {
        $out = [];
        foreach ([
            '8.8.8.8', '1.1.1.1', '100.128.0.1', '93.184.216.34', '151.101.1.69', '203.0.113.10',
            '2606:4700:4700::1111', '2a00:1450:4001:82a::200e',
            '::ffff:8.8.8.8', '2002:0808:0808::', '64:ff9b::808:808', '64:ff9b::8.8.8.8', '::808:808',
        ] as $ip) {
            $out[$ip] = [$ip];
        }

        return $out;
    }

    #[DataProvider('blockedAddresses')]
    public function test_not_public(string $ip): void
    {
        $this->assertFalse(OutboundUrlGuard::isPublicIp($ip), "{$ip} counts as public");
    }

    #[DataProvider('publicAddresses')]
    public function test_public(string $ip): void
    {
        $this->assertTrue(OutboundUrlGuard::isPublicIp($ip), "{$ip} counts as private");
    }

    public function test_embedded_ipv4(): void
    {
        $this->assertSame('169.254.169.254', OutboundUrlGuard::embeddedIpv4('::ffff:169.254.169.254'));
        $this->assertSame('169.254.169.254', OutboundUrlGuard::embeddedIpv4('::a9fe:a9fe'));
        $this->assertSame('169.254.169.254', OutboundUrlGuard::embeddedIpv4('64:ff9b::a9fe:a9fe'));
        $this->assertSame('169.254.169.254', OutboundUrlGuard::embeddedIpv4('2002:a9fe:a9fe:1::1'));
        $this->assertSame('8.8.8.8', OutboundUrlGuard::embeddedIpv4('2002:0808:0808::'));
        $this->assertNull(OutboundUrlGuard::embeddedIpv4('2606:4700:4700::1111'));
        $this->assertNull(OutboundUrlGuard::embeddedIpv4('64:ff9b:1::a9fe:a9fe'), 'local-use NAT64: blocked, not judged');
        $this->assertNull(OutboundUrlGuard::embeddedIpv4('8.8.8.8'));
    }

    /** @return array<string, array{0: string}> */
    public static function blockedUrls(): array
    {
        return [
            '6to4 metadata' => ['http://[2002:a9fe:a9fe::]/latest/meta-data'],
            'NAT64 metadata' => ['http://[64:ff9b::a9fe:a9fe]/latest/meta-data'],
            'NAT64 local-use' => ['http://[64:ff9b:1::a9fe:a9fe]/'],
            'compatible metadata' => ['http://[::a9fe:a9fe]/'],
            'mapped loopback' => ['http://[::ffff:127.0.0.1]:8080/'],
            'site-local' => ['http://[fec0::1]/'],
            'teredo' => ['http://[2001:0:4136:e378:8000:63bf:3fff:fdd2]/'],
            'documentation' => ['http://[2001:db8::1]/'],
            'discard' => ['http://[100::1]/'],
            'decimal ipv4' => ['http://2130706433/'],
            'hex ipv4' => ['http://0x7f.1/'],
            'short ipv4' => ['http://127.1/'],
            'octal ipv4' => ['http://0177.0.0.1/'],
            'non-ascii host' => ['http://hoöks.example.com/'],
            'percent-encoded host' => ['http://hooks%2eexample.com/'],
            'name in brackets' => ['http://[hooks.example.com]/'],
            'ipv4 in brackets' => ['http://[127.0.0.1]/'],
            'port 0' => ['http://hooks.example.com:0/'],
            'localhost.localdomain' => ['http://localhost.localdomain/'],
            'trailing dot localhost' => ['http://localhost./'],
            'cluster service' => ['http://runr-api.runr.svc.cluster.local/'],
            'name of a NAT64 metadata address' => ['https://nat64.example.com/'],
            'name of a 6to4 metadata address' => ['https://sixtofour.example.com/'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function test_blocked_urls(string $url): void
    {
        $this->answers = [
            'nat64.example.com' => [['93.184.216.34', '64:ff9b::a9fe:a9fe']],
            'sixtofour.example.com' => [['2002:a9fe:a9fe::1']],
        ];

        $target = $this->guard()->target($url);

        $this->assertFalse($target->allowed(), "{$url} may be called");
        $this->assertNotNull($this->guard()->check($url));
    }

    public function test_public_transition_forms_may_be_called(): void
    {
        foreach (['http://[::ffff:8.8.8.8]/', 'http://[2002:0808:0808::]/', 'http://[64:ff9b::808:808]/', 'https://[2606:4700:4700::1111]/'] as $url) {
            $this->assertNull($this->guard()->check($url), $url);
        }
    }

    public function test_the_checked_addresses_are_pinned(): void
    {
        $this->answers['hooks.example.com'] = [['93.184.216.34', '93.184.216.35', '2606:4700:4700::1111']];

        $target = $this->guard()->target('https://hooks.example.com/services/T/B/x');

        $this->assertTrue($target->allowed());
        $this->assertSame([
            'allow_redirects' => false,
            'proxy' => '', // no proxy of the environment: straight to the checked addresses
            'curl' => [CURLOPT_RESOLVE => [
                'hooks.example.com:443:93.184.216.34,93.184.216.35,[2606:4700:4700::1111]',
                'hooks.example.com.:443:93.184.216.34,93.184.216.35,[2606:4700:4700::1111]',
            ]],
        ], $target->options(), 'every checked address, IPv6 in brackets, the name with and without its trailing dot');

        $this->answers['hooks.example.com.'] = [['93.184.216.34']]; // looked up as written, lower-cased
        $this->assertSame([
            'hooks.example.com:8443:93.184.216.34',
            'hooks.example.com.:8443:93.184.216.34',
        ], $this->guard()->target('http://Hooks.Example.com.:8443/x')->options()['curl'][CURLOPT_RESOLVE], 'explicit port, trailing dot');

        $this->answers['plain.example.com'] = [['93.184.216.34']];
        $this->assertSame('plain.example.com:80:93.184.216.34', $this->guard()->target('http://plain.example.com/')->options()['curl'][CURLOPT_RESOLVE][0]);
    }

    public function test_literal_addresses_and_allowed_private_targets_need_no_pin(): void
    {
        $this->assertSame(['allow_redirects' => false], $this->guard()->target('http://151.101.1.69/hook')->options());
        $this->assertSame(['allow_redirects' => false], $this->guard()->target('https://[2606:4700:4700::1111]:8443/')->options());

        $this->answers['internal.example.com'] = [['10.1.2.3']];
        $allowed = $this->guard()->target('https://internal.example.com/', true);
        $this->assertTrue($allowed->allowed());
        $this->assertSame(['allow_redirects' => false], $allowed->options());
        $this->assertSame([], $this->lookups, 'nothing to resolve: a literal address, or private targets allowed');

        $this->assertSame('credentials', $this->guard()->target('https://u:p@internal.example.com/', true)->code, 'scheme and credentials still checked');
    }

    public function test_every_address_must_be_public(): void
    {
        $this->answers['mixed.example.com'] = [['93.184.216.35', '127.0.0.1']];
        $this->answers['mapped.example.com'] = [['93.184.216.35', '::ffff:169.254.169.254']];

        $this->assertSame('private', $this->guard()->target('https://mixed.example.com/')->code);
        $this->assertSame('private', $this->guard()->target('https://mapped.example.com/')->code);
        $this->assertSame('unresolvable', $this->guard()->target('https://nothing.example.com/')->code);
        $this->assertSame(__('observability.url.unresolvable'), $this->guard()->target('https://nothing.example.com/')->reason);
    }

    /**
     * DNS rebinding: the name answers a public address first and the metadata address afterwards.
     * The guard resolves once; the request goes out with the pin of the address it checked, so the
     * HTTP client never asks DNS again (test_curl_connects_to_the_pinned_address_without_dns).
     */
    public function test_a_rebinding_name_is_pinned_to_the_checked_address(): void
    {
        $this->answers['rebind.example.com'] = [['93.184.216.34'], ['169.254.169.254']];

        $seen = [];
        Http::fake(function ($request, array $options) use (&$seen) {
            $seen[] = $options;

            return Http::response('ok');
        });

        $url = 'http://rebind.example.com/latest/meta-data';
        $target = $this->guard()->target($url);
        Http::withOptions($target->options())->get($url);

        $this->assertSame(['rebind.example.com'], $this->lookups, 'resolved once');
        $this->assertCount(1, $seen);
        $this->assertFalse($seen[0]['allow_redirects']);
        $this->assertSame(['rebind.example.com:80:93.184.216.34', 'rebind.example.com.:80:93.184.216.34'], $seen[0]['curl'][CURLOPT_RESOLVE]);

        // A second check sees the internal address and refuses
        $this->assertSame('private', $this->guard()->target($url)->code);
    }

    /**
     * The pin with a real curl handle: a name that cannot resolve (.invalid) reaches the pinned
     * address — curl takes the address from CURLOPT_RESOLVE and does not ask DNS. The first
     * address refuses (nothing listens on its port), curl fails over to the next one.
     */
    public function test_curl_connects_to_the_pinned_address_without_dns(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($server, $error);
        $port = (int) substr((string) stream_socket_get_name($server, false), strrpos((string) stream_socket_get_name($server, false), ':') + 1);

        $options = OutboundTarget::pinned('runr-pin-test.invalid', $port, ['::1', '127.0.0.1'])->options();

        $handler = new CurlMultiHandler;
        $client = new Client(['handler' => HandlerStack::create($handler), 'http_errors' => false]);
        $promise = $client->getAsync("http://runr-pin-test.invalid:{$port}/", $options + ['timeout' => 5]);

        $connection = null;
        $deadline = microtime(true) + 5;
        while ($promise->getState() === 'pending' && microtime(true) < $deadline) {
            $handler->tick();
            if ($connection === null && ($accepted = @stream_socket_accept($server, 0.05))) {
                $connection = $accepted;
                fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 6\r\nConnection: close\r\n\r\npinned");
            }
        }
        $response = $promise->wait();

        $this->assertNotNull($connection, 'curl connected to the pinned address');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pinned', (string) $response->getBody());

        fclose($connection);
        fclose($server);
    }
}
