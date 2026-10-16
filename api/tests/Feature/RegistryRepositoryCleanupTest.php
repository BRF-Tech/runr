<?php

namespace Tests\Feature;

use App\Jobs\DeleteRuntimeImagesJob;
use App\Jobs\PruneRegistryRepositoriesJob;
use App\Models\KnativeRegion;
use App\Models\RuntimeBuild;
use App\Models\User;
use App\Services\Manifests\BuildManifests;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegistryImageLifecycleService;
use App\Services\Runtimes\RuntimeImageBuildService;
use App\Services\Runtimes\RuntimeManifest;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\FakesBuildCluster;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * Registry leftovers of the region's internal (Zot) registry:
 *  - a deleted runtime left its images (runr-runtimes/<id>:<version>-<digest>);
 *  - a failed build left an empty runr-builds/<project> repository: Kaniko opened an upload there
 *    to check its push permission, Zot created the repository, and the garbage collection of Zot
 *    releases before the pinned one never removes a repository without blobs.
 */
class RegistryRepositoryCleanupTest extends TestCase
{
    use FakesBuildCluster, RefreshDatabase, RegistersRuntimes;

    private const PROXY = '/api/v1/namespaces/runr-registry/services/http:runr-zot:5000/proxy/v2/';

    /** @var array<string, list<string>> repository => tags */
    private array $repos = [];

    /** @var list<array{method: string, path: string, query: string, body: string, type: string}> */
    private array $zotCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Regions of this test only (no tenant schemas)
        RegionReconciler::clearRegionSources();
    }

    protected function tearDown(): void
    {
        RegionReconciler::clearRegionSources();
        parent::tearDown();
    }

    private function fakeZot(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $query = (string) parse_url($request->url(), PHP_URL_QUERY);
            $method = $request->method();
            $this->zotCalls[] = ['method' => $method, 'path' => $path, 'query' => $query, 'body' => $request->body(), 'type' => $request->header('Content-Type')[0] ?? ''];

            if (! str_starts_with($path, self::PROXY)) {
                return Http::response(['kind' => 'Status', 'code' => 404], 404);
            }
            $rest = substr($path, strlen(self::PROXY));

            if ($rest === '_catalog') {
                parse_str($query, $q);
                $names = array_keys($this->repos);
                sort($names);
                if (isset($q['last'])) {
                    $names = array_values(array_filter($names, fn ($n) => strcmp($n, $q['last']) > 0));
                }
                $page = array_slice($names, 0, (int) $q['n']);
                $more = count($names) > count($page);

                return Http::response(['repositories' => $page], 200, $more ? ['Link' => '</v2/_catalog?last='.end($page).'&n='.$q['n'].'>; rel="next"'] : []);
            }
            if ($method === 'GET' && preg_match('#^(.+)/tags/list$#', $rest, $m)) {
                return isset($this->repos[$m[1]])
                    ? Http::response(['name' => $m[1], 'tags' => $this->repos[$m[1]]])
                    : Http::response(['errors' => [['code' => 'NAME_UNKNOWN']]], 404);
            }
            if (preg_match('#^(.+)/manifests/(.+)$#', $rest, $m)) {
                return $method === 'DELETE'
                    ? Http::response('', 202)
                    : Http::response('', 200, ['Docker-Content-Digest' => "sha256:{$m[2]}"]);
            }
            if ($method === 'POST' && preg_match('#^(.+)/blobs/uploads/$#', $rest)) {
                return Http::response('', 201);
            }

            return Http::response('', 404);
        });
    }

    private function region(array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create($attributes + [
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
            'knative_serving_status' => 'ready',
            'registry_mode' => 'internal',
            'registry_status' => 'ready',
            'registry_address' => '10.43.0.5:5000',
            'registry_namespace' => 'runr-registry',
        ]);
    }

    /** @return list<string> "METHOD repo/rest" of the registry calls */
    private function zot(string $method): array
    {
        return array_values(array_map(
            fn ($c) => substr($c['path'], strlen(self::PROXY)),
            array_filter($this->zotCalls, fn ($c) => $c['method'] === $method && str_starts_with($c['path'], self::PROXY)),
        ));
    }

    // ── Deleted runtimes ────────────────────────────────────────────────────

    public function test_deleting_a_runtime_deletes_its_images_in_the_regions_it_was_built_in(): void
    {
        $region = $this->region();
        $this->region(['name' => 'other', 'registry_address' => '10.43.9.9:5000']);
        $runtime = $this->registerRuntime($this->qManifest(), $this->qFiles());
        RuntimeBuild::create(['runtime_id' => 'q', 'version' => '1.0', 'knative_region_id' => $region->id, 'status' => 'ready', 'digest' => str_repeat('a', 64), 'image' => '10.43.0.5:5000/runr-runtimes/q:1.0-aaaaaaaaaaaa']);
        // An earlier source of the version, and another runtime's image, in the same registry
        $this->repos = ['runr-runtimes/q' => ['1.0-aaaaaaaaaaaa', '1.0-bbbbbbbbbbbb'], 'runr-runtimes/c' => ['17-cccccccccccc']];
        $this->fakeZot();

        $this->actingAs(User::factory()->create(), 'sanctum')->deleteJson("/api/runtimes/{$runtime->id}")->assertNoContent();

        $this->assertEqualsCanonicalizing([
            'runr-runtimes/q/manifests/sha256:1.0-aaaaaaaaaaaa',
            'runr-runtimes/q/manifests/sha256:1.0-bbbbbbbbbbbb',
        ], $this->zot('DELETE'));
        // Only the region it was built in is asked
        $this->assertSame(['runr-runtimes/q/tags/list'], array_values(array_filter($this->zot('GET'), fn ($p) => str_ends_with($p, 'tags/list'))));
    }

    public function test_a_runtime_never_built_in_a_region_touches_no_registry(): void
    {
        Bus::fake([DeleteRuntimeImagesJob::class]);
        $this->region();
        $runtime = $this->registerRuntime($this->bashManifest());

        $this->actingAs(User::factory()->create(), 'sanctum')->deleteJson("/api/runtimes/{$runtime->id}")->assertNoContent();

        Bus::assertNotDispatched(DeleteRuntimeImagesJob::class);
    }

    public function test_a_runtime_registered_again_keeps_the_repository(): void
    {
        $region = $this->region();
        $this->registerRuntime($this->qManifest(), $this->qFiles());
        $this->repos = ['runr-runtimes/q' => ['1.0-aaaaaaaaaaaa']];
        $this->fakeZot();

        (new DeleteRuntimeImagesJob('q', [(string) $region->id]))->handle(app(RegistryImageLifecycleService::class));

        $this->assertSame([], $this->zotCalls);
    }

    public function test_demo_and_external_registries_are_left_alone(): void
    {
        $demo = $this->region(['name' => 'demo', 'kubeconfig' => 'demo', 'is_demo' => true]);
        $external = $this->region(['name' => 'ext', 'registry_mode' => 'external']);
        $this->repos = ['runr-runtimes/q' => ['1.0-aaaaaaaaaaaa'], 'runr-builds/x' => []];
        $this->fakeZot();
        $registry = app(RegistryImageLifecycleService::class);

        foreach ([$demo, $external] as $region) {
            $this->assertSame(0, $registry->deleteRuntimeImages($region, 'q'));
            $this->assertSame([], $registry->pruneEmptyRepositories($region));
        }
        $this->assertSame([], $this->zotCalls);
    }

    // ── Empty repositories ──────────────────────────────────────────────────

    public function test_builds_no_longer_open_an_upload_before_they_built(): void
    {
        $this->setUpBuildCluster();
        $this->submit($this->buildVersion('python', null));
        $this->assertContains('--skip-push-permission-check', $this->created('jobs')['spec']['template']['spec']['containers'][0]['args']);

        $runtime = $this->registerRuntime($this->qManifest(), $this->qFiles());
        $this->calls = [];
        Bus::fake();
        app(RuntimeImageBuildService::class)->ensure($runtime, (new RuntimeManifest($runtime->manifest))->version('1.0'), KnativeRegion::first());
        $this->assertContains(BuildManifests::SKIP_PUSH_PERMISSION_CHECK, $this->created('jobs')['spec']['template']['spec']['containers'][0]['args']);
    }

    public function test_empty_build_repositories_are_handed_to_the_garbage_collection(): void
    {
        $region = $this->region();
        $this->repos = [
            'runr-builds/failed' => [],
            'runr-builds/live' => ['v1', 'v2'],
            'runr-runtimes/gone' => [],
            'runr-runtimes/q' => ['1.0-aaaaaaaaaaaa'],
            // not RunR's build repositories: never touched
            'runr-uploads/platform/tool' => [],
            'ghcr.io/brf-tech/runr/exec-python' => [],
            'library/alpine' => [],
        ];
        $this->fakeZot();

        $pruned = app(RegistryImageLifecycleService::class)->pruneEmptyRepositories($region);

        $this->assertSame(['runr-builds/failed', 'runr-runtimes/gone'], $pruned);
        $uploads = array_values(array_filter($this->zotCalls, fn ($c) => $c['method'] === 'POST'));
        $this->assertSame([self::PROXY.'runr-builds/failed/blobs/uploads/', self::PROXY.'runr-runtimes/gone/blobs/uploads/'], array_column($uploads, 'path'));
        foreach ($uploads as $upload) {
            // One monolithic upload of a small blob nothing references
            $this->assertSame('digest='.RegistryImageLifecycleService::gcSeedDigest(), urldecode($upload['query']));
            $this->assertSame('sha256:'.hash('sha256', $upload['body']), RegistryImageLifecycleService::gcSeedDigest());
            $this->assertSame('application/octet-stream', $upload['type']);
        }
        $this->assertSame([], $this->zot('DELETE'));
    }

    public function test_the_catalog_is_read_page_by_page(): void
    {
        $region = $this->region();
        foreach (range(1, 1000) as $i) {
            $this->repos[sprintf('runr-builds/p%04d', $i)] = ['v1'];
        }
        $this->repos['runr-builds/zz-empty'] = [];
        $this->fakeZot();

        $this->assertSame(['runr-builds/zz-empty'], app(RegistryImageLifecycleService::class)->pruneEmptyRepositories($region));
        $this->assertCount(2, array_filter($this->zot('GET'), fn ($p) => $p === '_catalog'));
    }

    public function test_the_daily_job_covers_each_registry_once(): void
    {
        $this->region();
        $this->region(['name' => 'same-cluster']);
        $this->region(['name' => 'inactive', 'is_active' => false, 'registry_address' => '10.43.7.7:5000']);
        $this->repos = ['runr-builds/failed' => []];
        $this->fakeZot();

        dispatch_sync(new PruneRegistryRepositoriesJob);

        $this->assertSame(['runr-builds/failed/blobs/uploads/'], $this->zot('POST'));

        $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => $e->description === 'registry-empty-repositories');
        $this->assertNotNull($event, 'scheduled task registry-empty-repositories');
        $this->assertSame('45 4 * * *', $event->expression);
    }
}
