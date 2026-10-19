<?php

namespace Tests\Feature\Billing;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Billing\Database\BillingSchema;
use Modules\Billing\Models\ArchivedBill;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\Markup;
use Modules\Billing\Services\BillArchive;
use Modules\Billing\Services\PriceBook;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantLocalRegion;
use Modules\Tenancy\Services\TenantDeletionService;
use Modules\Tenancy\Support\TenantRegionShares;
use Tests\Concerns\WiresTenancy;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * Bills of deleted tenants (docs/billing.md#bills-of-deleted-tenants): tenant deletion copies the
 * tenant's project and account bills — line items, amounts, payer, paid state, payment link — into
 * the central billing_archived_bills before its schema is dropped, and keeps the tenant when that
 * fails; platform administrators read them with GET /api/billing/archived-bills. Runs on SQLite and
 * on PostgreSQL (DB_CONNECTION=pgsql) like the other tenancy tests.
 */
class TenantBillArchiveTest extends TestCase
{
    use EnablesBilling, RefreshDatabase, WiresTenancy;

    /** What TenantDeletionService and the bill calculation read in the tenant's schema. */
    private const TENANT_MIGRATIONS = [
        'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0002_create_knative_regions_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0004_create_settings_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0005_add_migration_fields_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0006_add_sandbox_and_kata_hypervisor_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0008_add_region_status_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0009_create_usage_records_table.php',
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
    ];

    private Tenant $tenant;

    private KnativeRegion $region;

    private User $owner;

    private string $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        // Nothing of these tenants runs in a cluster; no request may leave the test
        Http::fake(fn () => Http::response(['kind' => 'Status', 'code' => 404], 404));

        $this->setUpTenancy();
        config(['tenancy.enabled' => true, 'app.key' => config('app.key') ?: 'base64:'.base64_encode(random_bytes(32))]);
        $this->setUpBilling();

        $this->tenant = $this->createTenantWithSchema('acme'.Str::lower(Str::random(4)), self::TENANT_MIGRATIONS);
        $this->region = KnativeRegion::query()->create([
            'name' => 'eu-1', 'project_namespace' => 'eu-1', 'is_active' => true, 'is_demo' => true,
            'total_cpu_m' => 64000, 'total_memory_mb' => 262144,
        ]);
        $this->priceVersion($this->region->id, '2026-01-01', ['cpu_vcpu_hour' => 0.10]);
        $this->owner = User::factory()->create(['username' => 'owner-'.Str::lower(Str::random(6)), 'name' => 'Ada Example', 'email' => 'ada@example.com']);
        $this->projectId = (string) Str::ulid();
    }

    protected function tearDown(): void
    {
        $this->tearDownBilling();
        $this->tearDownTenancy();
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * The tenant's bills: September closed and paid, an account bill closed and unpaid, October
     * open with 10 vCPU-hours of usage in the shared region at the tenant's 20 % markup.
     *
     * @return array{closed: string, account: string, open: string} bill ids
     */
    private function tenantBills(): array
    {
        DB::connection('central')->table('tenant_regions')->insert([
            'tenant_id' => $this->tenant->id, 'knative_region_id' => $this->region->id, 'cpu_quota_m' => 8000, 'memory_quota_mb' => 16384,
            'status' => TenantRegionShares::ACTIVE, 'offered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->tenant->run(function () {
            TenantLocalRegion::query()->create([
                'id' => $this->region->id, 'name' => $this->region->name, 'project_namespace' => 'eu-1',
                'is_active' => true, 'is_demo' => true, 'is_synced' => true, 'total_cpu_m' => 64000, 'total_memory_mb' => 262144,
            ]);
            Markup::query()->create(['region_id' => $this->region->id, 'percent' => 20, 'effective_from' => '2026-10-01']);
            DB::table('usage_records')->insert([
                'id' => (string) Str::ulid(), 'project_id' => $this->projectId, 'deployment_id' => (string) Str::ulid(),
                'region_id' => $this->region->id, 'record_date' => '2026-10-05', 'scale_mode' => 'scale_to_zero', 'entry_point' => 'http',
                'session_count' => 1, 'total_cpu_mc_ms' => 10 * 3_600_000_000, 'total_memory_mib_ms' => 0,
                'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
            ]);

            $closed = Bill::query()->create([
                'kind' => Bill::PROJECT, 'subject_key' => Bill::projectKey($this->projectId), 'project_id' => $this->projectId, 'project_name' => 'shop',
                'owner_id' => (string) $this->owner->id, 'owner_name' => 'Ada Example', 'owner_email' => 'ada@example.com',
                'period' => '2026-09', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency' => 'USD',
                'status' => Bill::CLOSED, 'billing_type' => 'payg', 'source' => Bill::SOURCE_RUNR,
                'line_items' => [['type' => 'usage', 'metric' => 'cpu', 'label' => 'vCPU-hours (eu-1)', 'quantity' => 120.0, 'unit_price' => 0.12, 'amount' => 14.4]],
                'metered_lines' => [],
                'fixed_total' => 0, 'usage_total' => 14.4, 'total' => 14.4, 'metered_cost' => 14.4,
                'payment_url' => 'https://pay.example.com/i/2026-09-shop', 'payment_url_set_at' => '2026-10-01 08:00:00',
                'paid' => true, 'paid_at' => '2026-10-03 10:00:00', 'payment_reference' => 'INV-2026-0917',
                'closed_at' => '2026-10-01 02:30:00',
            ]);
            $account = Bill::query()->create([
                'kind' => Bill::ACCOUNT, 'subject_key' => Bill::accountKey((string) $this->owner->id), 'owner_id' => (string) $this->owner->id,
                'owner_name' => 'Ada Example', 'owner_email' => 'ada@example.com',
                'period' => '2026-09', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency' => 'USD',
                'status' => Bill::CLOSED, 'source' => Bill::SOURCE_RUNR,
                'line_items' => [['type' => 'usage', 'metric' => 'kv_gb_hour', 'label' => 'Binding: cache (KV)', 'quantity' => 720.0, 'unit_price' => 0.001, 'amount' => 0.72]],
                'fixed_total' => 0, 'usage_total' => 0.72, 'total' => 0.72, 'metered_cost' => 0.72,
                'closed_at' => '2026-10-01 02:30:00',
            ]);
            $open = Bill::query()->create([
                'kind' => Bill::PROJECT, 'subject_key' => Bill::projectKey($this->projectId), 'project_id' => $this->projectId, 'project_name' => 'shop',
                'owner_id' => (string) $this->owner->id,
                'period' => '2026-10', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'currency' => 'USD',
                'status' => Bill::OPEN, 'source' => Bill::SOURCE_RUNR,
            ]);
            app(PriceBook::class)->flush();

            return ['closed' => $closed->id, 'account' => $account->id, 'open' => $open->id];
        });
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['username' => 'padmin-'.Str::lower(Str::random(6))]);
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function tenantSchemaExists(Tenant $tenant): bool
    {
        return $tenant->database()->manager()->databaseExists($tenant->database()->getName());
    }

    public function test_deleting_a_tenant_archives_its_bills_with_their_line_items(): void
    {
        $ids = $this->tenantBills();
        // The platform's bill to the tenant is central and stays where it is
        $platformBill = Bill::on('central')->create([
            'kind' => Bill::TENANT, 'subject_key' => Bill::tenantKey((string) $this->tenant->id), 'tenant_id' => (string) $this->tenant->id,
            'period' => '2026-09', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency' => 'USD',
            'status' => Bill::CLOSED, 'source' => Bill::SOURCE_RUNR, 'total' => 12, 'closed_at' => '2026-10-01 02:30:00',
        ]);

        $result = app(TenantDeletionService::class)->delete($this->tenant);

        $this->assertTrue($result['deleted'], implode('; ', $result['failures']));
        $this->assertNull(Tenant::query()->find($this->tenant->id));
        $this->assertFalse($this->tenantSchemaExists($this->tenant), 'the schema went with the tenant');

        $archived = ArchivedBill::on('central')->get()->keyBy('bill_id');
        $this->assertCount(3, $archived);
        $this->assertTrue(Bill::on('central')->whereKey($platformBill->id)->exists());
        $this->assertFalse($archived->has($platformBill->id));

        $closed = $archived[$ids['closed']];
        $this->assertSame((string) $this->tenant->id, $closed->tenant_id);
        $this->assertSame($this->tenant->slug, $closed->tenant_slug);
        $this->assertSame($this->tenant->name, $closed->tenant_name);
        $this->assertSame(['2026-09', Bill::PROJECT, Bill::CLOSED, 'payg'], [$closed->period, $closed->kind, $closed->status, $closed->billing_type]);
        $this->assertSame([$this->projectId, 'shop'], [$closed->project_id, $closed->project_name]);
        $this->assertSame([(string) $this->owner->id, 'Ada Example', 'ada@example.com'], [$closed->owner_id, $closed->owner_name, $closed->owner_email]);
        $this->assertSame('vCPU-hours (eu-1)', $closed->line_items[0]['label']);
        $this->assertEqualsWithDelta(14.4, $closed->line_items[0]['amount'], 0.0001);
        $this->assertEqualsWithDelta(14.4, $closed->total, 0.001);
        $this->assertEqualsWithDelta(14.4, $closed->metered_cost, 0.001);
        $this->assertTrue($closed->paid);
        $this->assertSame('2026-10-03 10:00:00', $closed->paid_at?->format('Y-m-d H:i:s'));
        $this->assertSame('INV-2026-0917', $closed->payment_reference);
        $this->assertSame('https://pay.example.com/i/2026-09-shop', $closed->payment_url);
        $this->assertSame('2026-10-01 02:30:00', $closed->closed_at?->format('Y-m-d H:i:s'));

        $account = $archived[$ids['account']];
        $this->assertSame([Bill::ACCOUNT, false, null], [$account->kind, $account->paid, $account->project_id]);
        $this->assertEqualsWithDelta(0.72, $account->total, 0.001);

        // The open month: the estimate at the deletion, at the tenant's price (0.10 × 1.2 × 10 vCPU-hours)
        $open = $archived[$ids['open']];
        $this->assertSame(Bill::OPEN, $open->status);
        $this->assertNull($open->closed_at);
        $this->assertEqualsWithDelta(1.20, $open->total, 0.001);
        $this->assertNotEmpty($open->line_items);
        $this->assertSame((string) $this->owner->id, $open->owner_id);
    }

    public function test_platform_administrators_read_the_bills_of_deleted_tenants(): void
    {
        $ids = $this->tenantBills();
        $this->assertTrue(app(TenantDeletionService::class)->delete($this->tenant)['deleted']);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/billing/archived-bills?tenant='.$this->tenant->slug)->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.period', '2026-10')
            ->assertJsonPath('data.0.estimate', true)
            ->assertJsonPath('data.0.tenant.slug', $this->tenant->slug)
            ->assertJsonPath('data.0.line_items', null);
        $this->actingAs($admin)->getJson('/api/billing/archived-bills?tenant='.$this->tenant->id.'&paid=true')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.bill_id', $ids['closed'])
            ->assertJsonPath('data.0.payment_reference', 'INV-2026-0917');
        $this->actingAs($admin)->getJson('/api/billing/archived-bills?period=2026-09&kind=account')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.owner.email', 'ada@example.com');
        $this->actingAs($admin)->getJson('/api/billing/archived-bills?tenant=someone-else')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($admin)->getJson('/api/billing/archived-bills?status=paid')->assertUnprocessable();

        $id = ArchivedBill::on('central')->where('bill_id', $ids['closed'])->value('id');
        $this->actingAs($admin)->getJson("/api/billing/archived-bills/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.project.name', 'shop')
            ->assertJsonPath('data.line_items.0.label', 'vCPU-hours (eu-1)')
            ->assertJsonPath('data.total', 14.4)
            ->assertJsonPath('data.difference', 0)
            ->assertJsonPath('data.paid', true)
            ->assertJsonPath('data.payment_url', 'https://pay.example.com/i/2026-09-shop');
        $this->actingAs($admin)->getJson('/api/billing/archived-bills/'.Str::ulid())->assertNotFound();

        // Platform administrators only
        $this->actingAs($this->owner)->getJson('/api/billing/archived-bills')->assertForbidden();
    }

    public function test_the_tenant_is_kept_when_its_bills_cannot_be_archived_even_when_forced(): void
    {
        $ids = $this->tenantBills();
        Schema::connection('central')->drop(BillArchive::TABLE);
        $admin = $this->admin();
        // Without the archive table the API answers an empty archive
        $this->actingAs($admin)->getJson('/api/billing/archived-bills')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($admin)->getJson('/api/billing/archived-bills/'.Str::ulid())->assertNotFound();

        $result = app(TenantDeletionService::class)->delete($this->tenant, force: true);

        $this->assertFalse($result['deleted']);
        $kept = Tenant::query()->find($this->tenant->id);
        $this->assertNotNull($kept);
        $this->assertSame(Tenant::STATUS_DELETE_FAILED, $kept->status);
        $this->assertStringContainsString('bills not archived', (string) $kept->status_message);
        $this->assertTrue($this->tenantSchemaExists($this->tenant), 'the schema with the bills is kept');
        $this->assertSame(3, $this->tenant->run(fn () => Bill::query()->count()));

        // A retry once the table is back completes the deletion with the bills archived
        BillingSchema::createArchivedBills(Schema::connection('central'));
        $this->assertTrue(app(TenantDeletionService::class)->delete($kept)['deleted']);
        $this->assertSame(3, ArchivedBill::on('central')->where('tenant_id', $this->tenant->id)->count());
        $this->assertTrue(ArchivedBill::on('central')->where('bill_id', $ids['closed'])->value('paid'));
    }

    public function test_the_months_missing_bills_are_made_and_archiving_again_replaces_the_rows(): void
    {
        $ids = $this->tenantBills();
        // A project without a bill of the month yet (the daily job would make it)
        $api = $this->tenant->run(fn () => Project::withoutEvents(function () {
            $project = new Project;
            $project->forceFill([
                'name' => 'api', 'slug' => 'api', 'service_type' => 'caas', 'knative_region_id' => $this->region->id,
                'created_by' => $this->owner->id, 'owner_id' => $this->owner->id,
            ])->save();

            return $project;
        }));
        $archive = app(BillArchive::class);

        $this->assertSame(4, $archive->archiveTenant($this->tenant));
        $made = ArchivedBill::on('central')->where('project_id', $api->id)->sole();
        $this->assertSame(['2026-10', Bill::OPEN, 'api'], [$made->period, $made->status, $made->project_name]);
        $this->assertEqualsWithDelta(0.0, $made->total, 0.001);

        $this->tenant->run(fn () => Bill::query()->whereKey($ids['account'])->update(['paid' => true, 'payment_reference' => 'INV-ACC-1']));
        $this->assertSame(4, $archive->archiveTenant($this->tenant));

        $this->assertSame(4, ArchivedBill::on('central')->count());
        $account = ArchivedBill::on('central')->where('bill_id', $ids['account'])->sole();
        $this->assertTrue($account->paid);
        $this->assertSame('INV-ACC-1', $account->payment_reference);
    }

    public function test_a_tenant_without_billing_tables_archives_nothing_and_the_platforms_bills_stay(): void
    {
        // No Billing migration in this tenant's schema. On PostgreSQL a tenant's search path ends
        // in the central schema: the platform's own bills there are not the tenant's
        $plain = $this->createTenantWithSchema('plain'.Str::lower(Str::random(4)), array_values(array_filter(
            self::TENANT_MIGRATIONS,
            fn (string $m) => ! str_contains($m, 'Modules/Billing/'),
        )));
        $platformOwn = Bill::on('central')->create([
            'kind' => Bill::PROJECT, 'subject_key' => Bill::projectKey((string) Str::ulid()), 'project_name' => 'platform-site',
            'period' => '2026-09', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency' => 'USD',
            'status' => Bill::CLOSED, 'source' => Bill::SOURCE_RUNR, 'total' => 3, 'closed_at' => '2026-10-01 02:30:00',
        ]);

        $this->assertSame(0, app(BillArchive::class)->archiveTenant($plain));
        $this->assertTrue(app(TenantDeletionService::class)->delete($plain)['deleted']);

        $this->assertSame(0, ArchivedBill::on('central')->count());
        $this->assertTrue(Bill::on('central')->whereKey($platformOwn->id)->exists());
    }
}
