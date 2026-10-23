<?php

namespace Tests\Feature\Billing;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\Metering\UsageAggregator;
use App\Support\Projects\ProjectAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Modules\Billing\Models\RegionOverride;
use Modules\Billing\Models\RegionSetting;
use Modules\Billing\Services\BillCalculator;
use Modules\Billing\Support\Period;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * Queue consumers (docs/billing.md#queue-consumers): a queue trigger's consumer (50m / 64 MiB,
 * running as long as the deployment) is metered always, and charged only where the region charges
 * queue consumers (off by default) and the organization has not switched it off for itself in that
 * region — on pay as you go as a line of its own, in a fixed region as part of the worst case the
 * allocation must hold.
 */
class QueueConsumerChargingTest extends TestCase
{
    use ActsWithRoles, EnablesBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->setUpBilling();
        Event::fake([DeploymentStatusChanged::class, DeploymentCreated::class]);
    }

    protected function tearDown(): void
    {
        $this->tearDownBilling();
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A queue-triggered project of 500m / 512 MiB, one pod at most, deployed (ready) on 1 October. */
    private function queueProject(KnativeRegion $region, bool $deployed = true): Project
    {
        $owner = $this->userWithRole('owner');
        $project = $this->billedProject('jobs', $region, $owner);
        ContainerConfig::query()->create([
            'project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '500m', 'memory_mb' => 512,
            'timeout_seconds' => 30, 'trigger_type' => 'queue', 'min_scale' => 0, 'max_scale' => 1, 'scale_to_zero' => true,
        ]);

        if ($deployed) {
            $version = Version::query()->create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
            $deployment = Deployment::query()->create([
                'project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active',
                'sizing' => ['region_id' => $region->id, 'cpu_m' => 500, 'memory_mb' => 512, 'trigger_type' => 'queue', 'min_scale' => 0, 'max_scale' => 1, 'scale_to_zero' => true, 'gpu_count' => 0],
            ]);
            $deployment->forceFill(['ready_at' => '2026-10-01 00:00:00', 'created_at' => '2026-10-01 00:00:00'])->saveQuietly();
        }

        return $project->fresh();
    }

    /** @return array<string, mixed> */
    private function region(KnativeRegion $region): array
    {
        $admin = $this->userWithRole('owner');

        return collect($this->actingAs($admin)->getJson('/api/billing/regions')->assertOk()->json('data'))->firstWhere('id', $region->id);
    }

    public function test_the_setting_is_off_by_default_set_per_region_and_switched_off_per_organization(): void
    {
        $admin = $this->userWithRole('owner');
        $region = $this->billingRegion();

        $row = $this->region($region);
        $this->assertFalse($row['charge_queue_consumers'], 'off unless set: an upgrade bills no consumer');
        $this->assertNull($row['queue_consumers_override']);
        $this->assertFalse($row['queue_consumers_charged']);

        $this->actingAs($admin)->putJson("/api/billing/regions/{$region->id}", ['charge_queue_consumers' => true])->assertOk()
            ->assertJsonPath('data.charge_queue_consumers', true)
            ->assertJsonPath('data.queue_consumers_charged', true)
            ->assertJsonPath('data.billing_type', 'payg');
        $this->assertTrue(RegionSetting::query()->find($region->id)->charge_queue_consumers);

        // The organization switches it off for itself in the region
        $this->actingAs($admin)->putJson("/api/billing/regions/{$region->id}/queue-consumers", ['charge' => false])->assertOk()
            ->assertJsonPath('data.charge_queue_consumers', true)
            ->assertJsonPath('data.queue_consumers_override', false)
            ->assertJsonPath('data.queue_consumers_charged', false);

        // Back to the region's setting
        $this->actingAs($admin)->putJson("/api/billing/regions/{$region->id}/queue-consumers", ['charge' => null])->assertOk()
            ->assertJsonPath('data.queue_consumers_override', null)
            ->assertJsonPath('data.queue_consumers_charged', true);
        $this->assertNull(RegionOverride::query()->find($region->id));

        // The organization cannot switch on what the region leaves off
        $this->actingAs($admin)->putJson("/api/billing/regions/{$region->id}", ['charge_queue_consumers' => false])->assertOk();
        $this->actingAs($admin)->putJson("/api/billing/regions/{$region->id}/queue-consumers", ['charge' => true])->assertOk()
            ->assertJsonPath('data.queue_consumers_charged', false);

        // A type change keeps the setting; a request with neither field is refused
        $this->actingAs($admin)->putJson("/api/billing/regions/{$region->id}", ['charge_queue_consumers' => true])->assertOk();
        $this->actingAs($admin)->putJson("/api/billing/regions/{$region->id}", ['billing_type' => 'fixed'])->assertOk()
            ->assertJsonPath('data.charge_queue_consumers', true);
        $this->actingAs($admin)->putJson("/api/billing/regions/{$region->id}", [])->assertStatus(422);
        $this->actingAs($admin)->putJson("/api/billing/regions/{$region->id}/queue-consumers", [])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/billing/regions/no-such-region/queue-consumers', ['charge' => false])->assertNotFound();

        // billing.view may read, not write (the built-in billing role manages billing: a reader role)
        $this->customRole('billing-reader', ['billing.view']);
        $viewer = $this->userWithRole('billing-reader');
        $this->actingAs($viewer)->putJson("/api/billing/regions/{$region->id}/queue-consumers", ['charge' => false])->assertForbidden();
    }

    public function test_a_queue_consumer_is_billed_as_its_own_line_only_where_it_is_charged(): void
    {
        $admin = $this->userWithRole('owner');
        $region = $this->billingRegion();
        $this->priceVersion($region->id, '2026-01-01', ['cpu_vcpu_hour' => 1.00, 'memory_gb_hour' => 0.10, 'multiplier_queue' => 1]);
        $project = $this->queueProject($region);

        // 5 October: 24 hours of the consumer — 50m × 24 h = 1.2 vCPU-hours, 64 MiB × 24 h = 1.5 GB-hours
        app(UsageAggregator::class)->aggregate('2026-10-05');
        $bill = fn () => app(BillCalculator::class)->forProject((string) $project->id, Period::fromKey('2026-10'));

        $this->assertSame([], $bill()['line_items'], 'off by default: metered, not billed');
        $this->assertEqualsWithDelta(0.0, $bill()['metered_cost'], 0.001);

        $this->actingAs($admin)->putJson("/api/billing/regions/{$region->id}", ['charge_queue_consumers' => true])->assertOk();
        $result = $bill();
        $lines = collect($result['line_items']);
        $this->assertCount(2, $lines);
        $this->assertSame(['queue_consumer'], $lines->pluck('component')->unique()->values()->all());
        $this->assertSame('queue', $lines->first()['entry_point']);
        $this->assertStringContainsString('queue consumer', $lines->firstWhere('metric', 'vcpu_hours')['description']);
        $this->assertEqualsWithDelta(1.2, $lines->firstWhere('metric', 'vcpu_hours')['quantity'], 1e-9);
        $this->assertEqualsWithDelta(1.5, $lines->firstWhere('metric', 'memory_gb_hours')['quantity'], 1e-9);
        $this->assertEqualsWithDelta(1.20 + 0.15, $result['total'], 0.001);

        // Switched off by the organization for the region: not billed again
        $this->actingAs($admin)->putJson("/api/billing/regions/{$region->id}/queue-consumers", ['charge' => false])->assertOk();
        $this->assertSame([], $bill()['line_items']);
    }

    public function test_a_fixed_allocation_holds_the_consumer_only_where_it_is_charged(): void
    {
        $admin = $this->userWithRole('owner');
        $region = $this->billingRegion('fx', 'fixed');
        $project = $this->queueProject($region, deployed: false);
        // One pod of 500m + 100m queue-proxy, 512 + 100 MiB: 600m / 612 MiB; with the consumer 650m / 676 MiB
        $this->allocation($project, 620, 640, '2026-11-01');

        $this->assertNull($this->refusal($project), 'off: the allocation holds the function only');

        $this->actingAs($admin)->putJson("/api/billing/regions/{$region->id}", ['charge_queue_consumers' => true])->assertOk();
        $refusal = $this->refusal($project->fresh());
        $this->assertSame('fixed_allocation_exceeded', $refusal['code'] ?? null);
        $this->assertStringContainsString('650', (string) ($refusal['message'] ?? ''), 'the consumer counts against the allocation');

        $this->actingAs($admin)->putJson("/api/billing/regions/{$region->id}/queue-consumers", ['charge' => false])->assertOk();
        $this->assertNull($this->refusal($project->fresh()), 'switched off for the organization: not deducted');
    }

    private function refusal(Project $project): ?array
    {
        try {
            ProjectAdmission::assertDeployable($project);
        } catch (HttpResponseException $e) {
            return $e->getResponse()->getData(true);
        }

        return null;
    }
}
