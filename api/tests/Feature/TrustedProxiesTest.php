<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustProxies;
use App\Support\ApiRateLimits;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The client IP (per-IP rate limits, audit IPs) comes from X-Forwarded-For only
 * as far as the known proxies wrote it. Helm: client → ingress controller (Traefik) →
 * runr-gateway (Caddy) → runr-api = 2 hops from the pod network; a client's own
 * X-Forwarded-For never counts, and a peer outside TRUSTED_PROXIES is the client itself.
 */
class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    private const GATEWAY = '10.42.0.20';   // runr-gateway pod (REMOTE_ADDR at the API)

    private const TRAEFIK = '10.42.0.7';    // ingress controller pod (appended by the gateway)

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->get('/api/_client-ip', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'key' => ApiRateLimits::principalKey($request),
            'scheme' => $request->getScheme(),
            'host' => $request->getHost(),
        ]));
        app('router')->getRoutes()->refreshNameLookups();
    }

    private function clientIp(string $peer, ?string $forwardedFor = null, array $headers = []): array
    {
        $headers += $forwardedFor === null ? [] : ['X-Forwarded-For' => $forwardedFor];

        return $this->withServerVariables(['REMOTE_ADDR' => $peer])
            ->getJson('/api/_client-ip', $headers)
            ->assertOk()
            ->json();
    }

    private function helmChain(): void
    {
        config(['trustedproxy.proxies' => 'private_ranges', 'trustedproxy.hops' => 2]);
    }

    public function test_the_framework_trust_proxies_middleware_is_replaced(): void
    {
        $kernel = app(Kernel::class);

        $this->assertTrue($kernel->hasMiddleware(TrustProxies::class));
        $this->assertFalse($kernel->hasMiddleware(\Illuminate\Http\Middleware\TrustProxies::class));
        $this->assertSame('private_ranges', config('trustedproxy.proxies'));
        $this->assertSame(1, config('trustedproxy.hops'));
    }

    public function test_a_spoofed_header_from_an_untrusted_peer_changes_nothing(): void
    {
        $this->helmChain();

        $response = $this->clientIp('203.0.113.7', '198.51.100.99, 10.42.0.7');

        $this->assertSame('203.0.113.7', $response['ip']);
        $this->assertSame('ip:203.0.113.7', $response['key']);
    }

    public function test_rotating_a_spoofed_header_does_not_escape_the_guest_limit(): void
    {
        $this->helmChain();
        config(['ratelimit.api.per_guest_per_minute' => 2]);

        $call = fn (string $spoof) => $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->getJson('/api/version', ['X-Forwarded-For' => $spoof]);

        $call('198.51.100.1')->assertOk();
        $call('198.51.100.2')->assertOk();
        $call('198.51.100.3')->assertStatus(429);
    }

    public function test_behind_ingress_and_gateway_the_client_is_the_address_the_ingress_saw(): void
    {
        $this->helmChain();

        // default Traefik: drops client-sent X-Forwarded-For, sets the client's address
        $this->assertSame('198.51.100.23', $this->clientIp(self::GATEWAY, '198.51.100.23, '.self::TRAEFIK)['ip']);

        // an ingress that trusts forwarded headers from every source (RunR's region Traefik
        // before 0.4.1, or ingress_trusted_ips covering the client) keeps what the
        // client sent in front: still the address the ingress saw
        $this->assertSame('198.51.100.23', $this->clientIp(self::GATEWAY, '1.2.3.4, 198.51.100.23, '.self::TRAEFIK)['ip']);

        // …also for a client inside a private network (range trust alone would pick 1.2.3.4) —
        // or behind a load balancer that hands Traefik a private source address
        $this->assertSame('10.9.8.7', $this->clientIp(self::GATEWAY, '1.2.3.4, 10.9.8.7, '.self::TRAEFIK)['ip']);
        $this->assertSame('10.9.8.7', $this->clientIp(self::GATEWAY, '10.9.8.7, '.self::TRAEFIK)['ip']);

        // ports and IPv6
        $this->assertSame('198.51.100.23', $this->clientIp(self::GATEWAY, '1.2.3.4, 198.51.100.23:51234, '.self::TRAEFIK.':443')['ip']);
        $this->assertSame('2001:db8::42', $this->clientIp(self::GATEWAY, '[2001:db8::42]:443, '.self::TRAEFIK)['ip']);
    }

    public function test_behind_the_chain_each_client_keeps_its_own_bucket(): void
    {
        $this->helmChain();
        config(['ratelimit.api.per_guest_per_minute' => 2]);

        $call = fn (string $forwardedFor) => $this->withServerVariables(['REMOTE_ADDR' => self::GATEWAY])
            ->getJson('/api/version', ['X-Forwarded-For' => $forwardedFor]);

        $call('9.9.9.1, 198.51.100.23, '.self::TRAEFIK)->assertOk();
        $call('9.9.9.2, 198.51.100.23, '.self::TRAEFIK)->assertOk();
        $call('9.9.9.3, 198.51.100.23, '.self::TRAEFIK)->assertStatus(429);

        // another client behind the same ingress and gateway is not affected
        $call('198.51.100.24, '.self::TRAEFIK)->assertOk();
    }

    public function test_one_hop_trusts_only_the_connecting_proxy(): void
    {
        // compose / gateway exposed without an ingress: the gateway replaces X-Forwarded-For
        config(['trustedproxy.proxies' => 'private_ranges', 'trustedproxy.hops' => 1]);

        $this->assertSame('203.0.113.9', $this->clientIp(self::GATEWAY, '203.0.113.9')['ip']);
        $this->assertSame('203.0.113.9', $this->clientIp(self::GATEWAY, '1.2.3.4, 203.0.113.9')['ip']);
        // a pod calling the API directly without the header is itself the client
        $this->assertSame('10.42.3.3', $this->clientIp('10.42.3.3')['ip']);
    }

    public function test_explicit_ranges_star_and_none(): void
    {
        config(['trustedproxy.proxies' => '10.42.0.0/16, 10.43.0.0/16', 'trustedproxy.hops' => 2]);
        $this->assertSame('198.51.100.23', $this->clientIp(self::GATEWAY, '198.51.100.23, '.self::TRAEFIK)['ip']);
        // a peer outside the configured ranges (here: another private network) is the client
        $this->assertSame('192.168.1.5', $this->clientIp('192.168.1.5', '198.51.100.23, '.self::TRAEFIK)['ip']);
        // a hop outside the ranges ends the proxy chain: that hop is the client
        $this->assertSame('192.168.1.5', $this->clientIp(self::GATEWAY, '198.51.100.23, 192.168.1.5')['ip']);

        // "*" with one hop: the behaviour of trustProxies('*') before 0.4.1
        config(['trustedproxy.proxies' => '*', 'trustedproxy.hops' => 1]);
        $this->assertSame('1.2.3.4', $this->clientIp('203.0.113.7', '1.2.3.4')['ip']);

        // no proxy at all
        config(['trustedproxy.proxies' => '', 'trustedproxy.hops' => 2]);
        $this->assertSame(self::GATEWAY, $this->clientIp(self::GATEWAY, '198.51.100.23, '.self::TRAEFIK)['ip']);
    }

    /**
     * A dual-stack listener (Octane / FrankenPHP, many proxies) reports an IPv4 peer as
     * ::ffff:a.b.c.d: it is a proxy exactly when its IPv4 address is one. A public peer in
     * mapped form is the client: none of its X-Forwarded-* counts.
     */
    public function test_an_ipv4_mapped_public_peer_is_the_client(): void
    {
        $this->helmChain();

        $direct = $this->clientIp('::ffff:203.0.113.7', '198.51.100.99, '.self::TRAEFIK,
            ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example']);
        $this->assertSame(['::ffff:203.0.113.7', 'ip:::ffff:203.0.113.7', 'http', 'localhost'],
            [$direct['ip'], $direct['key'], $direct['scheme'], $direct['host']]);
        $this->assertSame('::ffff:8.8.8.8', $this->clientIp('::ffff:8.8.8.8', '10.42.0.7')['ip']);

        // "*" still trusts every peer, mapped or not
        config(['trustedproxy.proxies' => '*', 'trustedproxy.hops' => 1]);
        $this->assertSame('1.2.3.4', $this->clientIp('::ffff:8.8.8.8', '1.2.3.4')['ip']);
    }

    /** A private peer (the gateway) in mapped form is a trusted proxy, also as a mapped hop */
    public function test_an_ipv4_mapped_private_peer_is_a_trusted_proxy(): void
    {
        $this->helmChain();

        $this->assertSame('198.51.100.23', $this->clientIp('::ffff:'.self::GATEWAY, '198.51.100.23, ::ffff:'.self::TRAEFIK)['ip']);
        $this->assertSame('198.51.100.23', $this->clientIp('0:0:0:0:0:ffff:a2a:14', '198.51.100.23, '.self::TRAEFIK)['ip']);

        // last: the forwarded host and scheme become the URL root of the requests that follow
        $trusted = $this->clientIp('::ffff:'.self::GATEWAY, '198.51.100.23, '.self::TRAEFIK,
            ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'runr.test']);
        $this->assertSame(['198.51.100.23', 'https', 'runr.test'], [$trusted['ip'], $trusted['scheme'], $trusted['host']]);
    }

    public function test_plain_ipv4_and_ipv6_peers_are_unchanged(): void
    {
        $this->helmChain();

        $this->assertSame('198.51.100.23', $this->clientIp(self::GATEWAY, '198.51.100.23, '.self::TRAEFIK)['ip']);
        $this->assertSame('8.8.8.8', $this->clientIp('8.8.8.8', '198.51.100.23, '.self::TRAEFIK)['ip']);
        // unique-local and loopback IPv6 peers are proxies, a global IPv6 peer is the client
        $this->assertSame('198.51.100.23', $this->clientIp('fd00:42::20', '198.51.100.23, fd00:42::7')['ip']);
        $this->assertSame('198.51.100.23', $this->clientIp('::1', '198.51.100.23, '.self::TRAEFIK)['ip']);
        $this->assertSame('2001:db8::7', $this->clientIp('2001:db8::7', '198.51.100.23, '.self::TRAEFIK)['ip']);
    }

    public function test_ipv4_mapped_addresses_are_matched_as_ipv4(): void
    {
        $private = TrustProxies::ranges('private_ranges');

        $this->assertNotContains('::ffff:0:0/96', $private);
        $this->assertTrue(TrustProxies::inRanges('::ffff:10.0.0.5', $private));
        $this->assertTrue(TrustProxies::inRanges('::FFFF:192.168.1.9', $private));
        $this->assertFalse(TrustProxies::inRanges('::ffff:8.8.8.8', $private));
        $this->assertTrue(TrustProxies::inRanges('10.0.0.5', $private));
        $this->assertFalse(TrustProxies::inRanges('8.8.8.8', $private));
        $this->assertTrue(TrustProxies::inRanges('fd00::5', $private));
        $this->assertFalse(TrustProxies::inRanges('2001:db8::5', $private));
        $this->assertFalse(TrustProxies::inRanges('not-an-ip', $private));
        // only as IPv4: an IPv6 range that spans the mapped block trusts no IPv4 peer
        $this->assertFalse(TrustProxies::inRanges('::ffff:8.8.8.8', ['::ffff:0:0/96', '::/64']));

        $this->assertSame('10.0.0.5', TrustProxies::unmapped('::ffff:10.0.0.5'));
        $this->assertSame('10.42.0.20', TrustProxies::unmapped('0:0:0:0:0:ffff:a2a:14'));
        $this->assertSame('10.0.0.5', TrustProxies::unmapped('10.0.0.5'));
        $this->assertSame('fd00::5', TrustProxies::unmapped('fd00::5'));
        $this->assertSame('::ffff', TrustProxies::unmapped('::ffff'));
    }

    public function test_ranges_parse_keywords_and_lists(): void
    {
        $this->assertContains('10.0.0.0/8', TrustProxies::ranges('private_ranges'));
        $this->assertContains('fc00::/7', TrustProxies::ranges('private_ranges'));
        // not Symfony's IpUtils::PRIVATE_SUBNETS: publicly routable 6to4 / Teredo / NAT64
        // prefixes and the documentation ranges are no proxies of ours
        $this->assertSame(TrustProxies::PRIVATE_RANGES, TrustProxies::ranges('private_ranges'));
        $this->assertSame(TrustProxies::PRIVATE_RANGES, TrustProxies::ranges('PRIVATE_SUBNETS'));
        foreach (['2002::/16', '2001::/32', '64:ff9b::/96', '203.0.113.0/24', '100.64.0.0/10'] as $range) {
            $this->assertNotContains($range, TrustProxies::ranges('private_ranges'));
        }
        $this->assertSame(['0.0.0.0/0', '::/0'], TrustProxies::ranges('*'));
        $this->assertSame(['10.42.0.0/16', '10.43.0.0/16'], TrustProxies::ranges(' 10.42.0.0/16,10.43.0.0/16 '));
        $this->assertSame(['10.42.0.0/16', '10.43.0.0/16'], TrustProxies::ranges('10.42.0.0/16 10.43.0.0/16'));
        $this->assertSame([], TrustProxies::ranges(''));
    }
}
