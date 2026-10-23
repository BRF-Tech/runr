<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\SettingsController;
use App\Http\Middleware\AuthorizeApiRoute;
use App\Models\KnativeRegion;
use App\Models\Setting;
use App\Models\User;
use App\Services\Dns\DnsResolver;
use App\Services\KubernetesClient;
use App\Services\PublicHostRewrites;
use App\Services\RegionDomainAliasService;
use App\Services\RegionStatusService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Tenancy\Http\Controllers\TenancySettingsController;
use Modules\Tenancy\Http\Middleware\IdentifyTenant;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantDomainService;
use Tests\Concerns\BuildsAiFixtures;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * An organization's own base domain moves the way the platform's does (TenantDomainService): the
 * functions keep their URLs on the current domain and answer on the new one too — on the platform
 * regions through the organization's alias (only its services), on its own regions through
 * DomainMigrateService in its context — until DNS of the new <region>.<domain> answers the
 * region's external address (the schedule) or the move is completed by hand; then the new domain
 * takes over and the old one is no longer served. Setting it back rolls the move back, clearing
 * it returns to the platform's domain at once. The platform's own entries are never touched.
 * Runs on SQLite and on PostgreSQL (DB_CONNECTION=pgsql).
 */
class TenantDomainMigrationTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase, WiresTenancy;

    private const SELECTOR = "selector:\n  runr/tenant: acme";

    private Tenant $acme;

    private User $owner;

    /** @var array<string, string> config-domain data of the fake cluster */
    private array $configDomain = [];

    /** @var array<string, list<string>> host => A records */
    private array $addresses = [];

    /** @var list<array> IngressRoutes of the eu project namespace */
    private array $euRoutes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        config(['tenancy.enabled' => true, 'knative.agent.serviceaccount_dir' => sys_get_temp_dir().'/runr-no-sa-'.uniqid()]);

        $this->app->bind(SettingsController::class, TenantDomainMoveSettingsController::class);
        Setting::setFallbackConnection('central');
        $router = $this->app['router'];
        $priority = $router->middlewarePriority;
        $at = array_search(AuthorizeApiRoute::class, $priority, true);
        array_splice($priority, $at === false ? count($priority) : $at, 0, [IdentifyTenant::class]);
        $router->middlewarePriority = $priority;
        $router->pushMiddlewareToGroup('api', IdentifyTenant::class);

        $this->acme = $this->createTenantWithSchema('acme', array_merge(self::TENANT_REGION_MIGRATIONS, [
            'Modules/Tenancy/Database/TenantMigrations/0004_create_settings_table.php',
            'Modules/Tenancy/Database/TenantMigrations/0040_create_rbac_tables.php',
        ]));
        Setting::set('base_domain', 'platform.example');

        $this->owner = User::factory()->create(['username' => 'move-'.uniqid()]);
        DB::connection('central')->table('tenant_users')->insert([
            'tenant_id' => $this->acme->id, 'user_id' => $this->owner->id, 'role' => 'owner',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $test = $this;
        $this->app->instance(DnsResolver::class, new class($test) extends DnsResolver
        {
            public function __construct(private readonly TenantDomainMigrationTest $test) {}

            public function addresses(string $name): array
            {
                return $this->test->addressesOf($name);
            }

            public function cname(string $name): array
            {
                return [];
            }
        });

        $this->fakeCluster([
            'GET /api/v1/namespaces/knative-serving/configmaps/config-domain' => fn () => ['data' => $this->configDomain],
            'PATCH /api/v1/namespaces/knative-serving/configmaps/config-domain' => function ($request) {
                $this->configDomain = (array) (json_decode($request->body(), true)['data'] ?? []);

                return ['data' => $this->configDomain];
            },
            'GET /apis/traefik.io/v1alpha1/namespaces/eu/ingressroutes' => fn () => ['items' => $this->euRoutes],
            'GET /apis/traefik.io/v1alpha1/namespaces/eu/middlewares' => fn () => ['items' => array_values($this->euMiddlewares)],
            'PATCH /apis/traefik.io/v1alpha1/namespaces/eu/middlewares/*' => function ($request) {
                $name = basename((string) parse_url($request->url(), PHP_URL_PATH));
                $host = json_decode($request->body(), true)['spec']['headers']['customRequestHeaders']['Host'] ?? null;
                if (isset($this->euMiddlewares[$name]) && is_string($host)) {
                    $this->euMiddlewares[$name]['spec']['headers']['customRequestHeaders']['Host'] = $host;
                }

                return ['kind' => 'Middleware'];
            },
        ]);
    }

    /** @var array<string, array> rewrite Middlewares of the eu project namespace by name */
    private array $euMiddlewares = [];

    private function rewrite(string $name, string $service, string $host): void
    {
        $this->euMiddlewares[$name] = [
            'metadata' => ['name' => $name, 'labels' => [PublicHostRewrites::LABEL => PublicHostRewrites::LABEL_VALUE], 'annotations' => [PublicHostRewrites::ANNOTATION_SERVICE => $service]],
            'spec' => ['headers' => ['customRequestHeaders' => ['Host' => $host]]],
        ];
    }

    private function rewrittenTo(string $name): ?string
    {
        return $this->euMiddlewares[$name]['spec']['headers']['customRequestHeaders']['Host'] ?? null;
    }

    public function test_the_organizations_rewrites_follow_a_completed_move_at_once_and_only_its_own(): void
    {
        $this->platformRegion();
        $this->configDomain['eu.acme.example'] = self::SELECTOR;
        $this->acme->run(fn () => Setting::set('base_domain', 'acme.example'));
        $this->rewrite('orders-domain', 'orders-acme', 'orders-acme.eu.acme.example');
        $this->rewrite('orders-pr-domain', 'orders-acme-pr-3', 'orders-acme-pr-3.eu.acme.example');
        // another organization's service on a domain with the same suffix, and the platform's own
        $this->rewrite('shop-domain', 'shop-other', 'shop-other.eu.acme.example');
        $this->rewrite('docs-domain', 'docs', 'docs.eu.platform.example');

        $this->send(['base_domain' => 'acme.dev'])->assertOk();
        $this->assertSame('orders-acme.eu.acme.example', $this->rewrittenTo('orders-domain'), 'nothing moves before the move completes');

        $this->send(['complete_domain_migration' => true])->assertOk();

        $this->assertSame('orders-acme.eu.acme.dev', $this->rewrittenTo('orders-domain'));
        $this->assertSame('orders-acme-pr-3.eu.acme.dev', $this->rewrittenTo('orders-pr-domain'));
        $this->assertSame('shop-other.eu.acme.example', $this->rewrittenTo('shop-domain'));
        $this->assertSame('docs.eu.platform.example', $this->rewrittenTo('docs-domain'));
    }

    public function test_a_cleared_domain_sends_the_organizations_rewrites_to_the_platform_domain_at_once(): void
    {
        $this->platformRegion();
        $this->configDomain['eu.acme.example'] = self::SELECTOR;
        $this->acme->run(fn () => Setting::set('base_domain', 'acme.example'));
        $this->rewrite('orders-domain', 'orders-acme', 'orders-acme.eu.acme.example');

        $this->send(['base_domain' => null])->assertOk();

        $this->assertSame('orders-acme.eu.platform.example', $this->rewrittenTo('orders-domain'));
    }

    protected function tearDown(): void
    {
        Setting::setFallbackConnection(null);
        $this->tearDownTenancy();
        parent::tearDown();
    }

    /** @return list<string> */
    public function addressesOf(string $name): array
    {
        return $this->addresses[$name] ?? [];
    }

    private function send(array $data, string $method = 'PATCH'): TestResponse
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->owner->createToken('api')->plainTextToken,
            'Accept' => 'application/json',
            'X-Tenant-ID' => $this->acme->id,
        ];

        try {
            return $this->json($method, '/api/settings', $data, $headers);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
            $this->app['auth']->forgetGuards();
        }
    }

    /** A platform region acme uses (project namespace eu, external address 203.0.113.10). */
    private function platformRegion(array $attributes = []): KnativeRegion
    {
        $region = KnativeRegion::create(array_merge([
            'name' => 'eu', 'project_namespace' => 'eu', 'is_active' => true, 'kubeconfig' => $this->fakeKubeconfig(),
            'knative_serving_status' => 'ready', 'external_ip' => '203.0.113.10',
            'knative_settings' => ['knative_domain' => 'eu.platform.example'],
        ], $attributes));
        $this->acme->regions()->attach($region->id, ['cpu_quota_m' => 1000, 'memory_quota_mb' => 2048]);
        $this->configDomain['eu.platform.example'] = '';

        return $region;
    }

    /** The alias objects applied in a namespace under the organization's alias name. */
    private function aliasPatches(string $ns): array
    {
        return $this->k8sCallsTo('PATCH', "/namespaces/{$ns}/deployments/".TenantDomainService::aliasName($this->acme));
    }

    private function aliasRemoved(string $ns): bool
    {
        return $this->k8sCallsTo('DELETE', "/namespaces/{$ns}/deployments/".TenantDomainService::aliasName($this->acme)) !== [];
    }

    private function state(): ?array
    {
        return Tenant::find($this->acme->id)->getAttribute(TenantDomainService::STATE);
    }

    public function test_a_first_domain_answers_next_to_the_platform_domain_until_its_dns_answers(): void
    {
        $this->platformRegion();

        $this->send(['base_domain' => 'acme.example'])->assertOk()
            ->assertJsonPath('tenant_domain_migration.from', null)
            ->assertJsonPath('tenant_domain_migration.to', 'acme.example')
            ->assertJsonPath('tenant_domain_migration.regions', ['eu']);

        // The functions keep their URLs: no entry of acme's yet, its alias answers the new domain
        $this->assertSame(['eu.platform.example' => ''], $this->configDomain);
        $deployment = $this->aliasPatches('eu')[0]['body'] ?? null;
        $this->assertNotNull($deployment, 'the organization\'s alias');
        $env = collect($deployment['spec']['template']['spec']['containers'][0]['env'])->pluck('value', 'name')->all();
        $this->assertSame('eu.acme.example', $env['ALIAS_DOMAIN']);
        $this->assertSame('-acme', $env['SERVICE_NAME_SUFFIX'], 'only acme\'s services');
        $this->assertSame('acme', $deployment['metadata']['labels']['runr/tenant']);
        $platformAlias = array_filter($this->k8sCalls, fn ($c) => str_ends_with($c['path'], '/deployments/'.RegionDomainAliasService::NAME));
        $this->assertSame([], $platformAlias, 'the platform\'s alias is not touched');

        // DNS not there yet: the schedule leaves it as it is
        app(TenantDomainService::class)->completeResolvedMigrations();
        $this->assertNotNull($this->state());
        $this->assertFalse($this->aliasRemoved('eu'));

        // DNS answers: acme's entry, alias gone, move done
        $this->addresses['dns-check.eu.acme.example'] = ['203.0.113.10'];
        app(TenantDomainService::class)->completeResolvedMigrations();

        $this->assertSame(['eu.platform.example' => '', 'eu.acme.example' => self::SELECTOR], $this->configDomain);
        $this->assertTrue($this->aliasRemoved('eu'));
        $this->assertNull($this->state());
        $this->send([], 'GET')->assertOk()->assertJsonPath('tenant_domain_migration', null);
    }

    public function test_a_changed_domain_keeps_the_old_entry_until_the_move_completes_by_hand(): void
    {
        $this->platformRegion();
        $this->configDomain['eu.acme.example'] = self::SELECTOR;
        $this->acme->run(fn () => Setting::set('base_domain', 'acme.example'));

        $this->send(['base_domain' => 'acme.dev'])->assertOk()
            ->assertJsonPath('tenant_domain_migration.from', 'acme.example')
            ->assertJsonPath('tenant_domain_migration.to', 'acme.dev');
        $this->assertSame(self::SELECTOR, $this->configDomain['eu.acme.example'] ?? null, 'still the functions\' URLs');
        $this->assertArrayNotHasKey('eu.acme.dev', $this->configDomain);

        // A move by hand: DNS behind a proxy the control plane cannot check
        $this->send(['complete_domain_migration' => true])->assertOk()->assertJsonPath('tenant_domain_migration', null);

        $this->assertSame(['eu.platform.example' => '', 'eu.acme.dev' => self::SELECTOR], $this->configDomain);
        $this->assertTrue($this->aliasRemoved('eu'));
    }

    public function test_setting_the_domain_back_rolls_the_move_back_and_clearing_returns_to_the_platform_domain(): void
    {
        $this->platformRegion();
        $this->configDomain['eu.acme.example'] = self::SELECTOR;
        $this->acme->run(fn () => Setting::set('base_domain', 'acme.example'));

        // acme.dev, then acme.io before DNS answered: still moving from acme.example
        $this->send(['base_domain' => 'acme.dev'])->assertOk();
        $this->send(['base_domain' => 'acme.io'])->assertOk()
            ->assertJsonPath('tenant_domain_migration.from', 'acme.example')
            ->assertJsonPath('tenant_domain_migration.to', 'acme.io');
        $patches = $this->aliasPatches('eu');
        $last = end($patches)['body'];
        $this->assertSame('eu.acme.io', collect($last['spec']['template']['spec']['containers'][0]['env'])->firstWhere('name', 'ALIAS_DOMAIN')['value']);

        // Back to acme.example: the alias goes, nothing else changes
        $this->send(['base_domain' => 'acme.example'])->assertOk()->assertJsonPath('tenant_domain_migration', null);
        $this->assertTrue($this->aliasRemoved('eu'));
        $this->assertSame(['eu.platform.example' => '', 'eu.acme.example' => self::SELECTOR], $this->configDomain);

        // Moving again, then cleared: the platform's domain answers already — acme's entry goes now
        $this->send(['base_domain' => 'acme.dev'])->assertOk();
        $this->send(['base_domain' => null])->assertOk()->assertJsonPath('tenant_domain_migration', null);
        $this->assertSame(['eu.platform.example' => ''], $this->configDomain);
    }

    public function test_the_organizations_own_regions_move_through_the_platform_flow_in_its_context(): void
    {
        $this->acme->run(fn () => Setting::set('base_domain', 'acme.example'));
        $ownId = $this->acme->run(fn () => KnativeRegion::create([
            'name' => 'own', 'project_namespace' => 'own', 'is_active' => true, 'kubeconfig' => $this->fakeKubeconfig(),
            'knative_serving_status' => 'ready', 'external_ip' => '198.51.100.7',
            'knative_settings' => ['knative_domain' => 'own.acme.example'],
        ])->id);
        $this->configDomain['own.acme.example'] = '';

        $this->send(['base_domain' => 'acme.dev'])->assertOk()
            ->assertJsonPath('tenant_domain_migration.regions', ['own']);

        $own = fn () => $this->acme->run(fn () => KnativeRegion::find($ownId));
        $this->assertSame('dns_check', $own()->domain_migration_status);
        $this->assertSame('acme.example', $own()->domain_migration_old_domain);
        $this->assertNotEmpty($this->k8sCallsTo('PATCH', '/namespaces/own/deployments/'.RegionDomainAliasService::NAME), 'the region\'s own alias');
        $this->assertSame('own.acme.example', $own()->knative_settings['knative_domain']);

        $this->addresses['dns-check.own.acme.dev'] = ['198.51.100.7'];
        app(TenantDomainService::class)->completeResolvedMigrations();

        $this->assertSame('completed', $own()->domain_migration_status);
        $this->assertSame('own.acme.dev', $own()->knative_settings['knative_domain']);
        $this->assertArrayHasKey('own.acme.dev', $this->configDomain);
        $this->assertArrayNotHasKey('own.acme.example', $this->configDomain);
        $this->assertNull($this->state());
    }

    public function test_a_detached_region_loses_only_the_organizations_entries(): void
    {
        $region = $this->platformRegion();
        // A tenant without a domain of its own: Setting::get() falls back to the platform's
        $this->configDomain['eu.acme.dev'] = self::SELECTOR;

        app(TenantDomainService::class)->removeDomainFromRegion($this->acme, $region);
        $this->assertSame(['eu.platform.example' => '', 'eu.acme.dev' => self::SELECTOR], $this->configDomain, 'the platform\'s entry is never the organization\'s');

        $this->acme->run(fn () => Setting::set('base_domain', 'acme.dev'));
        app(TenantDomainService::class)->removeDomainFromRegion($this->acme, $region);
        $this->assertSame(['eu.platform.example' => ''], $this->configDomain);
    }

    public function test_the_status_block_of_a_region_covers_an_alias_domain(): void
    {
        $region = $this->platformRegion(['is_active' => false]);
        $this->euRoutes = [
            ['metadata' => ['name' => TenantDomainService::aliasName($this->acme), 'annotations' => [RegionDomainAliasService::ANNOTATION_ALIAS => 'eu.acme.dev']]],
        ];

        $rules = RegionStatusService::blockedHostRules($region, new KubernetesClient($region->kubeconfig));

        $this->assertSame(['HostRegexp(`^.+\\.eu\\.acme\\.dev$`)', 'HostRegexp(`^.+\\.eu\\.platform\\.example$`)'], $rules);
    }
}

/** The multi-tenant settings controller without the platform-wide runtime catalog (TenantSettingsDomainTest). */
final class TenantDomainMoveSettingsController extends TenancySettingsController
{
    protected function activeExecutorImages(): Collection
    {
        return DB::connection()->getDriverName() === 'pgsql' ? parent::activeExecutorImages() : new Collection;
    }
}
