<?php

namespace Tests\Feature\Metering;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Jobs\Metering\UsageAggregationJob;
use App\Models\AiModel;
use App\Models\AiModelServiceUsage;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\DeploymentSession;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\UsageRecord;
use App\Models\Version;
use App\Services\Bindings\BindingTenancy;
use App\Services\GitDeploy\DeploymentStarter;
use App\Services\Metering\UsageAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * Metering in the core (docs/monitoring.md#metering): every run recalculates whole days of the
 * current organization context — with or without tenancy and billing, quantities only (the Billing
 * module prices them). A project's day is the sum over its deployments, each from what it really
 * ran: the sessions of scale-to-zero deployments, the reservation of always-on ones for the time
 * they were active with the size they were applied with. Every session and every second of a
 * reservation counts once however often the project is redeployed, re-runs give the same totals,
 * and the first-hour run for yesterday never uses today's state. Model services carry their
 * creator.
 */
class UsageAggregatorTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    private const DAY_MS = 86_400_000;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-11 12:00:00'));
        $this->region = KnativeRegion::create(['name' => 'fn', 'project_namespace' => 'fn', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        BindingTenancy::reset();
        parent::tearDown();
    }

    public function test_scale_to_zero_sessions_of_the_day_make_one_record_without_cost(): void
    {
        [$project, $deployment] = $this->project('on-demand', scaleToZero: true);

        $this->endedSession($project, $deployment, 60_000, cold: true);
        app(UsageAggregator::class)->aggregate('2026-10-11');
        $this->endedSession($project, $deployment, 30_000);
        app(UsageAggregator::class)->aggregate('2026-10-11');

        $record = UsageRecord::sole();
        $this->assertSame('2026-10-11', $record->record_date->toDateString());
        $this->assertSame('scale_to_zero', $record->scale_mode);
        $this->assertSame('http', $record->entry_point);
        $this->assertSame($deployment->id, $record->deployment_id);
        $this->assertSame(2, (int) $record->session_count);
        $this->assertSame(90_000, (int) $record->total_duration_ms);
        $this->assertSame(250 * 90_000, (int) $record->total_cpu_mc_ms, 'the size (250m) for the 90 s the pods ran');
        $this->assertSame(128 * 90_000, (int) $record->total_memory_mib_ms, '128 MiB for the 90 s');
        $this->assertSame(1, (int) $record->cold_start_count);
        $this->assertSame(0.0, (float) $record->final_cost, 'no money is calculated by the metering');
        $this->assertSame('USD', $record->currency);
        $this->assertNull($record->tenant_id);
    }

    public function test_an_always_on_workload_counts_its_reservation_while_it_was_active(): void
    {
        [$project, $deployment] = $this->project('always-on', scaleToZero: false, minScale: 2, readyAt: '2026-10-11 00:00:00');

        // Today until now (12:00): half a day
        app(UsageAggregator::class)->aggregate('2026-10-11');

        $record = UsageRecord::sole();
        $this->assertSame('custom_scale', $record->scale_mode);
        $this->assertSame(250, (int) $record->reserved_cpu_m);
        $this->assertSame(128, (int) $record->reserved_memory_mb);
        $this->assertSame(2, (int) $record->min_scale);
        $this->assertSame(2 * 250 * self::DAY_MS / 2, (int) $record->total_cpu_mc_ms);
        $this->assertSame(2 * 128 * self::DAY_MS / 2, (int) $record->total_memory_mib_ms);

        // The day once it is over: 24 hours
        Carbon::setTestNow('2026-10-12 00:10:00');
        app(UsageAggregator::class)->aggregate('2026-10-11');
        $this->assertSame(2 * 250 * self::DAY_MS, (int) UsageRecord::sole()->total_cpu_mc_ms);

        // A config change that was not deployed does not change what ran
        $deployment->forceFill(['sizing' => ['region_id' => $this->region->id, 'cpu_m' => 250, 'memory_mb' => 128, 'trigger_type' => 'http', 'min_scale' => 2, 'max_scale' => 2, 'scale_to_zero' => false, 'gpu_count' => 0]])->save();
        $project->containerConfig->update(['min_scale' => 3, 'cpu' => '1']);
        app(UsageAggregator::class)->aggregate('2026-10-11');
        $this->assertSame(2, (int) UsageRecord::sole()->min_scale);
        $this->assertSame(2 * 250 * self::DAY_MS, (int) UsageRecord::sole()->total_cpu_mc_ms);
    }

    public function test_a_timed_warmer_counts_the_containers_it_kept_warm_and_the_sessions_of_cold_stretches(): void
    {
        [$project, $deployment] = $this->project('warmed', scaleToZero: true, readyAt: '2026-10-11 00:00:00');
        // What the warmer applied to the PodAutoscaler (WarmScaleService's warm_log): nothing warm
        // from midnight, 3 from 02:00, 1 from 06:00, none again from 10:00
        $at = fn (string $time) => Carbon::parse("2026-10-11 {$time}")->getTimestamp();
        $deployment->forceFill(['sizing' => [
            'region_id' => $this->region->id, 'cpu_m' => 250, 'memory_mb' => 128, 'trigger_type' => 'http',
            'min_scale' => 3, 'max_scale' => 5, 'scale_to_zero' => true, 'gpu_count' => 0,
            'warm_log' => [[$at('00:00:00'), 0], [$at('02:00:00'), 3], [$at('06:00:00'), 1], [$at('10:00:00'), 0]],
        ]])->save();

        // 01:00 and 11:00: nothing was warm, the sessions count; 03:00: covered by the warm containers
        $this->endedSession($project, $deployment, 60_000, cold: true, startedAt: Carbon::parse('2026-10-11 01:00:00'));
        $this->endedSession($project, $deployment, 30_000, startedAt: Carbon::parse('2026-10-11 03:00:00'));
        $this->endedSession($project, $deployment, 15_000, cold: true, startedAt: Carbon::parse('2026-10-11 11:00:00'));

        // Today until now (12:00)
        app(UsageAggregator::class)->aggregate('2026-10-11');

        $hourMs = 3_600_000;
        $podMs = 4 * $hourMs * 3 + 4 * $hourMs * 1;
        $warm = UsageRecord::query()->where('scale_mode', 'custom_scale')->sole();
        $this->assertSame(3, (int) $warm->min_scale, 'the most it kept warm');
        $this->assertSame($podMs, (int) $warm->total_duration_ms);
        $this->assertSame($podMs * 250, (int) $warm->total_cpu_mc_ms);
        $this->assertSame($podMs * 128, (int) $warm->total_memory_mib_ms);
        $this->assertSame(0, (int) $warm->session_count);

        $cold = UsageRecord::query()->where('scale_mode', 'scale_to_zero')->sole();
        $this->assertSame(2, (int) $cold->session_count);
        $this->assertSame(75_000, (int) $cold->total_duration_ms);
        $this->assertSame(2, (int) $cold->cold_start_count);

        // A re-run gives the same totals
        app(UsageAggregator::class)->aggregate('2026-10-11');
        $this->assertSame($podMs, (int) UsageRecord::query()->where('scale_mode', 'custom_scale')->sole()->total_duration_ms);
    }

    public function test_a_redeploy_counts_the_sessions_of_the_day_once(): void
    {
        [$project, $first] = $this->project('redeployed', scaleToZero: true);
        $this->endedSession($project, $first, 60_000, startedAt: Carbon::parse('2026-10-11 11:00:00'));
        app(UsageAggregator::class)->aggregate('2026-10-11');

        // Redeploy, then a change of region, scale mode and trigger: four deployments in a day
        Carbon::setTestNow('2026-10-11 13:00:00');
        $first->update(['status' => 'shut_down']);
        $second = $this->deploy($project, 'active');
        app(UsageAggregator::class)->aggregate('2026-10-11');
        $this->endedSession($project, $second, 30_000, startedAt: Carbon::parse('2026-10-11 13:30:00'));

        Carbon::setTestNow('2026-10-11 14:00:00');
        $other = KnativeRegion::create(['name' => 'fn2', 'project_namespace' => 'fn2', 'is_active' => true]);
        $project->forceFill(['knative_region_id' => $other->id])->saveQuietly();
        $project->containerConfig->update(['trigger_type' => 'queue']);
        $second->update(['status' => 'shut_down']);
        $third = $this->deploy($project, 'active');
        app(UsageAggregator::class)->aggregate('2026-10-11');
        app(UsageAggregator::class)->aggregate('2026-10-11');

        $this->assertSame(250 * (60_000 + 30_000), (int) UsageRecord::sum('total_cpu_mc_ms'), 'each session once');
        $this->assertSame(2, (int) UsageRecord::sum('session_count'));
        $this->assertSame(2, UsageRecord::count());
        $this->assertFalse(UsageRecord::query()->where('deployment_id', $third->id)->exists(), 'the new deployment ran no session yet');
    }

    public function test_always_on_deployments_of_a_day_count_their_own_time_and_size(): void
    {
        Carbon::setTestNow('2026-10-12 00:30:00');
        [$project, $small] = $this->project('resized', scaleToZero: false, minScale: 1, readyAt: '2026-10-10 08:00:00');
        $small->forceFill(['sizing' => $this->sizing(500, 512, minScale: 1)])->save();

        // 2026-10-11 06:00 a redeploy with 1 vCPU and two pods; at 18:00 one in another region
        $small->forceFill(['status' => 'shut_down', 'ended_at' => '2026-10-11 06:00:00'])->saveQuietly();
        $big = $this->deploy($project, 'shut_down', readyAt: '2026-10-11 06:00:00', createdAt: '2026-10-11 05:59:00');
        $big->forceFill(['ended_at' => '2026-10-11 18:00:00', 'sizing' => $this->sizing(1000, 1024, minScale: 2)])->saveQuietly();
        $other = KnativeRegion::create(['name' => 'fn2', 'project_namespace' => 'fn2', 'is_active' => true]);
        $moved = $this->deploy($project, 'active', readyAt: '2026-10-11 18:00:00', createdAt: '2026-10-11 17:59:00');
        $moved->forceFill(['sizing' => $this->sizing(500, 512, minScale: 1, regionId: $other->id)])->saveQuietly();

        (new UsageAggregationJob('2026-10-12 00:30:00'))->handle(app(UsageAggregator::class));

        $day = UsageRecord::query()->whereDate('record_date', '2026-10-11')->get()->keyBy('deployment_id');
        $hour = 3_600_000;
        $this->assertSame(500 * 6 * $hour, (int) $day[$small->id]->total_cpu_mc_ms, '00:00–06:00 at 500m');
        $this->assertSame(2 * 1000 * 12 * $hour, (int) $day[$big->id]->total_cpu_mc_ms, '06:00–18:00 at 2 × 1000m');
        $this->assertSame(500 * 6 * $hour, (int) $day[$moved->id]->total_cpu_mc_ms, '18:00–24:00 at 500m');
        $this->assertSame($other->id, $day[$moved->id]->region_id);
        $this->assertSame(1000, (int) $day[$big->id]->reserved_cpu_m);

        // Today so far: only the deployment that runs now, for 30 minutes
        $today = UsageRecord::query()->whereDate('record_date', '2026-10-12')->sole();
        $this->assertSame($moved->id, $today->deployment_id);
        $this->assertSame(500 * $hour / 2, (int) $today->total_cpu_mc_ms);

        // Re-running gives the same totals
        $before = (int) UsageRecord::sum('total_cpu_mc_ms');
        (new UsageAggregationJob('2026-10-12 00:30:00'))->handle(app(UsageAggregator::class));
        $this->assertSame($before, (int) UsageRecord::sum('total_cpu_mc_ms'));
    }

    public function test_a_redeploy_while_the_first_deploy_is_pending_meters_one_reservation(): void
    {
        Event::fake([DeploymentCreated::class, DeploymentStatusChanged::class]);
        [$project] = $this->project('pending-redeploy', scaleToZero: false, minScale: 1);
        Deployment::query()->where('project_id', $project->id)->delete();
        $version = Version::query()->where('project_id', $project->id)->firstOrFail();

        // The first deploy is still pending (its readiness check polls the shared Knative Service)
        Carbon::setTestNow('2026-10-11 06:00:00');
        $first = app(DeploymentStarter::class)->start($project, $version, 'dev');
        Carbon::setTestNow('2026-10-11 06:01:00');
        $second = app(DeploymentStarter::class)->start($project->fresh(), $version, 'dev');

        // Both readiness checks see the (shared) service ready: only the second goes live
        Carbon::setTestNow('2026-10-11 06:02:00');
        $this->assertFalse($first->fresh()->transition(['pending', 'running'], ['status' => 'active', 'ready_at' => now()]));
        $this->assertTrue($second->fresh()->transition(['pending', 'running'], ['status' => 'active', 'ready_at' => now()]));

        Carbon::setTestNow('2026-10-11 12:02:00');
        app(UsageAggregator::class)->aggregate('2026-10-11');

        $record = UsageRecord::query()->where('project_id', $project->id)->sole();
        $this->assertSame($second->id, $record->deployment_id);
        $this->assertSame(250 * 6 * 3_600_000, (int) $record->total_cpu_mc_ms, '6 h of one pod, counted once');
    }

    public function test_two_live_always_on_deployments_of_a_region_are_not_metered_twice(): void
    {
        // Rows as two deploys at once (or an earlier release) left them: both active
        [$project, $older] = $this->project('twice', scaleToZero: false, minScale: 1, readyAt: '2026-10-11 06:00:00');
        $older->forceFill(['sizing' => $this->sizing(250, 128, minScale: 1)])->saveQuietly();
        $newer = $this->deploy($project, 'active', readyAt: '2026-10-11 08:00:00');
        $newer->forceFill(['sizing' => $this->sizing(500, 128, minScale: 1)])->saveQuietly();
        // One in another region runs on its own
        $other = KnativeRegion::create(['name' => 'fn2', 'project_namespace' => 'fn2', 'is_active' => true]);
        $elsewhere = $this->deploy($project, 'active', readyAt: '2026-10-11 09:00:00');
        $elsewhere->forceFill(['sizing' => $this->sizing(250, 128, minScale: 1, regionId: $other->id)])->saveQuietly();

        app(UsageAggregator::class)->aggregate('2026-10-11');

        $day = UsageRecord::query()->get()->keyBy('deployment_id');
        $hour = 3_600_000;
        $this->assertSame(250 * 2 * $hour, (int) $day[$older->id]->total_cpu_mc_ms, '06:00–08:00: the newer one runs from 08:00');
        $this->assertSame(500 * 4 * $hour, (int) $day[$newer->id]->total_cpu_mc_ms, '08:00–12:00');
        $this->assertSame(250 * 3 * $hour, (int) $day[$elsewhere->id]->total_cpu_mc_ms, '09:00–12:00 in the other region');
    }

    public function test_a_project_first_deployed_after_midnight_has_no_usage_yesterday(): void
    {
        Carbon::setTestNow('2026-10-12 00:20:00');
        $this->project('new', scaleToZero: false, minScale: 1, cpu: '1', memoryMb: 1024);

        Carbon::setTestNow('2026-10-12 00:25:00');
        BindingTenancy::setRunner(fn (callable $cb) => $cb());
        (new UsageAggregationJob('2026-10-12 00:25:00'))->handle(app(UsageAggregator::class));

        $this->assertSame(['2026-10-12'], UsageRecord::orderBy('record_date')->get()->map(fn ($r) => $r->record_date->toDateString())->all());
    }

    public function test_a_resize_after_midnight_does_not_change_yesterday(): void
    {
        Carbon::setTestNow('2026-10-12 00:15:00');
        [$project, $old] = $this->project('resize', scaleToZero: false, minScale: 1, readyAt: '2026-10-10 00:00:00');
        $old->forceFill(['sizing' => $this->sizing(250, 128, minScale: 1)])->save();
        // 00:05 today: config and deploy at 4 vCPU × 3
        $project->containerConfig->update(['cpu' => '4', 'min_scale' => 3]);
        $old->forceFill(['status' => 'shut_down', 'ended_at' => '2026-10-12 00:05:00'])->saveQuietly();
        $new = $this->deploy($project, 'active', readyAt: '2026-10-12 00:05:00', createdAt: '2026-10-12 00:04:00');
        $new->forceFill(['sizing' => $this->sizing(4000, 128, minScale: 3)])->saveQuietly();

        (new UsageAggregationJob('2026-10-12 00:15:00'))->handle(app(UsageAggregator::class));

        $yesterday = UsageRecord::query()->whereDate('record_date', '2026-10-11')->sole();
        $this->assertSame($old->id, $yesterday->deployment_id);
        $this->assertSame(250 * self::DAY_MS, (int) $yesterday->total_cpu_mc_ms, 'yesterday ran 250m all day');
        $this->assertSame(1, (int) $yesterday->min_scale);
    }

    public function test_a_recalculated_day_replaces_older_records_of_existing_deployments_and_keeps_deleted_ones(): void
    {
        [$project, $deployment] = $this->project('legacy', scaleToZero: true);
        $this->endedSession($project, $deployment, 60_000);
        // Rows of 0.5.7: the day's sessions counted again on another (existing) deployment's row,
        // and the row of a deployment that was deleted since
        $existing = $this->deploy($project, 'shut_down', readyAt: '2026-10-11 01:00:00', createdAt: '2026-10-11 01:00:00');
        $existing->forceFill(['ended_at' => '2026-10-11 02:00:00'])->saveQuietly();
        $this->legacyRow($project, $existing->id, 1000);
        $this->legacyRow($project, $deployment->id, 1000);
        $deleted = $this->legacyRow($project, (string) Str::ulid(), 5000);

        app(UsageAggregator::class)->aggregate('2026-10-11');

        $this->assertSame([$deleted->id], UsageRecord::query()->whereKeyNot(UsageRecord::query()->where('deployment_id', $deployment->id)->value('id'))->pluck('id')->all());
        $this->assertSame(250 * 60_000 + 5000, (int) UsageRecord::sum('total_cpu_mc_ms'), 'the session at 250m, the deleted deployment\'s row as metered');
    }

    public function test_earlier_days_are_recalculated_on_demand(): void
    {
        [$project, $deployment] = $this->project('history', scaleToZero: true);
        $this->endedSession($project, $deployment, 60_000, startedAt: Carbon::parse('2026-10-09 10:00:00'));
        // 0.5.7 counted the day's session again on a second deployment's row
        $other = $this->deploy($project, 'shut_down', readyAt: '2026-10-09 09:00:00');
        $this->legacyRow($project, $other->id, 1000)->forceFill(['record_date' => '2026-10-09'])->save();
        $this->legacyRow($project, $deployment->id, 1000)->forceFill(['record_date' => '2026-10-09'])->save();

        $this->artisan('usage:recalculate', ['from' => '2026-10-09', 'to' => '2026-10-10'])->assertSuccessful();

        $this->assertSame(250 * 60_000, (int) UsageRecord::query()->whereDate('record_date', '2026-10-09')->sum('total_cpu_mc_ms'));
        $this->artisan('usage:recalculate', ['from' => '2026-10-12'])->assertExitCode(2);
        $this->artisan('usage:recalculate', ['from' => 'yesterday-ish'])->assertExitCode(2);
    }

    public function test_recalculating_a_pre_upgrade_day_keeps_the_scale_mode_0_5_7_recorded(): void
    {
        Carbon::setTestNow('2026-10-19 12:00:00');
        // Today's config: always on, 2 pods of 1 vCPU (changed later, the deployment of 1 October
        // by 0.5.7 recorded no size and ran scale to zero)
        [$project, $deployment] = $this->project('pre-upgrade', scaleToZero: false, minScale: 2, cpu: '1', memoryMb: 1024, readyAt: '2026-10-01 00:00:00');
        $this->endedSession($project, $deployment, 60_000, startedAt: Carbon::parse('2026-10-05 10:00:00'));
        $this->legacyRow($project, $deployment->id, 1000)->forceFill(['record_date' => '2026-10-05'])->save();

        app(UsageAggregator::class)->aggregate('2026-10-05');

        $row = UsageRecord::query()->whereDate('record_date', '2026-10-05')->sole();
        $this->assertSame('scale_to_zero', $row->scale_mode, 'not an always-on reservation of today\'s config');
        $this->assertSame(1, (int) $row->session_count);
        // The deployment recorded no size: its session counts with its project's (1 vCPU)
        $this->assertSame(1000 * 60_000, (int) $row->total_cpu_mc_ms, 'the day\'s session, not 2 × 1 vCPU × 24 h');
    }

    public function test_a_pre_upgrade_always_on_day_keeps_its_recorded_size_for_the_time_it_ran(): void
    {
        Carbon::setTestNow('2026-10-19 12:00:00');
        [$project, $old] = $this->project('pre-upgrade-reserved', scaleToZero: false, minScale: 3, cpu: '1', readyAt: '2026-10-01 00:00:00');
        // 5 October: replaced at 11:58 by a deployment that ran two minutes (between two 0.5.7 runs,
        // so it has no row), then by the one of 12:00. 0.5.7 rows: the reservation only, 24 h each
        $old->forceFill(['status' => 'shut_down', 'ended_at' => '2026-10-05 11:58:00'])->saveQuietly();
        $sliver = $this->deploy($project, 'shut_down', readyAt: '2026-10-05 11:58:00');
        $sliver->forceFill(['ended_at' => '2026-10-05 12:00:00'])->saveQuietly();
        $new = $this->deploy($project, 'active', readyAt: '2026-10-05 12:00:00');
        $this->legacyReservation($project, $old->id, '2026-10-05', cpuM: 500, memoryMb: 256, minScale: 2);
        $this->legacyReservation($project, $new->id, '2026-10-05', cpuM: 500, memoryMb: 256, minScale: 2);

        app(UsageAggregator::class)->aggregate('2026-10-05');

        $day = UsageRecord::query()->whereDate('record_date', '2026-10-05')->get()->keyBy('deployment_id');
        $minute = 60_000;
        $this->assertEqualsCanonicalizing([$old->id, $new->id], $day->keys()->all(), 'no reservation is made up for the two minutes');
        $this->assertSame(2 * 500 * (12 * 60 - 2) * $minute, (int) $day[$old->id]->total_cpu_mc_ms, '00:00–11:58 at 2 × 500m, not today\'s 3 × 1 vCPU');
        $this->assertSame(2 * 500 * 12 * 60 * $minute, (int) $day[$new->id]->total_cpu_mc_ms, '12:00–24:00');
        $this->assertSame(2, (int) $day[$new->id]->min_scale);
        $this->assertSame(500, (int) $day[$new->id]->reserved_cpu_m);
        $this->assertSame(256, (int) $day[$new->id]->reserved_memory_mb);

        // An earlier day nobody metered: no reservation from today's config either
        app(UsageAggregator::class)->aggregate('2026-10-03');
        $this->assertSame(0, UsageRecord::query()->whereDate('record_date', '2026-10-03')->count());
    }

    public function test_a_deleted_deployments_0_5_7_full_day_keeps_only_the_time_the_others_did_not_run(): void
    {
        Carbon::setTestNow('2026-10-19 12:00:00');
        // 5 October: the deployment that ran until 14:00 was deleted since; 0.5.7 billed both 24 hours
        [$project, $new] = $this->project('deleted-old', scaleToZero: false, minScale: 2, readyAt: '2026-10-05 14:00:00');
        $this->legacyReservation($project, $new->id, '2026-10-05', cpuM: 500, memoryMb: 256, minScale: 2);
        $deleted = $this->legacyReservation($project, (string) Str::ulid(), '2026-10-05', cpuM: 500, memoryMb: 256, minScale: 2);
        // Other rows of deleted deployments stay as metered: sessions, and a reservation of 0.5.8
        $sessions = $this->legacyRow($project, (string) Str::ulid(), 7000)->forceFill(['record_date' => '2026-10-05']);
        $sessions->save();
        $metered = $this->legacyReservation($project, (string) Str::ulid(), '2026-10-05', cpuM: 500, memoryMb: 256, minScale: 1)
            ->forceFill(['total_duration_ms' => 60_000, 'total_cpu_mc_ms' => 500 * 60_000, 'total_memory_mib_ms' => 256 * 60_000]);
        $metered->save();

        app(UsageAggregator::class)->aggregate('2026-10-05');

        $hour = 3_600_000;
        $this->assertSame(2 * 500 * 10 * $hour, (int) UsageRecord::query()->where('deployment_id', $new->id)->sole()->total_cpu_mc_ms, '14:00–24:00');
        $trimmed = $deleted->fresh();
        $this->assertSame(2 * 500 * 14 * $hour, (int) $trimmed->total_cpu_mc_ms, 'the 14 hours before, not 24 again');
        $this->assertSame(2 * 256 * 14 * $hour, (int) $trimmed->total_memory_mib_ms);
        $this->assertSame(7000, (int) $sessions->fresh()->total_cpu_mc_ms);
        $this->assertSame(500 * 60_000, (int) $metered->fresh()->total_cpu_mc_ms);

        // Recalculating again gives the same totals
        $before = (int) UsageRecord::sum('total_cpu_mc_ms');
        app(UsageAggregator::class)->aggregate('2026-10-05');
        $this->assertSame($before, (int) UsageRecord::sum('total_cpu_mc_ms'));
    }

    public function test_min_scale_keeps_a_scale_to_zero_workload_always_on(): void
    {
        [$project, $deployment] = $this->project('warm', scaleToZero: true, minScale: 1, readyAt: '2026-10-11 06:00:00');
        $this->endedSession($project, $deployment, 60_000);

        app(UsageAggregator::class)->aggregate('2026-10-11');

        $record = UsageRecord::sole();
        $this->assertSame('custom_scale', $record->scale_mode, 'min_scale >= 1 keeps a pod running: its reservation is metered');
        $this->assertSame(250 * 6 * 3_600_000, (int) $record->total_cpu_mc_ms);
        $this->assertSame(0, (int) $record->session_count, 'its sessions are covered by the reservation');
    }

    public function test_model_services_are_metered_with_their_creator(): void
    {
        $model = AiModel::query()->firstOrCreate(['name' => 'llm'], ['source' => 'hub', 'hub_id' => 'acme/llm', 'task' => 'text-generation']);
        $service = $this->modelService($model, $this->region, ['engine' => 'vllm', 'device' => 'cpu', 'cpu' => '2', 'memory_mb' => 4096, 'created_by' => 'user-42']);
        AiModelServiceUsage::create([
            'ai_model_service_id' => $service->id, 'knative_region_id' => $this->region->id, 'record_date' => '2026-10-11',
            'replica_ms' => 3_600_000, 'gpu_unit_ms' => 0, 'cpu_mc_ms' => 7_200_000_000, 'memory_mib_ms' => 4096 * 3_600_000,
        ]);

        app(UsageAggregator::class)->aggregate('2026-10-11');

        $record = UsageRecord::sole();
        $this->assertSame('model', $record->source);
        $this->assertSame($service->id, $record->project_id);
        $this->assertSame('user-42', $record->created_by, 'usage of no project is billed to its creator');
        $this->assertSame(7_200_000_000, (int) $record->total_cpu_mc_ms);
    }

    public function test_the_job_meters_every_context_and_finishes_yesterday_in_the_first_hour(): void
    {
        [$project, $deployment] = $this->project('on-demand', scaleToZero: true);
        $this->endedSession($project, $deployment, 10_000, startedAt: Carbon::parse('2026-10-11 23:50:00'));
        $this->endedSession($project, $deployment, 20_000, startedAt: Carbon::parse('2026-10-12 00:01:00'));

        $contexts = 0;
        BindingTenancy::setRunner(function (callable $callback) use (&$contexts) {
            $contexts++;
            $callback();
        });

        (new UsageAggregationJob('2026-10-12 00:10:00'))->handle(app(UsageAggregator::class));

        $this->assertSame(1, $contexts);
        $this->assertSame(
            ['2026-10-11' => 10_000, '2026-10-12' => 20_000],
            UsageRecord::orderBy('record_date')->get()->mapWithKeys(fn ($r) => [$r->record_date->toDateString() => (int) $r->total_duration_ms])->all(),
        );
    }

    public function test_a_deployment_records_when_it_stops_running(): void
    {
        [, $deployment] = $this->project('ending', scaleToZero: true);
        $this->assertNull($deployment->ended_at);

        Carbon::setTestNow('2026-10-11 15:00:00');
        $deployment->update(['status' => 'shutting_down']);
        $this->assertSame('2026-10-11 15:00:00', $deployment->fresh()->ended_at->toDateTimeString());

        // Shut down later: the time it stopped running stays
        Carbon::setTestNow('2026-10-11 15:01:00');
        $deployment->update(['status' => 'shut_down']);
        $this->assertSame('2026-10-11 15:00:00', $deployment->fresh()->ended_at->toDateTimeString());

        // Live again (set by hand): open again
        $deployment->update(['status' => 'active']);
        $this->assertNull($deployment->fresh()->ended_at);
    }

    public function test_a_status_change_only_applies_while_the_deployment_is_still_in_the_expected_state(): void
    {
        [, $deployment] = $this->project('race', scaleToZero: true);
        $stale = Deployment::query()->findOrFail($deployment->id);
        $deployment->update(['status' => 'shutting_down']);

        $this->assertFalse($stale->transition(['pending', 'running', 'active'], ['status' => 'active']));
        $this->assertSame('shutting_down', $stale->status, 'the model is read back');
        $this->assertTrue($stale->transition(['shutting_down'], ['status' => 'shut_down']));
        $this->assertSame('shut_down', $deployment->fresh()->status);
        $this->assertNotNull($deployment->fresh()->ended_at);
    }

    public function test_the_currency_is_the_install_billing_currency(): void
    {
        config(['billing.currency' => 'eur']);
        $this->assertSame('EUR', UsageAggregator::currency());

        config(['billing.currency' => 'not-a-currency']);
        $this->assertSame('USD', UsageAggregator::currency());
    }

    public function test_mirror_does_nothing_outside_a_tenant(): void
    {
        $this->project('always-on', scaleToZero: false, minScale: 1, readyAt: '2026-10-11 08:00:00');
        app(UsageAggregator::class)->aggregate('2026-10-11');

        $this->assertSame(0, app(UsageAggregator::class)->mirror('2026-10-11'));
        $this->assertSame(1, UsageRecord::count());
    }

    /**
     * Environments (docs/environments.md): a project's day covers every environment's deployment,
     * each record names its environment, and one environment's deployment never ends another's
     * time in the same region.
     */
    public function test_environments_are_metered_side_by_side_and_named(): void
    {
        [$project, $production] = $this->project('envs', scaleToZero: false, minScale: 1, readyAt: '2026-10-11 06:00:00');
        $production->forceFill(['sizing' => $this->sizing(250, 128, minScale: 1)])->saveQuietly();
        $staging = ProjectEnvironment::query()->create(['project_id' => $project->id, 'name' => 'staging', 'workload_name' => 'envs-staging']);
        $stagingDeployment = $this->deploy($project, 'active', readyAt: '2026-10-11 08:00:00');
        $stagingDeployment->forceFill(['environment_id' => $staging->id, 'sizing' => $this->sizing(500, 128, minScale: 1)])->saveQuietly();

        app(UsageAggregator::class)->aggregate('2026-10-11');

        $day = UsageRecord::query()->get()->keyBy('deployment_id');
        $hour = 3_600_000;
        $this->assertSame(250 * 6 * $hour, (int) $day[$production->id]->total_cpu_mc_ms, '06:00–12:00: staging does not replace production');
        $this->assertSame(500 * 4 * $hour, (int) $day[$stagingDeployment->id]->total_cpu_mc_ms, '08:00–12:00');
        $this->assertNull($day[$production->id]->environment_id);
        $this->assertSame($staging->id, $day[$stagingDeployment->id]->environment_id);
        // The name too, so a bill names the environment after it is deleted
        $this->assertNull($day[$production->id]->environment_name);
        $this->assertSame('staging', $day[$stagingDeployment->id]->environment_name);

        // Deleted since (its deployments with it): a recalculation keeps the record and its name
        $staging->delete();
        app(UsageAggregator::class)->aggregate('2026-10-11');
        $this->assertSame('staging', UsageRecord::query()->where('environment_id', $staging->id)->value('environment_name'));
    }

    /** @return array{Project, Deployment} */
    private function project(string $name, bool $scaleToZero, int $minScale = 0, ?string $readyAt = null, string $cpu = '250m', int $memoryMb = 128): array
    {
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        ContainerConfig::create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'cpu' => $cpu,
            'memory_mb' => $memoryMb,
            'trigger_type' => 'http',
            'scale_to_zero' => $scaleToZero,
            'min_scale' => $minScale,
        ]);

        return [$project->fresh(), $this->deploy($project, 'active', readyAt: $readyAt)];
    }

    private function deploy(Project $project, string $status, ?string $readyAt = null, ?string $createdAt = null): Deployment
    {
        $version = Version::query()->firstOrCreate(['project_id' => $project->id, 'number' => 1], ['created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = $project->deployments()->create(['version_id' => $version->id, 'status' => $status, 'k8s_job_name' => "run-{$project->slug}-v1", 'deployed_by' => 'dev']);
        $deployment->forceFill(array_filter(['ready_at' => $readyAt, 'created_at' => $createdAt ?? $readyAt]))->saveQuietly();

        return $deployment->fresh();
    }

    /** @return array<string, mixed> */
    private function sizing(int $cpuM, int $memoryMb, int $minScale, ?string $regionId = null): array
    {
        return [
            'region_id' => $regionId ?? $this->region->id, 'cpu_m' => $cpuM, 'memory_mb' => $memoryMb, 'trigger_type' => 'http',
            'min_scale' => $minScale, 'max_scale' => max(1, $minScale), 'scale_to_zero' => false, 'gpu_count' => 0,
        ];
    }

    private function legacyRow(Project $project, string $deploymentId, int $cpuMcMs): UsageRecord
    {
        $id = (string) Str::ulid();
        DB::table('usage_records')->insert([
            'id' => $id, 'project_id' => $project->id, 'deployment_id' => $deploymentId, 'region_id' => $this->region->id,
            'record_date' => '2026-10-11', 'scale_mode' => 'scale_to_zero', 'entry_point' => 'http', 'session_count' => 1,
            'total_cpu_mc_ms' => $cpuMcMs, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return UsageRecord::query()->findOrFail($id);
    }

    /** A 0.5.7 always-on row: the reserved size and min_scale only (UsageReader prices it as 24 hours). */
    private function legacyReservation(Project $project, string $deploymentId, string $date, int $cpuM, int $memoryMb, int $minScale): UsageRecord
    {
        $id = (string) Str::ulid();
        DB::table('usage_records')->insert([
            'id' => $id, 'project_id' => $project->id, 'deployment_id' => $deploymentId, 'region_id' => $this->region->id,
            'record_date' => $date, 'scale_mode' => 'custom_scale', 'entry_point' => 'http', 'session_count' => 0,
            'reserved_cpu_m' => $cpuM, 'reserved_memory_mb' => $memoryMb, 'min_scale' => $minScale,
            'total_cpu_mc_ms' => 0, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return UsageRecord::query()->findOrFail($id);
    }

    /** An ended session as the agent reports it: no CPU or memory figures (metering counts the size). */
    private function endedSession(Project $project, Deployment $deployment, int $ms, bool $cold = false, ?Carbon $startedAt = null): void
    {
        $startedAt ??= now()->subMinutes(2);

        DeploymentSession::create([
            'project_id' => $project->id, 'deployment_id' => $deployment->id, 'region_id' => $this->region->id,
            'entry_point' => 'http', 'started_at' => $startedAt, 'ended_at' => $startedAt->copy()->addMilliseconds($ms),
            'duration_ms' => $ms, 'cold_start' => $cold,
        ]);
    }
}
