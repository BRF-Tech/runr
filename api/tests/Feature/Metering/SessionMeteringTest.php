<?php

namespace Tests\Feature\Metering;

use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\DeploymentSession;
use App\Models\Git\PreviewEnvironment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\UsageRecord;
use App\Models\Version;
use App\Services\AgentMessageHandler;
use App\Services\GitDeploy\PreviewManifests;
use App\Services\Metering\UsageAggregator;
use App\Services\SessionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * From the region agent's session events to the day's usage record
 * (docs/billing.md#metering-and-the-fixed-bill). The agent reports when a pod of a scale-to-zero
 * workload or of a CronJob run starts and ends — with no CPU or memory figures, in no version of
 * it. Metering counts the size the deployment was applied with for the time the pod ran (CPU,
 * memory and GPUs alike), under the deployment's trigger type, on each day for the part of the
 * session that ran on it.
 */
class SessionMeteringTest extends TestCase
{
    use RefreshDatabase;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-11 10:00:00');
        $this->region = KnativeRegion::create(['name' => 'fn', 'project_namespace' => 'fn', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sessions_reported_without_figures_are_metered_at_the_deployments_size(): void
    {
        [$project] = $this->workload('orders', cpuM: 500, memoryMb: 1024);

        // Two pods: one from 10:00:00, a second one from 10:00:30, both gone at 10:01:30
        $this->agent('session_start', $project, 'orders-00001-deployment-6b8f9c7d4-xk9pq');
        Carbon::setTestNow('2026-10-11 10:00:30');
        $this->agent('session_start', $project, 'orders-00001-deployment-6b8f9c7d4-bcdfg');
        Carbon::setTestNow('2026-10-11 10:01:30');
        $this->agent('session_end', $project, 'orders-00001-deployment-6b8f9c7d4-xk9pq', ['duration_ms' => 90_000]);
        $this->agent('session_end', $project, 'orders-00001-deployment-6b8f9c7d4-bcdfg', ['duration_ms' => 60_000]);

        $sessions = DeploymentSession::query()->orderBy('started_at')->get();
        $this->assertSame([90_000, 60_000], $sessions->pluck('duration_ms')->all());
        $this->assertSame([null, null], $sessions->pluck('cpu_used_ms')->all(), 'the agent reports no figures');

        app(UsageAggregator::class)->aggregate('2026-10-11');

        $record = UsageRecord::sole();
        $this->assertSame('scale_to_zero', $record->scale_mode);
        $this->assertSame('http', $record->entry_point);
        $this->assertSame(2, (int) $record->session_count);
        $this->assertSame(150_000, (int) $record->total_duration_ms);
        $this->assertSame(500 * 150_000, (int) $record->total_cpu_mc_ms, '500m for the 150 s of pod time');
        $this->assertSame(1024 * 150_000, (int) $record->total_memory_mib_ms, '1 GiB for the 150 s');
        $this->assertSame(0, (int) $record->total_gpu_unit_ms);
    }

    public function test_figures_on_a_session_row_do_not_change_what_is_metered(): void
    {
        [$project, $deployment] = $this->workload('orders', cpuM: 500, memoryMb: 1024, gpus: 1);
        $start = Carbon::parse('2026-10-11 09:00:00');
        DeploymentSession::create([
            'project_id' => $project->id, 'deployment_id' => $deployment->id, 'region_id' => $this->region->id,
            'entry_point' => 'http', 'started_at' => $start, 'ended_at' => $start->copy()->addMinute(),
            'duration_ms' => 60_000, 'pod_count' => 2, 'cpu_used_ms' => 1, 'memory_used_mb_ms' => 0,
        ]);

        app(UsageAggregator::class)->aggregate('2026-10-11');

        $record = UsageRecord::sole();
        $this->assertSame(500 * 60_000 * 2, (int) $record->total_cpu_mc_ms, 'the size × duration × pods');
        $this->assertSame(1024 * 60_000 * 2, (int) $record->total_memory_mib_ms);
        $this->assertSame(1 * 60_000 * 2, (int) $record->total_gpu_unit_ms, 'GPUs the same way');
    }

    public function test_a_queue_triggers_sessions_are_metered_under_queue(): void
    {
        [$project] = $this->workload('jobs', trigger: 'queue');

        // The agent cannot tell the queue trigger's Knative Service from an HTTP one: it says http
        $this->agent('session_start', $project, 'jobs-00001-deployment-6b8f9c7d4-xk9pq');
        $this->assertSame('queue', DeploymentSession::sole()->entry_point);

        Carbon::setTestNow('2026-10-11 10:00:10');
        $this->agent('session_end', $project, 'jobs-00001-deployment-6b8f9c7d4-xk9pq');
        app(UsageAggregator::class)->aggregate('2026-10-11');

        $record = UsageRecord::query()->where('source', UsageRecord::SOURCE_PROJECT)->sole();
        $this->assertSame('queue', $record->entry_point, 'billed with the queue multiplier');
        $this->assertSame(500 * 10_000, (int) $record->total_cpu_mc_ms);
    }

    public function test_a_queue_triggers_consumer_is_metered_for_the_time_its_deployment_ran(): void
    {
        // Ready since 1 October: today until now (10:00) is 10 hours of its consumer
        [$project, $deployment] = $this->workload('jobs', trigger: 'queue');
        [$web] = $this->workload('web');

        app(UsageAggregator::class)->aggregate('2026-10-11');
        app(UsageAggregator::class)->aggregate('2026-10-11');

        $consumer = UsageRecord::query()->where('source', UsageRecord::SOURCE_QUEUE_CONSUMER)->sole();
        $hours10 = 10 * 3_600_000;
        $this->assertSame($project->id, $consumer->project_id);
        $this->assertSame($deployment->id, $consumer->deployment_id);
        $this->assertSame('custom_scale', $consumer->scale_mode);
        $this->assertSame('queue', $consumer->entry_point);
        $this->assertSame(50, (int) $consumer->reserved_cpu_m);
        $this->assertSame(64, (int) $consumer->reserved_memory_mb);
        $this->assertSame($hours10, (int) $consumer->total_duration_ms);
        $this->assertSame(50 * $hours10, (int) $consumer->total_cpu_mc_ms);
        $this->assertSame(64 * $hours10, (int) $consumer->total_memory_mib_ms);
        $this->assertSame(0, (int) $consumer->session_count);
        $this->assertFalse(UsageRecord::query()->where('project_id', $web->id)->exists(), 'an HTTP project has no consumer');

        // A stopped deployment's consumer stops with it
        Carbon::setTestNow('2026-10-11 12:00:00');
        $deployment->forceFill(['status' => 'shut_down', 'ended_at' => '2026-10-11 11:00:00'])->saveQuietly();
        app(UsageAggregator::class)->aggregate('2026-10-11');
        $this->assertSame(11 * 3_600_000, (int) UsageRecord::query()->where('source', UsageRecord::SOURCE_QUEUE_CONSUMER)->sole()->total_duration_ms);
    }

    public function test_a_preview_pods_session_is_metered_as_the_previews_own_record(): void
    {
        [$project] = $this->workload('shop', cpuM: 500, memoryMb: 1024);
        $preview = PreviewEnvironment::query()->create(['project_id' => $project->id, 'number' => 12, 'provider' => 'github',
            'status' => PreviewEnvironment::READY, 'service_name' => 'shop-pr-12']);
        $pod = 'shop-pr-12-00001-deployment-6b8f9c7d4-xk9pq';

        // What the agent sends for a preview pod: the project's slug and the preview's Service
        $this->agent('session_start', $project, $pod, ['preview' => 'shop-pr-12']);
        $session = DeploymentSession::sole();
        $this->assertNull($session->deployment_id);
        $this->assertSame($preview->id, $session->preview_environment_id);
        $this->assertSame('http', $session->entry_point);
        $this->assertSame(['cpu_m' => 500, 'memory_mb' => 1024, 'gpu_count' => 0], $session->metadata['size'] ?? null, 'the size it runs with');

        Carbon::setTestNow('2026-10-11 10:02:00');
        $this->agent('session_end', $project, $pod);
        // A pod of a preview the project does not have is not metered
        $this->agent('session_start', $project, 'shop-pr-99-00001-deployment-a-b', ['preview' => 'shop-pr-99']);
        $this->assertSame(1, DeploymentSession::query()->count());

        app(UsageAggregator::class)->aggregate('2026-10-11');

        $record = UsageRecord::sole();
        $this->assertSame(UsageRecord::SOURCE_PREVIEW, $record->source);
        $this->assertSame($preview->id, $record->deployment_id, 'one record per preview');
        $this->assertSame('scale_to_zero', $record->scale_mode);
        $this->assertSame(1, (int) $record->session_count);
        $this->assertSame(500 * 120_000, (int) $record->total_cpu_mc_ms);
        $this->assertSame(1024 * 120_000, (int) $record->total_memory_mib_ms);

        // The project's own session statistics leave the preview out
        $this->assertSame(0, app(SessionTrackingService::class)->getStats($project, now()->subDay(), now())['total_sessions']);
    }

    public function test_a_preview_whose_service_name_shortened_the_slug_is_metered_by_its_project_id(): void
    {
        // A slug of an earlier release, longer than <slug>-pr-<number> leaves room for
        [$project] = $this->workload('reports', cpuM: 250, memoryMb: 512);
        $slug = 'reports-of-the-quarterly-reconciliation-of-every-department-x';
        $project->forceFill(['slug' => $slug])->saveQuietly();
        $service = PreviewManifests::serviceName($slug, 7);
        $this->assertSame(63, strlen($service));
        $preview = PreviewEnvironment::query()->create(['project_id' => $project->id, 'number' => 7, 'provider' => 'github',
            'status' => PreviewEnvironment::READY, 'service_name' => $service]);
        $shortened = substr($service, 0, -strlen('-pr-7'));
        $pod = "{$service}-00001-deployment-6b8f9c7d4-xk9pq";

        // What the agent sends: what the Service name kept of the slug, and the project's id
        $send = fn (string $type, array $extra = []) => app(AgentMessageHandler::class)->handle(array_merge(
            ['type' => $type, 'project_slug' => $shortened, 'project_id' => $project->id, 'pod' => $pod, 'entry_point' => 'http', 'preview' => $service], $extra,
        ), $this->region->id);
        $send('session_start', ['cold_start' => true]);
        $this->assertSame($preview->id, DeploymentSession::sole()->preview_environment_id);

        // A slug that is not the start of the project's names nothing
        app(AgentMessageHandler::class)->handle(['type' => 'session_start', 'project_slug' => 'other', 'project_id' => $project->id,
            'pod' => 'other-pr-7-00001-deployment-a-b', 'entry_point' => 'http', 'preview' => 'other-pr-7'], $this->region->id);
        $this->assertSame(1, DeploymentSession::query()->count());

        Carbon::setTestNow('2026-10-11 10:00:40');
        Cache::flush(); // the end finds the session without the cache entry too
        $send('session_end', ['duration_ms' => 40_000]);
        $this->assertNotNull(DeploymentSession::sole()->ended_at);

        app(UsageAggregator::class)->aggregate('2026-10-11');
        $record = UsageRecord::sole();
        $this->assertSame(UsageRecord::SOURCE_PREVIEW, $record->source);
        $this->assertSame($preview->id, $record->deployment_id);
        $this->assertSame(250 * 40_000, (int) $record->total_cpu_mc_ms);

        // A heartbeat of a pod whose start was lost starts its session the same way
        $pod = "{$service}-00002-deployment-6b8f9c7d4-bcdfg";
        $send('session_heartbeat', ['sessions' => [['project_slug' => $shortened, 'project_id' => $project->id, 'pod' => $pod, 'entry_point' => 'http', 'preview' => $service]]]);
        $this->assertSame($preview->id, DeploymentSession::query()->where('metadata->pod', $pod)->sole()->preview_environment_id);
    }

    public function test_a_heartbeat_starts_the_session_of_a_preview_pod_whose_start_was_lost(): void
    {
        [$project] = $this->workload('shop');
        $preview = PreviewEnvironment::query()->create(['project_id' => $project->id, 'number' => 3, 'provider' => 'github',
            'status' => PreviewEnvironment::READY, 'service_name' => 'shop-pr-3']);

        app(AgentMessageHandler::class)->handle(['type' => 'session_heartbeat', 'sessions' => [
            ['project_slug' => $project->slug, 'pod' => 'shop-pr-3-00001-deployment-a-bcdfg', 'entry_point' => 'http', 'preview' => 'shop-pr-3'],
        ]], $this->region->id);

        $session = DeploymentSession::sole();
        $this->assertSame($preview->id, $session->preview_environment_id);
        $this->assertNull($session->deployment_id);
        $this->assertSame('2026-10-11 10:00:00', $session->last_seen_at?->toDateTimeString());
        $this->assertFalse($session->cold_start);
    }

    public function test_a_cronjob_run_is_metered_under_schedule(): void
    {
        [$project, $deployment] = $this->workload('nightly', cpuM: 250, memoryMb: 512, trigger: 'schedule');

        $this->agent('session_start', $project, 'nightly-trigger-29345678-x7k2p', ['entry_point' => 'schedule']);
        Carbon::setTestNow('2026-10-11 10:00:45');
        $this->agent('session_end', $project, 'nightly-trigger-29345678-x7k2p', ['duration_ms' => 45_000]);

        $session = DeploymentSession::sole();
        $this->assertSame('schedule', $session->entry_point);
        $this->assertSame($deployment->id, $session->deployment_id);

        app(UsageAggregator::class)->aggregate('2026-10-11');

        $record = UsageRecord::sole();
        $this->assertSame('scale_to_zero', $record->scale_mode, 'a CronJob is never always on');
        $this->assertSame('schedule', $record->entry_point);
        $this->assertSame(1, (int) $record->session_count);
        $this->assertSame(250 * 45_000, (int) $record->total_cpu_mc_ms);
        $this->assertSame(512 * 45_000, (int) $record->total_memory_mib_ms);
    }

    public function test_a_session_closed_as_stale_is_metered_until_its_close_and_corrected_by_its_end(): void
    {
        [$project] = $this->workload('orders', cpuM: 500, memoryMb: 1024);
        $this->agent('session_start', $project, 'orders-00001-deployment-6b8f9c7d4-xk9pq');

        // The end did not arrive (agent disconnected): closed after 40 minutes
        Carbon::setTestNow('2026-10-11 10:40:00');
        app(SessionTrackingService::class)->closeStale();
        app(UsageAggregator::class)->aggregate('2026-10-11');
        $this->assertSame(500 * 40 * 60_000, (int) UsageRecord::sole()->total_cpu_mc_ms);

        // The pod ran until 11:00
        Carbon::setTestNow('2026-10-11 11:00:00');
        $this->agent('session_end', $project, 'orders-00001-deployment-6b8f9c7d4-xk9pq');
        app(UsageAggregator::class)->aggregate('2026-10-11');

        $record = UsageRecord::sole();
        $this->assertSame(500 * 60 * 60_000, (int) $record->total_cpu_mc_ms);
        $this->assertSame(1024 * 60 * 60_000, (int) $record->total_memory_mib_ms);
    }

    public function test_a_session_over_midnight_counts_on_each_day_for_the_time_it_ran_there(): void
    {
        Carbon::setTestNow('2026-10-12 00:30:00');
        [$project, $deployment] = $this->workload('orders', cpuM: 500, memoryMb: 1024);
        $start = Carbon::parse('2026-10-11 23:00:00');
        DeploymentSession::create([
            'project_id' => $project->id, 'deployment_id' => $deployment->id, 'region_id' => $this->region->id,
            'entry_point' => 'http', 'started_at' => $start, 'ended_at' => $start->copy()->addMinutes(80),
            'duration_ms' => 80 * 60_000, 'cold_start' => true,
        ]);

        app(UsageAggregator::class)->aggregate('2026-10-11');
        app(UsageAggregator::class)->aggregate('2026-10-12');

        $days = UsageRecord::query()->get()->keyBy(fn (UsageRecord $r) => $r->record_date->toDateString());
        $this->assertSame(60 * 60_000, (int) $days['2026-10-11']->total_duration_ms, '23:00–24:00');
        $this->assertSame(500 * 60 * 60_000, (int) $days['2026-10-11']->total_cpu_mc_ms);
        $this->assertSame(1, (int) $days['2026-10-11']->session_count, 'a session on the day it started');
        $this->assertSame(1, (int) $days['2026-10-11']->cold_start_count);
        $this->assertSame(20 * 60_000, (int) $days['2026-10-12']->total_duration_ms, '00:00–00:20');
        $this->assertSame(1024 * 20 * 60_000, (int) $days['2026-10-12']->total_memory_mib_ms);
        $this->assertSame(0, (int) $days['2026-10-12']->session_count);
        $this->assertSame(0, (int) $days['2026-10-12']->cold_start_count);
    }

    public function test_a_running_session_counts_until_now_and_a_closed_month_keeps_only_its_part(): void
    {
        [$project] = $this->workload('orders', cpuM: 500, memoryMb: 1024);

        // A busy service: its pod runs from 31 October 22:00 into November
        Carbon::setTestNow('2026-10-31 22:00:00');
        $this->agent('session_start', $project, 'orders-00001-deployment-6b8f9c7d4-xk9pq');

        // The first hour of 1 November: the job finishes October with the session still running
        Carbon::setTestNow('2026-11-01 00:30:00');
        $this->agent('session_heartbeat', $project, 'orders-00001-deployment-6b8f9c7d4-xk9pq');
        app(UsageAggregator::class)->aggregate('2026-10-31');
        app(UsageAggregator::class)->aggregate('2026-11-01');

        $october = UsageRecord::query()->whereDate('record_date', '2026-10-31')->sole();
        $this->assertSame(2 * 3_600_000, (int) $october->total_duration_ms, '22:00–24:00, though the session has not ended');
        $this->assertSame(500 * 2 * 3_600_000, (int) $october->total_cpu_mc_ms);
        $this->assertSame(30 * 60_000, (int) UsageRecord::query()->whereDate('record_date', '2026-11-01')->sole()->total_duration_ms, 'until now');

        // The pod is removed at 05:00; November is recalculated, October stays as it was
        Carbon::setTestNow('2026-11-01 05:00:00');
        $this->agent('session_end', $project, 'orders-00001-deployment-6b8f9c7d4-xk9pq');
        app(UsageAggregator::class)->aggregate('2026-11-01');
        app(UsageAggregator::class)->aggregate('2026-10-31');

        $this->assertSame(7 * 3_600_000, DeploymentSession::sole()->duration_ms);
        $this->assertSame(2 * 3_600_000, (int) UsageRecord::query()->whereDate('record_date', '2026-10-31')->sole()->total_duration_ms);
        $november = UsageRecord::query()->whereDate('record_date', '2026-11-01')->sole();
        $this->assertSame(5 * 3_600_000, (int) $november->total_duration_ms);
        $this->assertSame(1024 * 5 * 3_600_000, (int) $november->total_memory_mib_ms);
    }

    public function test_recalculating_a_day_metered_with_zero_cpu_and_memory_fills_them_in(): void
    {
        Carbon::setTestNow('2026-10-21 12:00:00');
        [$project, $deployment] = $this->workload('orders', cpuM: 500, memoryMb: 1024);
        $start = Carbon::parse('2026-10-05 09:00:00');
        DeploymentSession::create([
            'project_id' => $project->id, 'deployment_id' => $deployment->id, 'region_id' => $this->region->id,
            'entry_point' => 'http', 'started_at' => $start, 'ended_at' => $start->copy()->addMinutes(10),
            'duration_ms' => 600_000, 'cold_start' => true,
        ]);
        // The day as earlier releases metered it: the session's time, no CPU or memory
        $id = (string) Str::ulid();
        DB::table('usage_records')->insert([
            'id' => $id, 'project_id' => $project->id, 'deployment_id' => $deployment->id, 'region_id' => $this->region->id,
            'record_date' => '2026-10-05', 'scale_mode' => 'scale_to_zero', 'entry_point' => 'http', 'session_count' => 1,
            'total_duration_ms' => 600_000, 'total_cpu_mc_ms' => 0, 'total_memory_mib_ms' => 0, 'cold_start_count' => 1,
            'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('usage:recalculate', ['from' => '2026-10-05', 'to' => '2026-10-05'])->assertSuccessful();

        $record = UsageRecord::sole();
        $this->assertSame($id, $record->id, 'the record is corrected in place');
        $this->assertSame(500 * 600_000, (int) $record->total_cpu_mc_ms);
        $this->assertSame(1024 * 600_000, (int) $record->total_memory_mib_ms);
        $this->assertSame(600_000, (int) $record->total_duration_ms);
    }

    /**
     * A container project with an active deployment applied at this size (deployments.sizing), scale
     * to zero.
     *
     * @return array{Project, Deployment}
     */
    private function workload(string $name, int $cpuM = 500, int $memoryMb = 1024, string $trigger = 'http', int $gpus = 0): array
    {
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        ContainerConfig::create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'cpu' => $cpuM.'m',
            'memory_mb' => $memoryMb,
            'trigger_type' => $trigger,
            'schedule' => $trigger === 'schedule' ? '0 3 * * *' : null,
            'scale_to_zero' => true,
            'min_scale' => 0,
        ]);

        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = Deployment::create([
            'project_id' => $project->id,
            'version_id' => $version->id,
            'status' => 'active',
            'sizing' => [
                'region_id' => $this->region->id, 'cpu_m' => $cpuM, 'memory_mb' => $memoryMb, 'trigger_type' => $trigger,
                'min_scale' => 0, 'max_scale' => 3, 'scale_to_zero' => true, 'gpu_count' => $gpus,
            ],
        ]);
        $deployment->forceFill(['ready_at' => '2026-10-01 00:00:00', 'created_at' => '2026-10-01 00:00:00'])->saveQuietly();

        return [$project->fresh(), $deployment->fresh()];
    }

    /**
     * A message of the region agent as it sends it (runtime/agent session Tracker): session_start
     * with the entry point it can tell (http for a serving pod, schedule for a CronJob run),
     * session_end with the duration it measured, session_heartbeat listing the running pod — and
     * no CPU or memory figures.
     *
     * @param  array<string, mixed>  $extra
     */
    private function agent(string $type, Project $project, string $pod, array $extra = []): void
    {
        $message = match ($type) {
            'session_start' => ['type' => $type, 'project_slug' => $project->slug, 'project_id' => $project->id, 'entry_point' => 'http', 'pod' => $pod, 'cold_start' => true],
            'session_heartbeat' => ['type' => $type, 'sessions' => [['project_slug' => $project->slug, 'project_id' => $project->id, 'pod' => $pod, 'entry_point' => 'http']]],
            default => ['type' => $type, 'project_slug' => $project->slug, 'pod' => $pod],
        };

        app(AgentMessageHandler::class)->handle(array_merge($message, $extra), $this->region->id);
    }
}
