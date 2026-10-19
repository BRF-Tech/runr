<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Models\Setting;
use App\Services\Dns\DnsResolver;
use App\Services\DomainMigrateService;
use App\Services\Helpers\RegionDomains;
use App\Services\RegionDomainAliasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * The platform's base domain (PATCH /api/settings base_domain) on the regions that follow it
 * (function domain <region>.<base domain>): set for the first time on the regions without a
 * function domain; changed with a migration — the region keeps its function domain and answers on
 * the new one too (runr-domain-alias) until DNS of the new domain answers its external address,
 * or the migration is completed by hand; then the new domain is the function domain (config-domain,
 * the KnativeServing resource, knative_domain) and the old one is no longer served. Setting the
 * base domain back rolls the migration back; clearing it removes the domain. A region with a
 * function domain of its own is never touched; other domains of config-domain (tenants) stay.
 */
class DomainChangeTest extends TestCase
{
    use ActsWithRoles, BuildsAiFixtures, RefreshDatabase;

    /** @var array<string, string> the config-domain data of the fake cluster */
    private array $configDomain = [];

    /** @var array<string, string> spec.config.domain of the fake KnativeServing */
    private array $crDomain = [];

    /** @var array<string, string> the config-network data of the fake cluster */
    private array $configNetwork = ['ingress-class' => 'kourier.ingress.networking.knative.dev'];

    /** @var array<string, list<string>> host => A records */
    private array $addresses = [];

    private KnativeRegion $eu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signedIn($this->userWithRole('owner'));
        $this->eu = $this->region('eu');

        $test = $this;
        $this->app->instance(DnsResolver::class, new class($test) extends DnsResolver
        {
            public function __construct(private readonly DomainChangeTest $test) {}

            public function addresses(string $name): array
            {
                return $this->test->addressesOf($name);
            }

            public function cname(string $name): array
            {
                return [];
            }
        });

        $cr = '/apis/operator.knative.dev/v1beta1/namespaces/knative-serving/knativeservings/knative-serving';
        $this->fakeCluster([
            'GET /api/v1/namespaces/knative-serving/configmaps/config-domain' => fn () => ['data' => $this->configDomain],
            'PATCH /api/v1/namespaces/knative-serving/configmaps/config-domain' => function ($request) {
                $this->configDomain = json_decode($request->body(), true)['data'];

                return ['data' => $this->configDomain];
            },
            'GET /api/v1/namespaces/knative-serving/configmaps/config-network' => fn () => ['data' => $this->configNetwork],
            "GET {$cr}" => fn () => ['spec' => ['config' => ['domain' => $this->crDomain]]],
            "PATCH {$cr}" => function ($request) {
                foreach (json_decode($request->body(), true)['spec']['config']['domain'] ?? [] as $domain => $selector) {
                    if ($selector === null) {
                        unset($this->crDomain[$domain]);
                    } else {
                        $this->crDomain[$domain] = $selector;
                    }
                }

                return ['spec' => ['config' => ['domain' => $this->crDomain]]];
            },
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<string> */
    public function addressesOf(string $name): array
    {
        return $this->addresses[$name] ?? [];
    }

    private function region(string $name, array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => $name, 'project_namespace' => 'fn', 'is_active' => true, 'kubeconfig' => $this->fakeKubeconfig(),
            'knative_serving_status' => 'ready', 'external_ip' => '203.0.113.10',
        ], $attributes));
    }

    private function applied(): int
    {
        return count($this->k8sCallsTo('PATCH', '/configmaps/config-domain'));
    }

    private function aliasApplied(string $domain): bool
    {
        foreach ($this->k8sCallsTo('PATCH', '/namespaces/fn/ingressroutes/'.RegionDomainAliasService::NAME) as $call) {
            if (str_contains($call['body']['spec']['routes'][0]['match'] ?? '', str_replace('.', '\\.', $domain))) {
                return true;
            }
        }

        return false;
    }

    private function aliasRemoved(): bool
    {
        return $this->k8sCallsTo('DELETE', '/namespaces/fn/deployments/'.RegionDomainAliasService::NAME) !== [];
    }

    /** eu follows old.test: its function domain eu.old.test in config-domain and the KnativeServing */
    private function onOldDomain(): void
    {
        Setting::set('base_domain', 'old.test');
        $this->eu->update(['knative_settings' => ['knative_domain' => 'eu.old.test']]);
        $this->configDomain = ['eu.old.test' => '', 'acme.tenant.test' => "selector:\n  runr/tenant: acme\n"];
        $this->crDomain = ['eu.old.test' => ''];
    }

    public function test_the_first_base_domain_goes_to_every_region_without_a_function_domain(): void
    {
        $this->region('demo', ['is_demo' => true]);
        $this->region('fresh', ['knative_serving_status' => 'installing']);
        $own = $this->region('own', ['knative_settings' => ['knative_domain' => 'functions.customer.test']]);
        $this->configDomain = ['acme.tenant.test' => "selector:\n  runr/tenant: acme\n"];

        $this->patchJson('/api/settings', ['base_domain' => 'example.com'])->assertOk()->assertJsonPath('base_domain', 'example.com');

        $this->assertSame(['acme.tenant.test' => "selector:\n  runr/tenant: acme\n", 'eu.example.com' => ''], $this->configDomain, 'a tenant\'s domain stays');
        $this->assertSame(1, $this->applied(), 'not the demo region, one without Serving nor one with its own domain');
        $this->assertSame('eu.example.com', $this->eu->fresh()->effective_settings['knative_domain']);
        $this->assertSame('functions.customer.test', $own->fresh()->effective_settings['knative_domain'], 'its own domain is kept');
    }

    public function test_a_changed_domain_serves_both_until_dns_of_the_new_one_answers(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->onOldDomain();

        $this->patchJson('/api/settings', ['base_domain' => 'new.test'])->assertOk();

        // both while DNS moves: the projects keep their URLs on the old domain, the new one is an alias
        $eu = $this->eu->fresh();
        $this->assertSame('dns_check', $eu->domain_migration_status);
        $this->assertSame('old.test', $eu->domain_migration_old_domain);
        $this->assertSame('eu.old.test', $eu->effective_settings['knative_domain']);
        $this->assertSame(['eu.old.test' => '', 'acme.tenant.test' => "selector:\n  runr/tenant: acme\n"], $this->configDomain);
        $this->assertTrue($this->aliasApplied('eu.new.test'), 'every host under eu.new.test is answered');
        $this->getJson("/api/knative-regions/{$eu->id}")->assertJsonPath('data.domain_migration_target', 'eu.new.test');

        // 48 hours and more without DNS: nothing is removed
        Carbon::setTestNow('2026-10-20 12:00:00');
        app(DomainMigrateService::class)->completeResolvedMigrations();
        $this->assertSame('dns_check', $this->eu->fresh()->domain_migration_status);
        $this->assertSame('eu.old.test', $this->eu->fresh()->effective_settings['knative_domain']);

        // DNS of the new domain answers the external address: the new domain takes over
        $this->addresses['dns-check.eu.new.test'] = ['203.0.113.10'];
        app(DomainMigrateService::class)->completeResolvedMigrations();

        $eu = $this->eu->fresh();
        $this->assertSame('completed', $eu->domain_migration_status);
        $this->assertNull($eu->domain_migration_old_domain);
        $this->assertNull($eu->domain_migration_started_at);
        $this->assertSame('eu.new.test', $eu->effective_settings['knative_domain'], 'project URLs, function TLS, status block and replay follow');
        $this->assertSame(['acme.tenant.test' => "selector:\n  runr/tenant: acme\n", 'eu.new.test' => ''], $this->configDomain);
        $this->assertSame(['eu.new.test' => ''], $this->crDomain, 'the operator keeps the new domain');
        $this->assertTrue($this->aliasRemoved());
        $this->assertNull(RegionDomains::migrationTarget($eu));
    }

    public function test_the_status_check_completes_a_resolved_migration(): void
    {
        $this->onOldDomain();
        $this->patchJson('/api/settings', ['base_domain' => 'new.test'])->assertOk();
        $this->addresses['dns-check.eu.new.test'] = ['203.0.113.10'];

        app(DomainMigrateService::class)->completeIfResolved($this->eu->fresh());

        $this->assertSame('eu.new.test', $this->eu->fresh()->effective_settings['knative_domain']);
    }

    public function test_a_migration_can_be_completed_by_hand(): void
    {
        $this->onOldDomain();
        $this->patchJson('/api/settings', ['base_domain' => 'new.test'])->assertOk();

        $this->postJson("/api/knative-regions/{$this->eu->id}/complete-domain-migration")
            ->assertOk()->assertJsonPath('region.domain_migration_status', 'completed');

        $this->assertSame('eu.new.test', $this->eu->fresh()->effective_settings['knative_domain']);
        $this->postJson("/api/knative-regions/{$this->eu->id}/complete-domain-migration")->assertStatus(422);
    }

    public function test_setting_the_old_domain_back_rolls_the_migration_back(): void
    {
        $this->onOldDomain();
        $this->patchJson('/api/settings', ['base_domain' => 'new.test'])->assertOk();

        $this->patchJson('/api/settings', ['base_domain' => 'old.test'])->assertOk();

        $eu = $this->eu->fresh();
        $this->assertNull($eu->domain_migration_status);
        $this->assertSame('eu.old.test', $eu->effective_settings['knative_domain']);
        $this->assertSame(['eu.old.test' => ''], $this->crDomain);
        $this->assertTrue($this->aliasRemoved());
    }

    public function test_a_third_domain_moves_the_alias_and_keeps_the_function_domain(): void
    {
        $this->onOldDomain();
        $this->patchJson('/api/settings', ['base_domain' => 'new.test'])->assertOk();

        $this->patchJson('/api/settings', ['base_domain' => 'third.test'])->assertOk();

        $eu = $this->eu->fresh();
        $this->assertSame('dns_check', $eu->domain_migration_status);
        $this->assertSame('old.test', $eu->domain_migration_old_domain, 'still serving the domain it moves from');
        $this->assertTrue($this->aliasApplied('eu.third.test'));
        $this->assertSame('eu.third.test', RegionDomains::migrationTarget($eu));
    }

    public function test_a_region_with_its_own_domain_is_not_migrated(): void
    {
        Setting::set('base_domain', 'old.test');
        $own = $this->region('own', ['knative_settings' => ['knative_domain' => 'functions.customer.test']]);
        $this->eu->update(['knative_serving_status' => 'not_installed']);

        $this->patchJson('/api/settings', ['base_domain' => 'new.test'])->assertOk();
        $this->patchJson('/api/settings', ['base_domain' => null])->assertOk();

        $own = $own->fresh();
        $this->assertNull($own->domain_migration_status);
        $this->assertSame('functions.customer.test', $own->effective_settings['knative_domain']);
        $this->assertSame(0, $this->applied());
        $this->assertSame([], $this->k8sCallsTo('PATCH', '/ingressroutes/'));
    }

    public function test_a_cleared_domain_is_removed_and_a_missing_one_left_alone(): void
    {
        $this->onOldDomain();

        $this->patchJson('/api/settings', ['base_domain' => null])->assertOk();

        $this->assertSame(['acme.tenant.test' => "selector:\n  runr/tenant: acme\n"], $this->configDomain);
        $this->assertSame([], $this->crDomain, 'the operator does not write it back');
        $this->assertArrayNotHasKey('knative_domain', $this->eu->fresh()->effective_settings);

        // a region that never had it: config-domain is not rewritten
        Setting::set('base_domain', 'example.com');
        $before = $this->applied();
        $this->patchJson('/api/settings', ['base_domain' => ''])->assertOk();
        $this->assertSame($before, $this->applied());
        $this->assertSame(['acme.tenant.test' => "selector:\n  runr/tenant: acme\n"], $this->configDomain);
    }

    public function test_a_region_without_a_domain_is_repaired_and_gets_the_domain_template(): void
    {
        Setting::set('base_domain', 'example.com');

        $this->assertTrue(app(DomainMigrateService::class)->repairDomainForRegion($this->eu));

        $this->assertSame(['eu.example.com' => ''], $this->configDomain);
        $this->assertSame('eu.example.com', $this->eu->fresh()->effective_settings['knative_domain']);
        $network = $this->k8sCallsTo('PATCH', '/configmaps/config-network');
        $this->assertSame(['ingress-class' => 'kourier.ingress.networking.knative.dev', 'domain-template' => '{{.Name}}.{{.Domain}}'], $network[0]['body']['data']);

        // repaired already: nothing to do — and the template, once set, is not applied again
        $this->configNetwork = $network[0]['body']['data'];
        $this->assertFalse(app(DomainMigrateService::class)->repairDomainForRegion($this->eu->fresh()));
        $this->assertCount(1, $this->k8sCallsTo('PATCH', '/configmaps/config-network'));
        $this->assertFalse(app(DomainMigrateService::class)->repairDomainForRegion($this->region('cold', ['knative_serving_status' => 'not_installed'])));
    }

    public function test_the_alias_routes_every_service_of_the_region_through_its_cluster_local_host(): void
    {
        $manifests = RegionDomainAliasService::manifests($this->eu, 'eu.new.test');
        $byKind = [];
        foreach ($manifests as $m) {
            $byKind[$m['kind'].'/'.$m['metadata']['name']] = $m;
        }

        $this->assertSame(['ConfigMap/runr-domain-alias', 'Deployment/runr-domain-alias', 'Service/runr-domain-alias', 'IngressRoute/runr-domain-alias', 'IngressRoute/runr-domain-alias-tls'], array_keys($byKind));
        $this->assertSame('HostRegexp(`^[^.]+\\.eu\\.new\\.test$`)', $byKind['IngressRoute/runr-domain-alias']['spec']['routes'][0]['match']);
        $this->assertSame(['web'], $byKind['IngressRoute/runr-domain-alias']['spec']['entryPoints']);
        $this->assertSame(['websecure'], $byKind['IngressRoute/runr-domain-alias-tls']['spec']['entryPoints']);
        $this->assertArrayHasKey('tls', $byKind['IngressRoute/runr-domain-alias-tls']['spec']);
        $env = collect($byKind['Deployment/runr-domain-alias']['spec']['template']['spec']['containers'][0]['env'])->pluck('value', 'name')->all();
        $this->assertSame(['ALIAS_DOMAIN' => 'eu.new.test', 'SERVICE_SUFFIX' => '.fn.svc', 'TARGET_HOST' => 'traefik.knative-serving.svc.cluster.local', 'TARGET_PORT' => '80'], $env);
        $this->assertSame('fn', $byKind['Deployment/runr-domain-alias']['metadata']['namespace']);
    }
}
