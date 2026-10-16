<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Events\PlatformEvent;
use App\Jobs\BuildVersionJob;
use App\Jobs\CheckBuildReadyJob;
use App\Jobs\CheckDeploymentReadyJob;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\FunctionBuildService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Build / deployment readiness: first check ~1 s after submit / apply, then every 2 s, up to the
 * overall timeout (the build Job's deadline + 60 s / 300 s). The checks are delayed queue jobs, which Redis
 * workers pick up only when they pop: block_for + Horizon sleep bounds how late a due check runs.
 */
class ReadinessPollingScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function setupProject(): array
    {
        $region = KnativeRegion::create([
            'name' => 'fn',
            'project_namespace' => 'fn',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1',
                'kind' => 'Config',
                'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://region.test:6443', 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'is_active' => true,
        ]);
        $project = Project::create(['name' => 'orders', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'cpu' => '250m',
            'memory_mb' => 128,
            'timeout_seconds' => 30,
            'trigger_type' => 'http',
        ]);
        $version = Version::create(['project_id' => $project->id, 'number' => 2, 'created_by' => 'dev', 'build_status' => 'building']);

        return [$project->fresh(), $version];
    }

    private function deployment(Project $project, Version $version): Deployment
    {
        return $project->deployments()->create([
            'version_id' => $version->id,
            'status' => 'pending',
            'k8s_job_name' => "run-{$project->slug}-v{$version->number}",
            'deployed_by' => 'dev',
        ]);
    }

    public function test_the_schedule_keeps_the_overall_timeouts(): void
    {
        // Builds: the build Job's deadline (900 s by default) plus a minute
        $this->assertSame(960, CheckBuildReadyJob::timeoutSeconds());

        foreach ([CheckBuildReadyJob::class => 960, CheckDeploymentReadyJob::class => 300] as $job => $timeout) {
            $this->assertSame(1, $job::FIRST_CHECK_DELAY);
            $this->assertSame(2, $job::POLL_INTERVAL);
            $this->assertSame($timeout, $job === CheckBuildReadyJob::class ? $job::timeoutSeconds() : $job::TIMEOUT_SECONDS);

            // the last check is the first one at or after the timeout
            $lastCheckAt = fn (int $attempts) => $job::FIRST_CHECK_DELAY + ($attempts - 1) * $job::POLL_INTERVAL;
            $this->assertGreaterThanOrEqual($timeout, $lastCheckAt($job::maxAttempts()), $job);
            $this->assertLessThan($timeout, $lastCheckAt($job::maxAttempts() - 1), $job);
        }

        $this->assertSame(481, CheckBuildReadyJob::maxAttempts());
        $this->assertSame(151, CheckDeploymentReadyJob::maxAttempts());
    }

    public function test_a_due_check_is_picked_up_within_a_second(): void
    {
        $blockFor = config('queue.connections.redis.block_for');
        $sleep = config('horizon.defaults.supervisor-1.sleep');

        $this->assertSame(1, $blockFor);
        $this->assertSame(0, $sleep);
        $this->assertLessThanOrEqual(CheckBuildReadyJob::FIRST_CHECK_DELAY, $blockFor + $sleep);
        $this->assertGreaterThan(config('horizon.defaults.supervisor-1.timeout'), config('queue.connections.redis.retry_after'));

        // 0 would block until the next push (due delayed jobs would wait for it): never below 1
        $_SERVER['REDIS_QUEUE_BLOCK_FOR'] = '0';
        try {
            $this->assertSame(1, (require config_path('queue.php'))['connections']['redis']['block_for']);
        } finally {
            unset($_SERVER['REDIS_QUEUE_BLOCK_FOR']);
        }
    }

    public function test_a_submitted_build_is_first_checked_after_one_second(): void
    {
        $this->freezeTime();
        Bus::fake([CheckBuildReadyJob::class]);
        [, $version] = $this->setupProject();

        $builder = $this->mock(FunctionBuildService::class);
        $builder->shouldReceive('submitBuild')->once();

        (new BuildVersionJob($version))->handle($builder);

        Bus::assertDispatched(CheckBuildReadyJob::class, fn (CheckBuildReadyJob $job) => $job->attempt === 1
            && $job->delay->equalTo(now()->addSeconds(1)));
    }

    public function test_a_running_build_is_checked_again_after_two_seconds_until_the_timeout(): void
    {
        $this->freezeTime();
        Event::fake([PlatformEvent::class, NotificationCreated::class]);
        [$project, $version] = $this->setupProject();
        Http::fake(fn () => Http::response(['status' => ['active' => 1]]));

        Bus::fake([CheckBuildReadyJob::class]);
        (new CheckBuildReadyJob($version, 5))->handle();

        Bus::assertDispatched(CheckBuildReadyJob::class, fn (CheckBuildReadyJob $job) => $job->attempt === 6
            && $job->delay->equalTo(now()->addSeconds(2)));
        $this->assertSame('building', $version->fresh()->build_status);

        (new CheckBuildReadyJob($version, CheckBuildReadyJob::maxAttempts()))->handle();

        $this->assertSame('failed', $version->fresh()->build_status);
        $this->assertStringStartsWith('Build timeout after 960s', $version->fresh()->build_error);
    }

    public function test_a_check_that_runs_out_of_time_checks_again_instead_of_failing_the_build(): void
    {
        $this->freezeTime();
        Event::fake([PlatformEvent::class, NotificationCreated::class]);
        [, $version] = $this->setupProject();

        // the worker killed the check after its $timeout (a region API server answering slowly)
        Bus::fake([CheckBuildReadyJob::class]);
        (new CheckBuildReadyJob($version, 7))->failed(new \RuntimeException('App\Jobs\CheckBuildReadyJob has timed out.'));

        $this->assertSame('building', $version->fresh()->build_status);
        Bus::assertDispatched(CheckBuildReadyJob::class, fn (CheckBuildReadyJob $job) => $job->attempt === 8
            && $job->delay->equalTo(now()->addSeconds(2)));

        // the last check of the overall timeout: the version does not stay "building"
        (new CheckBuildReadyJob($version, CheckBuildReadyJob::maxAttempts()))->failed(new \RuntimeException('timed out'));

        $this->assertSame('failed', $version->fresh()->build_status);
        $this->assertSame('Build check failed: timed out', $version->fresh()->build_error);
    }

    public function test_a_readiness_check_that_runs_out_of_time_checks_again_instead_of_failing_the_deployment(): void
    {
        $this->freezeTime();
        Event::fake([PlatformEvent::class, NotificationCreated::class]);
        [$project, $version] = $this->setupProject();
        $deployment = $this->deployment($project, $version);

        Bus::fake([CheckDeploymentReadyJob::class]);
        (new CheckDeploymentReadyJob($deployment, $project, 3))->failed(new \RuntimeException('App\Jobs\CheckDeploymentReadyJob has timed out.'));

        $this->assertSame('pending', $deployment->fresh()->status);
        Bus::assertDispatched(CheckDeploymentReadyJob::class, fn (CheckDeploymentReadyJob $job) => $job->attempt === 4
            && $job->delay->equalTo(now()->addSeconds(2)));

        (new CheckDeploymentReadyJob($deployment, $project, CheckDeploymentReadyJob::maxAttempts()))->failed(new \RuntimeException('timed out'));

        $this->assertSame('failed', $deployment->fresh()->status);
    }

    public function test_a_deployment_is_checked_after_one_second_then_every_two_until_the_timeout(): void
    {
        $this->freezeTime();
        Event::fake([PlatformEvent::class, NotificationCreated::class]);
        [$project, $version] = $this->setupProject();
        $deployment = $this->deployment($project, $version);
        Http::fake(fn () => Http::response(['status' => ['conditions' => [['type' => 'Ready', 'status' => 'Unknown', 'reason' => 'Deploying']]]]));

        Bus::fake([CheckDeploymentReadyJob::class]);
        CheckDeploymentReadyJob::start($deployment, $project);
        Bus::assertDispatched(CheckDeploymentReadyJob::class, fn (CheckDeploymentReadyJob $job) => $job->attempt === 1
            && $job->delay->equalTo(now()->addSeconds(1)));

        (new CheckDeploymentReadyJob($deployment, $project, 1))->handle();
        Bus::assertDispatched(CheckDeploymentReadyJob::class, fn (CheckDeploymentReadyJob $job) => $job->attempt === 2
            && $job->delay->equalTo(now()->addSeconds(2)));

        (new CheckDeploymentReadyJob($deployment, $project, CheckDeploymentReadyJob::maxAttempts()))->handle();
        $this->assertSame('failed', $deployment->fresh()->status);
        $this->assertStringStartsWith('Readiness timeout after 300s', $deployment->fresh()->knative_message);
    }
}
