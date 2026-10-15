<?php

namespace Tests\Feature\WhiteLabel;

use App\Models\SecureSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Charge;
use Modules\Tenancy\Models\Purchase;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\Billing\BillingSettings;
use Modules\Tenancy\Services\Plans\PlanService;
use Tests\Feature\WhiteLabel\Concerns\WhiteLabelTenancy;
use Tests\TestCase;

/**
 * The tenant's side of plans and billing — Settings → Subscription (read-only plan page) and
 * Settings → Billing (charges, credit, purchases) — and the admin's billing settings with the
 * write-only webhook secret (docs/billing.md).
 */
class TenantSubscriptionApiTest extends TestCase
{
    use RefreshDatabase;
    use WhiteLabelTenancy;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWhiteLabelTenancy();
        $this->enablePlans();

        $this->tenant = $this->createTenantWithSchema('sub'.Str::lower(Str::random(5)), self::WL_TENANT_MIGRATIONS);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    public function test_members_see_their_plan_limits_and_usage(): void
    {
        $this->plan(['slug' => 'starter', 'price_monthly' => 0]);
        $pro = $this->plan(['slug' => 'pro', 'price_monthly' => 20, 'max_projects' => 5, 'invocations_per_month' => 1000]);
        app(PlanService::class)->assign($this->tenant, $pro);
        $member = $this->member($this->tenant, 'member');

        $this->actingAs($member)->getJson('/api/tenant/subscription', $this->tenantHeaders($this->tenant))
            ->assertOk()
            ->assertJsonPath('plans_enabled', true)
            ->assertJsonPath('subscription.plan.slug', 'pro')
            ->assertJsonPath('subscription.prepaid', false)
            ->assertJsonPath('limits.max_projects', 5)
            ->assertJsonPath('limits.invocations_per_month', 1000)
            ->assertJsonPath('plans.0.slug', 'starter')
            ->assertJsonPath('usage.projects', 0)
            ->assertJsonPath('store_url', null);
    }

    public function test_running_add_ons_raise_the_limits_shown(): void
    {
        $pro = $this->plan(['slug' => 'pro', 'max_projects' => 5, 'gpu_quota' => null]);
        app(PlanService::class)->assign($this->tenant, $pro);
        Purchase::query()->create([
            'tenant_id' => $this->tenant->id, 'kind' => 'addon', 'external_ref' => 'ord-1', 'limits' => ['max_projects' => 3, 'gpu_quota' => 2],
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'purchased_at' => now(),
        ]);
        Purchase::query()->create([
            'tenant_id' => $this->tenant->id, 'kind' => 'addon', 'external_ref' => 'ord-old', 'limits' => ['max_projects' => 50],
            'starts_at' => now()->subMonths(2), 'ends_at' => now()->subMonth(), 'purchased_at' => now()->subMonths(2),
        ]);
        $owner = $this->member($this->tenant, 'owner');

        $this->actingAs($owner)->getJson('/api/tenant/subscription', $this->tenantHeaders($this->tenant))
            ->assertOk()
            ->assertJsonPath('limits.max_projects', 8)
            ->assertJsonPath('limits.gpu_quota', null)   // an unlimited plan limit stays unlimited
            ->assertJsonPath('addon_limits.max_projects', 3);
    }

    public function test_the_store_link_is_shown_to_billing_managers_only(): void
    {
        config(['tenancy.billing.store_url' => 'https://shop.example.com/runr']);
        $owner = $this->member($this->tenant, 'owner');
        $developer = $this->member($this->tenant, 'developer');

        $this->actingAs($owner)->getJson('/api/tenant/subscription', $this->tenantHeaders($this->tenant))
            ->assertOk()->assertJsonPath('store_url', 'https://shop.example.com/runr');
        $this->actingAs($developer)->getJson('/api/tenant/subscription', $this->tenantHeaders($this->tenant))
            ->assertOk()->assertJsonPath('store_url', null);
    }

    public function test_plans_off_lists_no_plans(): void
    {
        $this->enablePlans(false);
        $this->plan();
        $owner = $this->member($this->tenant, 'owner');

        $this->actingAs($owner)->getJson('/api/tenant/subscription', $this->tenantHeaders($this->tenant))
            ->assertOk()->assertJsonPath('plans_enabled', false)->assertJsonPath('plans', [])->assertJsonPath('limits', null);
    }

    public function test_tenant_endpoints_need_a_tenant_context(): void
    {
        $this->actingAs($this->admin())->getJson('/api/tenant/subscription')->assertStatus(422);
        $this->actingAs($this->admin())->getJson('/api/tenant/billing')->assertStatus(422);
    }

    // ── Settings → Billing of the tenant ────────────────────────────────────

    public function test_billing_viewers_see_charges_credit_and_purchases_of_their_tenant_only(): void
    {
        $other = $this->plainTenant('other');
        Charge::query()->create([
            'tenant_id' => $this->tenant->id, 'kind' => 'manual', 'amount' => 40, 'currency' => 'USD',
            'period_start' => now()->subMonthNoOverflow()->startOfMonth(), 'period_end' => now()->subMonthNoOverflow()->endOfMonth(),
            'status' => 'closed', 'notes' => 'Setup fee', 'total' => 40, 'credit_applied' => 10, 'amount_due' => 30, 'closed_at' => now(),
            'line_items' => [['description' => 'Setup fee', 'quantity' => 1, 'unit' => 'item', 'unit_price' => 40, 'total' => 40, 'metadata' => []]],
        ]);
        Charge::query()->create([
            'tenant_id' => $this->tenant->id, 'kind' => 'manual', 'amount' => 15, 'currency' => 'USD',
            'period_start' => now()->startOfMonth(), 'period_end' => now()->startOfMonth(), 'status' => 'open', 'notes' => 'Support hours',
        ]);
        Charge::query()->create([
            'tenant_id' => $this->tenant->id, 'kind' => 'manual', 'amount' => 99, 'currency' => 'USD',
            'period_start' => now()->startOfMonth(), 'period_end' => now()->startOfMonth(), 'status' => 'void', 'notes' => 'Mistake',
        ]);
        Charge::query()->create([
            'tenant_id' => $other->id, 'kind' => 'manual', 'amount' => 5, 'currency' => 'USD',
            'period_start' => now()->startOfMonth(), 'period_end' => now()->startOfMonth(), 'status' => 'open', 'notes' => 'Not yours',
        ]);
        Purchase::query()->create([
            'tenant_id' => $this->tenant->id, 'kind' => 'credit', 'external_ref' => 'shop-1', 'amount' => 25, 'currency' => 'USD',
            'purchased_at' => now(), 'metadata' => ['internal' => 'do-not-show'],
        ]);

        $billing = $this->member($this->tenant, 'billing');
        $response = $this->actingAs($billing)->getJson('/api/tenant/billing', $this->tenantHeaders($this->tenant))
            ->assertOk()
            ->assertJsonCount(2, 'charges')
            ->assertJsonPath('charges.0.description', 'Support hours')
            ->assertJsonPath('charges.0.status', 'open')
            ->assertJsonPath('charges.0.total', 15)          // open: amounts as of now
            ->assertJsonPath('charges.1.amount_due', 30)
            ->assertJsonPath('charges.1.line_items.0.description', 'Setup fee')
            ->assertJsonPath('credit_balances.USD', 15)       // 25 bought − 10 drawn
            ->assertJsonPath('purchases.0.external_ref', 'shop-1');
        $this->assertStringNotContainsString('Not yours', $response->getContent());
        $this->assertStringNotContainsString('do-not-show', $response->getContent());

        $developer = $this->member($this->tenant, 'developer');
        $this->actingAs($developer)->getJson('/api/tenant/billing', $this->tenantHeaders($this->tenant))->assertForbidden();
    }

    // ── Admin billing settings ──────────────────────────────────────────────

    public function test_billing_settings_never_return_the_webhook_secret(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/admin/billing-settings')->assertOk()->assertJson([
            'plans_enabled' => true,
            'webhook' => ['url' => null, 'url_source' => 'config', 'secret_set' => false],
            'usage_thresholds' => [],
            'store_url' => null,
            'default_currency' => 'USD',
        ]);

        $response = $this->actingAs($admin)->putJson('/api/admin/billing-settings', [
            'webhook_url' => 'https://billing.example.com/hooks/runr',
            'webhook_secret' => 'whsec-supersecret-0123456789',
            'usage_thresholds' => [500, 100, 100, 1000],
            'store_url' => 'https://shop.example.com',
        ])->assertOk()
            ->assertJsonPath('webhook.url', 'https://billing.example.com/hooks/runr')
            ->assertJsonPath('webhook.url_source', 'settings')
            ->assertJsonPath('webhook.secret_set', true)
            ->assertJsonPath('usage_thresholds', [100, 500, 1000])
            ->assertJsonPath('store_url', 'https://shop.example.com');
        $this->assertStringNotContainsString('supersecret', $response->getContent());
        $this->assertSame('whsec-supersecret-0123456789', SecureSetting::getValue(BillingSettings::SECRET_WEBHOOK));

        // null keeps, clear_secrets removes; the URL falls back to the install default
        $this->actingAs($admin)->putJson('/api/admin/billing-settings', ['webhook_secret' => null])->assertJsonPath('webhook.secret_set', true);
        $this->actingAs($admin)->putJson('/api/admin/billing-settings', ['clear_secrets' => ['webhook_secret']])->assertJsonPath('webhook.secret_set', false);
        config(['tenancy.billing.webhook.url' => 'https://helm.example.com/hook']);
        $this->actingAs($admin)->putJson('/api/admin/billing-settings', ['webhook_url' => null])
            ->assertJsonPath('webhook.url', 'https://helm.example.com/hook')->assertJsonPath('webhook.url_source', 'config');

        $this->actingAs($admin)->putJson('/api/admin/billing-settings', ['webhook_url' => 'ftp://billing.example.com'])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/admin/billing-settings', ['webhook_secret' => 'short'])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/admin/billing-settings', ['usage_thresholds' => [-5]])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/admin/billing-settings', ['clear_secrets' => ['captcha_secret']])->assertStatus(422);

        $owner = $this->member($this->tenant, 'owner');
        $this->actingAs($owner)->getJson('/api/admin/billing-settings')->assertForbidden();
    }

    public function test_usage_thresholds_from_the_install_default(): void
    {
        config(['tenancy.billing.events.usage_thresholds' => '250, 50,abc,0']);

        $this->assertSame([50.0, 250.0], app(BillingSettings::class)->usageThresholds());
    }
}
