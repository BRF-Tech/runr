<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\SettingsController;
use App\Http\Middleware\AuthorizeApiRoute;
use App\Models\KnativeRegion;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Modules\Tenancy\Http\Controllers\TenancySettingsController;
use Modules\Tenancy\Http\Middleware\IdentifyTenant;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Settings in a multi-tenant install: the organization domain of a tenant (its own base_domain,
 * set by members with settings.manage in the console) next to the platform default, the old
 * domain taken from the organization's own setting — never the platform's — when the
 * config-domain entries move (once the move completes, TenantDomainMigrationTest), and the
 * platform regions' capacity (assignable_regions) for platform administrators only.
 */
class TenantSettingsDomainTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private Tenant $acme;

    /** @var array<string, string> config-domain data of the platform cluster */
    private array $configDomain = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        config(['tenancy.enabled' => true]);

        // As TenancyServiceProvider wires it: the tenant-aware settings controller, IdentifyTenant
        // after authentication and before the route's permission check
        $this->app->bind(SettingsController::class, TenantDomainTestSettingsController::class);
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
    }

    protected function tearDown(): void
    {
        Setting::setFallbackConnection(null);
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function user(bool $admin = false, ?string $role = null): User
    {
        static $n = 0;
        $user = User::factory()->create(['username' => 'dom-'.(++$n).'-'.uniqid()]);
        if ($admin) {
            $user->forceFill(['is_admin' => true])->save();
        }
        if ($role) {
            DB::connection('central')->table('tenant_users')->insert([
                'tenant_id' => $this->acme->id, 'user_id' => $user->id, 'role' => $role,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $user->fresh();
    }

    private function send(User $user, string $method, array $data = [], bool $inTenant = true): TestResponse
    {
        $headers = ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken, 'Accept' => 'application/json'];
        if ($inTenant) {
            $headers['X-Tenant-ID'] = $this->acme->id;
        }

        try {
            return $this->json($method, '/api/settings', $data, $headers);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
            $this->app['auth']->forgetGuards();
        }
    }

    private function ownDomain(): ?string
    {
        return $this->acme->run(fn () => Setting::query()->whereKey('base_domain')->value('value'));
    }

    /** A platform region assigned to acme whose cluster records the config-domain changes. */
    private function platformRegion(): KnativeRegion
    {
        $region = KnativeRegion::create([
            'name' => 'eu', 'project_namespace' => 'eu', 'is_active' => true,
            'total_cpu_m' => 8000, 'total_memory_mb' => 16384,
            'knative_serving_status' => 'ready',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://platform.test:6443', 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
        ]);
        $this->acme->regions()->attach($region->id, ['cpu_quota_m' => 1000, 'memory_quota_mb' => 2048]);

        $this->configDomain = ['eu.platform.example' => ''];
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/configmaps/config-domain')) {
                if ($request->method() !== 'GET') {
                    // server-side apply: the whole data map
                    $this->configDomain = (array) (json_decode($request->body(), true)['data'] ?? []);
                }

                return Http::response(['data' => $this->configDomain]);
            }

            return Http::response(['kind' => 'Status', 'code' => 404], 404);
        });

        return $region;
    }

    public function test_the_organization_domain_and_the_platform_default_come_apart(): void
    {
        $owner = $this->user(role: 'owner');

        $this->send($owner, 'GET')->assertOk()
            ->assertJsonPath('base_domain', 'platform.example')
            ->assertJsonPath('tenant_base_domain', null)
            ->assertJsonPath('platform_base_domain', 'platform.example');

        $this->send($owner, 'PATCH', ['base_domain' => 'Apps.Acme.example'])->assertOk()
            ->assertJsonPath('base_domain', 'apps.acme.example')
            ->assertJsonPath('tenant_base_domain', 'apps.acme.example')
            ->assertJsonPath('platform_base_domain', 'platform.example');
        $this->assertSame('apps.acme.example', $this->ownDomain());
        $this->assertSame('platform.example', Setting::on('central')->whereKey('base_domain')->value('value'), 'the platform default is untouched');

        // Cleared: the platform default applies again
        $this->send($owner, 'PATCH', ['base_domain' => null])->assertOk()
            ->assertJsonPath('base_domain', 'platform.example')
            ->assertJsonPath('tenant_base_domain', null);
        $this->assertNull($this->ownDomain());
    }

    public function test_the_platform_domain_or_a_malformed_one_is_refused(): void
    {
        $owner = $this->user(role: 'owner');

        $this->send($owner, 'PATCH', ['base_domain' => 'platform.example'])->assertStatus(422)->assertJsonValidationErrors('base_domain');
        $this->send($owner, 'PATCH', ['base_domain' => 'not a domain'])->assertStatus(422)->assertJsonValidationErrors('base_domain');
        $this->assertNull($this->ownDomain());
    }

    public function test_setting_a_first_domain_never_removes_the_platform_entries(): void
    {
        $this->platformRegion();
        $owner = $this->user(role: 'owner');

        // The first domain moves like any other (TenantDomainMigrationTest): until it completes,
        // the functions keep answering on the platform's domain
        $this->send($owner, 'PATCH', ['base_domain' => 'acme.example'])->assertOk()
            ->assertJsonPath('tenant_domain_migration.to', 'acme.example')
            ->assertJsonPath('tenant_domain_migration.from', null);
        $this->assertSame(['eu.platform.example'], array_keys($this->configDomain));

        // Completed: the platform's eu.platform.example stays; acme's own entry is added with its selector
        $this->send($owner, 'PATCH', ['complete_domain_migration' => true])->assertOk()
            ->assertJsonPath('tenant_domain_migration', null);
        $this->assertArrayHasKey('eu.platform.example', $this->configDomain);
        $this->assertSame("selector:\n  runr/tenant: acme", $this->configDomain['eu.acme.example'] ?? null);

        // Moving and clearing touch only acme's entries
        $this->send($owner, 'PATCH', ['base_domain' => 'acme.dev'])->assertOk();
        $this->assertArrayHasKey('eu.acme.example', $this->configDomain, 'served until the move completes');
        $this->send($owner, 'PATCH', ['complete_domain_migration' => true])->assertOk();
        $this->assertArrayNotHasKey('eu.acme.example', $this->configDomain);
        $this->assertArrayHasKey('eu.acme.dev', $this->configDomain);

        $this->send($owner, 'PATCH', ['base_domain' => ''])->assertOk();
        $this->assertSame(['eu.platform.example'], array_keys($this->configDomain));
    }

    public function test_changing_the_domain_needs_settings_manage(): void
    {
        $dev = $this->user(role: 'developer');

        $this->send($dev, 'PATCH', ['base_domain' => 'acme.example'])->assertForbidden();
        $this->assertNull($this->ownDomain());
    }

    public function test_the_platform_regions_capacity_is_for_platform_admins_only(): void
    {
        $this->platformRegion();
        Http::fake();

        $member = $this->send($this->user(role: 'owner'), 'GET');
        $member->assertOk();
        $this->assertArrayNotHasKey('assignable_regions', $member->json());

        $admin = $this->user(admin: true);
        $central = $this->send($admin, 'GET', inTenant: false);
        $central->assertOk();
        $this->assertSame(['eu'], array_column($central->json('assignable_regions'), 'name'));
        $this->assertSame(1000, $central->json('assignable_regions.0.allocated_cpu_m'));
    }
}

/**
 * The settings controller of a multi-tenant install. The runtime catalog (executor images,
 * runtimes) is platform-wide: central tables a tenant connection reaches through its search path
 * on PostgreSQL — a SQLite tenant database is a file of its own, so the catalog stays empty there.
 */
final class TenantDomainTestSettingsController extends TenancySettingsController
{
    protected function activeExecutorImages(): Collection
    {
        return DB::connection()->getDriverName() === 'pgsql' ? parent::activeExecutorImages() : new Collection;
    }
}
