<?php

namespace Tests\Feature;

use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use App\Services\Manifests\BuildManifests;
use App\Services\Manifests\ZotManifests;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionReconcile\Steps\RegistryPrewarmStep;
use App\Services\RegionReconcile\Steps\RegistrySyncConfigStep;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Kaniko builds hit their 300 s deadline while the region's Zot fetched a new base image from
 * upstream for the first time (4m33s–4m52s measured). region:reconcile has Zot fetch the base
 * images of the release's function builds ahead of the first build: one Job in the registry
 * namespace for the images Zot does not hold yet.
 */
class RegistryPrewarmReconcileStepTest extends TestCase
{
    use RefreshDatabase;

    private const NS = 'runr-registry';

    private const ZOT = '10.43.0.5:5000';

    private ?string $zotConfig = null;

    /** @var array<string, list<string>> repository => tags Zot holds */
    private array $held = [];

    /** @var array<string, array> Jobs in the registry namespace */
    private array $jobs = [];

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([['python', '3.14'], ['nodejs', '24.14'], ['php', '8.4'], ['go', '1.26']] as $i => [$runtime, $version]) {
            ExecutorImage::create(['runtime' => $runtime, 'version' => $version, 'docker_image' => "ghcr.io/brf-tech/runr/exec-{$runtime}:{$version}", 'is_default' => true, 'is_active' => true, 'sort_order' => $i]);
        }
        config(['executor.runtimes' => ['python', 'nodejs', 'php', 'go', 'wasm-rust']]);
        $this->zotConfig = json_encode(ZotManifests::mirrorConfig());

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();
            $proxy = '/api/v1/namespaces/'.self::NS.'/services/http:runr-zot:5000/proxy/v2/';

            if ($path === '/api/v1/namespaces/'.self::NS.'/configmaps/runr-zot-config') {
                return $this->zotConfig === null ? Http::response(['kind' => 'Status', 'code' => 404], 404) : Http::response(['data' => ['config.json' => $this->zotConfig]]);
            }
            if (str_starts_with($path, $proxy) && preg_match('#^(.+)/tags/list$#', substr($path, strlen($proxy)), $m)) {
                return isset($this->held[$m[1]])
                    ? Http::response(['name' => $m[1], 'tags' => $this->held[$m[1]]])
                    : Http::response(['errors' => [['code' => 'NAME_UNKNOWN']]], 404);
            }
            if ($method === 'POST' && $path === '/apis/batch/v1/namespaces/'.self::NS.'/jobs') {
                $job = json_decode($request->body(), true);
                $this->jobs[$job['metadata']['name']] = $job;

                return Http::response($job, 201);
            }
            if (preg_match('#^/apis/batch/v1/namespaces/'.self::NS.'/jobs/([^/]+)$#', $path, $m)) {
                return isset($this->jobs[$m[1]]) ? Http::response($this->jobs[$m[1]]) : Http::response(['kind' => 'Status', 'code' => 404], 404);
            }

            return Http::response(['kind' => 'Status', 'code' => 404], 404);
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
                'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://zot.test:6443', 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'is_active' => true,
            'knative_serving_status' => 'ready',
            'registry_mode' => 'internal',
            'registry_status' => 'ready',
            'registry_address' => self::ZOT,
            'registry_namespace' => self::NS,
        ]);
    }

    private function cluster(KnativeRegion ...$regions): ClusterGroup
    {
        return new ClusterGroup('uid-z', array_map(fn (KnativeRegion $r) => new ReconcileTarget($r), $regions), new KubernetesClient($regions[0]->kubeconfig));
    }

    /** [repository, tag] in the region's registry of an image a build starts FROM */
    private function local(string $image): array
    {
        $ref = substr($this->region->buildBaseImageRef($image, true), strlen(self::ZOT) + 1);
        $colon = strrpos($ref, ':');

        return [substr($ref, 0, $colon), substr($ref, $colon + 1)];
    }

    public function test_the_release_base_images_are_those_function_builds_start_from(): void
    {
        $runtimes = app(RuntimeRegistry::class);
        $images = $runtimes->buildBaseImages();

        foreach (['python', 'nodejs', 'php', 'go'] as $id) {
            $this->assertContains(ExecutorImage::resolve($id)->docker_image, $images, $id);
        }
        // the dependency install image of PHP and the image Go binaries run in
        $this->assertContains($runtimes->manifest('php')->dependencies()['image'], $images);
        $this->assertContains($runtimes->manifest('go')->build()['runImage'], $images);
        // WebAssembly runtimes build in their toolchain Job, not with Kaniko
        $this->assertSame([], array_values(array_filter($images, fn ($i) => str_contains($i, 'wasm'))));
    }

    public function test_reconcile_fetches_the_images_zot_does_not_hold_yet_once(): void
    {
        $images = app(RuntimeRegistry::class)->buildBaseImages();
        $this->region = $this->region();
        [$heldRepo, $heldTag] = $this->local(ExecutorImage::resolve('python')->docker_image);
        $this->held[$heldRepo] = [$heldTag];
        $step = new RegistryPrewarmStep;
        $cluster = $this->cluster($this->region, $this->region(['name' => 'fn-2']));

        $lines = $step->run($cluster, false);

        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('fetching '.(count($images) - 1).' build base image(s) into the registry (Job runr-registry/'.ZotManifests::PREWARM_JOB_PREFIX, $lines[0]);
        $this->assertCount(1, $this->jobs);
        $job = array_values($this->jobs)[0];
        $script = $job['spec']['template']['spec']['containers'][0]['command'][2];
        $this->assertSame(BuildManifests::INIT_IMAGE, $job['spec']['template']['spec']['containers'][0]['image']);
        $this->assertStringContainsString("R='http://".self::ZOT."'", $script);
        foreach ($images as $image) {
            [$repo, $tag] = $this->local($image);
            $line = "fetch '{$repo}' '{$tag}'";
            $image === ExecutorImage::resolve('python')->docker_image
                ? $this->assertStringNotContainsString($line, $script)
                : $this->assertStringContainsString($line, $script);
        }
        $this->assertSame(3600, $job['spec']['activeDeadlineSeconds']);
        $this->assertTrue($job['spec']['template']['spec']['securityContext']['runAsNonRoot']);

        // Second run while the Job exists: nothing new
        $this->assertSame([], $step->run($cluster, false));
        $this->assertCount(1, $this->jobs);

        // Everything held: up to date
        $this->jobs = [];
        foreach ($images as $image) {
            [$repo, $tag] = $this->local($image);
            $this->held[$repo][] = $tag;
        }
        $this->assertSame([], $step->run($cluster, false));
        $this->assertSame([], $this->jobs);
    }

    public function test_dry_run_starts_nothing(): void
    {
        $lines = (new RegistryPrewarmStep)->run($this->cluster($this->region()), true);

        $this->assertStringStartsWith('would fetch ', $lines[0]);
        $this->assertSame([], $this->jobs);
    }

    public function test_without_the_ghcr_upstream_or_an_internal_registry_nothing_is_fetched(): void
    {
        $this->zotConfig = null;
        $lines = (new RegistryPrewarmStep)->run($this->cluster($this->region()), false);
        $this->assertStringStartsWith('skipped: the registry has no ghcr.io upstream', $lines[0]);

        $this->zotConfig = json_encode(ZotManifests::mirrorConfig());
        $this->assertSame([], (new RegistryPrewarmStep)->run($this->cluster($this->region(['name' => 'ext', 'registry_mode' => 'external'])), false));
        $this->assertSame([], $this->jobs);
    }

    public function test_the_step_runs_after_the_registry_sync_config(): void
    {
        $steps = array_map(fn ($s) => $s::class, app(RegionReconciler::class)->steps());

        $this->assertGreaterThan(array_search(RegistrySyncConfigStep::class, $steps, true), array_search(RegistryPrewarmStep::class, $steps, true));
        $this->assertSame('registry-prewarm', (new RegistryPrewarmStep)->name());
    }

    public function test_the_job_script_waits_for_zot_and_fails_on_an_image_it_could_not_fetch(): void
    {
        $script = ZotManifests::prewarmJob('j', self::NS, self::ZOT, [['ghcr.io/brf-tech/runr/exec-python', '3.14']])['spec']['template']['spec']['containers'][0]['command'][2];

        $this->assertStringContainsString('until wget -q -O /dev/null "$R/v2/"', $script);
        $this->assertStringContainsString('-T 1800 --header "$A" "$R/v2/$1/manifests/$2"', $script);
        $this->assertStringContainsString("A='Accept: application/vnd.oci.image.manifest.v1+json", $script);
        $this->assertStringEndsWith('exit $failed', $script);
    }
}
