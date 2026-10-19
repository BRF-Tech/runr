<?php

namespace Tests\Feature\Billing;

use App\Models\Project;
use App\Models\User;
use App\Services\Metering\UsageAggregator;
use App\Support\ConvertedOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Billing\Jobs\CloseBillsJob;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\FixedRequest;
use Modules\Billing\Services\BillingEvents;
use Modules\Billing\Services\BillService;
use Modules\Billing\Services\PriceBook;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\SingleTenantConversion;
use Modules\Tenancy\Services\TenantSchema;
use SharedSupport\TenantMigrationRegistry;
use Tests\Concerns\WiresTenancy;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * A single-organization install with billing on becomes its first tenant
 * (docs/tenancy.md#billing-after-the-conversion): from the conversion day every project is metered
 * and billed in the tenant only — the central bills of the conversion month end the day before
 * and close, the allocations move to the tenant (the platform bills the tenant for them from the
 * conversion day), the central metering and bills skip the converted projects, and the platform's
 * webhook settings and event log stay central. Runs on SQLite and on PostgreSQL.
 */
class ConversionBillingTest extends TestCase
{
    use EnablesBilling, RefreshDatabase, WiresTenancy;

    /** @var list<string> */
    private array $registryBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 09:00:00');
        $this->setUpTenancy();
        $this->registryBefore = TenantMigrationRegistry::paths();
        config(['tenancy.enabled' => false]);
        $this->setUpBilling();
    }

    protected function tearDown(): void
    {
        foreach (Tenant::query()->get() as $tenant) {
            $this->wiredTenants[] = $tenant;
        }
        (new \ReflectionProperty(TenantMigrationRegistry::class, 'paths'))->setValue(null, $this->registryBefore);
        $this->tearDownBilling();
        $this->tearDownTenancy();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_after_the_conversion_every_project_is_billed_once_by_the_tenant(): void
    {
        // The single organization: an always-on project in a fixed region, its allocation, usage, an open bill
        $owner = User::factory()->create(['username' => 'olivia']);
        $region = $this->billingRegion('fra', 'fixed');
        $this->priceVersion($region->id, '2026-01-01', ['cpu_vcpu_month' => 31, 'memory_gb_month' => 0, 'cpu_vcpu_hour' => 0.10, 'memory_gb_hour' => 0]);
        $project = $this->billedProject('shop', $region, $owner);
        $version = (string) Str::ulid();
        DB::table('versions')->insert(['id' => $version, 'project_id' => $project->id, 'number' => 1, 'created_by' => 'olivia', 'created_at' => now(), 'updated_at' => now()]);
        // running since the start of the month (metering counts the days a deployment ran)
        DB::table('deployments')->insert(['id' => (string) Str::ulid(), 'project_id' => $project->id, 'version_id' => $version, 'status' => 'active',
            'ready_at' => '2026-10-01 00:00:00', 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
        DB::table('container_configs')->insert(['id' => (string) Str::ulid(), 'project_id' => $project->id, 'image' => 'nginx:1.27',
            'scale_to_zero' => false, 'min_scale' => 1, 'cpu' => '1000m', 'memory_mb' => 128, 'created_at' => now(), 'updated_at' => now()]);
        $allocation = $this->allocation($project, 1000, 0, '2026-10-01');
        $this->usageDay($project, '2026-10-05', 10);
        app(BillService::class)->ensureOpenBills();
        DB::table('billing_settings')->insert(['key' => 'webhook_url', 'value' => 'https://invoicing.example.com/hooks', 'created_at' => now(), 'updated_at' => now()]);
        app(BillingEvents::class)->record(BillingEvents::TEST, ['message' => 'before the conversion']);
        app(UsageAggregator::class)->aggregate('2026-10-09');
        $this->assertSame(1, DB::table('usage_records')->where('project_id', $project->id)->whereDate('record_date', '2026-10-09')->count());

        // Converted on Oct 20
        Carbon::setTestNow('2026-10-20 10:00:00');
        config(['tenancy.enabled' => true]);
        $tenant = (new SingleTenantConversion)->run('Default', 'runr');

        // Recorded: metered and billed in the tenant from today
        $converted = new ConvertedOrganization;
        $this->assertSame('2026-10-20', $converted->since());
        $this->assertTrue($converted->contains((string) $project->id));

        // Central metering skips the project (it runs in the tenant now)
        app(UsageAggregator::class)->aggregate('2026-10-20');
        $this->assertSame(0, DB::table('usage_records')->where('project_id', $project->id)->whereDate('record_date', '2026-10-20')->count());

        // The central allocation ended yesterday; the platform bills the tenant for its copy
        $this->assertSame([FixedRequest::ENDED, '2026-10-19'], [$allocation->fresh()->status, $allocation->fresh()->active_until->toDateString()]);
        $copy = FixedRequest::query()->where('tenant_id', (string) $tenant->id)->sole();
        $this->assertSame([$allocation->id, FixedRequest::APPROVED, null], [$copy->source_id, $copy->status, $copy->active_until]);
        $this->assertNotSame($allocation->id, $copy->id);

        // The tenant: no copied open bill, its billing starts today, without the platform's webhook and event log
        $tenantState = TenantSchema::inTenant($tenant, fn () => [
            Bill::query()->where('status', Bill::OPEN)->count(),
            DB::table('billing_settings')->pluck('value', 'key')->all(),
            DB::table('billing_events')->count(),
        ]);
        $this->assertSame(0, $tenantState[0]);
        $this->assertSame('2026-10-20', $tenantState[1]['bills_from']);
        $this->assertArrayNotHasKey('webhook_url', $tenantState[1]);
        $this->assertSame(0, $tenantState[2]);
        $this->assertSame('https://invoicing.example.com/hooks', DB::table('billing_settings')->where('key', 'webhook_url')->value('value'));

        // The central bill of October ends yesterday and closes with the next run: 19 days (the
        // usage is covered by the allocation)
        $central = Bill::query()->where('subject_key', Bill::projectKey((string) $project->id))->sole();
        $this->assertSame('2026-10-19', $central->period_end->toDateString());
        Carbon::setTestNow('2026-10-21 02:30:00');
        app(PriceBook::class)->flush();
        dispatch_sync(new CloseBillsJob);
        TenantSchema::inTenant($tenant, fn () => app(BillService::class)->ensureOpenBills());   // the job's run in the tenant
        $central->refresh();
        $this->assertSame(Bill::CLOSED, $central->status);
        $this->assertEqualsWithDelta(19.0, $central->total, 0.001);

        // No new central bill for the converted project, also in the next month
        Carbon::setTestNow('2026-11-01 02:30:00');
        app(PriceBook::class)->flush();
        dispatch_sync(new CloseBillsJob);
        TenantSchema::inTenant($tenant, fn () => app(BillService::class)->closeEndedPeriods());
        $this->assertSame(1, Bill::query()->where('subject_key', Bill::projectKey((string) $project->id))->count());

        // The tenant billed the project from the conversion day: 12 days
        $tenantBills = TenantSchema::inTenant($tenant, fn () => Bill::query()->where('period', '2026-10')->get()
            ->map(fn (Bill $b) => [$b->period_start->toDateString(), $b->period_end->toDateString(), $b->status, (float) $b->total])->all());
        $this->assertSame([['2026-10-20', '2026-10-31', Bill::CLOSED, 12.0]], $tenantBills);

        // The platform's bill to the tenant: the allocation from Oct 20 (12 days)
        $platform = Bill::query()->where('kind', Bill::TENANT)->where('tenant_id', (string) $tenant->id)->where('period', '2026-10')->sole();
        $this->assertSame('2026-10-20', $platform->period_start->toDateString());
        $this->assertEqualsWithDelta(12.0, $platform->fixed_total, 0.001);
    }

    public function test_a_conversion_finished_by_an_earlier_release_gets_the_billing_step_once(): void
    {
        $owner = User::factory()->create(['username' => 'olivia']);
        $project = $this->billedProject('shop', $this->billingRegion('fra'), $owner);
        app(BillService::class)->ensureOpenBills();
        config(['tenancy.enabled' => true]);
        $conversion = new SingleTenantConversion;
        $conversion->run('Default', 'runr');
        // As an earlier release left it: finished, without the billing step
        $state = $conversion->state();
        unset($state['steps']['billing'], $state['converted']);
        DB::table('settings')->where('key', SingleTenantConversion::STATE_KEY)->update(['value' => json_encode($state)]);
        Bill::query()->where('project_id', $project->id)->update(['period_end' => '2026-10-31']);

        Carbon::setTestNow('2026-10-25 08:00:00');
        $this->assertSame(['billing'], $conversion->runAddedSteps());
        $this->assertSame([], $conversion->runAddedSteps());

        $this->assertSame('2026-10-25', (new ConvertedOrganization)->since());
        $this->assertSame('2026-10-24', Bill::query()->where('project_id', $project->id)->sole()->period_end->toDateString());
        $this->assertSame(SingleTenantConversion::STATUS_DONE, $conversion->status());
    }
}
