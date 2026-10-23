<?php

namespace Tests\Feature\Projects;

use App\Models\KnativeRegion;
use App\Models\User;
use App\Support\Projects\WarmEstimates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * POST /api/scale-schedule/preview (ScaleScheduleController): a timed warmer before it is saved —
 * its week in the organization's zone, the value now, and in a pay-as-you-go region an estimate
 * of its next 30 days (docs/scaling.md#timed-warmer). Wednesday 2026-10-21 10:00 UTC = 13:00 in
 * Istanbul.
 */
class ScaleSchedulePreviewTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private const OFFICE = [
        ['cron' => '0 9 * * 1-5', 'min_scale' => 3],
        ['cron' => '0 18 * * 1-5', 'min_scale' => 0],
    ];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', 'UTC'));
        $this->owner = $this->userWithRole('owner');
    }

    protected function tearDown(): void
    {
        WarmEstimates::priceWith(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function preview(array $payload): TestResponse
    {
        return $this->signedIn($this->owner)->postJson('/api/scale-schedule/preview', $payload);
    }

    public function test_the_week_and_the_value_now_in_the_organization_zone(): void
    {
        $res = $this->preview(['scale_schedule' => self::OFFICE, 'max_scale' => 5])
            ->assertOk()
            ->assertJsonPath('timezone', 'Europe/Istanbul')
            ->assertJsonPath('week_start', '2026-10-19T00:00:00+03:00')
            ->assertJsonPath('now', 3)
            ->assertJsonPath('peak', 3)
            ->assertJsonPath('estimate', null);

        $timeline = $res->json('timeline');
        $this->assertCount(7, $timeline);
        $this->assertSame([0, 3, 3, 0], [$timeline[0][8], $timeline[0][9], $timeline[0][17], $timeline[0][18]]);
        $this->assertSame(array_fill(0, 24, 0), $timeline[6]);
    }

    public function test_scale_to_zero_off_shows_the_one_container_it_keeps(): void
    {
        $res = $this->preview(['scale_schedule' => self::OFFICE, 'max_scale' => 5, 'scale_to_zero' => false])->assertOk();

        $this->assertSame(1, $res->json('timeline.0.8'));
    }

    public function test_rows_are_validated_as_on_a_project(): void
    {
        $this->preview(['scale_schedule' => [['cron' => '@daily', 'min_scale' => 1]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scale_schedule.0.cron']);

        $this->preview(['scale_schedule' => [['cron' => '0 9 * * *', 'min_scale' => 4]], 'max_scale' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scale_schedule.0.min_scale']);
    }

    public function test_a_pay_as_you_go_region_gets_an_estimate_of_the_warm_hours(): void
    {
        $region = KnativeRegion::create(['name' => 'fn', 'project_namespace' => 'fn', 'is_active' => true, 'is_demo' => true]);
        $asked = null;
        WarmEstimates::priceWith(function (?string $regionId, float $vcpuHours, float $memoryGbHours, string $trigger) use (&$asked) {
            $asked = [$regionId, $vcpuHours, $memoryGbHours, $trigger];

            return ['billing_type' => 'payg', 'cost' => 6.31, 'currency' => 'USD'];
        });

        // 30 days from Wednesday 13:00: 198 office hours × 3 containers
        $this->preview([
            'scale_schedule' => self::OFFICE, 'max_scale' => 5, 'knative_region_id' => $region->id,
            'cpu' => '250m', 'memory_mb' => 128, 'trigger_type' => 'http',
        ])
            ->assertOk()
            ->assertJsonPath('estimate.billing_type', 'payg')
            ->assertJsonPath('estimate.days', 30)
            ->assertJsonPath('estimate.container_hours', 594)
            ->assertJsonPath('estimate.vcpu_hours', 148.5)
            ->assertJsonPath('estimate.memory_gb_hours', 74.3)
            ->assertJsonPath('estimate.cost', 6.31)
            ->assertJsonPath('estimate.currency', 'USD');

        $this->assertSame([$region->id, 148.5, 74.25, 'http'], $asked);
    }

    public function test_a_fixed_region_gets_no_estimate(): void
    {
        WarmEstimates::priceWith(fn () => ['billing_type' => 'fixed', 'cost' => null, 'currency' => null]);

        $this->preview(['scale_schedule' => self::OFFICE, 'max_scale' => 5])
            ->assertOk()
            ->assertJsonPath('estimate', null);
    }
}
