<?php

namespace Tests\Feature;

use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\User;
use App\Services\Dns\DnsResolver;
use App\Services\Manifests\ProjectDomainManifests as M;
use App\Services\ProjectDomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\Fixtures\TlsFixture;
use Tests\TestCase;

/**
 * Custom domains per project — API endpoints, DNS ownership verification
 * with a fake resolver, routing objects applied to the region through its kubeconfig,
 * TLS modes, and cleanup on domain delete / project delete / region change / trigger change.
 */
class ProjectDomainApiTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = 'https://region.test:6443';

    private const SERVER2 = 'https://other.test:6443';

    private const DOMAIN = 'shop.fn.runr.test';

    /** @var list<string> "METHOD /path" of every Kubernetes call */
    private array $calls = [];

    /** @var array<string, array> decoded body of the last call per "METHOD /path" */
    private array $bodies = [];

    /** @var array<string, array{0: int, 1: array}> "METHOD /path" → [status, body] */
    private array $responses = [];

    private object $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new class extends DnsResolver
        {
            public array $txtRecords = [];

            public array $cnameRecords = [];

            public array $addressRecords = [];

            public function txt(string $name): array
            {
                return $this->txtRecords[$name] ?? [];
            }

            public function cname(string $name): array
            {
                return $this->cnameRecords[$name] ?? [];
            }

            public function addresses(string $name): array
            {
                return $this->addressRecords[$name] ?? [];
            }
        };
        $this->app->instance(DnsResolver::class, $this->dns);

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $key = $request->method().' '.$path;
            $this->calls[] = $key;
            $this->bodies[$key] = json_decode($request->body(), true) ?? [];

            if (isset($this->responses[$key])) {
                [$status, $body] = $this->responses[$key];

                return Http::response($body, $status);
            }

            return match (true) {
                $request->method() === 'GET' && str_ends_with($path, '/ingressroutes') => Http::response(['items' => []]),
                $request->method() === 'GET' => Http::response(['kind' => 'Status', 'code' => 404], 404),
                default => Http::response([], 200),
            };
        });
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function kubeconfig(string $server = self::SERVER): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => $server, 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    private function region(array $overrides = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => 'eu',
            'project_namespace' => 'fn',
            'kubeconfig' => $this->kubeconfig(),
            'knative_serving_namespace' => 'knative-serving',
            'external_ip' => '203.0.113.10',
            'knative_settings' => ['knative_domain' => 'eu.runr.test'],
            'is_active' => true,
        ], $overrides));
    }

    private function project(string $trigger = 'http', ?KnativeRegion $region = null, string $name = 'shop'): Project
    {
        $region ??= $this->region();
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();

        ContainerConfig::create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'cpu' => '250m',
            'memory_mb' => 128,
            'timeout_seconds' => 30,
            'trigger_type' => $trigger,
            'schedule' => $trigger === 'schedule' ? '*/5 * * * *' : null,
            'queue_stream' => $trigger === 'queue' ? 'orders' : null,
        ]);

        return $project->fresh();
    }

    private function user(): User
    {
        return User::factory()->create(['username' => 'dev']);
    }

    private function addDomain(Project $project, string $domain = self::DOMAIN): ProjectDomain
    {
        return app(ProjectDomainService::class)->add($project, $domain);
    }

    private function publishDns(ProjectDomain $domain, string $cname = 'shop.eu.runr.test'): void
    {
        $this->dns->txtRecords[$domain->challengeName()] = ['some-other=value', $domain->challengeValue()];
        $this->dns->cnameRecords[$domain->domain] = [$cname];
    }

    private function activeDomain(Project $project, array $attributes = []): ProjectDomain
    {
        $domain = $this->addDomain($project);
        $domain->update(array_merge(['status' => ProjectDomain::STATUS_ACTIVE], $attributes));

        return $domain->fresh();
    }

    private function resName(Project $project, string $domain = self::DOMAIN): string
    {
        return M::resourceName($project->id, $domain);
    }

    private function url(Project $project, string $suffix = ''): string
    {
        return "/api/projects/{$project->id}/domains{$suffix}";
    }

    private function writes(): array
    {
        return array_values(array_filter($this->calls, fn ($c) => ! str_starts_with($c, 'GET ')));
    }

    // ── Add / list ──────────────────────────────────────────────────────────

    public function test_adding_a_domain_stores_it_pending_with_a_txt_challenge(): void
    {
        $project = $this->project();

        $response = $this->actingAs($this->user(), 'sanctum')
            ->postJson($this->url($project), ['domain' => '  Shop.FN.runr.test. '])
            ->assertCreated()
            ->assertJsonPath('data.domain', self::DOMAIN)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.tls_mode', 'none')
            ->assertJsonPath('data.https_certificate', 'region-default')
            ->assertJsonPath('data.url', 'http://'.self::DOMAIN)
            ->assertJsonPath('data.verification.type', 'TXT')
            ->assertJsonPath('data.verification.name', '_runr-challenge.'.self::DOMAIN)
            ->assertJsonPath('data.dns_targets', [
                ['type' => 'CNAME', 'name' => self::DOMAIN, 'value' => 'shop.eu.runr.test'],
                ['type' => 'A', 'name' => self::DOMAIN, 'value' => '203.0.113.10'],
            ]);

        $domain = ProjectDomain::firstOrFail();
        $this->assertSame('runr-verify='.$domain->verification_token, $response->json('data.verification.value'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $domain->verification_token);
        $this->assertNotEmpty($response->json('data.tls_note'));
        $this->assertSame([], $this->calls, 'Adding a domain touches no cluster');
    }

    public function test_invalid_wildcard_and_duplicate_domains_are_rejected(): void
    {
        $project = $this->project();
        $user = $this->user();
        $this->addDomain($project);

        foreach (['not a domain', 'localhost', '*.example.com', '203.0.113.10', self::DOMAIN, 'SHOP.fn.runr.test'] as $value) {
            $this->actingAs($user, 'sanctum')
                ->postJson($this->url($project), ['domain' => $value])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('domain');
        }

        $this->assertSame(1, ProjectDomain::count());
    }

    public function test_function_domains_and_the_console_domain_are_reserved(): void
    {
        config(['app.url' => 'https://console.runr.test']);
        $project = $this->project();
        $user = $this->user();

        foreach (['eu.runr.test', 'other.eu.runr.test', 'console.runr.test', 'api.console.runr.test'] as $value) {
            $this->actingAs($user, 'sanctum')
                ->postJson($this->url($project), ['domain' => $value])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('domain');
        }

        $this->actingAs($user, 'sanctum')
            ->postJson($this->url($project), ['domain' => 'runr.test.example.com'])
            ->assertCreated();
    }

    public function test_schedule_and_queue_projects_cannot_get_a_domain(): void
    {
        $user = $this->user();

        foreach (['schedule', 'queue'] as $i => $trigger) {
            $project = $this->project($trigger, $this->region(['name' => "r{$i}"]), "p{$trigger}");

            $this->actingAs($user, 'sanctum')
                ->postJson($this->url($project), ['domain' => "{$trigger}.example.com"])
                ->assertUnprocessable()
                ->assertJsonPath('message', __('api.domain.not_http', ['trigger' => $trigger]));

            $this->actingAs($user, 'sanctum')
                ->getJson($this->url($project))
                ->assertOk()
                ->assertJsonPath('meta.eligible', false);
        }

        $this->assertSame(0, ProjectDomain::count());
    }

    public function test_index_lists_domains_with_targets_and_cert_manager_availability(): void
    {
        $region = $this->region(['knative_settings' => ['knative_domain' => 'eu.runr.test', 'cert_manager_cluster_issuer' => 'letsencrypt']]);
        $project = $this->project(region: $region);
        $this->addDomain($project);

        $this->actingAs($this->user(), 'sanctum')
            ->getJson($this->url($project))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.domain', self::DOMAIN)
            ->assertJsonPath('meta.eligible', true)
            ->assertJsonPath('meta.reason', null)
            ->assertJsonPath('meta.cert_manager_issuer', 'letsencrypt')
            ->assertJsonPath('meta.default_host', 'shop.eu.runr.test')
            ->assertJsonPath('meta.dns_targets.1', ['type' => 'A', 'value' => '203.0.113.10'])
            ->assertJsonMissingPath('data.0.verification_token');
    }

    public function test_a_domain_of_another_project_is_not_reachable_through_this_project(): void
    {
        $region = $this->region();
        $mine = $this->project(region: $region);
        $other = $this->project(region: $region, name: 'other');
        $domain = $this->addDomain($other, 'other.example.com');
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->postJson($this->url($mine, "/{$domain->id}/verify"))->assertNotFound();
        $this->actingAs($user, 'sanctum')->deleteJson($this->url($mine, "/{$domain->id}"))->assertNotFound();
        $this->assertNotNull($domain->fresh());
    }

    // ── Verify ──────────────────────────────────────────────────────────────

    public function test_verify_without_the_txt_record_keeps_the_domain_pending(): void
    {
        $project = $this->project();
        $domain = $this->addDomain($project);
        $this->dns->cnameRecords[self::DOMAIN] = ['shop.eu.runr.test'];

        $this->actingAs($this->user(), 'sanctum')
            ->postJson($this->url($project, "/{$domain->id}/verify"))
            ->assertUnprocessable()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('message', __('api.domain.txt_missing', [
                'name' => '_runr-challenge.'.self::DOMAIN,
                'value' => $domain->challengeValue(),
            ]));

        $this->assertSame('pending', $domain->fresh()->status);
        $this->assertSame([], $this->writes());
    }

    public function test_verify_with_txt_but_dns_pointing_elsewhere_keeps_the_domain_pending(): void
    {
        $project = $this->project();
        $domain = $this->addDomain($project);
        $this->publishDns($domain, 'somewhere.else.test');
        $this->dns->addressRecords[self::DOMAIN] = ['198.51.100.7'];

        $this->actingAs($this->user(), 'sanctum')
            ->postJson($this->url($project, "/{$domain->id}/verify"))
            ->assertUnprocessable()
            ->assertJsonPath('data.status', 'pending');

        $this->assertStringContainsString('CNAME shop.eu.runr.test', $domain->fresh()->status_message);
        $this->assertSame([], $this->writes());
    }

    public function test_verify_with_txt_and_cname_routes_the_domain_on_the_region(): void
    {
        $project = $this->project();
        $domain = $this->addDomain($project);
        $this->publishDns($domain);
        $name = $this->resName($project);

        $this->actingAs($this->user(), 'sanctum')
            ->postJson($this->url($project, "/{$domain->id}/verify"))
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.status_message', null);

        $this->assertSame('active', $domain->fresh()->status);
        $this->assertEqualsCanonicalizing([
            "PATCH /apis/traefik.io/v1alpha1/namespaces/fn/middlewares/{$name}",
            "PATCH /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/{$name}",
            "PATCH /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/{$name}-tls",
        ], $this->writes());

        // not deployed yet: the project's default host; the public route on web serves it
        $mw = $this->bodies["PATCH /apis/traefik.io/v1alpha1/namespaces/fn/middlewares/{$name}"];
        $this->assertSame('shop.eu.runr.test', $mw['spec']['headers']['customRequestHeaders']['Host']);
        $this->assertSame('shop', $mw['metadata']['annotations']['runr.dev/service']);

        $tlsRoute = $this->bodies["PATCH /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/{$name}-tls"];
        $this->assertSame(['websecure'], $tlsRoute['spec']['entryPoints']);
        $this->assertSame([], $tlsRoute['spec']['tls'], 'No certificate: region default');
        $this->assertSame('knative-serving', $tlsRoute['spec']['routes'][0]['services'][0]['namespace']);
        $this->assertSame('traefik', $tlsRoute['spec']['routes'][0]['services'][0]['name']);
    }

    public function test_the_route_follows_the_public_url_of_the_deployed_service(): void
    {
        $project = $this->project();
        $domain = $this->addDomain($project);
        $this->publishDns($domain);
        $name = $this->resName($project);
        // e.g. an organization's own domain (config-domain selects its services)
        $this->responses['GET /apis/serving.knative.dev/v1/namespaces/fn/services/shop'] = [200, ['status' => ['url' => 'https://shop.eu.acme.test']]];

        $this->actingAs($this->user(), 'sanctum')
            ->postJson($this->url($project, "/{$domain->id}/verify"))
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $mw = $this->bodies["PATCH /apis/traefik.io/v1alpha1/namespaces/fn/middlewares/{$name}"];
        $this->assertSame('shop.eu.acme.test', $mw['spec']['headers']['customRequestHeaders']['Host']);
    }

    public function test_an_a_record_to_the_region_external_ip_also_verifies(): void
    {
        $project = $this->project();
        $domain = $this->addDomain($project);
        $this->dns->txtRecords[$domain->challengeName()] = [$domain->challengeValue()];
        $this->dns->addressRecords[self::DOMAIN] = ['203.0.113.10'];

        $this->actingAs($this->user(), 'sanctum')
            ->postJson($this->url($project, "/{$domain->id}/verify"))
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_verify_refuses_a_host_another_project_already_routes_on_the_region(): void
    {
        $project = $this->project();
        $domain = $this->addDomain($project);
        $this->publishDns($domain);
        $this->responses['GET /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes'] = [200, ['items' => [[
            'metadata' => ['name' => 'runr-domain-foreign', 'labels' => [M::LABEL_PROJECT => '01J9ZX0000000000000000OTHER']],
        ]]]];

        $this->actingAs($this->user(), 'sanctum')
            ->postJson($this->url($project, "/{$domain->id}/verify"))
            ->assertUnprocessable()
            ->assertJsonPath('data.status', 'error');

        $this->assertSame([], $this->writes());
    }

    public function test_a_rejected_apply_puts_the_domain_in_error(): void
    {
        $project = $this->project();
        $domain = $this->addDomain($project);
        $this->publishDns($domain);
        $name = $this->resName($project);
        $this->responses["PATCH /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/{$name}"] = [403, ['kind' => 'Status']];

        $this->actingAs($this->user(), 'sanctum')
            ->postJson($this->url($project, "/{$domain->id}/verify"))
            ->assertUnprocessable()
            ->assertJsonPath('data.status', 'error');

        $this->assertStringContainsString("IngressRoute/{$name}", $domain->fresh()->status_message);
    }

    // ── TLS ─────────────────────────────────────────────────────────────────

    public function test_custom_certificate_goes_into_a_secret_and_the_https_route(): void
    {
        $project = $this->project();
        $domain = $this->activeDomain($project);
        $name = $this->resName($project);

        $this->actingAs($this->user(), 'sanctum')
            ->putJson($this->url($project, "/{$domain->id}/tls"), [
                'mode' => 'custom',
                'certificate' => TlsFixture::CERT,
                'privateKey' => TlsFixture::KEY,
            ])
            ->assertOk()
            ->assertJsonPath('data.tls_mode', 'custom')
            ->assertJsonPath('data.https_certificate', 'custom')
            ->assertJsonPath('data.url', 'https://'.self::DOMAIN)
            ->assertJsonPath('data.status', 'active');

        $fresh = $domain->fresh();
        $this->assertSame($name, $fresh->tls_secret_name);
        $this->assertSame('2036-09-27', $fresh->tls_expires_at->toDateString());

        $secret = $this->bodies["PATCH /api/v1/namespaces/fn/secrets/{$name}"];
        $this->assertSame('kubernetes.io/tls', $secret['type']);
        $this->assertStringContainsString('BEGIN CERTIFICATE', base64_decode($secret['data']['tls.crt']));

        $tlsRoute = $this->bodies["PATCH /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/{$name}-tls"];
        $this->assertSame(['secretName' => $name], $tlsRoute['spec']['tls']);

        // The PEM pair itself is never stored in the database
        $this->assertStringNotContainsString('BEGIN', json_encode($fresh->getAttributes()));
    }

    public function test_a_certificate_for_another_host_or_a_foreign_key_is_rejected(): void
    {
        $project = $this->project();
        $user = $this->user();
        $other = $this->addDomain($project, 'shop.example.com');

        $this->actingAs($user, 'sanctum')
            ->putJson($this->url($project, "/{$other->id}/tls"), [
                'mode' => 'custom', 'certificate' => TlsFixture::CERT, 'privateKey' => TlsFixture::KEY,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('certificate');

        $domain = $this->addDomain($project);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $keyPem);

        $this->actingAs($user, 'sanctum')
            ->putJson($this->url($project, "/{$domain->id}/tls"), [
                'mode' => 'custom', 'certificate' => TlsFixture::CERT, 'privateKey' => $keyPem,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('privateKey');

        $this->actingAs($user, 'sanctum')
            ->putJson($this->url($project, "/{$domain->id}/tls"), ['mode' => 'custom', 'certificate' => 'nope', 'privateKey' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['certificate', 'privateKey']);

        $this->assertSame([], $this->writes());
        $this->assertSame('none', $domain->fresh()->tls_mode);
    }

    public function test_cert_manager_mode_needs_a_configured_and_present_cluster_issuer(): void
    {
        $project = $this->project();
        $domain = $this->activeDomain($project);
        $user = $this->user();

        // No issuer configured for the region
        $this->actingAs($user, 'sanctum')
            ->putJson($this->url($project, "/{$domain->id}/tls"), ['mode' => 'cert-manager'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mode');

        // Configured, but cert-manager is not installed on the cluster (discovery 404)
        $project->knativeRegion->update(['knative_settings' => ['knative_domain' => 'eu.runr.test', 'cert_manager_cluster_issuer' => 'letsencrypt']]);
        $this->actingAs($user, 'sanctum')
            ->putJson($this->url($project->fresh(), "/{$domain->id}/tls"), ['mode' => 'cert-manager'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mode');

        $this->assertSame('none', $domain->fresh()->tls_mode);
        $this->assertSame([], $this->writes());
    }

    public function test_cert_manager_mode_creates_a_certificate_for_an_active_domain(): void
    {
        $region = $this->region(['knative_settings' => ['knative_domain' => 'eu.runr.test', 'cert_manager_cluster_issuer' => 'letsencrypt']]);
        $project = $this->project(region: $region);
        $domain = $this->activeDomain($project);
        $name = $this->resName($project);

        $this->responses['GET /apis/cert-manager.io/v1'] = [200, ['kind' => 'APIResourceList']];
        $this->responses['GET /apis/cert-manager.io/v1/clusterissuers/letsencrypt'] = [200, ['kind' => 'ClusterIssuer']];
        $this->responses["GET /apis/cert-manager.io/v1/namespaces/fn/certificates/{$name}"] = [200, ['status' => [
            'conditions' => [['type' => 'Ready', 'status' => 'True']],
            'notAfter' => '2026-12-29T10:00:00Z',
        ]]];

        $this->actingAs($this->user(), 'sanctum')
            ->putJson($this->url($project, "/{$domain->id}/tls"), ['mode' => 'cert-manager'])
            ->assertOk()
            ->assertJsonPath('data.tls_mode', 'cert-manager')
            ->assertJsonPath('data.https_certificate', 'cert-manager')
            ->assertJsonPath('data.status', 'active');

        $cert = $this->bodies["PATCH /apis/cert-manager.io/v1/namespaces/fn/certificates/{$name}"];
        $this->assertSame([self::DOMAIN], $cert['spec']['dnsNames']);
        $this->assertSame('letsencrypt', $cert['spec']['issuerRef']['name']);
        $this->assertSame($name, $cert['spec']['secretName']);

        $tlsRoute = $this->bodies["PATCH /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/{$name}-tls"];
        $this->assertSame(['secretName' => $name], $tlsRoute['spec']['tls']);
        $this->assertSame('2026-12-29', $domain->fresh()->tls_expires_at->toDateString());
    }

    public function test_switching_back_to_no_tls_removes_the_certificate_objects(): void
    {
        $project = $this->project();
        $name = $this->resName($project);
        $domain = $this->activeDomain($project, ['tls_mode' => 'custom', 'tls_secret_name' => $name]);

        $this->actingAs($this->user(), 'sanctum')
            ->putJson($this->url($project, "/{$domain->id}/tls"), ['mode' => 'none'])
            ->assertOk()
            ->assertJsonPath('data.tls_mode', 'none')
            ->assertJsonPath('data.https_certificate', 'region-default');

        $this->assertContains("DELETE /api/v1/namespaces/fn/secrets/{$name}", $this->calls);
        $this->assertContains("DELETE /apis/cert-manager.io/v1/namespaces/fn/certificates/{$name}", $this->calls);
        // The route stopped referencing the Secret before it was deleted
        $this->assertLessThan(
            array_search("DELETE /api/v1/namespaces/fn/secrets/{$name}", $this->calls, true),
            array_search("PATCH /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/{$name}-tls", $this->calls, true),
        );
        $this->assertNull($domain->fresh()->tls_secret_name);
    }

    // ── Delete & lifecycle ──────────────────────────────────────────────────

    public function test_deleting_a_domain_removes_its_objects_from_the_region(): void
    {
        $project = $this->project();
        $domain = $this->activeDomain($project);
        $name = $this->resName($project);

        $this->actingAs($this->user(), 'sanctum')
            ->deleteJson($this->url($project, "/{$domain->id}"))
            ->assertNoContent();

        $this->assertNull($domain->fresh());
        $this->assertEqualsCanonicalizing([
            "DELETE /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/{$name}-tls",
            "DELETE /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/{$name}",
            "DELETE /apis/traefik.io/v1alpha1/namespaces/fn/middlewares/{$name}",
            "DELETE /apis/cert-manager.io/v1/namespaces/fn/certificates/{$name}",
            "DELETE /api/v1/namespaces/fn/secrets/{$name}",
        ], $this->calls);
    }

    public function test_deleting_a_pending_domain_touches_no_cluster(): void
    {
        $project = $this->project();
        $domain = $this->addDomain($project);

        $this->actingAs($this->user(), 'sanctum')
            ->deleteJson($this->url($project, "/{$domain->id}"))
            ->assertNoContent();

        $this->assertSame([], $this->calls);
    }

    public function test_a_failed_removal_keeps_the_domain(): void
    {
        $project = $this->project();
        $domain = $this->activeDomain($project);
        $name = $this->resName($project);
        $this->responses["DELETE /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/{$name}"] = [500, ['kind' => 'Status']];

        $this->actingAs($this->user(), 'sanctum')
            ->deleteJson($this->url($project, "/{$domain->id}"))
            ->assertUnprocessable();

        $this->assertNotNull($domain->fresh());
        $this->assertStringContainsString("IngressRoute/{$name}", $domain->fresh()->status_message);
    }

    public function test_deleting_the_project_removes_its_domains_from_the_region(): void
    {
        $project = $this->project();
        $domain = $this->activeDomain($project);
        $name = $this->resName($project);

        $project->delete();

        foreach ([
            "DELETE /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/{$name}-tls",
            "DELETE /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/{$name}",
            "DELETE /apis/traefik.io/v1alpha1/namespaces/fn/middlewares/{$name}",
            "DELETE /apis/cert-manager.io/v1/namespaces/fn/certificates/{$name}",
            "DELETE /api/v1/namespaces/fn/secrets/{$name}",
        ] as $call) {
            $this->assertContains($call, $this->calls);
        }

        $this->assertNull(Project::find($project->id));
        $this->assertNull(ProjectDomain::find($domain->id));
    }

    public function test_a_failing_domain_cleanup_does_not_block_the_project_delete(): void
    {
        $project = $this->project();
        $this->activeDomain($project);
        $this->responses['DELETE /apis/traefik.io/v1alpha1/namespaces/fn/middlewares/'.$this->resName($project)] = [500, ['kind' => 'Status']];

        $project->delete();

        $this->assertNull(Project::find($project->id));
        $this->assertSame(0, ProjectDomain::count());
    }

    public function test_moving_the_project_to_another_region_sends_its_domains_back_to_pending(): void
    {
        $project = $this->project();
        $name = $this->resName($project);
        $domain = $this->activeDomain($project, ['tls_mode' => 'custom', 'tls_secret_name' => $name]);
        $target = $this->region(['name' => 'us', 'kubeconfig' => $this->kubeconfig(self::SERVER2)]);

        $project->update(['knative_region_id' => $target->id]);

        $this->assertContains("DELETE /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/{$name}", $this->calls);
        $this->assertContains("DELETE /api/v1/namespaces/fn/secrets/{$name}", $this->calls);

        $fresh = $domain->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame('none', $fresh->tls_mode, 'The uploaded certificate lived only on the old region');
        $this->assertNull($fresh->tls_secret_name);
        $this->assertSame(__('api.domain.region_changed'), $fresh->status_message);
    }

    public function test_a_deploy_that_leaves_http_unroutes_and_a_deploy_back_to_http_reroutes(): void
    {
        $project = $this->project();
        $domain = $this->activeDomain($project);
        $name = $this->resName($project);
        $service = app(ProjectDomainService::class);

        $project->containerConfig->update(['trigger_type' => 'queue', 'queue_stream' => 'orders']);
        $service->syncAfterDeploy($project->fresh());

        $this->assertEqualsCanonicalizing([
            "DELETE /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/{$name}-tls",
            "DELETE /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/{$name}",
            "DELETE /apis/traefik.io/v1alpha1/namespaces/fn/middlewares/{$name}",
        ], $this->writes());
        $this->assertSame('verified', $domain->fresh()->status);

        $this->calls = [];
        $project->containerConfig->update(['trigger_type' => 'http']);
        $service->syncAfterDeploy($project->fresh());

        $this->assertSame('active', $domain->fresh()->status);
        $this->assertContains("PATCH /apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/{$name}", $this->calls);
    }
}
