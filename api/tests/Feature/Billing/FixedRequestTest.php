<?php

namespace Tests\Feature\Billing;

use App\Models\ContainerConfig;
use App\Models\Git\PreviewEnvironment;
use App\Models\Notification;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Billing\Models\BillingEvent;
use Modules\Billing\Models\FixedRequest;
use Modules\Billing\Models\RegionSetting;
use Modules\Billing\Services\Admission;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * Fixed regions (docs/billing.md#fixed-regions): a project requests resources, sees the monthly
 * price first, the seller approves or rejects; an approval supersedes the previous allocation; a
 * region's type cannot change while allocations are active there (BillingPolicy decision 1).
 */
class FixedRequestTest extends TestCase
{
    use ActsWithRoles, EnablesBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 09:00:00');
        $this->setUpBilling();
    }

    protected function tearDown(): void
    {
        $this->tearDownBilling();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_quote_shows_the_monthly_price_and_the_prorated_first_month(): void
    {
        $owner = $this->userWithRole('owner');
        $region = $this->billingRegion('fx', 'fixed');
        $project = $this->billedProject('shop', $region, $owner);

        // 2 vCPU × 25 + 4 GB × 3 = 62 per month; October has 31 days, 10 → 31 = 22 days
        $this->actingAs($owner)->getJson("/api/projects/{$project->id}/billing/quote?cpu_m=2000&memory_mb=4096")
            ->assertOk()
            ->assertJsonPath('monthly_price', 62)
            ->assertJsonPath('breakdown.cpu', 50)
            ->assertJsonPath('first_month.days', 22)
            ->assertJsonPath('first_month.amount', 44)
            ->assertJsonPath('billing_type', 'fixed');
    }

    public function test_a_request_needs_a_fixed_region_and_gpus_only_where_the_region_meters_them(): void
    {
        $owner = $this->userWithRole('owner');
        $payg = $this->billedProject('payg', $this->billingRegion('pg'), $owner);
        $fixed = $this->billedProject('fixed', $this->billingRegion('fx', 'fixed'), $owner);

        $this->actingAs($owner)->postJson("/api/projects/{$payg->id}/billing/fixed-requests", ['cpu_m' => 1000, 'memory_mb' => 1024])
            ->assertStatus(422)->assertJsonPath('code', 'region_not_fixed');
        $this->actingAs($owner)->postJson("/api/projects/{$fixed->id}/billing/fixed-requests", ['cpu_m' => 1000, 'memory_mb' => 1024, 'gpu_count' => 1])
            ->assertStatus(422)->assertJsonPath('code', 'gpu_not_available');
        $this->actingAs($owner)->postJson("/api/projects/{$fixed->id}/billing/fixed-requests", ['cpu_m' => 50, 'memory_mb' => 1024])
            ->assertStatus(422)->assertJsonValidationErrors('cpu_m');
    }

    public function test_request_approve_supersede_and_reject(): void
    {
        $admin = $this->userWithRole('owner');
        $dev = $this->userWithRole('developer');
        $region = $this->billingRegion('fx', 'fixed');
        $project = $this->billedProject('api', $region, $dev);

        $first = $this->actingAs($dev)->postJson("/api/projects/{$project->id}/billing/fixed-requests", ['cpu_m' => 1000, 'memory_mb' => 1024, 'note' => 'start'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.monthly_price', 28)
            ->json('data.id');

        // The approvers were told (in-app), the event was recorded
        $this->assertTrue(Notification::query()->where('user_id', $admin->id)->where('type', 'billing.fixed_request')->exists());
        $this->assertTrue(BillingEvent::query()->where('type', 'fixed_request.created')->exists());

        // A newer request replaces the pending one
        $second = $this->actingAs($dev)->postJson("/api/projects/{$project->id}/billing/fixed-requests", ['cpu_m' => 2000, 'memory_mb' => 4096])
            ->assertCreated()->json('data.id');
        $this->assertSame(FixedRequest::CANCELLED, FixedRequest::query()->find($first)->status);

        // The requester cannot approve; the seller can
        $this->actingAs($dev)->postJson("/api/billing/fixed-requests/{$second}/approve")->assertForbidden();
        $this->actingAs($admin)->postJson("/api/billing/fixed-requests/{$second}/approve", ['note' => 'ok'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.active_from', '2026-10-10');
        $this->actingAs($admin)->postJson("/api/billing/fixed-requests/{$second}/approve")
            ->assertStatus(409)->assertJsonPath('code', 'request_not_pending');
        $this->assertTrue(Notification::query()->where('user_id', $dev->id)->where('type', 'billing.fixed_request_decided')->exists());

        // A change is a new request; its approval supersedes the allocation the day before
        Carbon::setTestNow('2026-10-20 09:00:00');
        $third = $this->actingAs($dev)->postJson("/api/projects/{$project->id}/billing/fixed-requests", ['cpu_m' => 4000, 'memory_mb' => 8192])->json('data.id');
        $this->actingAs($admin)->postJson("/api/billing/fixed-requests/{$third}/approve")->assertOk();
        $old = FixedRequest::query()->find($second);
        $this->assertSame(FixedRequest::SUPERSEDED, $old->status);
        $this->assertSame('2026-10-19', $old->active_until->toDateString());

        // Rejection needs a reason
        $fourth = $this->actingAs($dev)->postJson("/api/projects/{$project->id}/billing/fixed-requests", ['cpu_m' => 8000, 'memory_mb' => 8192])->json('data.id');
        $this->actingAs($admin)->postJson("/api/billing/fixed-requests/{$fourth}/reject")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->postJson("/api/billing/fixed-requests/{$fourth}/reject", ['reason' => 'too large'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_reason', 'too large');

        $this->actingAs($admin)->getJson('/api/billing/fixed-requests?status=approved')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $third);
        $this->assertTrue(BillingEvent::query()->where('type', 'fixed_request.rejected')->exists());
    }

    public function test_a_pending_request_can_be_cancelled(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->billedProject('api', $this->billingRegion('fx', 'fixed'), $owner);
        $id = $this->actingAs($owner)->postJson("/api/projects/{$project->id}/billing/fixed-requests", ['cpu_m' => 1000, 'memory_mb' => 1024])->json('data.id');

        $this->actingAs($owner)->postJson("/api/projects/{$project->id}/billing/fixed-requests/{$id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->actingAs($owner)->postJson("/api/projects/{$project->id}/billing/fixed-requests/{$id}/cancel")
            ->assertStatus(409)->assertJsonPath('code', 'request_not_pending');
    }

    public function test_the_region_type_cannot_change_while_allocations_are_active(): void
    {
        $admin = $this->userWithRole('owner');
        $region = $this->billingRegion('fx', 'fixed');
        $project = $this->billedProject('api', $region, $admin);

        $this->actingAs($admin)->putJson("/api/billing/regions/{$region->id}", ['billing_type' => 'payg'])
            ->assertOk()->assertJsonPath('data.billing_type', 'payg');
        $this->actingAs($admin)->putJson("/api/billing/regions/{$region->id}", ['billing_type' => 'fixed'])->assertOk();

        $this->allocation($project, 1000, 1024, '2026-10-01');

        $this->actingAs($admin)->putJson("/api/billing/regions/{$region->id}", ['billing_type' => 'payg'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'active_fixed_allocations')
            ->assertJsonPath('active_allocations', 1);
        $this->assertSame('fixed', RegionSetting::query()->find($region->id)->billing_type);

        $this->actingAs($admin)->getJson('/api/billing/regions')->assertOk()
            ->assertJsonPath('data.0.type_locked', true)
            ->assertJsonPath('data.0.active_allocations', 1);
    }

    public function test_switching_a_region_to_pay_as_you_go_cancels_its_pending_requests(): void
    {
        $admin = $this->userWithRole('owner');
        $region = $this->billingRegion('fx', 'fixed');
        $project = $this->billedProject('api', $region, $admin);
        $id = $this->actingAs($admin)->postJson("/api/projects/{$project->id}/billing/fixed-requests", ['cpu_m' => 1000, 'memory_mb' => 1024])->json('data.id');

        $this->actingAs($admin)->putJson("/api/billing/regions/{$region->id}", ['billing_type' => 'payg'])->assertOk();

        $this->assertSame(FixedRequest::CANCELLED, FixedRequest::query()->find($id)->status);
    }

    public function test_a_request_smaller_than_what_runs_is_refused_until_the_project_scales_down(): void
    {
        $admin = $this->userWithRole('owner');
        $region = $this->billingRegion('fx', 'fixed');
        $project = $this->billedProject('api', $region, $admin);
        ContainerConfig::query()->create(['project_id' => $project->id, 'image' => 'nginx', 'cpu' => '500m', 'memory_mb' => 512, 'trigger_type' => 'http', 'scale_to_zero' => true, 'min_scale' => 0, 'max_scale' => 3]);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $this->allocation($project, 4000, 4096, '2026-10-01');
        // It runs with up to 3 × (500m + 100m queue-proxy) = 1800m, 3 × 612 MB = 1836 MB
        $project->deployments()->create(['version_id' => $version->id, 'status' => 'active', 'k8s_job_name' => 'run-api-v1']);

        $this->actingAs($admin)->postJson("/api/projects/{$project->id}/billing/fixed-requests", ['cpu_m' => 1000, 'memory_mb' => 4096])
            ->assertStatus(422)
            ->assertJsonPath('code', 'scale_down_first')
            ->assertJsonPath('running.cpu_m', 1800);
        $id = $this->actingAs($admin)->postJson("/api/projects/{$project->id}/billing/fixed-requests", ['cpu_m' => 2000, 'memory_mb' => 2048])
            ->assertCreated()->json('data.id');

        // The project grows before the decision (another preview): the approval is refused too
        PreviewEnvironment::query()->create(['project_id' => $project->id, 'number' => 3, 'provider' => 'github', 'status' => PreviewEnvironment::READY, 'service_name' => 'api-pr-3']);
        $this->actingAs($admin)->postJson("/api/billing/fixed-requests/{$id}/approve")
            ->assertStatus(422)->assertJsonPath('code', 'scale_down_first');
        $this->assertSame(FixedRequest::PENDING, FixedRequest::query()->find($id)->status);

        PreviewEnvironment::query()->where('number', 3)->update(['status' => PreviewEnvironment::DELETED]);
        $this->actingAs($admin)->postJson("/api/billing/fixed-requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_a_request_or_approval_while_a_deployment_without_max_scale_runs_is_refused(): void
    {
        $admin = $this->userWithRole('owner');
        $region = $this->billingRegion('fx', 'fixed');
        $project = $this->billedProject('api', $region, $admin);
        ContainerConfig::query()->create(['project_id' => $project->id, 'image' => 'nginx', 'cpu' => '500m', 'memory_mb' => 256, 'trigger_type' => 'http', 'scale_to_zero' => false, 'min_scale' => 1, 'max_scale' => 0]);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        // Deployed before the region became fixed: no max_scale, Knative may scale without bound
        $running = $project->deployments()->create(['version_id' => $version->id, 'status' => 'active', 'k8s_job_name' => 'run-api-v1']);
        $this->assertTrue(app(Admission::class)->runsUnbounded($project->fresh()));

        $this->actingAs($admin)->postJson("/api/projects/{$project->id}/billing/fixed-requests", ['cpu_m' => 600, 'memory_mb' => 512])
            ->assertStatus(422)
            ->assertJsonPath('code', 'fixed_max_scale_required')
            ->assertJsonPath('message', __('billing.fixed_running_unbounded'));
        $this->assertStringContainsString('redeploy', __('billing.fixed_running_unbounded'));

        $pending = $this->allocation($project, 4000, 4096, '2026-10-01', null, FixedRequest::PENDING);
        $pending->forceFill(['active_from' => null])->save();
        $this->actingAs($admin)->postJson("/api/billing/fixed-requests/{$pending->id}/approve")
            ->assertStatus(422)->assertJsonPath('code', 'fixed_max_scale_required');
        $this->assertSame(FixedRequest::PENDING, $pending->fresh()->status);

        // max_scale set and redeployed: what runs has a worst case again
        $running->forceFill(['sizing' => ['region_id' => $region->id, 'cpu_m' => 500, 'memory_mb' => 256, 'trigger_type' => 'http',
            'min_scale' => 1, 'max_scale' => 1, 'scale_to_zero' => false, 'gpu_count' => 0]])->save();
        $this->actingAs($admin)->postJson("/api/billing/fixed-requests/{$pending->id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_a_request_is_not_approved_once_the_region_is_pay_as_you_go(): void
    {
        $admin = $this->userWithRole('owner');
        $region = $this->billingRegion('fx', 'fixed');
        $project = $this->billedProject('api', $region, $admin);
        $request = $this->allocation($project, 1000, 1024, '2026-10-01', null, FixedRequest::PENDING);
        $request->forceFill(['active_from' => null])->save();

        // The type changed without the cancellation reaching the request (e.g. a tenant copy)
        RegionSetting::query()->whereKey($region->id)->update(['billing_type' => 'payg']);

        $this->actingAs($admin)->postJson("/api/billing/fixed-requests/{$request->id}/approve")
            ->assertStatus(409)->assertJsonPath('code', 'region_not_fixed');
        $this->assertSame(FixedRequest::PENDING, $request->fresh()->status);
    }

    public function test_the_project_billing_tab_is_for_the_owner_project_billing_and_billing_staff(): void
    {
        $this->userWithRole('owner');
        $owner = $this->userWithRole('developer');
        $other = $this->userWithRole('developer');
        $staff = $this->userWithRole('billing');
        $project = $this->billedProject('api', $this->billingRegion('fx', 'fixed'), $owner);

        $this->actingAs($owner)->getJson("/api/projects/{$project->id}/billing")->assertOk()
            ->assertJsonPath('billing_type', 'fixed')
            ->assertJsonPath('can_request', true)
            ->assertJsonPath('can_view_costs', false)
            ->assertJsonPath('current.status', 'open');
        // A developer sees every project (projects.view) but not its bills
        $this->actingAs($other)->getJson("/api/projects/{$project->id}/billing")->assertForbidden();
        $this->actingAs($other)->postJson("/api/projects/{$project->id}/billing/fixed-requests", ['cpu_m' => 1000, 'memory_mb' => 1024])->assertForbidden();

        // projects.billing on the project opens it
        $role = $this->customRole('project-payer', ['projects.billing']);
        $this->grantProject($other, $project, $role->key);
        $this->actingAs($other)->getJson("/api/projects/{$project->id}/billing")->assertOk();

        // billing staff (billing.view) see it with the metered cost — but the route needs projects.view
        $this->grantProject($staff, $project, 'viewer');
        $this->actingAs($staff)->getJson("/api/projects/{$project->id}/billing")->assertOk()->assertJsonPath('can_view_costs', true);
    }
}
