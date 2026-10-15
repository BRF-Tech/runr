<?php

namespace Tests\Feature\Bindings;

use App\Events\BindingAudited;
use App\Models\Binding;
use App\Services\Bindings\BindingQuotas;
use App\Services\Bindings\BindingTenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Modules\Tenancy\Http\Controllers\TenantBindingQuotaController;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantBindingQuota;
use Modules\Tenancy\Services\Bindings\TenancyBindingsBridge;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Bindings with the Tenancy module: per-tenant limit overrides (tenant_binding_quotas, central)
 * on top of config bindings.quotas, GET/PUT /tenants/{tenant}/binding-quotas, and the runner
 * that visits the central context and every tenant (scheduled binding work).
 */
class TenantBindingQuotaTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private const TENANT_MIGRATIONS = [
        'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0002_create_knative_regions_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0035_create_bindings_tables.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();

        config(['bindings.quotas' => [
            'max_bindings' => 20,
            'kv_mb' => 1024,
            'object_gb' => 100,
            'postgres_gb' => 50,
            'dedicated_postgres' => 2,
        ]]);

        BindingTenancy::reset();
        TenancyBindingsBridge::register();
    }

    protected function tearDown(): void
    {
        BindingTenancy::reset();
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function tenant(string $slug): Tenant
    {
        return $this->createTenantWithSchema($slug, self::TENANT_MIGRATIONS);
    }

    private function binding(string $name, string $type = 'kv', array $settings = ['memory_mb' => 64]): Binding
    {
        return Binding::create([
            'name' => $name,
            'resource_name' => $name.'-abcdefgh',
            'type' => $type,
            'plan' => 'shared',
            'status' => Binding::STATUS_READY,
            'settings' => $settings,
        ]);
    }

    public function test_overrides_replace_the_defaults_inside_the_tenant_only(): void
    {
        $tenant = $this->tenant('acme');
        TenantBindingQuota::query()->create(['tenant_id' => $tenant->getTenantKey(), 'max_bindings' => 3, 'kv_mb' => null]);

        $limits = $tenant->run(fn () => app(BindingQuotas::class)->limits());

        $this->assertSame(3, $limits['max_bindings'], 'override');
        $this->assertSame(1024, $limits['kv_mb'], 'null falls back to the default');
        $this->assertSame(2, $limits['dedicated_postgres']);

        // Central context: the install defaults
        $this->assertSame(20, app(BindingQuotas::class)->limits()['max_bindings']);
    }

    public function test_the_tenant_limit_is_what_creation_is_checked_against(): void
    {
        $tenant = $this->tenant('acme');
        TenantBindingQuota::query()->create(['tenant_id' => $tenant->getTenantKey(), 'max_bindings' => 1]);

        $exceeded = $tenant->run(function () {
            $this->binding('cache');

            return app(BindingQuotas::class)->exceeded('kv', 'shared', ['memory_mb' => 16]);
        });

        $this->assertSame('max_bindings', $exceeded['key']);
        $this->assertSame(1, $exceeded['limit']);
        $this->assertSame(1, $exceeded['used']);
    }

    public function test_update_stores_overrides_and_show_reports_limits_and_usage(): void
    {
        Event::fake([BindingAudited::class]);
        $tenant = $this->tenant('acme');
        $tenant->run(fn () => $this->binding('cache', 'kv', ['memory_mb' => 128]));

        $controller = app(TenantBindingQuotaController::class);
        $response = $controller->update(Request::create('/', 'PUT', ['max_bindings' => 5, 'dedicated_postgres' => 0]), (string) $tenant->getTenantKey());
        $data = $response->getData(true)['data'];

        $this->assertSame(5, $data['overrides']['max_bindings']);
        $this->assertSame(0, $data['overrides']['dedicated_postgres']);
        $this->assertNull($data['overrides']['kv_mb']);
        $this->assertSame(5, $data['limits']['max_bindings']);
        $this->assertSame(0, $data['limits']['dedicated_postgres'], '0 = unlimited is a valid override');
        $this->assertSame(1024, $data['limits']['kv_mb']);
        $this->assertSame(20, $data['defaults']['max_bindings']);
        $this->assertSame(1, $data['used']['max_bindings']);
        $this->assertSame(128, $data['used']['kv_mb']);

        $this->assertDatabaseHas('tenant_binding_quotas', ['tenant_id' => $tenant->getTenantKey(), 'max_bindings' => 5], 'central');
        Event::assertDispatched(BindingAudited::class, fn (BindingAudited $e) => $e->action === 'binding_quota.updated'
            && $e->subject['tenant_id'] === $tenant->getTenantKey()
            && $e->meta['max_bindings'] === 5);

        $shown = $controller->show((string) $tenant->getTenantKey())->getData(true)['data'];
        $this->assertSame($data['overrides'], $shown['overrides']);
    }

    public function test_update_rejects_negative_limits(): void
    {
        $tenant = $this->tenant('acme');

        $this->expectException(ValidationException::class);
        app(TenantBindingQuotaController::class)->update(Request::create('/', 'PUT', ['kv_mb' => -1]), (string) $tenant->getTenantKey());
    }

    public function test_the_runner_visits_the_central_context_and_every_tenant(): void
    {
        $this->binding('central-cache');
        $acme = $this->tenant('acme');
        $globex = $this->tenant('globex');
        $acme->run(fn () => $this->binding('acme-cache'));
        $globex->run(fn () => $this->binding('globex-db', 'postgres', ['storage_gb' => 1]));

        $seen = [];
        BindingTenancy::eachContext(function () use (&$seen) {
            $seen[(string) (BindingTenancy::tenantKey() ?? 'central')] = Binding::query()->pluck('name')->all();
        });

        $this->assertSame(['central-cache'], $seen['central']);
        $this->assertSame(['acme-cache'], $seen[(string) $acme->getTenantKey()]);
        $this->assertSame(['globex-db'], $seen[(string) $globex->getTenantKey()]);
        $this->assertFalse(tenancy()->initialized, 'the runner leaves the central context as it found it');
    }

    public function test_the_runner_restores_the_tenant_it_was_called_in(): void
    {
        $acme = $this->tenant('acme');
        $globex = $this->tenant('globex');

        $acme->run(function () use ($acme) {
            $visited = 0;
            BindingTenancy::eachContext(function () use (&$visited) {
                $visited++;
            });

            $this->assertSame(3, $visited, 'central + two tenants');
            $this->assertSame($acme->getTenantKey(), tenancy()->tenant?->getTenantKey());
        });

        $this->assertNotNull($globex);
    }
}
