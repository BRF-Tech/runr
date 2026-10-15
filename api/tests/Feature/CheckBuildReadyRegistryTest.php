<?php

namespace Tests\Feature;

use App\Events\PlatformEvent;
use App\Jobs\CheckBuildReadyJob;
use App\Jobs\RetainRegistryImagesJob;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Once a build lands (Kaniko Job succeeded), old build images in an internal
 * registry are trimmed — in a separate RetainRegistryImagesJob dispatched after build.completed
 * was broadcast, never inside the 30 s build check itself.
 */
class CheckBuildReadyRegistryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $calls = [];

    private function kubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://region.test:6443', 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    private function succeededBuild(string $registryMode): array
    {
        $region = KnativeRegion::create([
            'name' => 'fn',
            'project_namespace' => 'fn',
            'kubeconfig' => $this->kubeconfig(),
            'is_active' => true,
            'registry_mode' => $registryMode,
            'registry_namespace' => 'runr-registry',
        ]);
        $project = Project::create(['name' => 'demo', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        $version = Version::create(['project_id' => $project->id, 'number' => 3, 'created_by' => 'dev', 'build_status' => 'building']);

        $jobPath = "/apis/batch/v1/namespaces/fn/jobs/build-{$project->slug}-v3";

        Http::fake(function (Request $request) use ($jobPath) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = "{$request->method()} {$path}";

            return $request->method() === 'GET' && $path === $jobPath
                ? Http::response(['status' => ['succeeded' => 1]])
                : Http::response(['kind' => 'Status', 'code' => 404], 404);
        });

        return [$project, $version];
    }

    public function test_retention_is_dispatched_after_the_build_completed_broadcast(): void
    {
        Queue::fake();
        [$project, $version] = $this->succeededBuild('internal');

        $retentionQueuedAtBroadcast = null;
        Event::listen(PlatformEvent::class, function (PlatformEvent $e) use (&$retentionQueuedAtBroadcast) {
            if ($e->topic === 'build.completed') {
                $retentionQueuedAtBroadcast = Queue::pushed(RetainRegistryImagesJob::class)->count();
            }
        });

        (new CheckBuildReadyJob($version, 1))->handle();

        $this->assertSame('ready', $version->fresh()->build_status);
        $this->assertSame(0, $retentionQueuedAtBroadcast, 'build.completed goes out before retention is queued');
        Queue::assertPushed(RetainRegistryImagesJob::class, fn ($job) => $job->project->is($project));

        // The build check itself never talks to the registry
        $this->assertSame([], array_filter($this->calls, fn ($c) => str_contains($c, 'runr-zot')));
    }

    public function test_no_retention_for_an_external_registry(): void
    {
        Queue::fake();
        [, $version] = $this->succeededBuild('external');

        (new CheckBuildReadyJob($version, 1))->handle();

        $this->assertSame('ready', $version->fresh()->build_status);
        Queue::assertNotPushed(RetainRegistryImagesJob::class);
    }
}
