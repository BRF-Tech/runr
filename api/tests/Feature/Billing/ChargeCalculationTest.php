<?php

namespace Tests\Feature\Billing;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\SandboxUsageRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Tenancy\Jobs\ChargeScheduleJob;
use Modules\Tenancy\Models\BillingEvent;
use Modules\Tenancy\Models\CapacityDeal;
use Modules\Tenancy\Models\Charge;
use Modules\Tenancy\Models\Purchase;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\UsageRecord;
use Modules\Tenancy\Services\Billing\BillingEvents;
use Modules\Tenancy\Services\Billing\ChargeService;
use Modules\Tenancy\Services\Billing\CreditLedger;
use Modules\Tenancy\Services\Plans\PlanService;
use Tests\Feature\WhiteLabel\Concerns\WhiteLabelTenancy;
use Tests\TestCase;

/**
 * What each tenant owes per month (docs/billing.md): deal and usage charges are created, months
 * that ended are closed with their final line items, credit drawn from the tenant's balance and one
 * billing.period.closed event per tenant and month.
 */
class ChargeCalculationTest extends TestCase
{
    use RefreshDatabase;
    use WhiteLabelTenancy;

    private Tenant $tenant;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWhiteLabelTenancy(false);
        $this->tenant = $this->createTenantWithSchema('acme'.Str::lower(Str::random(4)), self::WL_TENANT_MIGRATIONS);
        $this->region = $this->platformRegion('eu-1');
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function service(): ChargeService
    {
        return app(ChargeService::class);
    }

    private function lastMonth(): array
    {
        $start = now()->subMonthNoOverflow()->startOfMonth();

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }

    private function deal(Tenant $tenant, float $price = 100.0, string $currency = 'USD'): CapacityDeal
    {
        return CapacityDeal::query()->create([
            'tenant_id' => $tenant->id, 'region_id' => $this->region->id, 'cpu_m' => 2000, 'memory_mb' => 4096,
            'start_date' => now()->subYear()->toDateString(), 'end_date' => now()->addYear()->toDateString(),
            'monthly_price' => $price, 'currency' => $currency, 'status' => 'active',
        ]);
    }

    private function usage(Tenant $tenant, string $project, string $date, array $attrs): void
    {
        $tenant->run(function () use ($project, $date, $attrs) {
            $p = Project::query()->where('name', $project)->first() ?? Project::create(['name' => $project, 'service_type' => 'caas']);
            UsageRecord::create(array_merge([
                'project_id' => $p->id, 'deployment_id' => (string) Str::ulid(), 'region_id' => $this->region->id, 'record_date' => $date,
                'scale_mode' => 'scale_to_zero', 'entry_point' => 'http', 'raw_cost' => 0, 'multiplier' => 5, 'final_cost' => 0, 'currency' => 'USD',
            ], $attrs));
        });
    }

    public function test_deal_and_usage_charges_of_the_month_are_created_once(): void
    {
        $deal = $this->deal($this->tenant);
        $deleting = $this->plainTenant('leaving');
        $deleting->forceFill(['status' => Tenant::STATUS_DELETING])->save();

        $this->assertSame(2, $this->service()->ensureCharges());
        $this->assertSame(0, $this->service()->ensureCharges(), 'idempotent');

        $dealCharge = Charge::query()->where('capacity_deal_id', $deal->id)->sole();
        $this->assertSame(Charge::KIND_CAPACITY_DEAL, $dealCharge->kind);
        $this->assertSame(now()->startOfMonth()->toDateString(), $dealCharge->period_start->toDateString());
        $this->assertSame(now()->endOfMonth()->toDateString(), $dealCharge->period_end->toDateString());
        $this->assertEquals(100, (float) $dealCharge->amount);

        $usage = Charge::query()->where('kind', Charge::KIND_USAGE)->sole();
        $this->assertSame((string) $this->tenant->id, (string) $usage->tenant_id);
        $this->assertSame('USD', $usage->currency);
        $this->assertSame('Usage '.now()->format('Y-m'), $usage->description());
    }

    public function test_with_plans_on_usage_is_charged_only_for_metered_plans_and_tenants_without_a_plan(): void
    {
        $this->enablePlans();
        $flat = $this->createTenantWithSchema('flat'.Str::lower(Str::random(4)), self::WL_TENANT_MIGRATIONS);
        $metered = $this->createTenantWithSchema('meter'.Str::lower(Str::random(4)), self::WL_TENANT_MIGRATIONS);
        app(PlanService::class)->assign($flat, $this->plan(['price_monthly' => 0, 'features' => ['metered_usage' => false]]));
        app(PlanService::class)->assign($metered, $this->plan(['price_monthly' => 0, 'currency' => 'EUR', 'features' => ['metered_usage' => true]]));

        $this->service()->ensureCharges();

        $usage = Charge::query()->where('kind', Charge::KIND_USAGE)->get()->keyBy('tenant_id');
        $this->assertTrue($usage->has((string) $this->tenant->id), 'no plan: pay as you go');
        $this->assertFalse($usage->has((string) $flat->id), 'usage included in the plan');
        $this->assertSame('EUR', $usage[(string) $metered->id]->currency, 'the plan currency');
    }

    public function test_an_ended_month_closes_with_line_items_credit_and_one_event(): void
    {
        [$from, $to] = $this->lastMonth();
        $this->deal($this->tenant);
        $this->assertSame(2, $this->service()->ensureCharges(now()->subMonthNoOverflow()));
        Charge::query()->create(['tenant_id' => $this->tenant->id, 'kind' => 'manual', 'amount' => 20, 'currency' => 'EUR',
            'period_start' => $from, 'period_end' => $from, 'notes' => 'Migration service']);
        $current = Charge::query()->create(['tenant_id' => $this->tenant->id, 'kind' => 'manual', 'amount' => 5, 'currency' => 'USD',
            'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->startOfMonth()->toDateString(), 'notes' => 'This month']);

        $this->usage($this->tenant, 'orders-api', $from, ['session_count' => 10000, 'raw_cost' => 0.5, 'final_cost' => 2.5]);
        $this->usage($this->tenant, 'orders-api', $to, ['session_count' => 5000, 'raw_cost' => 0.202, 'final_cost' => 1.01]);
        $this->usage($this->tenant, 'nightly', $from, ['scale_mode' => 'custom_scale', 'entry_point' => 'schedule', 'multiplier' => 1, 'raw_cost' => 2, 'final_cost' => 2]);
        $this->usage($this->tenant, 'nightly', now()->toDateString(), ['scale_mode' => 'custom_scale', 'entry_point' => 'schedule', 'final_cost' => 99]); // this month
        $this->usage($this->tenant, 'eu-shop', $from, ['session_count' => 100, 'final_cost' => 7, 'currency' => 'EUR']);
        SandboxUsageRecord::create(['tenant_id' => $this->tenant->id, 'knative_region_id' => $this->region->id, 'record_date' => $from,
            'sandbox_count' => 2, 'sandbox_seconds' => 7200, 'final_cost' => 3, 'raw_cost' => 3, 'currency' => 'USD']);
        Purchase::query()->create(['tenant_id' => $this->tenant->id, 'kind' => 'credit', 'external_ref' => 'credit-1', 'amount' => 50, 'currency' => 'USD', 'purchased_at' => now()]);

        dispatch_sync(new ChargeScheduleJob);

        $charges = Charge::query()->where('tenant_id', $this->tenant->id)->whereDate('period_start', '<', now()->startOfMonth()->toDateString())->get();
        $this->assertCount(4, $charges, 'deal, USD usage, EUR usage (added at close), manual');
        $this->assertSame([Charge::STATUS_CLOSED], $charges->pluck('status')->unique()->values()->all());
        $this->assertSame(Charge::STATUS_OPEN, $current->fresh()->status, 'the running month stays open');

        $deal = $charges->firstWhere('kind', Charge::KIND_CAPACITY_DEAL);
        $this->assertSame('Capacity deal: 2000m CPU, 4096 MB memory (eu-1)', $deal->line_items[0]['description']);
        $this->assertEquals(100, (float) $deal->total);
        $this->assertEquals(50, (float) $deal->credit_applied, 'the credit is drawn by the first charge');
        $this->assertEquals(50, (float) $deal->amount_due);

        $usd = $charges->first(fn (Charge $c) => $c->kind === Charge::KIND_USAGE && $c->currency === 'USD');
        $this->assertSame([
            'Project: nightly (Schedule, Custom Scale)',
            'Project: orders-api (HTTP, Scale-to-Zero)',
            'Sandboxes (eu-1)',
        ], array_column($usd->line_items, 'description'));
        $this->assertSame(15000, $usd->line_items[1]['quantity']);
        $this->assertSame('sessions', $usd->line_items[1]['unit']);
        $this->assertEquals(8.51, (float) $usd->total);
        $this->assertEquals(0, (float) $usd->credit_applied);
        $this->assertEquals(8.51, (float) $usd->amount_due);

        $eur = $charges->first(fn (Charge $c) => $c->kind === Charge::KIND_USAGE && $c->currency === 'EUR');
        $this->assertEquals(7, (float) $eur->total);
        $this->assertEquals(0.0, app(CreditLedger::class)->balance((string) $this->tenant->id, 'USD'));

        $event = BillingEvent::query()->where('type', BillingEvents::PERIOD_CLOSED)->sole();
        $data = $event->payload['data'];
        $this->assertSame(now()->subMonthNoOverflow()->format('Y-m'), $data['period']);
        $this->assertCount(4, $data['charges']);
        $this->assertCount(4, $data['closed_charge_ids']);
        $this->assertEquals(['total' => 108.51, 'credit_applied' => 50, 'amount_due' => 58.51], $data['totals']['USD']);
        $this->assertEquals(['total' => 27, 'credit_applied' => 0, 'amount_due' => 27], $data['totals']['EUR']);
        $this->assertEquals(['USD' => 0], $data['credit_balances']);

        // nothing more to close, no second event
        $this->assertSame(0, $this->service()->closeEndedPeriods());
        $this->assertSame(1, BillingEvent::query()->where('type', BillingEvents::PERIOD_CLOSED)->count());

        // a charge added later for the same month closes in a later run, with a new event
        $late = Charge::query()->create(['tenant_id' => $this->tenant->id, 'kind' => 'manual', 'amount' => 3, 'currency' => 'USD',
            'period_start' => $to, 'period_end' => $to, 'notes' => 'Late fee']);
        $this->assertSame(1, $this->service()->closeEndedPeriods());
        $events = BillingEvent::query()->where('type', BillingEvents::PERIOD_CLOSED)->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame([$late->id], $events[1]->payload['data']['closed_charge_ids']);
        $this->assertCount(5, $events[1]->payload['data']['charges']);

        // voiding a closed charge gives its credit back and is announced
        $this->service()->void($deal->fresh(), 'Deal waived');
        $this->assertEquals(50.0, app(CreditLedger::class)->balance((string) $this->tenant->id, 'USD'));
        $this->assertSame(1, BillingEvent::query()->where('type', BillingEvents::CHARGE_VOIDED)->count());
        $this->assertSame('Deal waived', $deal->fresh()->description());
    }

    public function test_a_tenant_whose_usage_cannot_be_read_does_not_stop_the_others(): void
    {
        [$from] = $this->lastMonth();
        $broken = $this->plainTenant('noschema');   // no database: its usage cannot be rated
        foreach ([$this->tenant, $broken] as $tenant) {
            Charge::query()->create(['tenant_id' => $tenant->id, 'kind' => 'usage', 'amount' => 0, 'currency' => 'USD',
                'period_start' => $from, 'period_end' => now()->subMonthNoOverflow()->endOfMonth()->toDateString()]);
        }

        $this->assertSame(1, $this->service()->closeEndedPeriods());

        $this->assertSame(Charge::STATUS_CLOSED, Charge::query()->where('tenant_id', $this->tenant->id)->sole()->status);
        $this->assertSame(Charge::STATUS_OPEN, Charge::query()->where('tenant_id', $broken->id)->sole()->status, 'retried on the next run');
    }

    public function test_an_open_charge_is_estimated_and_a_manual_charge_gets_the_tenant_currency(): void
    {
        $this->enablePlans();
        app(PlanService::class)->assign($this->tenant, $this->plan(['price_monthly' => 0, 'currency' => 'TRY']));

        $charge = $this->service()->createManual($this->tenant, ['amount' => 12.345, 'description' => '  Extra storage  ']);

        $this->assertSame('TRY', $charge->currency);
        $this->assertEquals(12.35, (float) $charge->amount);
        $this->assertSame(now()->toDateString(), $charge->period_end->toDateString());
        $this->assertSame(['line_items' => [[
            'description' => 'Extra storage', 'quantity' => 1, 'unit' => 'item', 'unit_price' => 12.35, 'total' => 12.35, 'metadata' => ['kind' => 'manual'],
        ]], 'total' => 12.35], $this->service()->estimate($charge));
    }
}
