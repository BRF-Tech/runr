<?php

namespace Tests\Feature\WhiteLabel;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Tenancy\Models\Charge;
use Modules\Tenancy\Models\Plan;
use Modules\Tenancy\Models\TenantSubscription;
use Modules\Tenancy\Services\Plans\PlanQuotaService;
use Modules\Tenancy\Services\Plans\PlanService;
use Tests\Feature\WhiteLabel\Concerns\WhiteLabelTenancy;
use Tests\TestCase;

/**
 * Plans (optional, off by default): admin CRUD, one default plan, archive instead of delete when
 * in use, public listing only while plans are on, plan → tenant region quota mapping (no
 * overcommit), plan fees as plan charges, period renewal.
 */
class PlansTest extends TestCase
{
    use RefreshDatabase;
    use WhiteLabelTenancy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWhiteLabelTenancy();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function quotaOf($tenant, $region): array
    {
        $row = DB::connection('central')->table('tenant_regions')
            ->where('tenant_id', $tenant->id)->where('knative_region_id', $region->id)->first();

        return [(int) $row->cpu_quota_m, (int) $row->memory_quota_mb];
    }

    public function test_plans_are_off_by_default_and_nothing_about_them_is_public(): void
    {
        $this->plan(['is_public' => true]);

        $this->getJson('/api/plans/public')->assertOk()->assertExactJson([]);
        $this->getJson('/api/auth/registration-status')->assertOk()->assertJsonMissingPath('plans');
        $this->getJson('/api/branding')->assertJsonPath('features.plans', false);
    }

    public function test_public_plans_are_listed_when_plans_are_on(): void
    {
        $this->enablePlans();
        $this->plan(['slug' => 'starter', 'is_public' => true, 'sort_order' => 1]);
        $this->plan(['slug' => 'enterprise', 'is_public' => false]);
        $this->plan(['slug' => 'legacy', 'is_archived' => true]);

        $this->getJson('/api/plans/public')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.slug', 'starter')
            ->assertJsonStructure([['id', 'name', 'currency', 'price_monthly', 'price_yearly', 'trial_days', 'limits' => ['cpu_quota_m', 'max_projects', 'invocations_per_month'], 'features']]);
        $this->getJson('/api/branding')->assertJsonPath('features.plans', true);
    }

    public function test_admins_create_update_and_archive_plans(): void
    {
        $admin = $this->admin();

        $created = $this->actingAs($admin)->postJson('/api/admin/plans', [
            'slug' => 'pro',
            'name' => 'Pro',
            'currency' => 'try',
            'price_monthly' => 499,
            'price_yearly' => 4990,
            'limits' => ['cpu_quota_m' => 8000, 'memory_quota_mb' => 16384, 'max_projects' => 20, 'invocations_per_month' => null],
            'features' => ['custom_domains' => true, 'gpu' => false],
            'trial_days' => 14,
            'is_default' => true,
        ])->assertCreated()->json();

        $this->assertSame('TRY', $created['currency']);
        $this->assertSame(20, $created['limits']['max_projects']);
        $this->assertNull($created['limits']['invocations_per_month']);
        $this->assertFalse($created['features']['gpu']);
        $this->assertTrue($created['is_default']);

        // a second default replaces the first
        $this->actingAs($admin)->postJson('/api/admin/plans', ['slug' => 'free', 'name' => 'Free', 'currency' => 'TRY', 'price_monthly' => 0, 'is_default' => true])->assertCreated();
        $this->assertFalse(Plan::query()->where('slug', 'pro')->value('is_default'));

        $this->actingAs($admin)->patchJson("/api/admin/plans/{$created['id']}", ['name' => 'Pro+', 'limits' => ['max_projects' => 25]])
            ->assertOk()->assertJsonPath('name', 'Pro+')->assertJsonPath('limits.max_projects', 25)->assertJsonPath('limits.cpu_quota_m', 8000);

        // unused → deleted, used → archived
        $tenant = $this->plainTenant('acme');
        TenantSubscription::forTenant($tenant->id)->forceFill(['plan_id' => $created['id']])->save();
        $this->actingAs($admin)->deleteJson("/api/admin/plans/{$created['id']}")->assertOk()->assertJson(['archived' => true, 'deleted' => false]);
        $this->assertTrue(Plan::query()->find($created['id'])->is_archived);

        $free = Plan::query()->where('slug', 'free')->first();
        $this->actingAs($admin)->deleteJson("/api/admin/plans/{$free->id}")->assertOk()->assertJson(['deleted' => true]);
    }

    public function test_plan_validation(): void
    {
        $admin = $this->admin();
        $this->plan(['slug' => 'taken']);

        $this->actingAs($admin)->postJson('/api/admin/plans', ['slug' => 'taken', 'name' => 'X', 'currency' => 'USD', 'price_monthly' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->actingAs($admin)->postJson('/api/admin/plans', ['slug' => 'x', 'name' => 'X', 'currency' => 'USD', 'price_monthly' => 1, 'limits' => ['bananas' => 3]])
            ->assertStatus(422)->assertJsonValidationErrors('limits');
        $this->actingAs($admin)->postJson('/api/admin/plans', ['slug' => 'x', 'name' => 'X', 'currency' => 'USD', 'price_monthly' => 1, 'features' => ['teleport' => true]])
            ->assertStatus(422)->assertJsonValidationErrors('features');
        $this->actingAs($admin)->postJson('/api/admin/plans', ['slug' => 'x', 'name' => 'X', 'currency' => 'US', 'price_monthly' => -1])
            ->assertStatus(422)->assertJsonValidationErrors(['currency', 'price_monthly']);
    }

    public function test_only_platform_admins_manage_plans(): void
    {
        $tenant = $this->plainTenant('acme');
        $owner = $this->member($tenant);

        $this->actingAs($owner)->getJson('/api/admin/plans')->assertForbidden();
        $this->actingAs($owner)->postJson('/api/admin/plans', ['slug' => 'x'])->assertForbidden();
    }

    public function test_a_plan_writes_its_quota_onto_every_region_of_the_tenant(): void
    {
        $tenant = $this->plainTenant('acme');
        $eu = $this->platformRegion('eu-1');
        $us = $this->platformRegion('us-1', ['region_mode' => 'infinite']);
        $this->attachRegion($tenant, $eu);
        $this->attachRegion($tenant, $us);

        $plan = $this->plan(['cpu_quota_m' => 4000, 'memory_quota_mb' => 8192]);
        app(PlanService::class)->assign($tenant, $plan);

        $this->assertSame([4000, 8192], $this->quotaOf($tenant, $eu));
        $this->assertSame([4000, 8192], $this->quotaOf($tenant, $us));

        // unlimited plan limits → 0 = unlimited quota
        $big = $this->plan(['cpu_quota_m' => null, 'memory_quota_mb' => null]);
        app(PlanService::class)->assign($tenant, $big);
        $this->assertSame([0, 0], $this->quotaOf($tenant, $eu));
    }

    public function test_a_plan_quota_is_lowered_to_the_free_capacity_of_a_managed_region(): void
    {
        $eu = $this->platformRegion('eu-1', ['total_cpu_m' => 6000, 'total_memory_mb' => 12288]);
        $other = $this->plainTenant('other');
        $this->attachRegion($other, $eu, 4000, 8192);

        $tenant = $this->plainTenant('acme');
        $this->attachRegion($tenant, $eu);

        $clamped = app(PlanQuotaService::class)->apply($tenant, $this->plan(['cpu_quota_m' => 4000, 'memory_quota_mb' => 8192]));

        $this->assertSame([(string) $eu->id], $clamped);
        $this->assertSame([2000, 4096], $this->quotaOf($tenant, $eu));
    }

    public function test_changing_a_plans_limits_updates_its_tenants(): void
    {
        $admin = $this->admin();
        $tenant = $this->plainTenant('acme');
        $eu = $this->platformRegion('eu-1');
        $this->attachRegion($tenant, $eu);
        $plan = $this->plan(['cpu_quota_m' => 2000, 'memory_quota_mb' => 4096]);
        app(PlanService::class)->assign($tenant, $plan);

        $this->actingAs($admin)->patchJson("/api/admin/plans/{$plan->id}", ['limits' => ['cpu_quota_m' => 3000]])->assertOk();

        $this->assertSame([3000, 4096], $this->quotaOf($tenant, $eu));
    }

    public function test_the_plan_fee_is_a_plan_charge_per_period_and_renews(): void
    {
        $tenant = $this->plainTenant('acme');
        $plan = $this->plan(['price_monthly' => 25, 'currency' => 'EUR', 'trial_days' => 0]);

        $subscription = app(PlanService::class)->assign($tenant, $plan);

        $this->assertSame(TenantSubscription::STATUS_ACTIVE, $subscription->status);
        $this->assertFalse($subscription->prepaid);
        $charge = Charge::query()->where('tenant_id', $tenant->id)->sole();
        $this->assertSame(Charge::KIND_PLAN, $charge->kind);
        $this->assertSame(Charge::STATUS_OPEN, $charge->status);
        $this->assertEquals(25, (float) $charge->amount);
        $this->assertSame('EUR', $charge->currency);
        $this->assertStringStartsWith('Plan ', $charge->notes);

        // assigning the same plan again records nothing new
        app(PlanService::class)->assign($tenant, $plan);
        $this->assertSame(1, Charge::query()->where('tenant_id', $tenant->id)->count());

        // period over → next period + fee
        $subscription->fresh()->forceFill(['current_period_end' => now()->subDay()])->save();
        $this->assertSame(['renewed' => 1, 'ended' => 0], app(PlanService::class)->renewPeriods());
        $this->assertSame(2, Charge::query()->where('tenant_id', $tenant->id)->where('kind', Charge::KIND_PLAN)->count());
        $this->assertTrue(TenantSubscription::findForTenant($tenant->id)->current_period_end->isFuture());
    }

    public function test_a_trial_starts_trialing_and_records_no_fee_until_it_ends(): void
    {
        $tenant = $this->plainTenant('acme');
        $plan = $this->plan(['price_monthly' => 25, 'trial_days' => 14]);

        $subscription = app(PlanService::class)->assign($tenant, $plan);

        $this->assertSame(TenantSubscription::STATUS_TRIALING, $subscription->status);
        $this->assertTrue($subscription->trial_used);
        $this->assertSame(14, (int) round(now()->diffInDays($subscription->trial_ends_at, true)));
        $this->assertSame(0, Charge::query()->count());

        // a second trial is not granted
        $other = $this->plan(['price_monthly' => 30, 'trial_days' => 14]);
        $this->assertSame(TenantSubscription::STATUS_ACTIVE, app(PlanService::class)->assign($tenant, $other)->status);
    }
}
