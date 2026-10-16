<?php

namespace Tests\Feature;

use App\Console\Commands\CheckStaleBuildCommand;
use App\Events\NotificationCreated;
use App\Events\PlatformEvent;
use App\Jobs\CheckBuildReadyJob;
use App\Jobs\GitDeploy\CheckPreviewBuildJob;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\FunctionBuildService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\FakesBuildCluster;
use Tests\TestCase;

/**
 * Kaniko builds hit `activeDeadlineSeconds: 300` (hard-coded) while the region's Zot fetched a new
 * base image from upstream for the first time (4m33s–4m52s measured). The deadline is
 * BUILD_DEADLINE_SECONDS (900 by default) for the build Job, its checks and the stale-build net,
 * and a build that ran out of time while it still waited for its base image is built once more.
 */
class BuildDeadlineTest extends TestCase
{
    use FakesBuildCluster, RefreshDatabase;

    public function test_the_build_job_deadline_comes_from_the_config(): void
    {
        $this->setUpBuildCluster();

        $this->submit($this->buildVersion('python', null));
        $this->assertSame(900, $this->created('jobs')['spec']['activeDeadlineSeconds']);

        config(['executor.build_deadline_seconds' => 1500]);
        $this->submit($this->buildVersion('python', null));
        $this->assertSame(1500, $this->created('jobs')['spec']['activeDeadlineSeconds']);
        $this->assertSame(1560, CheckBuildReadyJob::timeoutSeconds());
        $this->assertSame(1620, CheckStaleBuildCommand::staleAfterSeconds());
        // Preview builds (git deploy) are polled every 5 s just as long
        $this->assertSame(312, CheckPreviewBuildJob::maxAttempts());

        // Never below a minute
        config(['executor.build_deadline_seconds' => 10]);
        $this->submit($this->buildVersion('python', null));
        $this->assertSame(60, $this->created('jobs')['spec']['activeDeadlineSeconds']);
    }

    public function test_the_stale_build_net_follows_the_deadline(): void
    {
        $project = Project::create(['name' => 'demo', 'service_type' => 'caas']);
        $old = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'building']);
        $recent = Version::create(['project_id' => $project->id, 'number' => 2, 'created_by' => 'dev', 'build_status' => 'building']);
        Version::whereKey($old->id)->update(['updated_at' => now()->subSeconds(CheckStaleBuildCommand::staleAfterSeconds() + 5)]);
        // Past the former 6 minutes, within the deadline: still building
        Version::whereKey($recent->id)->update(['updated_at' => now()->subMinutes(10)]);

        $this->artisan('build:check-stale')->assertSuccessful();

        $this->assertSame('failed', $old->fresh()->build_status);
        $this->assertSame('Build timeout — no completion within 17 minutes', $old->fresh()->build_error);
        $this->assertSame('building', $recent->fresh()->build_status);
    }

    // ── A build that ran out of time on its base image ──────────────────────

    /** A build whose Kaniko Job failed with $reason; the pod's log ends with $lastLine. */
    private function failedBuild(string $reason, string $lastLine): Version
    {
        Event::fake([PlatformEvent::class, NotificationCreated::class]);
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

        Http::fake(function (Request $request) use ($project, $reason, $lastLine) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path === "/apis/batch/v1/namespaces/fn/jobs/build-{$project->slug}-v4") {
                return Http::response(['metadata' => ['name' => "build-{$project->slug}-v4"], 'status' => [
                    'failed' => 1,
                    'conditions' => [['type' => 'Failed', 'status' => 'True', 'reason' => $reason, 'message' => 'Job was active longer than specified deadline']],
                ]]);
            }
            if ($path === '/api/v1/namespaces/fn/pods') {
                return Http::response(['items' => [['metadata' => ['name' => 'build-pod']]]]);
            }
            if ($path === '/api/v1/namespaces/fn/pods/build-pod/log') {
                return Http::response("\e[36mINFO\e[0m[0000] Retrieving image manifest 10.43.0.5:5000/ghcr.io/brf-tech/runr/exec-python:3.14\n\e[36mINFO\e[0m[0001] Returning cached image manifest\n{$lastLine}\n");
            }

            return Http::response(['kind' => 'Status', 'code' => 404], 404);
        });

        return Version::create(['project_id' => $project->id, 'number' => 4, 'created_by' => 'dev', 'build_status' => 'building']);
    }

    public function test_a_build_out_of_time_on_its_base_image_is_built_once_more(): void
    {
        $version = $this->failedBuild('DeadlineExceeded', "\e[36mINFO\e[0m[0003] Retrieving image manifest 10.43.0.5:5000/ghcr.io/brf-tech/runr/exec-pytorch:2.3");
        $builder = $this->mock(FunctionBuildService::class);
        $builder->shouldReceive('submitBuild')->once();
        Bus::fake([CheckBuildReadyJob::class]);

        (new CheckBuildReadyJob($version, 12, 'user-1'))->handle();

        $version->refresh();
        $this->assertSame('building', $version->build_status);
        $this->assertStringContainsString('while the region registry fetched the base image 10.43.0.5:5000/ghcr.io/brf-tech/runr/exec-pytorch:2.3 for the first time; building once more', $version->build_error);
        Bus::assertDispatched(CheckBuildReadyJob::class, fn (CheckBuildReadyJob $job) => $job->attempt === 1 && $job->retried && $job->initiatorId === 'user-1');

        // The second build runs out of time as well: failed, no third one
        (new CheckBuildReadyJob($version, 3, 'user-1', true))->handle();
        $this->assertSame('failed', $version->fresh()->build_status);
        Bus::assertDispatchedTimes(CheckBuildReadyJob::class, 1);
    }

    public function test_a_build_out_of_time_in_its_own_steps_is_not_built_again(): void
    {
        $this->mock(FunctionBuildService::class)->shouldNotReceive('submitBuild');
        Bus::fake([CheckBuildReadyJob::class]);
        $version = $this->failedBuild('DeadlineExceeded', "\e[36mINFO\e[0m[0290] RUN pip install -r requirements.txt");

        (new CheckBuildReadyJob($version, 12))->handle();

        $this->assertSame('failed', $version->fresh()->build_status);
        Bus::assertNotDispatched(CheckBuildReadyJob::class);
    }

    public function test_a_failed_build_step_is_not_built_again(): void
    {
        $this->mock(FunctionBuildService::class)->shouldNotReceive('submitBuild');
        Bus::fake([CheckBuildReadyJob::class]);
        // The log's last line is the base image, but the Job failed, it did not run out of time
        $version = $this->failedBuild('BackoffLimitExceeded', "\e[36mINFO\e[0m[0003] Retrieving image manifest 10.43.0.5:5000/ghcr.io/brf-tech/runr/exec-python:3.14");

        (new CheckBuildReadyJob($version, 12))->handle();

        $this->assertSame('failed', $version->fresh()->build_status);
        Bus::assertNotDispatched(CheckBuildReadyJob::class);
    }
}
