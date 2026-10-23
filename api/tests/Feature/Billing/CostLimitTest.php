<?php

namespace Tests\Feature\Billing;

use App\Models\AuditEvent;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Billing\Jobs\CheckCostLimitsJob;
use Modules\Billing\Models\BillingEvent;
use Modules\Billing\Models\CostLimit;
use Modules\Billing\Services\BillingEvents;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * The monthly cost limit of a pay-as-you-go project (docs/billing.md#monthly-cost-limit): set by
 * the owner on the Billing tab, month to date and forecast next to it, a warning at 80 % and a
 * notice at 100 % once per month and limit, and at 100 % — if the owner chose it — a stop that the
 * owner lifts once the limit allows it, and RunR lifts when a new month begins. Fixed-price
 * projects have none.
 *
 * Prices: the seeded defaults (0.04 per vCPU-hour): 25 vCPU-hours cost 1.00. Today is
 * 20 October 2026: 20 of 31 days have passed.
 */
class CostLimitTest extends TestCase
{
    use ActsWithRoles, EnablesBilling, RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->setUpBilling();

        $this->userWithRole('owner');
        $this->owner = $this->userWithRole('developer');
        $this->project = $this->billedProject('api', $this->billingRegion(), $this->owner);
    }

    protected function tearDown(): void
    {
        $this->tearDownBilling();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(string $suffix = ''): string
    {
        return "/api/projects/{$this->project->id}/billing/cost-limit{$suffix}";
    }

    /** Usage worth $amount on one day of the month (25 vCPU-hours = 1.00). */
    private function spend(float $amount, string $date = '2026-10-05'): void
    {
        $this->usageDay($this->project, $date, $amount * 25);
    }

    private function check(): void
    {
        // a queue worker starts every job with fresh scoped services (the bills' values of the
        // previous run are not kept)
        app()->forgetScopedInstances();
        dispatch_sync(new CheckCostLimitsJob);
    }

    private function events(string $type): int
    {
        return BillingEvent::query()->where('type', $type)->count();
    }

    private function notices(string $type, ?User $user = null): int
    {
        return Notification::query()->where('user_id', (string) ($user ?? $this->owner)->id)->where('type', $type)->count();
    }

    public function test_the_billing_tab_shows_the_limit_the_month_so_far_and_the_forecast(): void
    {
        $this->spend(5);

        // No limit yet: the month so far and the forecast are there anyway
        $this->actingAs($this->owner)->getJson("/api/projects/{$this->project->id}/billing")->assertOk()
            ->assertJsonPath('cost_limit.available', true)
            ->assertJsonPath('cost_limit.limit', null)
            ->assertJsonPath('cost_limit.state', 'none')
            ->assertJsonPath('cost_limit.can_manage', true)
            ->assertJsonPath('cost_limit.currency', 'USD');

        $this->actingAs($this->owner)->putJson($this->url(), ['amount' => 10, 'action' => 'notify'])->assertOk()
            ->assertJsonPath('data.limit.amount', 10)
            ->assertJsonPath('data.limit.action', 'notify')
            ->assertJsonPath('data.limit.updated_by.id', (string) $this->owner->id);

        $status = $this->actingAs($this->owner)->getJson($this->url())->assertOk()->json('data');
        $this->assertEqualsWithDelta(5.0, $status['month_to_date'], 0.001);
        // 5.00 ÷ 20 days × 31 days
        $this->assertEqualsWithDelta(7.75, $status['forecast'], 0.001);
        $this->assertSame(20, $status['days_elapsed']);
        $this->assertSame(31, $status['days_in_month']);
        $this->assertEqualsWithDelta(50.0, $status['percent'], 0.001);
        $this->assertSame('ok', $status['state']);
        $this->assertSame('2026-10', $status['period']);
        $this->assertSame(80, $status['warning_percent']);
        $this->assertFalse($status['stopped']);

        $this->assertSame('USD', CostLimit::query()->sole()->currency);
        $this->assertTrue(AuditEvent::query()->where('action', 'billing.cost_limit.update')->exists());
    }

    public function test_fixed_price_projects_have_no_cost_limit(): void
    {
        $fixed = $this->billedProject('shop', $this->billingRegion('fx', 'fixed'), $this->owner);

        $this->actingAs($this->owner)->getJson("/api/projects/{$fixed->id}/billing")->assertOk()
            ->assertJsonPath('cost_limit.available', false)
            ->assertJsonPath('cost_limit.month_to_date', null);
        $this->actingAs($this->owner)->putJson("/api/projects/{$fixed->id}/billing/cost-limit", ['amount' => 10, 'action' => 'stop'])
            ->assertStatus(422)->assertJsonPath('code', 'cost_limit_not_available');
        $this->assertSame(0, CostLimit::query()->count());
    }

    public function test_a_region_that_charges_nothing_for_usage_has_no_cost_limit(): void
    {
        $free = $this->billingRegion('free');
        $this->priceVersion($free->id, '2026-01-01', ['cpu_vcpu_hour' => 0, 'memory_gb_hour' => 0, 'gpu_hour' => 0]);
        $project = $this->billedProject('free', $free, $this->owner);

        $this->actingAs($this->owner)->getJson("/api/projects/{$project->id}/billing/cost-limit")->assertOk()
            ->assertJsonPath('data.available', false);
        $this->actingAs($this->owner)->putJson("/api/projects/{$project->id}/billing/cost-limit", ['amount' => 10, 'action' => 'notify'])
            ->assertStatus(422)->assertJsonPath('code', 'cost_limit_not_available');
    }

    public function test_the_limit_is_a_positive_amount_in_the_billing_currency_with_notify_or_stop(): void
    {
        $this->actingAs($this->owner);

        $this->putJson($this->url(), ['amount' => 0, 'action' => 'notify'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->putJson($this->url(), ['amount' => -5, 'action' => 'notify'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->putJson($this->url(), ['amount' => 'lots', 'action' => 'notify'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->putJson($this->url(), ['amount' => 10.1234, 'action' => 'notify'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->putJson($this->url(), ['action' => 'notify'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->putJson($this->url(), ['amount' => 10, 'action' => 'scale_down'])->assertStatus(422)->assertJsonValidationErrors('action');
        $this->putJson($this->url(), ['amount' => 10, 'action' => 'notify', 'currency' => 'EUR'])->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->assertSame(0, CostLimit::query()->count());

        // The billing currency, in any case
        $this->putJson($this->url(), ['amount' => 12.5, 'action' => 'stop', 'currency' => 'usd'])->assertOk()
            ->assertJsonPath('data.limit.amount', 12.5)->assertJsonPath('data.limit.action', 'stop');
    }

    public function test_the_owner_project_billing_and_billing_manage_set_it_billing_view_reads_it(): void
    {
        $other = $this->userWithRole('developer');
        $this->actingAs($other)->getJson($this->url())->assertForbidden();
        $this->actingAs($other)->putJson($this->url(), ['amount' => 10, 'action' => 'notify'])->assertForbidden();

        // projects.billing on the project: like the owner
        $payer = $this->customRole('project-payer', ['projects.billing']);
        $this->grantProject($other, $this->project, $payer->key);
        $this->actingAs($other)->putJson($this->url(), ['amount' => 10, 'action' => 'notify'])->assertOk();

        // billing.view: reads it, cannot change it (the route needs projects.view)
        $role = $this->customRole('billing-reader', ['billing.view']);
        $viewer = $this->userWithRole($role->key);
        $this->grantProject($viewer, $this->project, 'viewer');
        $this->actingAs($viewer)->getJson($this->url())->assertOk()->assertJsonPath('data.can_manage', false);
        $this->actingAs($viewer)->putJson($this->url(), ['amount' => 20, 'action' => 'stop'])->assertForbidden();
        $this->actingAs($viewer)->deleteJson($this->url())->assertForbidden();

        // billing staff (billing.manage) change it
        $staff = $this->userWithRole('billing');
        $this->grantProject($staff, $this->project, 'viewer');
        $this->actingAs($staff)->deleteJson($this->url())->assertOk()->assertJsonPath('data.limit', null);
        $this->assertTrue(AuditEvent::query()->where('action', 'billing.cost_limit.delete')->exists());
    }

    public function test_the_warning_at_80_percent_and_the_notice_at_100_percent_go_out_once_a_month(): void
    {
        $this->actingAs($this->owner)->putJson($this->url(), ['amount' => 10, 'action' => 'notify'])->assertOk();

        $this->spend(7.5);
        $this->check();
        $this->assertSame(0, $this->events(BillingEvents::PROJECT_COST_LIMIT_WARNING));

        $this->spend(0.5, '2026-10-06'); // 8.00: 80 %
        $this->check();
        $this->check();
        $this->assertSame(1, $this->events(BillingEvents::PROJECT_COST_LIMIT_WARNING));
        $this->assertSame(1, $this->notices('billing.cost_limit_warning'));
        $warning = BillingEvent::query()->where('type', BillingEvents::PROJECT_COST_LIMIT_WARNING)->sole()->payload['data'];
        $this->assertSame((string) $this->project->id, $warning['project']['id']);
        $this->assertSame('2026-10', $warning['period']);
        $this->assertEqualsWithDelta(8.0, $warning['month_to_date'], 0.001);
        $this->assertEqualsWithDelta(80.0, $warning['percent'], 0.001);
        $this->assertSame('notify', $warning['action']);

        $this->spend(2.5, '2026-10-07'); // 10.50: reached
        $this->check();
        $this->check();
        $this->assertSame(1, $this->events(BillingEvents::PROJECT_COST_LIMIT_REACHED));
        $this->assertSame(1, $this->notices('billing.cost_limit_reached'));
        $reached = BillingEvent::query()->where('type', BillingEvents::PROJECT_COST_LIMIT_REACHED)->sole()->payload['data'];
        $this->assertFalse($reached['stopped']);
        $this->assertFalse($this->project->fresh()->isStopped(), 'notify only notifies');
        $this->assertSame('reached', $this->actingAs($this->owner)->getJson($this->url())->json('data.state'));

        $audit = AuditEvent::query()->where('action', 'billing.cost_limit.reached')->sole();
        $this->assertSame(AuditEvent::ACTOR_SYSTEM, $audit->actor_type);
        $this->assertFalse($audit->metadata['stopped']);

        // The webhook event types are known to the event log
        $this->assertContains('project.cost_limit.reached', BillingEvents::TYPES);
        $admin = $this->userWithRole('owner');
        $this->actingAs($admin)->getJson('/api/billing/events?type=project.cost_limit.warning')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_reaching_the_limit_at_once_sends_the_notice_without_a_late_warning(): void
    {
        $this->spend(15);
        $this->actingAs($this->owner)->putJson($this->url(), ['amount' => 10, 'action' => 'notify'])->assertOk()
            ->assertJsonPath('data.state', 'reached');

        $this->check();
        $this->assertSame(1, $this->events(BillingEvents::PROJECT_COST_LIMIT_REACHED));
        $this->assertSame(0, $this->events(BillingEvents::PROJECT_COST_LIMIT_WARNING));
    }

    public function test_the_stop_action_stops_the_project_and_the_owner_resumes_it_after_raising_the_limit(): void
    {
        $this->actingAs($this->owner)->putJson($this->url(), ['amount' => 10, 'action' => 'stop'])->assertOk()
            ->assertJsonPath('data.stopped', false);

        $this->spend(12);
        $this->check();

        $project = $this->project->fresh();
        $this->assertTrue($project->isStopped());
        $this->assertSame(ProjectStopService::systemActor('cost_limit'), $project->stopped_by);
        $this->assertSame(ProjectStopService::LEVEL_ORGANIZATION, $project->getAttribute('stop_level'));
        $this->assertStringContainsString('12.00 USD', (string) $project->stop_reason);

        // The members see a stop by the cost limit, no one named
        $this->actingAs($this->owner)->getJson("/api/projects/{$this->project->id}")->assertOk()
            ->assertJsonPath('data.stopped.system', 'cost_limit')
            ->assertJsonPath('data.stopped.by', null)
            ->assertJsonPath('data.stopped.level', 'organization');

        $reached = BillingEvent::query()->where('type', BillingEvents::PROJECT_COST_LIMIT_REACHED)->sole()->payload['data'];
        $this->assertTrue($reached['stopped']);
        $stopped = BillingEvent::query()->where('type', BillingEvents::PROJECT_STOPPED)->sole()->payload['data'];
        $this->assertSame('cost_limit', $stopped['system']);
        $this->assertSame('system:cost_limit', $stopped['stopped_by']);
        $this->assertSame(1, $this->notices('billing.cost_limit_reached'));
        $this->assertSame(1, $this->notices('project.stopped'));
        $stopAudit = AuditEvent::query()->where('action', 'project.stop')->sole();
        $this->assertSame(AuditEvent::ACTOR_SYSTEM, $stopAudit->actor_type);
        $this->assertSame('cost_limit', $stopAudit->metadata['system']);

        // Still at the limit: no resume
        $this->actingAs($this->owner)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.stopped', true)->assertJsonPath('data.can_resume', false);
        $this->actingAs($this->owner)->postJson($this->url('/resume'))
            ->assertStatus(409)->assertJsonPath('code', 'cost_limit_still_reached');
        $this->assertTrue($this->project->fresh()->isStopped());

        // Raised: the owner resumes it (no projects.stop needed)
        $this->actingAs($this->owner)->putJson($this->url(), ['amount' => 20, 'action' => 'stop'])->assertOk()
            ->assertJsonPath('data.can_resume', true)
            ->assertJsonPath('data.reached_at', null);
        $this->actingAs($this->owner)->postJson($this->url('/resume'))->assertOk()
            ->assertJsonPath('data.stopped', false)
            ->assertJsonPath('project.stopped', null)
            ->assertJsonPath('deployment_id', null);
        $this->assertFalse($this->project->fresh()->isStopped());
        $this->assertTrue(AuditEvent::query()->where('action', 'billing.cost_limit.resume')->exists());

        // Running again under the raised limit: nothing more happens this month
        $this->check();
        $this->assertFalse($this->project->fresh()->isStopped());
        $this->assertSame(1, $this->events(BillingEvents::PROJECT_COST_LIMIT_REACHED));
    }

    public function test_saving_a_stop_limit_the_month_already_passed_stops_the_project_at_once(): void
    {
        $this->spend(12);

        $this->actingAs($this->owner)->putJson($this->url(), ['amount' => 10, 'action' => 'stop'])->assertOk()
            ->assertJsonPath('data.stopped', true)
            ->assertJsonPath('data.state', 'reached')
            ->assertJsonPath('data.can_resume', false);
        $this->assertTrue($this->project->fresh()->isStopped());
    }

    public function test_switching_to_stop_after_the_notice_stops_the_project(): void
    {
        $this->spend(12);
        $this->actingAs($this->owner)->putJson($this->url(), ['amount' => 10, 'action' => 'notify'])->assertOk();
        $this->assertFalse($this->project->fresh()->isStopped());

        $this->actingAs($this->owner)->putJson($this->url(), ['amount' => 10, 'action' => 'stop'])->assertOk()
            ->assertJsonPath('data.stopped', true);
        $this->assertSame(2, $this->events(BillingEvents::PROJECT_COST_LIMIT_REACHED));

        // Back to notify: the owner may resume it while the month is still above the limit
        $this->actingAs($this->owner)->putJson($this->url(), ['amount' => 10, 'action' => 'notify'])->assertOk()
            ->assertJsonPath('data.can_resume', true);
        $this->actingAs($this->owner)->postJson($this->url('/resume'))->assertOk();
        $this->check();
        $this->assertFalse($this->project->fresh()->isStopped());
    }

    public function test_a_new_month_starts_over_and_resumes_the_project_by_itself(): void
    {
        $this->actingAs($this->owner)->putJson($this->url(), ['amount' => 10, 'action' => 'stop'])->assertOk();
        $this->spend(12);
        $this->check();
        $this->assertTrue($this->project->fresh()->isStopped());

        // Later the same month: still stopped
        Carbon::setTestNow('2026-10-31 23:50:00');
        $this->check();
        $this->assertTrue($this->project->fresh()->isStopped());

        Carbon::setTestNow('2026-11-01 00:10:00');
        $status = $this->actingAs($this->owner)->getJson($this->url())->assertOk()->json('data');
        $this->assertSame('2026-11', $status['period']);
        $this->assertEqualsWithDelta(0.0, $status['month_to_date'], 0.001);
        $this->assertNull($status['reached_at']);
        $this->assertTrue($status['stopped']);
        $this->assertTrue($status['can_resume']);

        // The first check of the month starts its state over and resumes the project
        $this->check();
        $limit = CostLimit::query()->sole();
        $this->assertSame('2026-11', $limit->period);
        $this->assertNull($limit->reached_at);
        $this->assertNull($limit->warned_at);
        $project = $this->project->fresh();
        $this->assertFalse($project->isStopped());
        $this->assertNull($project->stopped_by);

        $this->assertSame(1, $this->notices('billing.cost_limit_resumed'));
        $this->assertSame(1, $this->notices('project.resumed'));
        $resumed = BillingEvent::query()->where('type', BillingEvents::PROJECT_RESUMED)->sole()->payload['data'];
        $this->assertSame('system:cost_limit', $resumed['resumed_by']);
        $this->assertSame('cost_limit', $resumed['system']);
        $audit = AuditEvent::query()->where('action', 'project.resume')->sole();
        $this->assertSame(AuditEvent::ACTOR_SYSTEM, $audit->actor_type);
        $this->assertSame('cost_limit', $audit->metadata['system']);

        // Once: the next check finds it running
        $this->check();
        $this->assertSame(1, $this->notices('billing.cost_limit_resumed'));
        $this->actingAs($this->owner)->postJson($this->url('/resume'))
            ->assertStatus(409)->assertJsonPath('code', 'cost_limit_not_stopped');
    }

    public function test_a_new_month_that_reached_the_limit_already_keeps_the_stop(): void
    {
        $this->actingAs($this->owner)->putJson($this->url(), ['amount' => 10, 'action' => 'stop'])->assertOk();
        $this->spend(12);
        $this->check();
        $stoppedAt = $this->project->fresh()->stopped_at;

        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->spend(11, '2026-11-01');
        $this->check();

        $project = $this->project->fresh();
        $this->assertTrue($project->isStopped());
        $this->assertTrue($project->stopped_at->equalTo($stoppedAt));
        $this->assertSame(0, $this->notices('billing.cost_limit_resumed'));
        // The month's notice goes out again; the project is stopped already
        $this->assertSame(2, $this->events(BillingEvents::PROJECT_COST_LIMIT_REACHED));
    }

    public function test_the_new_month_resumes_only_a_stop_the_limit_still_holds(): void
    {
        $this->actingAs($this->owner)->putJson($this->url(), ['amount' => 10, 'action' => 'stop'])->assertOk();
        $this->spend(12);
        $this->check();

        // A platform administrator took the stop over: it is theirs to lift
        $platformAdmin = $this->platformAdmin();
        $this->signedIn($platformAdmin)->postJson("/api/projects/{$this->project->id}/stop", ['reason' => 'Abuse report'])->assertOk();

        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->check();

        $project = $this->project->fresh();
        $this->assertTrue($project->isStopped());
        $this->assertSame('Abuse report', $project->stop_reason);
        $this->assertSame(0, $this->notices('billing.cost_limit_resumed'));
    }

    public function test_without_a_limit_the_new_month_resumes_nothing(): void
    {
        $this->actingAs($this->owner)->putJson($this->url(), ['amount' => 10, 'action' => 'stop'])->assertOk();
        $this->spend(12);
        $this->check();
        $this->actingAs($this->owner)->deleteJson($this->url())->assertOk();

        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->check();

        $this->assertTrue($this->project->fresh()->isStopped());
        $this->actingAs($this->owner)->postJson($this->url('/resume'))->assertOk();
        $this->assertFalse($this->project->fresh()->isStopped());
    }

    public function test_removing_the_limit_keeps_the_stop_until_the_owner_resumes(): void
    {
        $this->actingAs($this->owner)->putJson($this->url(), ['amount' => 10, 'action' => 'stop'])->assertOk();
        $this->spend(12);
        $this->check();

        $this->actingAs($this->owner)->deleteJson($this->url())->assertOk()
            ->assertJsonPath('data.limit', null)
            ->assertJsonPath('data.stopped', true)
            ->assertJsonPath('data.can_resume', true);
        $this->assertTrue($this->project->fresh()->isStopped());

        $this->actingAs($this->owner)->postJson($this->url('/resume'))->assertOk();
        $this->assertFalse($this->project->fresh()->isStopped());
    }

    public function test_only_a_stop_by_the_cost_limit_is_resumed_here(): void
    {
        $this->actingAs($this->owner)->postJson($this->url('/resume'))
            ->assertStatus(409)->assertJsonPath('code', 'cost_limit_not_stopped');

        // An administrator's stop is lifted by projects.stop, not by the owner's cost limit
        $admin = $this->userWithRole('admin');
        $this->signedIn($admin)->postJson("/api/projects/{$this->project->id}/stop", ['reason' => 'Abuse report'])->assertOk();
        $this->signedIn($this->owner)->postJson($this->url('/resume'))
            ->assertStatus(409)->assertJsonPath('code', 'cost_limit_not_stopped');
        $this->assertTrue($this->project->fresh()->isStopped());
    }

    public function test_an_administrators_stop_is_not_taken_over_by_the_limit(): void
    {
        $admin = $this->userWithRole('admin');
        $this->signedIn($admin)->postJson("/api/projects/{$this->project->id}/stop", ['reason' => 'Abuse report'])->assertOk();

        $this->spend(12);
        $this->signedIn($this->owner)->putJson($this->url(), ['amount' => 10, 'action' => 'stop'])->assertOk()
            ->assertJsonPath('data.stopped', false);

        $project = $this->project->fresh();
        $this->assertSame((string) $admin->id, $project->stopped_by);
        $this->assertSame('Abuse report', $project->stop_reason);
        $this->assertFalse(BillingEvent::query()->where('type', BillingEvents::PROJECT_COST_LIMIT_REACHED)->sole()->payload['data']['stopped']);
    }

    public function test_a_deleted_project_takes_its_limit_along(): void
    {
        $this->actingAs($this->owner)->putJson($this->url(), ['amount' => 10, 'action' => 'notify'])->assertOk();
        $this->assertSame(1, CostLimit::query()->count());

        $this->project->delete();

        $this->assertSame(0, CostLimit::query()->count());
    }

    public function test_a_project_moved_to_a_fixed_region_keeps_its_limit_without_it_acting(): void
    {
        $this->actingAs($this->owner)->putJson($this->url(), ['amount' => 10, 'action' => 'stop'])->assertOk();
        $this->project->forceFill(['knative_region_id' => $this->billingRegion('fx', 'fixed')->id])->saveQuietly();

        $this->spend(12);
        $this->check();

        $this->assertFalse($this->project->fresh()->isStopped());
        $this->assertSame(0, $this->events(BillingEvents::PROJECT_COST_LIMIT_REACHED));
        $this->actingAs($this->owner)->getJson($this->url())->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.limit.amount', 10);
    }
}
