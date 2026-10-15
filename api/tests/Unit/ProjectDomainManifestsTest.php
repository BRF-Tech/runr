<?php

namespace Tests\Unit;

use App\Services\Helpers\DomainName;
use App\Services\Helpers\PemHelper;
use App\Services\Manifests\ProjectDomainManifests as M;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\TlsFixture;

/**
 * custom-domain host-name validation and the pure manifest builders
 * (Traefik IngressRoute/Middleware, TLS Secret, cert-manager Certificate).
 */
class ProjectDomainManifestsTest extends TestCase
{
    private const PROJECT = '01J9ZX0000000000000000PROJ';

    // ── DomainName ──────────────────────────────────────────────────────────

    public static function validDomains(): array
    {
        return [
            ['api.example.com'],
            ['example.com'],
            ['a.b.c.example.co'],
            ['x-1.example.io'],
            ['xn--bcher-kva.example'],
            ['shop.xn--p1ai'],
        ];
    }

    #[DataProvider('validDomains')]
    public function test_valid_domains_are_accepted(string $domain): void
    {
        $this->assertTrue(DomainName::isValid($domain));
    }

    public static function invalidDomains(): array
    {
        return [
            'single label' => ['localhost'],
            'wildcard' => ['*.example.com'],
            'ip address' => ['203.0.113.10'],
            'uppercase' => ['API.example.com'],
            'leading dash' => ['-api.example.com'],
            'trailing dash' => ['api-.example.com'],
            'empty label' => ['api..example.com'],
            'underscore' => ['my_api.example.com'],
            'numeric tld' => ['api.example.123'],
            'label too long' => [str_repeat('a', 64).'.example.com'],
            'name too long' => [str_repeat('abcdefghi.', 26).'com'],
            'unicode' => ['bücher.example'],
            'scheme' => ['https://api.example.com'],
            'path' => ['api.example.com/x'],
            'port' => ['api.example.com:8080'],
            'trailing dot kept' => ['api.example.com.'],
        ];
    }

    #[DataProvider('invalidDomains')]
    public function test_invalid_domains_are_rejected(string $domain): void
    {
        $this->assertFalse(DomainName::isValid($domain));
    }

    public function test_normalize_lowercases_trims_and_drops_the_trailing_dot(): void
    {
        $this->assertSame('api.example.com', DomainName::normalize("  API.Example.COM. \n"));
    }

    public function test_is_within_matches_the_domain_itself_and_its_subdomains_only(): void
    {
        $this->assertTrue(DomainName::isWithin('eu.runr.test', 'eu.runr.test'));
        $this->assertTrue(DomainName::isWithin('shop.eu.runr.test', 'eu.runr.test'));
        $this->assertFalse(DomainName::isWithin('shopeu.runr.test', 'eu.runr.test'));
        $this->assertFalse(DomainName::isWithin('runr.test', 'eu.runr.test'));
        $this->assertFalse(DomainName::isWithin('shop.example.com', ''));
    }

    public function test_host_of_ignores_localhost_and_ip_addresses(): void
    {
        $this->assertSame('console.runr.test', DomainName::hostOf('https://Console.runr.test:8443/app'));
        $this->assertNull(DomainName::hostOf('http://localhost'));
        $this->assertNull(DomainName::hostOf('http://10.0.0.1:8080'));
        $this->assertNull(DomainName::hostOf(null));
    }

    // ── PemHelper additions ────────────────────────────────────────────────

    public function test_certificate_host_coverage_follows_san_and_one_label_wildcards(): void
    {
        $this->assertSame(['*.fn.runr.test'], PemHelper::certificateHosts(TlsFixture::CERT));
        $this->assertTrue(PemHelper::certificateCoversHost(TlsFixture::CERT, 'shop.fn.runr.test'));
        $this->assertFalse(PemHelper::certificateCoversHost(TlsFixture::CERT, 'a.shop.fn.runr.test'));
        $this->assertFalse(PemHelper::certificateCoversHost(TlsFixture::CERT, 'fn.runr.test'));
        $this->assertFalse(PemHelper::certificateCoversHost(TlsFixture::CERT, 'shop.example.com'));
        $this->assertFalse(PemHelper::certificateCoversHost('garbage', 'shop.fn.runr.test'));
    }

    public function test_key_matches_certificate(): void
    {
        $this->assertTrue(PemHelper::keyMatchesCertificate(TlsFixture::CERT, TlsFixture::KEY));

        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherPem);
        $this->assertFalse(PemHelper::keyMatchesCertificate(TlsFixture::CERT, $otherPem));
    }

    // ── Manifests ───────────────────────────────────────────────────────────

    public function test_resource_names_are_stable_per_project_and_domain(): void
    {
        $name = M::resourceName(self::PROJECT, 'api.example.com');

        $this->assertMatchesRegularExpression('/^runr-domain-[0-9a-f]{20}$/', $name);
        $this->assertSame($name, M::resourceName(self::PROJECT, 'api.example.com'));
        $this->assertNotSame($name, M::resourceName('01J9ZX0000000000000000OTHER', 'api.example.com'));
        $this->assertNotSame($name, M::resourceName(self::PROJECT, 'www.example.com'));
        $this->assertSame($name.'-tls', M::secureRouteName($name));
    }

    public function test_middleware_rewrites_host_to_the_knative_service_cluster_local_host(): void
    {
        $mw = M::hostRewriteMiddleware('runr-domain-x', 'fn', self::PROJECT, 'api.example.com', M::serviceHost('shop', 'fn'));

        $this->assertSame('traefik.io/v1alpha1', $mw['apiVersion']);
        $this->assertSame('Middleware', $mw['kind']);
        $this->assertSame(['name' => 'runr-domain-x', 'namespace' => 'fn'], array_intersect_key($mw['metadata'], ['name' => 1, 'namespace' => 1]));
        $this->assertSame([
            'Host' => 'shop.fn.svc',
            'K-Original-Host' => 'api.example.com',
        ], $mw['spec']['headers']['customRequestHeaders']);
        $this->assertSame(self::PROJECT, $mw['metadata']['labels']['runr/project-id']);
        $this->assertSame(sha1('api.example.com'), $mw['metadata']['labels']['runr/domain-hash']);
        $this->assertSame('runr', $mw['metadata']['labels']['app.kubernetes.io/managed-by']);
        $this->assertSame('api.example.com', $mw['metadata']['annotations']['runr.dev/domain']);
    }

    public function test_http_ingress_route_targets_the_region_traefik_on_web(): void
    {
        $route = M::ingressRoute('runr-domain-x', 'fn', self::PROJECT, 'api.example.com', false, null, 'traefik', 'knative-serving');

        $this->assertSame('IngressRoute', $route['kind']);
        $this->assertSame('runr-domain-x', $route['metadata']['name']);
        $this->assertSame(['web'], $route['spec']['entryPoints']);
        $this->assertArrayNotHasKey('tls', $route['spec']);
        $this->assertSame([[
            'match' => 'Host(`api.example.com`)',
            'kind' => 'Rule',
            'middlewares' => [['name' => 'runr-domain-x', 'namespace' => 'fn']],
            'services' => [['name' => 'traefik', 'namespace' => 'knative-serving', 'port' => 80]],
        ]], $route['spec']['routes']);
    }

    public function test_https_ingress_route_uses_the_domain_secret_or_the_default_certificate(): void
    {
        $withSecret = M::ingressRoute('runr-domain-x', 'fn', self::PROJECT, 'api.example.com', true, 'runr-domain-x', 'traefik', 'knative-serving');
        $this->assertSame('runr-domain-x-tls', $withSecret['metadata']['name']);
        $this->assertSame(['websecure'], $withSecret['spec']['entryPoints']);
        $this->assertSame(['secretName' => 'runr-domain-x'], $withSecret['spec']['tls']);

        $default = M::ingressRoute('runr-domain-x', 'fn', self::PROJECT, 'api.example.com', true, null, 'traefik', 'knative-serving');
        // An empty object (not a list) — {} in the applied JSON
        $this->assertSame('{}', json_encode($default['spec']['tls']));
    }

    public function test_tls_secret_holds_the_pem_pair_base64_encoded(): void
    {
        $secret = M::tlsSecret('runr-domain-x', 'fn', self::PROJECT, 'api.example.com', "CERT\n", "KEY\n");

        $this->assertSame('v1', $secret['apiVersion']);
        $this->assertSame('Secret', $secret['kind']);
        $this->assertSame('kubernetes.io/tls', $secret['type']);
        $this->assertSame(base64_encode("CERT\n"), $secret['data']['tls.crt']);
        $this->assertSame(base64_encode("KEY\n"), $secret['data']['tls.key']);
    }

    public function test_certificate_requests_exactly_the_domain_from_the_cluster_issuer(): void
    {
        $cert = M::certificate('runr-domain-x', 'fn', self::PROJECT, 'api.example.com', 'letsencrypt');

        $this->assertSame('cert-manager.io/v1', $cert['apiVersion']);
        $this->assertSame('Certificate', $cert['kind']);
        $this->assertSame('runr-domain-x', $cert['spec']['secretName']);
        $this->assertSame(['api.example.com'], $cert['spec']['dnsNames']);
        $this->assertSame(['name' => 'letsencrypt', 'kind' => 'ClusterIssuer', 'group' => 'cert-manager.io'], $cert['spec']['issuerRef']);
        $this->assertSame(self::PROJECT, $cert['spec']['secretTemplate']['labels']['runr/project-id']);
    }
}
