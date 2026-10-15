<?php

namespace Tests\Feature\Runtimes;

use App\Jobs\CheckRuntimeImageBuildJob;
use App\Models\KnativeRegion;
use App\Models\RuntimeBuild;
use App\Models\User;
use App\Services\Runtimes\RuntimeImageBuildService;
use App\Services\Runtimes\RuntimeManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * Runtime versions built from a Dockerfile (uploaded files) or a git source: one Kaniko Job per
 * region into the region registry, cached by digest, followed by CheckRuntimeImageBuildJob.
 */
class RuntimeImageBuildTest extends TestCase
{
    use RefreshDatabase, RegistersRuntimes;

    /** @var list<array{method: string, path: string, body: array}> */
    private array $calls = [];

    /** Answer of GET .../jobs/<name> (status of the Kaniko Job) */
    private array $jobStatus = ['active' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([CheckRuntimeImageBuildJob::class]);

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = ['method' => $request->method(), 'path' => $path, 'body' => json_decode($request->body(), true) ?? []];

            if ($request->method() === 'GET' && str_contains($path, '/jobs/')) {
                return Http::response(['status' => $this->jobStatus]);
            }
            if ($request->method() === 'GET' && str_ends_with($path, '/pods')) {
                return Http::response(['items' => [['metadata' => ['name' => 'rt-pod']]]]);
            }
            if ($request->method() === 'GET' && str_contains($path, '/log')) {
                return Http::response("error building image: COPY failed\n");
            }

            return $request->method() === 'GET' ? Http::response(['kind' => 'Status', 'code' => 404], 404) : Http::response(['kind' => 'Status'], 200);
        });
    }

    private function region(): KnativeRegion
    {
        return KnativeRegion::create([
            'name' => 'eu',
            'project_namespace' => 'fn',
            'is_active' => true,
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1',
                'kind' => 'Config',
                'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://region.test:6443', 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'registry_mode' => 'internal',
            'registry_status' => 'ready',
            'registry_address' => '10.43.0.5:5000',
            'registry_namespace' => 'runr-registry',
        ]);
    }

    private function posted(string $plural): ?array
    {
        $call = collect($this->calls)->first(fn ($c) => $c['method'] === 'POST' && str_ends_with($c['path'], "/namespaces/fn/{$plural}"));

        return $call['body'] ?? null;
    }

    public function test_a_dockerfile_version_builds_from_the_uploaded_files(): void
    {
        $runtime = $this->registerRuntime($this->qManifest(['versions' => [['version' => '1.0', 'buildArgs' => ['Q_VERSION' => '1.0']]]]), $this->qFiles());
        $region = $this->region();
        $spec = (new RuntimeManifest($runtime->manifest))->version('1.0');

        $build = app(RuntimeImageBuildService::class)->ensure($runtime, $spec, $region);

        $this->assertSame(RuntimeBuild::STATUS_BUILDING, $build->status);
        $this->assertMatchesRegularExpression('~^10\.43\.0\.5:5000/runr-runtimes/q:1\.0-[0-9a-f]{12}$~', $build->image);
        $this->assertMatchesRegularExpression('~^rt-q-1-0-[0-9a-f]{8}$~', $build->job_name);

        $cm = $this->posted('configmaps');
        $this->assertSame($this->qFiles(), $cm['data']);
        $this->assertSame('q', $cm['metadata']['labels']['runr/runtime']);

        $job = $this->posted('jobs');
        $pod = $job['spec']['template']['spec'];
        $args = $pod['containers'][0]['args'];
        $this->assertContains('--context=dir:///workspace', $args);
        $this->assertContains('--dockerfile=/workspace/Dockerfile', $args);
        $this->assertContains('--build-arg=Q_VERSION=1.0', $args);
        $this->assertContains("--destination={$build->image}", $args);
        $this->assertContains('--insecure-registry=10.43.0.5:5000', $args);
        $this->assertSame(RuntimeImageBuildService::JOB_DEADLINE_SECONDS, $job['spec']['activeDeadlineSeconds']);
        $this->assertSame('copy-src', $pod['initContainers'][0]['name']);
        $this->assertSame(['name' => 'src', 'configMap' => ['name' => $build->job_name]], $pod['volumes'][1]);
        Bus::assertDispatched(CheckRuntimeImageBuildJob::class);

        // the same source is not built twice; changed files are
        $this->calls = [];
        $this->assertSame($build->id, app(RuntimeImageBuildService::class)->ensure($runtime, $spec, $region)->id);
        $this->assertNull($this->posted('jobs'));

        $runtime->update(['files' => ['Dockerfile' => "FROM alpine\n"] + $this->qFiles()]);
        $rebuilt = app(RuntimeImageBuildService::class)->ensure($runtime->fresh(), $spec, $region);
        $this->assertNotSame($build->digest, $rebuilt->digest);
        $this->assertNotNull($this->posted('jobs'));
        $this->assertSame(1, RuntimeBuild::count());
    }

    public function test_a_git_version_builds_from_the_repository(): void
    {
        $runtime = $this->registerRuntime($this->bashManifest(['versions' => [['version' => '6', 'image' => null, 'git' => [
            'url' => 'https://github.com/acme/bash-runtime.git', 'ref' => 'main', 'path' => 'image/', 'dockerfile' => 'Containerfile',
        ]]]]));
        $spec = (new RuntimeManifest($runtime->manifest))->version('6');

        app(RuntimeImageBuildService::class)->ensure($runtime, $spec, $this->region());

        $pod = $this->posted('jobs')['spec']['template']['spec'];
        $args = $pod['containers'][0]['args'];
        $this->assertContains('--context=git://github.com/acme/bash-runtime.git#refs/heads/main', $args);
        $this->assertContains('--context-sub-path=image', $args);
        $this->assertContains('--dockerfile=Containerfile', $args);
        $this->assertArrayNotHasKey('initContainers', $pod);
        $this->assertNull($this->posted('configmaps'));
    }

    public function test_registering_builds_in_every_region_with_a_registry(): void
    {
        $region = $this->region();
        KnativeRegion::create(['name' => 'no-registry', 'is_active' => true, 'kubeconfig' => $region->kubeconfig]);
        $runtime = $this->registerRuntime($this->qManifest(), $this->qFiles());

        app(RuntimeImageBuildService::class)->ensureAll($runtime);

        $this->assertSame([$region->id], RuntimeBuild::pluck('knative_region_id')->all());
    }

    public function test_the_check_job_follows_the_kaniko_job(): void
    {
        $runtime = $this->registerRuntime($this->qManifest(), $this->qFiles());
        $build = app(RuntimeImageBuildService::class)->ensure($runtime, (new RuntimeManifest($runtime->manifest))->version('1.0'), $this->region());

        // running: checked again
        (new CheckRuntimeImageBuildJob($build))->handle();
        $this->assertSame(RuntimeBuild::STATUS_BUILDING, $build->fresh()->status);
        Bus::assertDispatched(CheckRuntimeImageBuildJob::class, fn ($job) => $job->attempt === 2);

        $this->jobStatus = ['succeeded' => 1];
        (new CheckRuntimeImageBuildJob($build))->handle();
        $this->assertSame(RuntimeBuild::STATUS_READY, $build->fresh()->status);

        $build->refresh()->update(['status' => RuntimeBuild::STATUS_BUILDING]);   // refresh: the job changed the row
        $this->jobStatus = ['failed' => 1];
        (new CheckRuntimeImageBuildJob($build))->handle();
        $this->assertSame(RuntimeBuild::STATUS_FAILED, $build->fresh()->status);
        $this->assertStringContainsString('COPY failed', $build->fresh()->error);

        $build->refresh()->update(['status' => RuntimeBuild::STATUS_BUILDING]);   // refresh: the job changed the row
        $this->jobStatus = ['active' => 1];
        (new CheckRuntimeImageBuildJob($build, CheckRuntimeImageBuildJob::maxAttempts()))->handle();
        $this->assertSame('Build timed out', $build->fresh()->error);
    }

    public function test_the_builds_endpoint_starts_and_lists_region_builds(): void
    {
        $region = $this->region();
        $this->registerRuntime($this->qManifest(), $this->qFiles());
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/runtimes/q/builds', ['knative_region_id' => $region->id])
            ->assertStatus(202)
            ->assertJsonPath('data.0.version', '1.0')
            ->assertJsonPath('data.0.status', 'building')
            ->assertJsonPath('data.0.region_name', 'eu');

        $this->actingAs($user, 'sanctum')->postJson('/api/runtimes/python/builds', ['knative_region_id' => $region->id])
            ->assertStatus(422)->assertJsonValidationErrors('version');
    }
}
