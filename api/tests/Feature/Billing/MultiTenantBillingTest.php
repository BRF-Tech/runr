<?php

namespace Tests\Feature\Billing;

use App\Models\KnativeRegion;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Services\Bindings\BindingTenancy;
use App\Services\Dns\DnsResolver;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\BillingEvent;
use Modules\Billing\Models\FixedRequest;
use Modules\Billing\Models\Markup;
use Modules\Billing\Models\RegionSetting;
use Modules\Billing\Providers\BillingServiceProvider;
use Modules\Billing\Services\BillCalculator;
use Modules\Billing\Services\PriceBook;
use Modules\Billing\Services\RegionTypes;
use Modules\Billing\Services\TenantBillService;
use Modules\Billing\Support\Period;
use Modules\Tenancy\Events\RegionShareOffered;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantLocalRegion;
use Modules\Tenancy\Models\TenantUser;
use Modules\Tenancy\Services\TenantDeletionService;
use Modules\Tenancy\Support\TenantRegionShares;
use Tests\Concerns\WiresTenancy;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\Support\ActingTenant;
use Tests\TestCase;

/**
 * Billing with tenancy (docs/billing.md#with-tenancy): a shared platform region waits for the
 * tenant's acceptance with a markup; the tenant bills its projects at platform price × (1 + markup)
 * with effective dates; the platform bills the tenant at platform price from the central copies of
 * its usage and allocations; the tenant sees its bills from the platform. Runs on SQLite and on
 * PostgreSQL (DB_CONNECTION=pgsql) like the other tenancy tests.
 */
class MultiTenantBillingTest extends TestCase
{
    use EnablesBilling, RefreshDatabase, WiresTenancy;

    private const TENANT_MIGRATIONS = [
        'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0002_create_knative_regions_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0005_add_migration_fields_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0006_add_sandbox_and_kata_hypervisor_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0007_add_user_id_to_notifications.php',
        'Modules/Tenancy/Database/TenantMigrations/0008_add_region_status_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0009_create_usage_records_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0015_create_notification_preferences_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0020_add_created_by_to_projects.php',
        'Modules/Tenancy/Database/TenantMigrations/0022_add_agent_token_hash_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0023_add_observability_managed_flags_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0024_add_function_tls_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0035_add_wasm_sandbox_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0035_create_bindings_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0040_create_rbac_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0045_add_gpu_columns.php',
        'Modules/Tenancy/Database/TenantMigrations/0052_add_capacity_admission_columns.php',
        'Modules/Tenancy/Database/TenantMigrations/0058_add_owner_and_stop_to_projects.php',
        'Modules/Tenancy/Database/TenantMigrations/0059_add_created_by_to_usage_records.php',
        'Modules/Billing/Database/TenantMigrations/0060_create_billing_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0062_add_deployment_spans_and_stop_level.php',
    ];

    private Tenant $tenant;

    private KnativeRegion $region;

    private User $tenantOwner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');

        $this->setUpTenancy();
        config(['tenancy.enabled' => true, 'app.key' => config('app.key') ?: 'base64:'.base64_encode(random_bytes(32))]);
        $this->setUpBilling();

        // The test tenant context (X-Test-Tenant) is set where IdentifyTenant runs in an install:
        // right after authentication, before route model binding ({project} is the tenant's) and
        // the route's permission check
        $router = $this->app['router'];
        $priority = $router->middlewarePriority;
        $at = array_search(AuthenticatesRequests::class, $priority, true);
        array_splice($priority, $at === false ? 0 : $at + 1, 0, [ActingTenant::class]);
        $router->middlewarePriority = $priority;
        $router->pushMiddlewareToGroup('api', ActingTenant::class);

        $this->tenant = $this->createTenantWithSchema('acme'.Str::lower(Str::random(4)), self::TENANT_MIGRATIONS);
        $this->region = KnativeRegion::query()->create([
            'name' => 'eu-1', 'project_namespace' => 'eu-1', 'is_active' => true, 'is_demo' => true,
            'total_cpu_m' => 64000, 'total_memory_mb' => 262144,
        ]);
        // Platform price of the region (central)
        $this->priceVersion($this->region->id, '2026-01-01', ['cpu_vcpu_hour' => 0.10, 'cpu_vcpu_month' => 20, 'memory_gb_month' => 2]);

        $this->tenantOwner = User::factory()->create(['username' => 'owner-'.Str::lower(Str::random(6))]);
        TenantUser::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->tenantOwner->id, 'role' => 'owner']);
    }

    protected function tearDown(): void
    {
        $this->tearDownBilling();
        $this->tearDownTenancy();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function headers(): array
    {
        return ['X-Test-Tenant' => (string) $this->tenant->id, 'Accept' => 'application/json'];
    }

    private function share(string $status): void
    {
        DB::connection('central')->table('tenant_regions')->insert([
            'tenant_id' => $this->tenant->id, 'knative_region_id' => $this->region->id, 'cpu_quota_m' => 8000, 'memory_quota_mb' => 16384,
            'status' => $status, 'offered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The tenant's copy of the shared region (the stancl sync is not wired in the suite). */
    private function copyIntoTenant(): void
    {
        $this->tenant->run(fn () => TenantLocalRegion::query()->create([
            'id' => $this->region->id, 'name' => $this->region->name, 'project_namespace' => 'eu-1',
            'is_active' => true, 'is_demo' => true, 'is_synced' => true, 'total_cpu_m' => 64000, 'total_memory_mb' => 262144,
        ]));
    }

    /**
     * A project in the tenant, created without model events: ProjectObserver queues a region
     * capacity check that runs after the next response and restores the region it captured on the
     * tenant's connection. In the suite the next response is a test request whose tenancy
     * ActingTenant has ended by then ("Database connection [tenant] not configured"); in an
     * install the request that creates a project keeps its tenant until it terminates.
     */
    private function tenantProject(string $name): Project
    {
        return $this->tenant->run(fn () => Project::withoutEvents(function () use ($name) {
            $project = new Project;
            $project->forceFill([
                'name' => $name, 'slug' => $name, 'service_type' => 'caas', 'knative_region_id' => $this->region->id,
                'created_by' => $this->tenantOwner->id, 'owner_id' => $this->tenantOwner->id,
            ])->save();

            return $project;
        }));
    }

    /** A day of usage in the tenant's schema and its central copy (what the core metering writes). */
    private function tenantUsage(Project $project, string $date, float $vcpuHours): void
    {
        $row = [
            'id' => (string) Str::ulid(), 'project_id' => $project->id, 'deployment_id' => (string) Str::ulid(),
            'region_id' => $this->region->id, 'record_date' => $date, 'scale_mode' => 'scale_to_zero', 'entry_point' => 'http',
            'session_count' => 1, 'total_cpu_mc_ms' => (int) round($vcpuHours * 3_600_000_000), 'total_memory_mib_ms' => 0,
            'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ];
        $this->tenant->run(fn () => DB::table('usage_records')->insert($row));
        DB::connection('central')->table('usage_records')->insert($row + ['tenant_id' => (string) $this->tenant->id]);
    }

    public function test_a_shared_region_waits_for_acceptance_with_a_markup(): void
    {
        $this->assertTrue(TenantRegionShares::requiresAcceptance());
        $this->share(TenantRegionShares::PENDING);

        event(new RegionShareOffered($this->tenant, $this->region->id, null));

        // The tenant's billing managers were told, the platform's log has the offer
        $this->assertTrue($this->tenant->run(fn () => Notification::query()->where('user_id', $this->tenantOwner->id)->where('type', 'billing.region_offer')->exists()));
        $this->assertTrue(BillingEvent::on('central')->where('type', 'region_share.offered')->where('tenant_id', $this->tenant->id)->exists());

        // The tenant sets its default markup, sees the offer with the platform's price
        $this->actingAs($this->tenantOwner)->postJson('/api/billing/markups', ['percent' => 10, 'effective_from' => '2026-10-20'], $this->headers())->assertCreated();
        $this->actingAs($this->tenantOwner)->getJson('/api/billing/region-offers', $this->headers())->assertOk()
            ->assertJsonPath('default_markup_percent', 10)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.billing_type', 'payg')
            ->assertJsonPath('data.0.prices.current.cpu_vcpu_hour', 0.1);

        $this->actingAs($this->tenantOwner)->postJson("/api/billing/region-offers/{$this->region->id}/accept", ['markup_percent' => 20], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.markup_percent', 20);
        $this->actingAs($this->tenantOwner)->postJson("/api/billing/region-offers/{$this->region->id}/accept", [], $this->headers())
            ->assertStatus(409)->assertJsonPath('code', 'offer_not_pending');

        $this->assertSame(TenantRegionShares::ACTIVE, TenantRegionShares::status((string) $this->tenant->id, $this->region->id));
        $this->assertTrue(BillingEvent::on('central')->where('type', 'region_share.accepted')->exists());

        // Markups are a tenant thing: the platform has none
        $admin = User::factory()->create(['username' => 'padmin-'.Str::lower(Str::random(6))]);
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->getJson('/api/billing/markups')->assertNotFound()->assertJsonPath('code', 'tenant_context_required');
    }

    public function test_the_tenant_bills_platform_price_times_markup_and_the_platform_bills_the_tenant_at_platform_price(): void
    {
        $this->share(TenantRegionShares::ACTIVE);
        $this->copyIntoTenant();
        $project = $this->tenantProject('shop');

        // 20 % from Oct 1, 50 % from Oct 10 (a markup change has an effective date)
        $this->tenant->run(function () {
            Markup::query()->create(['region_id' => $this->region->id, 'percent' => 20, 'effective_from' => '2026-10-01']);
            Markup::query()->create(['region_id' => $this->region->id, 'percent' => 50, 'effective_from' => '2026-10-10']);
        });
        $this->tenantUsage($project, '2026-10-05', 10); // 10 × 0.10 × 1.2 = 1.20
        $this->tenantUsage($project, '2026-10-12', 10); // 10 × 0.10 × 1.5 = 1.50

        $tenantSide = $this->tenant->run(function () use ($project) {
            app(PriceBook::class)->flush();

            return app(BillCalculator::class)->forProject((string) $project->id, Period::fromKey('2026-10'));
        });
        $this->assertEqualsWithDelta(2.70, $tenantSide['total'], 0.001);
        $this->assertSame([20.0, 50.0], collect($tenantSide['line_items'])->pluck('markup_percent')->sort()->values()->all());

        // The platform bills the tenant 20 vCPU-hours at 0.10, without the markup
        app(PriceBook::class)->flush();
        $platformSide = app(BillCalculator::class)->forTenant((string) $this->tenant->id, Period::fromKey('2026-10'));
        $this->assertEqualsWithDelta(2.00, $platformSide['total'], 0.001);

        // The tenant sees its bill from the platform; platform administrators see every tenant's
        $this->actingAs($this->tenantOwner)->getJson('/api/billing/platform-bills', $this->headers())->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.kind', 'tenant')
            ->assertJsonPath('data.0.total', 2);

        $admin = User::factory()->create(['username' => 'padmin-'.Str::lower(Str::random(6))]);
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->getJson('/api/billing/tenant-bills?tenant='.$this->tenant->slug)->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.tenant.slug', $this->tenant->slug);
        // A tenant member never reaches the platform's tenant bills
        $this->actingAs($this->tenantOwner)->getJson('/api/billing/tenant-bills', $this->headers())->assertForbidden();
    }

    public function test_a_fixed_allocation_in_a_shared_region_is_copied_centrally_and_billed_at_platform_price(): void
    {
        $this->share(TenantRegionShares::ACTIVE);
        $this->copyIntoTenant();
        RegionSetting::query()->create(['region_id' => $this->region->id, 'billing_type' => 'fixed']); // the platform's type
        $this->tenant->run(fn () => Markup::query()->create(['region_id' => $this->region->id, 'percent' => 25, 'effective_from' => '2026-10-01']));
        $project = $this->tenantProject('api');

        // 2 vCPU × 20 + 4 GB × 2 = 48 at platform price; the tenant asks 48 × 1.25 = 60
        $id = $this->actingAs($this->tenantOwner)->postJson("/api/projects/{$project->id}/billing/fixed-requests", ['cpu_m' => 2000, 'memory_mb' => 4096], $this->headers())
            ->assertCreated()->assertJsonPath('data.monthly_price', 60)->json('data.id');
        $this->actingAs($this->tenantOwner)->postJson("/api/billing/fixed-requests/{$id}/approve", [], $this->headers())->assertOk();

        $mirror = FixedRequest::on('central')->whereKey($id)->first();
        $this->assertNotNull($mirror);
        $this->assertSame((string) $this->tenant->id, $mirror->tenant_id);
        $this->assertSame(FixedRequest::APPROVED, $mirror->status);

        // Approved Oct 20: 12 of 31 days
        app(PriceBook::class)->flush();
        $platformSide = app(BillCalculator::class)->forTenant((string) $this->tenant->id, Period::fromKey('2026-10'));
        $this->assertEqualsWithDelta(48 * 12 / 31, $platformSide['fixed_total'], 0.01);

        $tenantSide = $this->tenant->run(fn () => app(BillCalculator::class)->forProject((string) $project->id, Period::fromKey('2026-10')));
        $this->assertEqualsWithDelta(60 * 12 / 31, $tenantSide['fixed_total'], 0.01);
    }

    public function test_a_platform_region_switched_to_pay_as_you_go_cancels_the_tenants_pending_requests(): void
    {
        $this->share(TenantRegionShares::ACTIVE);
        $this->copyIntoTenant();
        RegionSetting::query()->create(['region_id' => $this->region->id, 'billing_type' => 'fixed']); // the platform's type
        $project = $this->tenantProject('api');
        $id = $this->actingAs($this->tenantOwner)->postJson("/api/projects/{$project->id}/billing/fixed-requests", ['cpu_m' => 2000, 'memory_mb' => 4096], $this->headers())
            ->assertCreated()->json('data.id');

        // The platform (central context) changes the type; every organization context is visited
        BindingTenancy::setRunner(function (callable $callback) {
            $callback();
            $this->tenant->run($callback);
        });
        try {
            app(RegionTypes::class)->set($this->region->id, 'payg', null);
        } finally {
            BindingTenancy::reset();
        }

        $request = $this->tenant->run(fn () => FixedRequest::query()->find($id));
        $this->assertSame(FixedRequest::CANCELLED, $request->status);
        $this->assertSame(__('billing.region_now_payg'), $request->decision_reason);
    }

    public function test_a_tenants_own_region_uses_its_prices_then_the_platform_default(): void
    {
        $own = $this->tenant->run(fn () => TenantLocalRegion::query()->create([
            'id' => (string) Str::ulid(), 'name' => 'own-1', 'project_namespace' => 'own', 'is_active' => true, 'is_demo' => true,
            'is_synced' => false, 'total_cpu_m' => 1000, 'total_memory_mb' => 1024,
        ]));

        $sources = $this->tenant->run(function () use ($own) {
            $book = app(PriceBook::class);
            $book->flush();
            $sources = [$book->resolve($own->id, '2026-10-20')->source];

            $this->priceVersion(null, '2026-01-01', ['cpu_vcpu_month' => 11]);      // tenant default
            $book->flush();
            $sources[] = $book->resolve($own->id, '2026-10-20')->source;

            $this->priceVersion($own->id, '2026-01-01', ['cpu_vcpu_month' => 9]);   // the region's own
            $book->flush();
            $sources[] = $book->resolve($own->id, '2026-10-20')->source;
            $sources[] = $book->resolve($own->id, '2026-10-20')->get('cpu_vcpu_month');
            // No markup on an own region
            $sources[] = $book->resolve($own->id, '2026-10-20')->markupPercent;

            return $sources;
        });

        $this->assertSame(['platform_default', 'tenant_default', 'region', 9.0, 0.0], $sources);
    }

    public function test_a_currency_change_relabels_the_open_tenant_bill_instead_of_adding_one(): void
    {
        $this->share(TenantRegionShares::ACTIVE);
        $this->copyIntoTenant();
        $this->tenantUsage($this->tenantProject('shop'), '2026-10-05', 10);
        $bills = app(TenantBillService::class);

        config(['billing.currency' => 'USD']);
        $bills->ensureOpenBills();
        config(['billing.currency' => 'EUR']);
        $bills->ensureOpenBills();
        Carbon::setTestNow('2026-11-01 03:00:00');
        app(PriceBook::class)->flush();
        $bills->closeEndedPeriods();

        $october = Bill::on('central')->where('kind', Bill::TENANT)->where('period', '2026-10')->get();
        $this->assertCount(1, $october);
        $this->assertSame(['EUR', Bill::CLOSED], [$october[0]->currency, $october[0]->status]);
        $this->assertEqualsWithDelta(1.0, $october[0]->total, 0.001);
    }

    public function test_a_converted_0_5_7_bill_does_not_stand_in_for_the_tenant_bill_of_its_month(): void
    {
        $this->share(TenantRegionShares::ACTIVE);
        $this->copyIntoTenant();
        $this->tenantUsage($this->tenantProject('shop'), '2026-10-05', 10);
        // Imported by an earlier build under the tenant's regular subject; the upgrade moves it to its own
        $legacy = (string) Str::ulid();
        DB::connection('central')->table('billing_bills')->insert([
            'id' => $legacy, 'kind' => 'tenant', 'subject_key' => 'tenant:'.$this->tenant->id, 'tenant_id' => $this->tenant->id,
            'period' => '2026-10', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'currency' => 'USD', 'status' => 'closed',
            'total' => 5, 'source' => 'legacy', 'closed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $harden = require base_path('Modules/Billing/Database/Migrations/2026_10_19_200004_harden_billing_tables.php');
        $harden->up();
        $harden->up();
        $this->assertSame(Bill::legacyTenantKey((string) $this->tenant->id), Bill::on('central')->find($legacy)->subject_key);

        $bills = app(TenantBillService::class);
        $bills->ensureOpenBills();
        Carbon::setTestNow('2026-11-01 03:00:00');
        $bills->closeEndedPeriods();

        $october = Bill::on('central')->where('kind', Bill::TENANT)->where('period', '2026-10')->get()->keyBy('source');
        $this->assertCount(2, $october);
        $this->assertEqualsWithDelta(5.0, $october[Bill::SOURCE_LEGACY]->total, 0.001);
        $this->assertSame(Bill::CLOSED, $october[Bill::SOURCE_RUNR]->status);
        $this->assertEqualsWithDelta(1.0, $october[Bill::SOURCE_RUNR]->total, 0.001);
    }

    public function test_a_tenants_webhook_never_reaches_private_addresses_and_errors_carry_no_internals(): void
    {
        // Platform administrators may allow private alert targets; a tenant's billing webhook stays guarded
        config(['observability.alerts.allow_private_targets' => true]);
        $this->app->instance(DnsResolver::class, new class extends DnsResolver
        {
            public function addresses(string $name): array
            {
                return ['hooks.acme.example' => ['93.184.215.14'], 'scan.acme.example' => ['10.20.0.5']][$name] ?? [];
            }
        });

        foreach (['http://10.0.0.5:5432/', 'https://scan.acme.example/', 'http://localhost/'] as $url) {
            $this->actingAs($this->tenantOwner)->putJson('/api/billing/settings', ['webhook_url' => $url], $this->headers())
                ->assertStatus(422)->assertJsonValidationErrors('webhook_url');
        }
        $this->actingAs($this->tenantOwner)->putJson('/api/billing/settings', ['webhook_url' => 'https://hooks.acme.example/runr'], $this->headers())->assertOk();

        // A refused connection: the tenant sees that it failed, not where it went
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to 10.20.0.9 port 5432: Connection refused'));
        $response = $this->actingAs($this->tenantOwner)->postJson('/api/billing/events/test', [], $this->headers())->assertOk()
            ->assertJsonPath('delivered', false);
        $this->assertSame(__('billing.webhook_unreachable'), $response->json('data.delivery.last_error'));
        $this->assertStringNotContainsString('10.20.0.9', (string) $response->getContent());
    }

    public function test_deleting_a_tenant_ends_its_allocations_in_its_schema_and_centrally(): void
    {
        $this->share(TenantRegionShares::ACTIVE);
        $this->copyIntoTenant();
        RegionSetting::query()->create(['region_id' => $this->region->id, 'billing_type' => 'fixed']);
        $project = $this->tenantProject('api');
        $id = $this->actingAs($this->tenantOwner)->postJson("/api/projects/{$project->id}/billing/fixed-requests", ['cpu_m' => 1000, 'memory_mb' => 1024], $this->headers())
            ->assertCreated()->json('data.id');
        $this->actingAs($this->tenantOwner)->postJson("/api/billing/fixed-requests/{$id}/approve", [], $this->headers())->assertOk();
        // Another tenant's allocation stays
        $other = FixedRequest::on('central')->create([
            'tenant_id' => 'another-tenant', 'source_id' => (string) Str::ulid(), 'project_id' => (string) Str::ulid(), 'region_id' => $this->region->id,
            'cpu_m' => 1000, 'memory_mb' => 1024, 'status' => FixedRequest::APPROVED, 'currency' => 'USD', 'active_from' => '2026-10-01',
        ]);

        // Forced: the suite's tenant schema lacks tables the project cleanup reads (it has no cluster either)
        $this->tenant->run(fn () => (require base_path('Modules/Tenancy/Database/TenantMigrations/0004_create_settings_table.php'))->up());
        Carbon::setTestNow('2026-10-25 12:00:00');
        $result = app(TenantDeletionService::class)->delete($this->tenant->fresh(), true);

        $this->assertTrue($result['deleted']);
        $copy = FixedRequest::on('central')->where('tenant_id', (string) $this->tenant->id)->sole();
        $this->assertSame([FixedRequest::ENDED, '2026-10-25'], [$copy->status, $copy->active_until->toDateString()]);
        $this->assertSame(FixedRequest::APPROVED, $other->fresh()->status);
        // The platform's bill to the deleted tenant: Oct 20 … Oct 25
        app(PriceBook::class)->flush();
        $this->assertEqualsWithDelta(round(22 * 6 / 31, 2), app(BillCalculator::class)->forTenant((string) $this->tenant->id, Period::fromKey('2026-10'))['fixed_total'], 0.001);
    }

    public function test_copies_of_allocations_of_a_tenant_that_is_gone_end_with_the_next_run(): void
    {
        $orphan = FixedRequest::on('central')->create([
            'tenant_id' => 'deleted-long-ago', 'source_id' => (string) Str::ulid(), 'project_id' => (string) Str::ulid(), 'region_id' => $this->region->id,
            'cpu_m' => 1000, 'memory_mb' => 1024, 'status' => FixedRequest::APPROVED, 'currency' => 'USD', 'active_from' => '2026-09-01',
        ]);

        app(TenantBillService::class)->ensureOpenBills();

        $orphan->refresh();
        $this->assertSame([FixedRequest::ENDED, '2026-10-19'], [$orphan->status, $orphan->active_until->toDateString()]);
    }

    public function test_region_offers_are_active_while_billing_is_off_and_stay_active_when_it_returns(): void
    {
        $this->share(TenantRegionShares::PENDING);
        $this->tenant->run(fn () => Markup::query()->create(['region_id' => null, 'percent' => 15, 'effective_from' => '2026-10-01']));

        // Billing on: the offer waits
        $this->assertSame(0, TenantRegionShares::activatePending());
        $this->assertSame(TenantRegionShares::PENDING, TenantRegionShares::status((string) $this->tenant->id, $this->region->id));

        // Billing off: acceptance is not asked for, the offer is active
        TenantRegionShares::requireAcceptanceWhen(null);
        $this->assertSame(1, TenantRegionShares::activatePending());
        $this->assertSame(TenantRegionShares::ACTIVE, TenantRegionShares::status((string) $this->tenant->id, $this->region->id));

        // Billing on again: still active, with the tenant's default markup
        BillingServiceProvider::wire();
        $this->assertSame(0, TenantRegionShares::activatePending());
        $this->assertSame(TenantRegionShares::ACTIVE, TenantRegionShares::status((string) $this->tenant->id, $this->region->id));
        $this->copyIntoTenant();
        $markup = $this->tenant->run(function () {
            app(PriceBook::class)->flush();

            return app(PriceBook::class)->resolve($this->region->id, '2026-10-20')->markupPercent;
        });
        $this->assertSame(15.0, $markup);
    }

    public function test_an_accept_that_fails_leaves_the_offer_pending_and_unannounced(): void
    {
        // A tenant schema without the billing tables (an upgrade that switched billing on before
        // tenancy:migrate ran): writing the markup fails
        $this->tenant->run(fn () => DB::getSchemaBuilder()->drop('billing_markups'));
        $this->share(TenantRegionShares::PENDING);

        $this->actingAs($this->tenantOwner)->postJson("/api/billing/region-offers/{$this->region->id}/accept", ['markup_percent' => 12], $this->headers())
            ->assertStatus(500);

        $this->assertSame(TenantRegionShares::PENDING, TenantRegionShares::status((string) $this->tenant->id, $this->region->id));
        $this->assertNull(DB::connection('central')->table('tenant_regions')->where('tenant_id', $this->tenant->id)->value('accepted_at'));
        $this->assertFalse(BillingEvent::on('central')->where('type', 'region_share.accepted')->exists());

        // Once the tenant migrations ran, the same acceptance goes through: markup, share, event
        $this->tenant->run(fn () => (require base_path('Modules/Billing/Database/TenantMigrations/0060_create_billing_tables.php'))->up());
        $this->actingAs($this->tenantOwner)->postJson("/api/billing/region-offers/{$this->region->id}/accept", ['markup_percent' => 12], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.markup_percent', 12);
        $this->assertTrue(BillingEvent::on('central')->where('type', 'region_share.accepted')->exists());
    }

    public function test_the_platform_bills_a_converted_tenant_from_the_conversion_day(): void
    {
        $this->share(TenantRegionShares::ACTIVE);
        $this->copyIntoTenant();
        $project = $this->tenantProject('shop');
        $this->tenantUsage($project, '2026-10-05', 10);   // before the conversion: the installation's own bill
        $this->tenantUsage($project, '2026-10-15', 10);
        DB::connection('central')->table('settings')->insert([
            'key' => 'tenancy_setup', 'value' => json_encode(['tenant_id' => (string) $this->tenant->id, 'completed_at' => now()->toIso8601String(),
                'converted' => ['on' => '2026-10-12', 'tenant_id' => (string) $this->tenant->id, 'ids' => []]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $bill = app(TenantBillService::class)->tenantBill((string) $this->tenant->id, Period::fromKey('2026-10'));

        $this->assertSame(['2026-10-12', '2026-10-31'], [$bill->period_start->toDateString(), $bill->period_end->toDateString()]);
        $this->assertEqualsWithDelta(1.0, app(TenantBillService::class)->values($bill)['total'], 0.001);
    }
}
