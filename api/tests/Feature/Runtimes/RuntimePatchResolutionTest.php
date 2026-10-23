<?php

namespace Tests\Feature\Runtimes;

use App\Models\Deployment;
use App\Models\ExecutorImage;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Services\KnativeDeployService;
use App\Services\Manifests\ZotManifests;
use App\Services\Projects\LiveDeployments;
use App\Services\Runtimes\Patches\RuntimePatches;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRuntimePatches;
use Tests\Concerns\FakesBuildCluster;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * Workloads run runtime patches by digest (RuntimePatches::resolve(), docs/runtimes.md#runtime-patches):
 * code-mounted functions and CronJobs the project's pin or the newest patch, recorded on the
 * deployment (a digest set before — a rollback — wins); prebuilt versions are built FROM the
 * patch and record it; sandboxes take the newest patch; without patches nothing changes.
 */
class RuntimePatchResolutionTest extends TestCase
{
    use BuildsRuntimePatches, FakesBuildCluster, RefreshDatabase, RegistersRuntimes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBuildCluster();
        $this->zotConfig = json_encode(ZotManifests::mirrorConfig());
    }

    private function image(Project $project, $version, $runtimeImage = null, string $kind = 'Service'): string
    {
        $manifests = app(KnativeDeployService::class)->buildManifests($project, $version, [], $runtimeImage);
        $m = collect($manifests)->firstWhere('kind', $kind);
        $spec = $kind === 'CronJob' ? $m['spec']['jobTemplate']['spec']['template']['spec'] : $m['spec']['template']['spec'];

        return $spec['containers'][0]['image'];
    }

    public function test_without_patches_the_floating_tag_stays(): void
    {
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);

        $this->assertSame('ghcr.io/brf-tech/runr/exec-python:3.14', $this->image($project, $version));
    }

    public function test_code_mounted_functions_and_cron_jobs_run_the_newest_patch_by_digest(): void
    {
        $this->runtimePatch(['tag' => '3.14.8-r1', 'published_at' => now()->subDays(3)]);
        $newest = $this->runtimePatch(['tag' => '3.14.9-r1', 'published_at' => now()->subDay()]);

        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $this->assertSame('ghcr.io/brf-tech/runr/exec-python@'.$newest->digest, $this->image($project, $version));

        [$cron, $cronVersion] = $this->runtimeProject('python', 'schedule', ['main.py' => 'x']);
        $this->assertSame('ghcr.io/brf-tech/runr/exec-python@'.$newest->digest, $this->image($cron, $cronVersion, null, 'CronJob'));
    }

    public function test_a_pinned_project_runs_its_pin(): void
    {
        $pinned = $this->runtimePatch(['tag' => '3.14.8-r1', 'published_at' => now()->subDays(3)]);
        $this->runtimePatch(['tag' => '3.14.9-r1', 'published_at' => now()->subDay()]);
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $project->forceFill(['runtime_patch_pin' => $pinned->id])->save();

        $this->assertSame('ghcr.io/brf-tech/runr/exec-python@'.$pinned->digest, $this->image($project, $version));
    }

    public function test_a_pin_that_left_the_last_five_falls_back_to_the_newest_and_is_removed(): void
    {
        $old = $this->runtimePatch(['tag' => '3.14.1-r1', 'published_at' => now()->subDays(30)]);
        foreach (range(2, 6) as $i) {
            $newest = $this->runtimePatch(['tag' => "3.14.{$i}-r1", 'published_at' => now()->subDays(10 - $i)]);
        }
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $project->forceFill(['runtime_patch_pin' => $old->id, 'owner_id' => null])->save();
        $deployment = Deployment::query()->create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending', 'deployed_by' => 'dev']);

        $resolved = app(RuntimePatches::class)->forDeployment($project, $version, $deployment);

        $this->assertTrue($resolved->pinDropped);
        $this->assertSame($newest->digest, $resolved->digest);
        $this->assertNull($project->fresh()->runtime_patch_pin);
        $this->assertSame($newest->digest, $deployment->fresh()->runtime_digest);
    }

    public function test_the_deployment_records_what_it_runs_and_a_digest_set_before_wins(): void
    {
        $older = $this->runtimePatch(['tag' => '3.14.8-r1', 'published_at' => now()->subDays(3)]);
        $newest = $this->runtimePatch(['tag' => '3.14.9-r1', 'published_at' => now()->subDay()]);
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);

        $fresh = Deployment::query()->create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending', 'deployed_by' => 'dev']);
        $resolved = app(RuntimePatches::class)->forDeployment($project, $version, $fresh);
        $this->assertSame([$newest->id, $newest->digest, 'ghcr.io/brf-tech/runr/exec-python@'.$newest->digest],
            [$fresh->fresh()->runtime_patch_id, $fresh->fresh()->runtime_digest, $fresh->fresh()->runtime_image]);
        $this->assertSame('ghcr.io/brf-tech/runr/exec-python@'.$newest->digest, $this->image($project, $version, $resolved));

        // a rollback: the deployment names the digest it must run
        $rollback = Deployment::query()->create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending', 'deployed_by' => 'runr', 'runtime_digest' => $older->digest]);
        $resolved = app(RuntimePatches::class)->forDeployment($project, $version, $rollback);
        $this->assertSame($older->id, $resolved->patchId);
        $this->assertSame('ghcr.io/brf-tech/runr/exec-python@'.$older->digest, $this->image($project, $version, $resolved));
    }

    public function test_activation_records_the_running_patch_on_the_project(): void
    {
        $newest = $this->runtimePatch(['tag' => '3.14.9-r1']);
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $deployment = Deployment::query()->create([
            'project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending', 'deployed_by' => 'dev',
            'runtime_digest' => $newest->digest, 'runtime_patch_id' => $newest->id,
        ]);

        $this->assertTrue(app(LiveDeployments::class)->activate($deployment, ['ready_at' => now()]));

        $project->refresh();
        $this->assertSame([$newest->id, $newest->digest], [$project->runtime_patch_id, $project->runtime_digest]);
    }

    public function test_a_prebuilt_version_is_built_from_the_patch_and_records_it(): void
    {
        $newest = $this->runtimePatch(['tag' => '3.14.9-r1']);
        $version = $this->buildVersion('python', null, 'internal', ['main.py' => 'x', 'requirements.txt' => "redis\n"]);

        $this->submit($version);

        $from = self::ZOT.'/ghcr.io/brf-tech/runr/exec-python@'.$newest->digest;
        $this->assertSame([$from, $from], $this->fromLines());
        $version->refresh();
        $this->assertSame([$newest->digest, $newest->id, null], [$version->runtime_digest, $version->runtime_patch_id, $version->runtime_base_pin]);
    }

    public function test_a_base_pin_of_the_version_wins_and_is_used_once(): void
    {
        $older = $this->runtimePatch(['tag' => '3.14.8-r1', 'published_at' => now()->subDays(3)]);
        $this->runtimePatch(['tag' => '3.14.9-r1', 'published_at' => now()->subDay()]);
        $version = $this->buildVersion('python', null, 'internal', ['main.py' => 'x', 'requirements.txt' => "redis\n"]);
        $version->forceFill(['runtime_base_pin' => $older->digest])->save();

        $this->submit($version);

        $this->assertSame(self::ZOT.'/ghcr.io/brf-tech/runr/exec-python@'.$older->digest, $this->fromLines()[1]);
        $this->assertNull($version->fresh()->runtime_base_pin);
        $this->assertSame($older->id, $version->fresh()->runtime_patch_id);
    }

    public function test_sandboxes_take_the_newest_patch_and_other_images_are_left_alone(): void
    {
        $newest = $this->runtimePatch(['tag' => '3.14.9-r1']);
        $executor = ExecutorImage::query()->where('runtime', 'python')->where('version', '3.14')->firstOrFail();
        $patches = app(RuntimePatches::class);

        $this->assertSame('ghcr.io/brf-tech/runr/exec-python@'.$newest->digest, $patches->forSandbox($executor, 'ghcr.io/brf-tech/runr/exec-python:3.14'));
        // a region build or an upload is not the runtime's image
        $this->assertSame('10.43.0.5:5000/runr-runtimes/python:3.14', $patches->forSandbox($executor, '10.43.0.5:5000/runr-runtimes/python:3.14'));
        $this->assertSame('ghcr.io/x/y@sha256:'.str_repeat('d', 64), $patches->forSandbox($executor, 'ghcr.io/x/y@sha256:'.str_repeat('d', 64)));
    }

    public function test_a_digest_reference_keeps_the_form_of_the_tag_reference(): void
    {
        $digest = 'sha256:'.str_repeat('e', 64);

        $this->assertSame("ghcr.io/brf-tech/runr/exec-php@{$digest}", RuntimePatches::byDigest('ghcr.io/brf-tech/runr/exec-php:8.3', $digest));
        $this->assertSame("runrsh/exec-python@{$digest}", RuntimePatches::byDigest('runrsh/exec-python:3.12', $digest));
        $this->assertSame("10.0.0.1:5000/exec-go@{$digest}", RuntimePatches::byDigest('10.0.0.1:5000/exec-go:1.26', $digest));
    }

    public function test_the_pin_dropped_owner_is_told(): void
    {
        $old = $this->runtimePatch(['tag' => '3.14.1-r1', 'published_at' => now()->subDays(30)]);
        foreach (range(2, 6) as $i) {
            $this->runtimePatch(['tag' => "3.14.{$i}-r1", 'published_at' => now()->subDays(10 - $i)]);
        }
        $owner = User::factory()->create();
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $project->forceFill(['runtime_patch_pin' => $old->id, 'owner_id' => $owner->id])->save();
        $deployment = Deployment::query()->create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending', 'deployed_by' => 'dev']);

        app(RuntimePatches::class)->forDeployment($project, $version, $deployment);

        $notice = Notification::query()->where('user_id', $owner->id)->where('type', 'runtime_patch')->first();
        $this->assertNotNull($notice);
        $this->assertSame('pin_dropped', $notice->data['event']);
    }
}
