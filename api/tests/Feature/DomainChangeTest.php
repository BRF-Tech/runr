<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Models\Setting;
use App\Services\DomainMigrateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * The platform's base domain (PATCH /api/settings base_domain) in the regions' Knative
 * config-domain: set for the first time on every region with Serving, changed with a migration
 * (the new domain next to the old one until DNS points at it, the old one removed after 48 hours
 * by the hourly domain-migration-auto-complete), cleared; other domains of a region (tenants)
 * are kept, and a domain a region does not have is never "removed" by rewriting the map.
 */
class DomainChangeTest extends TestCase
{
    use ActsWithRoles, BuildsAiFixtures, RefreshDatabase;

    /** @var array<string, string> the config-domain data of the fake cluster */
    private array $configDomain = [];

    /** @var array<string, string> the config-network data of the fake cluster */
    private array $configNetwork = ['ingress-class' => 'kourier.ingress.networking.knative.dev'];

    private KnativeRegion $eu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signedIn($this->userWithRole('owner'));
        $this->eu = $this->region('eu');

        $this->fakeCluster([
            'GET /api/v1/namespaces/knative-serving/configmaps/config-domain' => fn () => ['data' => $this->configDomain],
            'PATCH /api/v1/namespaces/knative-serving/configmaps/config-domain' => function ($request) {
                $this->configDomain = json_decode($request->body(), true)['data'];

                return ['data' => $this->configDomain];
            },
            'GET /api/v1/namespaces/knative-serving/configmaps/config-network' => fn () => ['data' => $this->configNetwork],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function region(string $name, array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => $name, 'project_namespace' => 'fn', 'is_active' => true, 'kubeconfig' => $this->fakeKubeconfig(),
            'knative_serving_status' => 'ready',
        ], $attributes));
    }

    private function applied(): int
    {
        return count($this->k8sCallsTo('PATCH', '/configmaps/config-domain'));
    }

    public function test_the_first_base_domain_goes_to_every_region_with_serving(): void
    {
        $this->region('demo', ['is_demo' => true]);
        $this->region('fresh', ['knative_serving_status' => 'installing']);
        $this->configDomain = ['acme.tenant.test' => "selector:\n  runr/tenant: acme\n"];

        $this->patchJson('/api/settings', ['base_domain' => 'example.com'])->assertOk()->assertJsonPath('base_domain', 'example.com');

        $this->assertSame(['acme.tenant.test' => "selector:\n  runr/tenant: acme\n", 'eu.example.com' => ''], $this->configDomain, 'a tenant\'s domain stays');
        $this->assertSame(1, $this->applied(), 'not the demo region nor one without Serving');
        $this->assertSame('eu.example.com', $this->eu->fresh()->effective_settings['knative_domain']);
    }

    public function test_a_changed_domain_is_migrated_and_the_old_one_removed_after_48_hours(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        Setting::set('base_domain', 'old.test');
        $this->configDomain = ['eu.old.test' => ''];

        $this->patchJson('/api/settings', ['base_domain' => 'new.test'])->assertOk();

        // both while DNS moves
        $this->assertSame(['eu.old.test' => '', 'eu.new.test' => ''], $this->configDomain);
        $eu = $this->eu->fresh();
        $this->assertSame('dns_check', $eu->domain_migration_status);
        $this->assertSame('old.test', $eu->domain_migration_old_domain);

        Carbon::setTestNow('2026-10-17 11:59:00');
        app(DomainMigrateService::class)->autoCompleteExpiredMigrations();
        $this->assertSame('dns_check', $this->eu->fresh()->domain_migration_status, 'not before 48 hours');

        Carbon::setTestNow('2026-10-17 12:00:00');
        app(DomainMigrateService::class)->autoCompleteExpiredMigrations();
        $this->assertSame(['eu.new.test' => ''], $this->configDomain);
        $eu = $this->eu->fresh();
        $this->assertSame('completed', $eu->domain_migration_status);
        $this->assertNull($eu->domain_migration_old_domain);
        $this->assertNull($eu->domain_migration_started_at);
    }

    public function test_a_cleared_domain_is_removed_and_a_missing_one_left_alone(): void
    {
        Setting::set('base_domain', 'example.com');
        $this->configDomain = ['eu.example.com' => '', 'acme.tenant.test' => ''];

        $this->patchJson('/api/settings', ['base_domain' => null])->assertOk();

        $this->assertSame(['acme.tenant.test' => ''], $this->configDomain);

        // a region that never had it: config-domain is not rewritten
        Setting::set('base_domain', 'example.com');
        $before = $this->applied();
        $this->patchJson('/api/settings', ['base_domain' => ''])->assertOk();
        $this->assertSame($before, $this->applied());
        $this->assertSame(['acme.tenant.test' => ''], $this->configDomain);
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
}
