<?php

namespace Tests\Feature;

use App\Jobs\RetainRegistryImagesJob;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Services\KnativeDeployService;
use App\Services\Manifests\RegistryImageManifests;
use App\Services\RegistryImageLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The internal (Zot) registry never deleted old build tags or a deleted
 * project's images. Talks to Zot via the K8s API server proxy (never registry_address
 * directly — the central process is not necessarily on the region's network), addressing the
 * Zot Helm release's Service by name ("runr-zot") in registry_namespace, port 5000.
 *
 * Retention must never delete an image that is still in use (active/pending/shutting_down
 * deployment, live Knative Service revision, CronJob, queue consumer): scale-from-zero,
 * reschedules and rollbacks pull it again.
 */
class RegistryImageLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const PROXY = '/api/v1/namespaces/runr-registry/services/http:runr-zot:5000/proxy/v2/';

    /** @var list<string> */
    private array $calls = [];

    /** @var list<string> Accept headers sent to manifest endpoints */
    private array $accepts = [];

    /** The API server answers every registry proxy call with its own bare 404 (no runr-zot Service) */
    private bool $zotServiceMissing = false;

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

    private function region(array $attrs = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => 'fn',
            'project_namespace' => 'fn',
            'kubeconfig' => $this->kubeconfig(),
            'is_active' => true,
            'registry_mode' => 'internal',
            'registry_namespace' => 'runr-registry',
            'registry_address' => '10.43.0.5:5000',
        ], $attrs));
    }

    private function project(KnativeRegion $region, string $name = 'demo'): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();

        return $project->fresh();
    }

    private function version(Project $project, int $number, string $status = 'ready', ?string $repo = null): Version
    {
        $repo ??= RegistryImageManifests::repositoryName($project->slug);

        return Version::create([
            'project_id' => $project->id,
            'number' => $number,
            'created_by' => 'dev',
            'build_status' => $status,
            'build_image' => "10.43.0.5:5000/{$repo}:v{$number}",
        ]);
    }

    /**
     * Fakes the Zot OCI Distribution API behind the K8s service proxy plus the project's
     * workloads in the region namespace:
     *   GET  /v2/{repo}/tags/list              → {tags: [...]}
     *   HEAD /v2/{repo}/manifests/{tag}        → Docker-Content-Digest: sha256:{tag} (tags in $present)
     *   GET  /v2/{repo}/manifests/{tag}        → 404 MANIFEST_UNKNOWN for tags not in $present
     *   DELETE /v2/{repo}/manifests/{digest}   → 202
     *   GET  namespaced K8s objects            → $objects[path] or 404
     *
     * @param  array<string, list<string>>  $present  repo => tags that exist
     * @param  array<string, array>  $objects  K8s API path => object
     */
    private function fakeCluster(array $present, array $objects = [], bool $clusterDown = false): void
    {
        Http::fake(function (Request $request) use ($present, $objects, $clusterDown) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();
            $this->calls[] = "{$method} {$path}";

            if (str_starts_with($path, self::PROXY) && $this->zotServiceMissing) {
                return Http::response($method === 'HEAD' ? '' : ['kind' => 'Status', 'reason' => 'NotFound', 'code' => 404], 404);
            }

            if (str_starts_with($path, self::PROXY)) {
                $rest = substr($path, strlen(self::PROXY));

                if ($method === 'GET' && preg_match('#^(.+)/tags/list$#', $rest, $m)) {
                    return Http::response(['name' => $m[1], 'tags' => $present[$m[1]] ?? []]);
                }

                if (preg_match('#^(.+)/manifests/(.+)$#', $rest, $m)) {
                    [, $repo, $ref] = $m;

                    if ($method === 'DELETE') {
                        return Http::response('', 202);
                    }

                    $this->accepts[] = $request->header('Accept')[0] ?? '';

                    return in_array($ref, $present[$repo] ?? [], true)
                        ? Http::response($method === 'HEAD' ? '' : ['schemaVersion' => 2], 200, ['Docker-Content-Digest' => "sha256:{$ref}"])
                        : Http::response($method === 'HEAD' ? '' : ['errors' => [['code' => 'MANIFEST_UNKNOWN']]], 404);
                }
            }

            if ($clusterDown) {
                throw new ConnectionException('connection refused');
            }

            if ($method === 'GET' && isset($objects[$path])) {
                return Http::response($objects[$path]);
            }

            return Http::response(['kind' => 'Status', 'code' => 404], 404);
        });
    }

    private function deletes(): array
    {
        return array_values(array_filter($this->calls, fn ($c) => str_starts_with($c, 'DELETE ')));
    }

    private function deleted(string $repo, string $tag): string
    {
        return 'DELETE '.self::PROXY."{$repo}/manifests/sha256:{$tag}";
    }

    private function podSpec(string $image): array
    {
        return ['containers' => [['name' => 'user-container', 'image' => $image]]];
    }

    public function test_retention_keeps_the_newest_builds_and_marks_the_rest_pruned(): void
    {
        $region = $this->region();
        $project = $this->project($region);
        foreach ([1, 2, 3, 4] as $n) {
            $this->version($project, $n);
        }
        $this->fakeCluster(['runr-builds/demo' => ['v1', 'v2', 'v3', 'v4']]);

        $pruned = app(RegistryImageLifecycleService::class)->retainForProject($project, 2);

        $this->assertCount(2, $pruned);
        $this->assertEqualsCanonicalizing([
            $this->deleted('runr-builds/demo', 'v1'),
            $this->deleted('runr-builds/demo', 'v2'),
        ], $this->deletes());

        $v1 = $project->versions()->where('number', 1)->first();
        $this->assertSame(Version::STATUS_PRUNED, $v1->build_status);
        $this->assertNull($v1->build_image);
        $this->assertSame('ready', $project->versions()->where('number', 4)->first()->build_status);

        // Digest resolution asks for image manifests/indexes only — not application/json
        $this->assertNotEmpty($this->accepts);
        foreach ($this->accepts as $accept) {
            $this->assertSame(RegistryImageManifests::MANIFEST_ACCEPT, $accept);
            $this->assertStringNotContainsString('application/json', $accept);
        }
    }

    public function test_images_of_unfinished_deployments_are_never_deleted(): void
    {
        $region = $this->region();
        $project = $this->project($region);
        $versions = [];
        foreach ([1, 2, 3, 4, 5, 6] as $n) {
            $versions[$n] = $this->version($project, $n);
        }

        foreach (['active' => 1, 'shutting_down' => 2, 'pending' => 3, 'shut_down' => 4, 'failed' => 5] as $status => $n) {
            Deployment::create(['project_id' => $project->id, 'version_id' => $versions[$n]->id, 'status' => $status]);
        }
        $this->fakeCluster(['runr-builds/demo' => ['v1', 'v2', 'v3', 'v4', 'v5', 'v6']]);

        app(RegistryImageLifecycleService::class)->retainForProject($project, 1);

        $this->assertEqualsCanonicalizing([
            $this->deleted('runr-builds/demo', 'v4'),
            $this->deleted('runr-builds/demo', 'v5'),
        ], $this->deletes());
        $this->assertSame('ready', $versions[1]->fresh()->build_status);
    }

    public function test_images_run_by_the_live_cronjob_or_serving_revision_are_never_deleted(): void
    {
        $region = $this->region();
        $project = $this->project($region);
        foreach ([1, 2, 3, 4] as $n) {
            $this->version($project, $n);
        }

        $ns = '/apis/serving.knative.dev/v1/namespaces/fn';
        $this->fakeCluster(['runr-builds/demo' => ['v1', 'v2', 'v3', 'v4']], [
            // v4 template, but traffic still on the last ready revision (v2) — a failed rollout
            "{$ns}/services/demo" => [
                'spec' => ['template' => ['spec' => $this->podSpec('10.43.0.5:5000/runr-builds/demo:v4')]],
                'status' => ['traffic' => [['revisionName' => 'demo-00002', 'percent' => 100]]],
            ],
            "{$ns}/revisions/demo-00002" => ['spec' => $this->podSpec('10.43.0.5:5000/runr-builds/demo:v2')],
            '/apis/batch/v1/namespaces/fn/cronjobs/demo-trigger' => [
                'spec' => ['jobTemplate' => ['spec' => ['template' => ['spec' => $this->podSpec('10.43.0.5:5000/runr-builds/demo:v1')]]]],
            ],
        ]);

        app(RegistryImageLifecycleService::class)->retainForProject($project, 1);

        $this->assertEqualsCanonicalizing([$this->deleted('runr-builds/demo', 'v3')], $this->deletes());
    }

    public function test_nothing_is_deleted_when_the_live_workloads_cannot_be_read(): void
    {
        $region = $this->region();
        $project = $this->project($region);
        foreach ([1, 2, 3] as $n) {
            $this->version($project, $n);
        }
        $this->fakeCluster(['runr-builds/demo' => ['v1', 'v2', 'v3']], [], clusterDown: true);

        $this->assertSame([], app(RegistryImageLifecycleService::class)->retainForProject($project, 1));
        $this->assertSame([], $this->deletes());
        $this->assertSame(3, $project->versions()->where('build_status', 'ready')->count());
    }

    public function test_repository_comes_from_the_stored_image_after_a_rename(): void
    {
        $region = $this->region();
        $project = $this->project($region, 'old-name');
        $this->version($project, 1, 'ready', 'runr-builds/old-name');
        $this->version($project, 2, 'ready', 'runr-builds/old-name');

        $project->update(['name' => 'new-name']);
        $project = $project->fresh();
        $this->assertSame('new-name', $project->slug);
        $this->version($project, 3);

        $this->fakeCluster(['runr-builds/old-name' => ['v1', 'v2'], 'runr-builds/new-name' => ['v3']]);

        app(RegistryImageLifecycleService::class)->retainForProject($project, 1);

        $this->assertEqualsCanonicalizing([
            $this->deleted('runr-builds/old-name', 'v1'),
            $this->deleted('runr-builds/old-name', 'v2'),
        ], $this->deletes());
    }

    public function test_an_image_already_gone_is_marked_pruned_an_unresolvable_one_is_not(): void
    {
        $region = $this->region();
        $project = $this->project($region);
        $gone = $this->version($project, 1);
        $this->version($project, 2);
        // v1 absent from the registry (MANIFEST_UNKNOWN), v2 present
        $this->fakeCluster(['runr-builds/demo' => ['v2']]);

        app(RegistryImageLifecycleService::class)->retainForProject($project, 1);
        $this->assertSame(Version::STATUS_PRUNED, $gone->fresh()->build_status);

        // Zot Service missing: a bare 404 (no registry error code) proves nothing → not pruned
        $other = $this->project($region, 'other');
        $old = $this->version($other, 1);
        $this->version($other, 2);
        $this->zotServiceMissing = true;

        app(RegistryImageLifecycleService::class)->retainForProject($other, 1);
        $this->assertSame('ready', $old->fresh()->build_status);
    }

    public function test_an_image_another_project_points_at_is_not_deleted(): void
    {
        $region = $this->region();
        $a = $this->project($region, 'a');
        $b = $this->project($region, 'b');
        $shared = $this->version($a, 1, 'ready', 'runr-builds/api');
        $this->version($a, 2);
        Version::create(['project_id' => $b->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready', 'build_image' => $shared->build_image]);
        $this->fakeCluster(['runr-builds/api' => ['v1'], 'runr-builds/a' => ['v2']]);

        app(RegistryImageLifecycleService::class)->retainForProject($a, 1);

        $this->assertSame([], $this->deletes());
        $this->assertSame('ready', $shared->fresh()->build_status);
    }

    public function test_the_job_uses_the_configured_retention_clamped_to_one(): void
    {
        config(['registry.retain_versions' => 0]);
        $region = $this->region();
        $project = $this->project($region);
        $this->version($project, 1);
        $this->version($project, 2);
        $this->fakeCluster(['runr-builds/demo' => ['v1', 'v2']]);

        (new RetainRegistryImagesJob($project))->handle(app(RegistryImageLifecycleService::class));

        $this->assertSame([$this->deleted('runr-builds/demo', 'v1')], $this->deletes());
        $this->assertSame((string) $project->id, (new RetainRegistryImagesJob($project))->uniqueId());
    }

    public function test_a_pruned_version_cannot_be_deployed(): void
    {
        $region = $this->region();
        $project = $this->project($region);
        $version = $this->version($project, 1, Version::STATUS_PRUNED);
        $version->forceFill(['build_image' => null])->save();
        $user = User::factory()->create(['username' => 'deployer']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/deploy", ['version_id' => $version->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('api.project.version_pruned', ['version' => 1]));

        // A deployment created some other way fails instead of running a code-less image
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending']);
        ContainerConfig::create([
            'project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m',
            'memory_mb' => 128, 'timeout_seconds' => 30, 'trigger_type' => 'http',
        ]);
        app(KnativeDeployService::class)->deploy($project->fresh(), $version->fresh(), $deployment);

        $this->assertSame('failed', $deployment->fresh()->status);
    }

    public function test_delete_all_for_project_removes_every_tag(): void
    {
        $region = $this->region();
        $project = $this->project($region, 'demo');
        $this->fakeCluster(['runr-builds/demo' => ['v1', 'v2']]);

        app(RegistryImageLifecycleService::class)->deleteAllForProject($region, $project);

        $this->assertEqualsCanonicalizing([
            $this->deleted('runr-builds/demo', 'v1'),
            $this->deleted('runr-builds/demo', 'v2'),
        ], $this->deletes());
    }

    public function test_external_registry_is_never_touched(): void
    {
        $region = $this->region(['registry_mode' => 'external']);
        $project = $this->project($region, 'demo');
        $this->version($project, 1);
        $this->version($project, 2);
        $this->fakeCluster(['runr-builds/demo' => ['v1', 'v2']]);

        app(RegistryImageLifecycleService::class)->deleteAllForProject($region, $project);
        app(RegistryImageLifecycleService::class)->retainForProject($project, 1);

        $this->assertSame([], $this->calls);
    }

    public function test_a_region_without_a_kubeconfig_touches_no_cluster(): void
    {
        $region = $this->region(['kubeconfig' => null]);
        $project = $this->project($region, 'demo');
        $this->fakeCluster(['runr-builds/demo' => ['v1']]);

        app(RegistryImageLifecycleService::class)->deleteAllForProject($region, $project);

        $this->assertSame([], $this->calls);
    }

    public function test_a_demo_region_touches_no_cluster(): void
    {
        // As DemoSeeder creates it: internal registry, placeholder kubeconfig
        $region = $this->region(['kubeconfig' => 'demo', 'is_demo' => true]);
        $project = $this->project($region, 'demo');
        $this->version($project, 1);
        $this->version($project, 2);
        $this->fakeCluster(['runr-builds/demo' => ['v1', 'v2']]);
        Log::spy();

        app(RegistryImageLifecycleService::class)->deleteAllForProject($region, $project);
        app(RegistryImageLifecycleService::class)->retainForProject($project, 1);

        $this->assertSame([], $this->calls);
        // Before: every project deletion on the demo region logged "Registry K8s client init
        // failed" (the placeholder parsed as a kubeconfig)
        Log::shouldNotHaveReceived('warning');
    }

    public function test_deleting_a_project_cleans_up_its_registry_images(): void
    {
        $region = $this->region();
        $project = $this->project($region, 'demo');

        ContainerConfig::create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'cpu' => '250m',
            'memory_mb' => 128,
            'timeout_seconds' => 30,
            'trigger_type' => 'http',
        ]);
        Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

        $this->fakeCluster([RegistryImageManifests::repositoryName($project->slug) => ['v1', 'v2']]);

        $project->delete();

        $this->assertContains($this->deleted('runr-builds/demo', 'v1'), $this->deletes());
        $this->assertContains($this->deleted('runr-builds/demo', 'v2'), $this->deletes());
        $this->assertNull(Project::find($project->id));
    }
}
