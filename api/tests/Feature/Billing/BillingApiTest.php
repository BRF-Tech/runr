<?php

namespace Tests\Feature\Billing;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\BillingEvent;
use Modules\Tenancy\Models\Charge;
use Modules\Tenancy\Models\Purchase;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantSubscription;
use Modules\Tenancy\Models\UsageRecord;
use Modules\Tenancy\Services\Billing\BillingEvents;
use Tests\Feature\WhiteLabel\Concerns\WhiteLabelTenancy;
use Tests\TestCase;

/**
 * The billing API an external billing / invoicing system uses (docs/billing.md): platform
 * administrators and their API tokens with billing.view (reads) / billing.manage (writes) read
 * usage and charges, change plans, record purchases and read billing events.
 */
class BillingApiTest extends TestCase
{
    use RefreshDatabase;
    use WhiteLabelTenancy;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWhiteLabelTenancy();
        $this->tenant = $this->createTenantWithSchema('acme'.Str::lower(Str::random(4)), self::WL_TENANT_MIGRATIONS);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function bearer(User $user, array $abilities): static
    {
        $plain = $user->createToken('billing-system', $abilities)->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$plain)->withHeader('Accept', 'application/json');
    }

    private function charge(array $attrs = []): Charge
    {
        return Charge::query()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'kind' => Charge::KIND_MANUAL,
            'amount' => 40,
            'currency' => 'USD',
            'period_start' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            'status' => Charge::STATUS_OPEN,
            'notes' => 'Setup fee',
        ], $attrs));
    }

    // ── Permissions ─────────────────────────────────────────────────────────

    public function test_tokens_are_scoped_to_billing_read_or_write(): void
    {
        $admin = $this->admin();

        $this->bearer($admin, ['billing.view'])->getJson('/api/billing/charges')->assertOk();
        $this->bearer($admin, ['billing.view'])->getJson('/api/billing/tenants')->assertOk();
        $this->bearer($admin, ['billing.view'])->postJson("/api/billing/tenants/{$this->tenant->slug}/purchases", ['kind' => 'credit', 'external_ref' => 'x', 'amount' => 5, 'currency' => 'USD'])
            ->assertForbidden()->assertJsonPath('permissions', ['billing.manage']);

        // billing.manage includes billing.view
        $this->bearer($admin, ['billing.manage'])->getJson('/api/billing/charges')->assertOk();
        $this->bearer($admin, ['billing.manage'])->postJson("/api/billing/tenants/{$this->tenant->slug}/purchases", ['kind' => 'credit', 'external_ref' => 'x', 'amount' => 5, 'currency' => 'USD'])
            ->assertCreated();

        // a token without billing permissions, even of a platform administrator, is refused
        $this->bearer($admin, ['projects.view'])->getJson('/api/billing/charges')->assertForbidden();
        // a full-access session of a platform administrator (the console) passes
        $this->actingAs($admin)->getJson('/api/billing/charges')->assertOk();
    }

    public function test_billing_permissions_of_a_tenant_do_not_open_the_platform_billing_api(): void
    {
        $billing = $this->member($this->tenant, 'billing');

        $this->actingAs($billing)->getJson('/api/billing/charges', $this->tenantHeaders($this->tenant))
            ->assertForbidden()->assertJsonPath('message', __('rbac.platform_admin_required'));
        $this->bearer($billing, ['billing.manage'])->getJson('/api/billing/tenants')
            ->assertForbidden();
    }

    // ── Tenants, plans, subscriptions ───────────────────────────────────────

    public function test_tenants_are_listed_and_resolved_by_id_or_slug(): void
    {
        $admin = $this->admin();
        $this->enablePlans();
        $this->plan(['slug' => 'pro']);

        $this->actingAs($admin)->getJson('/api/billing/tenants')->assertOk()
            ->assertJsonPath('plans_enabled', true)
            ->assertJsonPath('tenants.0.tenant.slug', $this->tenant->slug)
            ->assertJsonPath('tenants.0.subscription', null);

        $this->actingAs($admin)->getJson("/api/billing/tenants/{$this->tenant->slug}")->assertOk()
            ->assertJsonPath('tenant.id', (string) $this->tenant->id)
            ->assertJsonPath('metered_usage', true)
            ->assertJsonPath('currency', 'USD');
        $this->actingAs($admin)->getJson("/api/billing/tenants/{$this->tenant->id}/subscription")->assertOk()
            ->assertJsonPath('subscription', null);
        $this->actingAs($admin)->getJson('/api/billing/tenants/no-such-tenant')->assertNotFound();

        $this->actingAs($admin)->getJson('/api/billing/plans')->assertOk()->assertJsonPath('0.slug', 'pro');
    }

    public function test_a_plan_change_puts_the_plan_into_effect_with_its_fee_and_raises_an_event(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->putJson("/api/billing/tenants/{$this->tenant->slug}/subscription", ['plan' => 'pro'])
            ->assertStatus(409)->assertJsonPath('code', 'plans_disabled');

        $this->enablePlans();
        $pro = $this->plan(['slug' => 'pro', 'price_monthly' => 30, 'price_yearly' => null, 'trial_days' => 0]);

        $this->actingAs($admin)->putJson("/api/billing/tenants/{$this->tenant->slug}/subscription", ['plan' => 'pro', 'interval' => 'year'])
            ->assertStatus(422)->assertJsonValidationErrors('interval');
        $this->actingAs($admin)->putJson("/api/billing/tenants/{$this->tenant->slug}/subscription", ['plan' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors('plan');

        $this->bearer($admin, ['billing.manage'])->putJson("/api/billing/tenants/{$this->tenant->slug}/subscription", ['plan' => 'pro', 'external_ref' => 'sub_778'])
            ->assertOk()
            ->assertJsonPath('subscription.plan.slug', 'pro')
            ->assertJsonPath('subscription.status', 'active')
            ->assertJsonPath('subscription.external_ref', 'sub_778');

        $fee = Charge::query()->where('tenant_id', $this->tenant->id)->where('kind', Charge::KIND_PLAN)->sole();
        $this->assertEquals(30, (float) $fee->amount);

        $event = BillingEvent::query()->where('type', BillingEvents::PLAN_CHANGED)->sole();
        $this->assertSame('api', $event->payload['data']['source']);
        $this->assertNull($event->payload['data']['previous']['plan']);
        $this->assertSame('pro', $event->payload['data']['subscription']['plan']['slug']);
        $this->assertSame($this->tenant->slug, $event->payload['tenant']['slug']);

        // ends at the period end, then without a plan
        $this->actingAs($admin)->putJson("/api/billing/tenants/{$pro->id}x/subscription", ['cancel_at_period_end' => true])->assertNotFound();
        $this->actingAs($admin)->putJson("/api/billing/tenants/{$this->tenant->id}/subscription", ['cancel_at_period_end' => true])
            ->assertOk()->assertJsonPath('subscription.cancel_at_period_end', true);
        $this->actingAs($admin)->putJson("/api/billing/tenants/{$this->tenant->id}/subscription", ['cancel_at_period_end' => false])
            ->assertOk()->assertJsonPath('subscription.cancel_at_period_end', false);
        $this->actingAs($admin)->putJson("/api/billing/tenants/{$this->tenant->id}/subscription", [])
            ->assertStatus(422)->assertJsonValidationErrors('plan');
        $this->actingAs($admin)->putJson("/api/billing/tenants/{$this->tenant->id}/subscription", ['plan' => null])
            ->assertOk()->assertJsonPath('subscription.plan_id', null);
        $this->assertSame(4, BillingEvent::query()->where('type', BillingEvents::PLAN_CHANGED)->count());
    }

    // ── Charges ─────────────────────────────────────────────────────────────

    public function test_charges_are_listed_with_filters_and_open_ones_are_estimated(): void
    {
        $admin = $this->admin();
        $other = $this->plainTenant('other');
        $closed = $this->charge(['status' => Charge::STATUS_CLOSED, 'total' => 40, 'amount_due' => 40, 'closed_at' => now()->subDay(),
            'line_items' => [['description' => 'Setup fee', 'quantity' => 1, 'unit' => 'item', 'unit_price' => 40, 'total' => 40, 'metadata' => []]]]);
        $open = $this->charge(['period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(), 'amount' => 12.5, 'notes' => 'Extra support']);
        $this->charge(['tenant_id' => $other->id, 'status' => Charge::STATUS_CLOSED, 'external_ref' => 'INV-9', 'total' => 1, 'amount_due' => 1, 'closed_at' => now()]);

        $this->actingAs($admin)->getJson('/api/billing/charges')->assertOk()->assertJsonPath('meta.total', 3);
        $this->actingAs($admin)->getJson("/api/billing/charges?tenant={$this->tenant->slug}&status=closed")->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $closed->id)
            ->assertJsonPath('data.0.final', true)
            ->assertJsonPath('data.0.amount_due', 40);
        $this->actingAs($admin)->getJson('/api/billing/charges?status=closed&external_ref=none')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($admin)->getJson('/api/billing/charges?external_ref=INV-9')->assertOk()->assertJsonPath('data.0.tenant_id', (string) $other->id);
        $this->actingAs($admin)->getJson('/api/billing/charges?period='.now()->format('Y-m'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($admin)->getJson('/api/billing/charges?closed_since='.urlencode(now()->subHour()->toIso8601String()))->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($admin)->getJson('/api/billing/charges?period=2026-13')->assertStatus(422)->assertJsonValidationErrors('period');
        $this->actingAs($admin)->getJson('/api/billing/charges?status=paid')->assertStatus(422);

        // the list shows an open charge without amounts; one charge with its amounts as of now
        $this->actingAs($admin)->getJson('/api/billing/charges?status=open')->assertOk()->assertJsonPath('data.0.total', null);
        $this->actingAs($admin)->getJson("/api/billing/charges/{$open->id}")->assertOk()
            ->assertJsonPath('charge.status', 'open')
            ->assertJsonPath('charge.final', false)
            ->assertJsonPath('charge.total', 12.5)
            ->assertJsonPath('charge.line_items.0.description', 'Extra support');
    }

    public function test_the_billing_system_stores_its_reference_and_voids_charges(): void
    {
        $admin = $this->admin();
        $charge = $this->charge(['status' => Charge::STATUS_CLOSED, 'total' => 40, 'amount_due' => 40, 'closed_at' => now()]);

        $this->bearer($admin, ['billing.manage'])->patchJson("/api/billing/charges/{$charge->id}", ['external_ref' => 'INV-2026-0042'])
            ->assertOk()->assertJsonPath('charge.external_ref', 'INV-2026-0042');
        $this->bearer($admin, ['billing.manage'])->patchJson("/api/billing/charges/{$charge->id}", [])->assertStatus(422);

        $this->bearer($admin, ['billing.manage'])->postJson("/api/billing/charges/{$charge->id}/void", ['reason' => 'Waived'])
            ->assertOk()->assertJsonPath('charge.status', 'void')->assertJsonPath('charge.amount_due', 0)->assertJsonPath('charge.description', 'Waived');

        $event = BillingEvent::query()->where('type', BillingEvents::CHARGE_VOIDED)->sole();
        $this->assertSame($charge->id, $event->payload['data']['charge']['id']);
        $this->actingAs($admin)->getJson('/api/billing/charges/missing')->assertNotFound();
    }

    public function test_a_one_off_charge_is_added_for_a_tenant(): void
    {
        $admin = $this->admin();

        $this->bearer($admin, ['billing.manage'])->postJson("/api/billing/tenants/{$this->tenant->slug}/charges", [
            'description' => 'Onboarding workshop', 'amount' => 450, 'currency' => 'eur', 'period_start' => now()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('charge.kind', 'manual')
            ->assertJsonPath('charge.currency', 'EUR')
            ->assertJsonPath('charge.total', 450)
            ->assertJsonPath('charge.status', 'open');

        $this->actingAs($admin)->postJson("/api/billing/tenants/{$this->tenant->slug}/charges", ['description' => 'x', 'amount' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    // ── Usage ───────────────────────────────────────────────────────────────

    public function test_rated_usage_of_a_period_per_currency(): void
    {
        $admin = $this->admin();
        $month = now()->subMonthNoOverflow()->startOfMonth();
        $this->tenant->run(function () use ($month) {
            $api = Project::create(['name' => 'orders-api', 'service_type' => 'caas']);
            foreach ([[$month->toDateString(), 1000, 2.5, 'USD'], [$month->copy()->addDays(3)->toDateString(), 500, 1.25, 'USD'], [$month->toDateString(), 10, 9, 'EUR']] as [$day, $sessions, $cost, $currency]) {
                UsageRecord::create([
                    'project_id' => $api->id, 'deployment_id' => (string) Str::ulid(), 'region_id' => 'r1', 'record_date' => $day,
                    'scale_mode' => 'scale_to_zero', 'entry_point' => 'http', 'session_count' => $sessions,
                    'raw_cost' => $cost / 5, 'multiplier' => 5, 'final_cost' => $cost, 'currency' => $currency,
                ]);
            }
        });

        $this->bearer($admin, ['billing.view'])->getJson("/api/billing/tenants/{$this->tenant->slug}/usage?period=".$month->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('from', $month->toDateString())
            ->assertJsonPath('to', $month->copy()->endOfMonth()->toDateString())
            ->assertJsonPath('sessions', 1510)
            ->assertJsonPath('currencies.USD.total', 3.75)
            ->assertJsonPath('currencies.USD.line_items.0.description', 'Project: orders-api (HTTP, Scale-to-Zero)')
            ->assertJsonPath('currencies.USD.line_items.0.quantity', 1500)
            ->assertJsonPath('currencies.EUR.total', 9);

        $this->actingAs($admin)->getJson("/api/billing/tenants/{$this->tenant->slug}/usage")->assertOk()
            ->assertJsonPath('from', now()->startOfMonth()->toDateString());
        $this->actingAs($admin)->getJson("/api/billing/tenants/{$this->tenant->slug}/usage?from=2026-01-01&to=2027-06-01")
            ->assertStatus(422)->assertJsonValidationErrors('to');
        $this->actingAs($admin)->getJson("/api/billing/tenants/{$this->tenant->slug}/usage?from=2026-01-01")->assertStatus(422);
    }

    // ── Purchases ───────────────────────────────────────────────────────────

    public function test_a_credit_purchase_is_recorded_once_per_reference(): void
    {
        $admin = $this->admin();
        $other = $this->plainTenant('other');
        $body = ['kind' => 'credit', 'external_ref' => 'order-1001', 'amount' => 50, 'currency' => 'usd', 'description' => 'Credit pack', 'metadata' => ['order' => '1001']];

        $first = $this->bearer($admin, ['billing.manage'])->postJson("/api/billing/tenants/{$this->tenant->slug}/purchases", $body)
            ->assertCreated()
            ->assertJsonPath('purchase.kind', 'credit')
            ->assertJsonPath('purchase.currency', 'USD')
            ->assertJsonPath('purchase.metadata.order', '1001')
            ->assertJsonPath('credit_balances.USD', 50)
            ->json('purchase.id');

        // the same reference again: the stored purchase, no second credit
        $this->bearer($admin, ['billing.manage'])->postJson("/api/billing/tenants/{$this->tenant->slug}/purchases", $body)
            ->assertOk()->assertJsonPath('purchase.id', $first)->assertJsonPath('credit_balances.USD', 50);
        // … of another tenant is a conflict
        $this->bearer($admin, ['billing.manage'])->postJson("/api/billing/tenants/{$other->slug}/purchases", $body)->assertStatus(409);

        $this->actingAs($admin)->postJson("/api/billing/tenants/{$this->tenant->slug}/purchases", ['kind' => 'credit', 'external_ref' => 'o2', 'amount' => 0, 'currency' => 'USD'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->actingAs($admin)->postJson("/api/billing/tenants/{$this->tenant->slug}/purchases", ['kind' => 'gift', 'external_ref' => 'o3'])
            ->assertStatus(422)->assertJsonValidationErrors('kind');

        $this->actingAs($admin)->getJson("/api/billing/purchases?tenant={$this->tenant->slug}&kind=credit")->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($admin)->getJson("/api/billing/purchases/{$first}")->assertOk()->assertJsonPath('purchase.external_ref', 'order-1001');

        // reversed in the external system
        $this->bearer($admin, ['billing.manage'])->postJson("/api/billing/purchases/{$first}/void")
            ->assertOk()->assertJsonPath('credit_balances', []);
        $this->assertNotNull(Purchase::query()->find($first)->voided_at);
    }

    public function test_a_plan_purchase_is_prepaid_and_extends_its_term(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson("/api/billing/tenants/{$this->tenant->slug}/purchases", ['kind' => 'plan', 'external_ref' => 'p0', 'plan' => 'pro'])
            ->assertStatus(422)->assertJsonValidationErrors('kind');   // plans off

        $this->enablePlans();
        $this->plan(['slug' => 'pro', 'price_monthly' => 30, 'trial_days' => 14]);

        $this->bearer($admin, ['billing.manage'])->postJson("/api/billing/tenants/{$this->tenant->slug}/purchases", [
            'kind' => 'plan', 'external_ref' => 'shop-sub-1', 'plan' => 'pro', 'interval' => 'month', 'amount' => 30, 'currency' => 'USD',
        ])->assertCreated()
            ->assertJsonPath('purchase.plan.slug', 'pro')
            ->assertJsonPath('subscription.plan.slug', 'pro')
            ->assertJsonPath('subscription.prepaid', true)
            ->assertJsonPath('subscription.status', 'active')   // bought: no trial
            ->assertJsonPath('subscription.external_ref', 'shop-sub-1');

        $this->assertSame(0, Charge::query()->where('kind', Charge::KIND_PLAN)->count(), 'a prepaid term has no plan fee');
        $end = TenantSubscription::findForTenant($this->tenant->id)->current_period_end;

        $this->bearer($admin, ['billing.manage'])->postJson("/api/billing/tenants/{$this->tenant->slug}/purchases", [
            'kind' => 'plan', 'external_ref' => 'shop-sub-2', 'plan' => 'pro',
        ])->assertCreated();

        $subscription = TenantSubscription::findForTenant($this->tenant->id);
        $this->assertTrue($subscription->current_period_end->eq($end->copy()->addMonthNoOverflow()), 'the term is extended by one month');
        $this->assertSame(0, Charge::query()->where('kind', Charge::KIND_PLAN)->count());
        $second = Purchase::query()->where('external_ref', 'shop-sub-2')->sole();
        $this->assertSame($subscription->current_period_end->toIso8601String(), $second->ends_at->toIso8601String());
        $this->assertSame($end->toIso8601String(), $second->starts_at->toIso8601String(), 'it pays for the month after the first term');

        $this->actingAs($admin)->postJson("/api/billing/tenants/{$this->tenant->slug}/purchases", ['kind' => 'plan', 'external_ref' => 'p9', 'plan' => 'gold'])
            ->assertStatus(422)->assertJsonValidationErrors('plan');
    }

    public function test_an_add_on_raises_plan_limits_until_it_ends(): void
    {
        $admin = $this->admin();
        $this->enablePlans();
        $eu = $this->platformRegion('eu-1');
        $this->attachRegion($this->tenant, $eu);
        $this->actingAs($admin)->putJson("/api/billing/tenants/{$this->tenant->slug}/subscription", ['plan' => $this->plan(['slug' => 'pro', 'max_projects' => 5, 'cpu_quota_m' => 2000, 'memory_quota_mb' => 4096])->slug])->assertOk();

        $response = $this->bearer($admin, ['billing.manage'])->postJson("/api/billing/tenants/{$this->tenant->slug}/purchases", [
            'kind' => 'addon', 'external_ref' => 'addon-7', 'limits' => ['max_projects' => 10, 'cpu_quota_m' => 1000], 'description' => '+10 projects, +1 vCPU',
        ])->assertCreated()->assertJsonPath('purchase.limits.max_projects', 10);
        $this->assertTrue(now()->addMonthNoOverflow()->subMinute()->lt($response->json('purchase.ends_at')));

        $this->actingAs($admin)->getJson("/api/billing/tenants/{$this->tenant->slug}")->assertOk()
            ->assertJsonPath('limits.max_projects', 15)
            ->assertJsonPath('limits.cpu_quota_m', 3000);
        $this->assertSame(3000, (int) $this->tenant->regions()->first()->pivot->cpu_quota_m);

        $this->actingAs($admin)->postJson("/api/billing/tenants/{$this->tenant->slug}/purchases", ['kind' => 'addon', 'external_ref' => 'a2', 'limits' => ['bananas' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors('limits');
        $this->actingAs($admin)->postJson("/api/billing/tenants/{$this->tenant->slug}/purchases", ['kind' => 'addon', 'external_ref' => 'a3', 'limits' => ['max_projects' => 0]])
            ->assertStatus(422)->assertJsonValidationErrors('limits');
        $this->actingAs($admin)->postJson("/api/billing/tenants/{$this->tenant->slug}/purchases", ['kind' => 'addon', 'external_ref' => 'a4', 'limits' => ['max_projects' => 1], 'ends_at' => now()->subDay()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('ends_at');

        // voided: the quota goes back to the plan's
        $id = Purchase::query()->where('external_ref', 'addon-7')->value('id');
        $this->bearer($admin, ['billing.manage'])->postJson("/api/billing/purchases/{$id}/void")->assertOk();
        $this->assertSame(2000, (int) $this->tenant->regions()->first()->pivot->cpu_quota_m);
        $this->actingAs($admin)->getJson("/api/billing/tenants/{$this->tenant->slug}")->assertJsonPath('limits.max_projects', 5);
    }

    // ── Events ──────────────────────────────────────────────────────────────

    public function test_events_are_read_with_a_cursor(): void
    {
        $admin = $this->admin();
        $events = app(BillingEvents::class);
        $a = $events->record(BillingEvents::USAGE_THRESHOLD, (string) $this->tenant->id, ['kind' => 'amount']);
        $b = $events->record(BillingEvents::PLAN_CHANGED, (string) $this->tenant->id, ['source' => 'api']);
        $c = $events->record(BillingEvents::TEST, null, ['message' => 'hi']);

        $page = $this->bearer($admin, ['billing.view'])->getJson('/api/billing/events?limit=2')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $a->id)
            ->assertJsonPath('data.0.type', 'billing.usage.threshold')
            ->assertJsonPath('data.0.delivery.status', 'skipped')
            ->assertJsonPath('meta.webhook_configured', false);

        $page->assertJsonPath('meta.next_after', $b->id)->assertJsonPath('meta.next_before', null);
        $this->actingAs($admin)->getJson('/api/billing/events?after='.$page->json('meta.next_after'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $c->id)->assertJsonPath('meta.next_after', null);
        $this->actingAs($admin)->getJson('/api/billing/events?type=billing.plan.changed&tenant='.$this->tenant->slug)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b->id);

        // without a webhook there is nothing to deliver to
        $this->bearer($admin, ['billing.manage'])->postJson('/api/billing/events/test')->assertStatus(409);
        $this->bearer($admin, ['billing.manage'])->postJson("/api/billing/events/{$a->id}/redeliver")->assertStatus(409);
    }

    public function test_events_are_read_newest_first_with_a_before_cursor(): void
    {
        $admin = $this->admin();
        $events = app(BillingEvents::class);
        $a = $events->record(BillingEvents::USAGE_THRESHOLD, (string) $this->tenant->id, ['kind' => 'amount']);
        $b = $events->record(BillingEvents::PLAN_CHANGED, (string) $this->tenant->id, ['source' => 'api']);
        $c = $events->record(BillingEvents::TEST, null, ['message' => 'hi']);

        $page = $this->bearer($admin, ['billing.view'])->getJson('/api/billing/events?order=desc&limit=2')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $c->id)
            ->assertJsonPath('data.1.id', $b->id)
            ->assertJsonPath('meta.next_before', $b->id)
            ->assertJsonPath('meta.next_after', null);

        $this->actingAs($admin)->getJson('/api/billing/events?order=desc&before='.$page->json('meta.next_before'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id)->assertJsonPath('meta.next_before', null);

        // after / before bound the list in either order
        $this->actingAs($admin)->getJson("/api/billing/events?order=desc&after={$a->id}")->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $c->id)->assertJsonPath('data.1.id', $b->id);
        $this->actingAs($admin)->getJson("/api/billing/events?before={$c->id}")->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $a->id)->assertJsonPath('data.1.id', $b->id);
        $this->actingAs($admin)->getJson('/api/billing/events?order=desc&type=billing.plan.changed&tenant='.$this->tenant->slug)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b->id);

        $this->actingAs($admin)->getJson('/api/billing/events?order=newest')->assertStatus(422)->assertJsonValidationErrors('order');
    }
}
