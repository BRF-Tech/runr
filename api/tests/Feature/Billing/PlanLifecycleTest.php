<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tenancy\Jobs\PlanRenewalJob;
use Modules\Tenancy\Models\BillingEvent;
use Modules\Tenancy\Models\Charge;
use Modules\Tenancy\Models\Purchase;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantSubscription;
use Modules\Tenancy\Services\Billing\BillingEvents;
use Modules\Tenancy\Services\Plans\PlanLimitService;
use Modules\Tenancy\Services\Plans\PlanService;
use Tests\Feature\WhiteLabel\Concerns\WhiteLabelTenancy;
use Tests\TestCase;

/**
 * Plan periods over time (PlanRenewalJob, docs/billing.md): trials convert and start the plan fee,
 * prepaid terms and plans cancelled at period end end — on the free default plan when there is
 * one —, and quotas follow add-ons that ended.
 */
class PlanLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use WhiteLabelTenancy;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWhiteLabelTenancy(false);
        $this->enablePlans();
        $this->tenant = $this->plainTenant('acme');
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function plans(): PlanService
    {
        return app(PlanService::class);
    }

    private function changes(): array
    {
        return BillingEvent::query()->where('type', BillingEvents::PLAN_CHANGED)->orderBy('id')->get()
            ->map(fn (BillingEvent $e) => [$e->payload['data']['source'], $e->payload['data']['subscription']['status'], $e->payload['data']['subscription']['plan']['slug'] ?? null])
            ->all();
    }

    public function test_a_trial_converts_and_starts_the_plan_fee(): void
    {
        $this->plans()->assign($this->tenant, $this->plan(['slug' => 'pro', 'price_monthly' => 25, 'trial_days' => 7]));
        $this->assertSame(0, Charge::query()->count());

        $this->travel(8)->days();
        dispatch_sync(new PlanRenewalJob);

        $subscription = TenantSubscription::findForTenant($this->tenant->id);
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->status);
        $this->assertTrue($subscription->current_period_end->isFuture());
        $this->assertEquals(25, (float) Charge::query()->where('kind', Charge::KIND_PLAN)->sole()->amount);
        $this->assertSame([['api', 'trialing', 'pro'], ['renewal', 'active', 'pro']], $this->changes());
    }

    public function test_a_prepaid_term_ends_on_the_free_default_plan_or_cancels(): void
    {
        $free = $this->plan(['slug' => 'free', 'price_monthly' => 0, 'is_default' => true]);
        $pro = $this->plan(['slug' => 'pro', 'price_monthly' => 25]);
        $purchase = Purchase::query()->create(['tenant_id' => $this->tenant->id, 'kind' => 'plan', 'external_ref' => 'o1', 'plan_id' => $pro->id, 'interval' => 'month', 'purchased_at' => now()]);
        $this->plans()->applyPurchase($this->tenant, $pro, 'month', $purchase);

        $this->travel(32)->days();
        $this->assertSame(['renewed' => 0, 'ended' => 1], $this->plans()->renewPeriods());

        $subscription = TenantSubscription::findForTenant($this->tenant->id);
        $this->assertSame($free->id, $subscription->plan_id);
        $this->assertFalse($subscription->prepaid);
        $this->assertNull($subscription->external_ref);
        $this->assertSame(0, Charge::query()->count(), 'neither the prepaid term nor the free plan has a fee');

        // without a default plan the plan is canceled: no plan limits any more
        $free->forceFill(['is_default' => false])->save();
        $this->plans()->assign($this->tenant, $pro, 'month', ['prepaid' => true, 'source' => 'purchase']);
        $this->travel(32)->days();
        $this->plans()->renewPeriods();

        $this->assertSame(TenantSubscription::STATUS_CANCELED, TenantSubscription::findForTenant($this->tenant->id)->status);
        $this->assertNull(app(PlanLimitService::class)->planFor($this->tenant));
        $this->assertSame(['purchase', 'active', 'pro'], $this->changes()[0]);
        $this->assertSame(['renewal', 'canceled', 'pro'], last($this->changes()));
    }

    public function test_a_plan_cancelled_at_period_end_ends_then(): void
    {
        $this->plans()->assign($this->tenant, $this->plan(['slug' => 'pro', 'price_monthly' => 25]));
        $this->plans()->cancel($this->tenant);
        $this->assertTrue(TenantSubscription::findForTenant($this->tenant->id)->cancel_at_period_end);

        $this->travel(15)->days();
        $this->assertSame(['renewed' => 0, 'ended' => 0], $this->plans()->renewPeriods());

        $this->travel(20)->days();
        $this->assertSame(['renewed' => 0, 'ended' => 1], $this->plans()->renewPeriods());
        $this->assertSame(TenantSubscription::STATUS_CANCELED, TenantSubscription::findForTenant($this->tenant->id)->status);
        $this->assertSame(1, Charge::query()->count(), 'only the first period was charged');

        // cancelling without a running period ends at once
        $this->plans()->assign($this->tenant, $this->plan(['slug' => 'team', 'price_monthly' => 0]));
        $this->assertSame(TenantSubscription::STATUS_CANCELED, $this->plans()->cancel($this->tenant, false)->status);
    }

    public function test_quotas_follow_add_ons_that_ended(): void
    {
        $eu = $this->platformRegion('eu-1');
        $this->attachRegion($this->tenant, $eu);
        $plan = $this->plan(['slug' => 'pro', 'price_monthly' => 0, 'cpu_quota_m' => 2000, 'memory_quota_mb' => 4096]);
        Purchase::query()->create(['tenant_id' => $this->tenant->id, 'kind' => 'addon', 'external_ref' => 'a1', 'limits' => ['cpu_quota_m' => 1000, 'memory_quota_mb' => 1024],
            'starts_at' => now(), 'ends_at' => now()->addDays(3), 'purchased_at' => now()]);
        $this->plans()->assign($this->tenant, $plan);
        $this->assertSame([3000, 5120], $this->quota($eu->id));

        $this->travel(4)->days();
        dispatch_sync(new PlanRenewalJob);

        $this->assertSame([2000, 4096], $this->quota($eu->id));
    }

    public function test_the_job_does_nothing_while_plans_are_off(): void
    {
        $this->plans()->assign($this->tenant, $this->plan(['slug' => 'pro', 'price_monthly' => 25]));
        $this->enablePlans(false);

        $this->travel(40)->days();
        dispatch_sync(new PlanRenewalJob);

        $this->assertSame(1, Charge::query()->count());
    }

    /** @return array{0: int, 1: int} */
    private function quota(string $regionId): array
    {
        $pivot = $this->tenant->regions()->where('knative_region_id', $regionId)->first()->pivot;

        return [(int) $pivot->cpu_quota_m, (int) $pivot->memory_quota_mb];
    }
}
