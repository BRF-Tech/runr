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
use App\Services\RegionReconcile\Steps\ImageRegistryStep;
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

    /** @var list<array{0: string, 1: ?string}> deleted Jobs: [name, propagationPolicy] */
    private array $deleted = [];

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
                if ($method === 'DELETE' && isset($this->jobs[$m[1]])) {
                    $this->deleted[] = [$m[1], json_decode($request->body(), true)['propagationPolicy'] ?? null];
                    unset($this->jobs[$m[1]]);

                    return Http::response(['kind' => 'Status', 'status' => 'Success']);
                }

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

    /**
     * The Job started before image-registry rolled Zot onto RunR's image namespace: Zot restarted
     * under it and every image failed. The step runs last, after every step that restarts Zot.
     */
    public function test_the_step_runs_after_the_steps_that_restart_the_registry(): void
    {
        $steps = array_map(fn ($s) => $s::class, app(RegionReconciler::class)->steps());
        $prewarm = array_search(RegistryPrewarmStep::class, $steps, true);

        $this->assertGreaterThan(array_search(RegistrySyncConfigStep::class, $steps, true), $prewarm);
        $this->assertGreaterThan(array_search(ImageRegistryStep::class, $steps, true), $prewarm);
        $this->assertSame(count($steps) - 1, $prewarm, 'the last step');
        $this->assertSame('registry-prewarm', (new RegistryPrewarmStep)->name());
    }

    /**
     * A failed Job, named after the image list, kept every reconcile within its TTL (24 h) from
     * starting another one. A failed Job is deleted with its pods and started again; one that runs
     * (also while it retries a failed pod) or succeeded is left alone.
     */
    public function test_a_failed_job_is_started_again(): void
    {
        $this->region = $this->region();
        $step = new RegistryPrewarmStep;
        $cluster = $this->cluster($this->region);
        $step->run($cluster, false);
        $name = (string) array_key_first($this->jobs);
        $this->assertStringStartsWith(ZotManifests::PREWARM_JOB_PREFIX, $name);

        $this->jobs[$name]['status'] = ['active' => 1, 'failed' => 1];
        $this->assertSame([], $step->run($cluster, false), 'running: a new pod after a failed one');
        $this->jobs[$name]['status'] = ['succeeded' => 1, 'conditions' => [['type' => 'Complete', 'status' => 'True']]];
        $this->assertSame([], $step->run($cluster, false), 'succeeded');
        $this->assertSame([], $this->deleted);

        $this->jobs[$name]['status'] = ['failed' => 3, 'conditions' => [
            ['type' => 'FailureTarget', 'status' => 'True', 'reason' => 'BackoffLimitExceeded'],
            ['type' => 'Failed', 'status' => 'True', 'reason' => 'BackoffLimitExceeded'],
        ]];

        $dry = $step->run($cluster, true);
        $this->assertCount(1, $dry);
        $this->assertStringStartsWith('would fetch ', $dry[0]);
        $this->assertStringContainsString('(Job '.self::NS."/{$name}, again: the previous run failed): ", $dry[0]);
        $this->assertSame([], $this->deleted);
        $this->assertArrayHasKey('status', $this->jobs[$name]);

        $lines = $step->run($cluster, false);

        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('fetching ', $lines[0]);
        $this->assertStringContainsString('(Job '.self::NS."/{$name}, again: the previous run failed): ", $lines[0]);
        $this->assertSame([[$name, 'Background']], $this->deleted, 'deleted with its pods');
        $this->assertSame([$name], array_keys($this->jobs));
        $this->assertArrayNotHasKey('status', $this->jobs[$name], 'a new Job');

        // the new Job runs: left alone again
        $this->assertSame([], $step->run($cluster, false));
        $this->assertCount(1, $this->deleted);
    }

    public function test_the_job_script_waits_for_zot_and_fails_on_an_image_it_could_not_fetch(): void
    {
        $job = ZotManifests::prewarmJob('j', self::NS, self::ZOT, [['ghcr.io/brf-tech/runr/exec-python', '3.14']]);
        $script = $job['spec']['template']['spec']['containers'][0]['command'][2];
        $lines = explode("\n", $script);

        // Zot answers /v2/ before the first image and before each further try, backing off 2 → 30 s, 600 s at most
        $this->assertStringContainsString('up() { w=2; t=0; until wget -q -O /dev/null -T 10 "$R/v2/"; do [ "$t" -ge 600 ]', $script);
        $this->assertStringContainsString('w=$((w*2)); if [ "$w" -gt 30 ]; then w=30; fi; done; }', $script);
        $this->assertLessThan(array_search("fetch 'ghcr.io/brf-tech/runr/exec-python' '3.14'", $lines, true), array_search('up || exit 1', $lines, true));
        $this->assertStringContainsString('if [ "$n" -ge 3 ]; then echo "$1:$2 could not be fetched"; failed=1; return; fi', $script);
        $this->assertStringContainsString('sleep $((n*5)); up || exit 1; done;', $script);
        $this->assertStringContainsString('-T 1800 --header "$A" "$R/v2/$1/manifests/$2"', $script);
        $this->assertStringContainsString("A='Accept: application/vnd.oci.image.manifest.v1+json", $script);
        $this->assertStringEndsWith('exit $failed', $script);

        // a failed pod is tried again in a new one, within the Job's deadline
        $this->assertSame(2, $job['spec']['backoffLimit']);
        $this->assertSame(3600, $job['spec']['activeDeadlineSeconds']);
    }

    /**
     * The script itself (POSIX sh, wget and sleep stubbed): Zot restarting under the Job, an image
     * Zot cannot fetch, Zot not answering at all.
     */
    public function test_the_job_script_rides_out_a_zot_restart(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! is_executable('/bin/sh')) {
            $this->markTestSkipped('needs a POSIX shell');
        }

        $images = [['ghcr.io/brf-tech/runr/exec-python', '3.14'], ['ghcr.io/brf-tech/runr/exec-nodejs', '24.14']];

        // the second image's first try hits the restart: Zot answers /v2/ again on the third call
        [$code, $out, $calls, $sleeps] = $this->runPrewarm($images, ['ok', 'ok', 'fail', 'fail', 'fail', 'ok', 'ok']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('ghcr.io/brf-tech/runr/exec-python:3.14 in the registry after', $out);
        $this->assertStringContainsString('ghcr.io/brf-tech/runr/exec-nodejs:24.14 not fetched yet, try 2 once the registry answers', $out);
        $this->assertStringContainsString('ghcr.io/brf-tech/runr/exec-nodejs:24.14 in the registry after', $out);
        $zot = 'http://'.self::ZOT;
        $this->assertSame([
            "{$zot}/v2/", "{$zot}/v2/ghcr.io/brf-tech/runr/exec-python/manifests/3.14", "{$zot}/v2/ghcr.io/brf-tech/runr/exec-nodejs/manifests/24.14",
            "{$zot}/v2/", "{$zot}/v2/", "{$zot}/v2/", "{$zot}/v2/ghcr.io/brf-tech/runr/exec-nodejs/manifests/24.14",
        ], $calls);
        $this->assertSame([10, 2, 4], $sleeps, '10 s after the failed try, then backing off while Zot does not answer');

        // an image Zot cannot fetch: three tries, the others still fetched, the pod fails
        [$code, $out, $calls] = $this->runPrewarm($images, ['ok', 'fail', 'ok', 'fail', 'ok', 'fail', 'ok']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('ghcr.io/brf-tech/runr/exec-python:3.14 could not be fetched', $out);
        $this->assertStringContainsString('ghcr.io/brf-tech/runr/exec-nodejs:24.14 in the registry after', $out);
        $this->assertCount(3, array_filter($calls, fn ($c) => str_ends_with($c, 'exec-python/manifests/3.14')));

        // Zot does not answer: the wait is bounded
        [$code, $out, $calls, $sleeps] = $this->runPrewarm($images, [], 'fail');
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('registry http://'.self::ZOT.' does not answer', $out);
        $this->assertSame([2, 4, 8, 16], array_slice($sleeps, 0, 4));
        $this->assertSame(30, max($sleeps));
        $this->assertGreaterThanOrEqual(600, array_sum($sleeps));
        $this->assertLessThan(630, array_sum($sleeps));
        $this->assertSame([], array_filter($calls, fn ($c) => str_contains($c, '/manifests/')));
    }

    /**
     * Runs the pre-warm script with wget answering by $plan (one "ok" / "fail" per call, then
     * $then) and sleep recorded instead of waiting.
     *
     * @param  list<array{0: string, 1: string}>  $images
     * @param  list<string>  $plan
     * @return array{0: int, 1: string, 2: list<string>, 3: list<int>} [exit code, output, wget URLs, sleeps]
     */
    private function runPrewarm(array $images, array $plan, string $then = 'ok'): array
    {
        $dir = sys_get_temp_dir().'/runr-prewarm-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("{$dir}/plan", $plan === [] ? '' : implode("\n", $plan)."\n");

        $stubs = 'S='.escapeshellarg($dir)."\nTHEN=".escapeshellarg($then)."\n".<<<'SH'
wget() { eval "_u=\${$#}"; echo "$_u" >> "$S/calls"; _r=$(head -n 1 "$S/plan"); sed -i 1d "$S/plan"; [ "${_r:-$THEN}" = ok ]; }
sleep() { echo "$1" >> "$S/sleeps"; }

SH;
        $script = ZotManifests::prewarmJob('j', self::NS, self::ZOT, $images)['spec']['template']['spec']['containers'][0]['command'][2];
        exec('sh -c '.escapeshellarg($stubs.$script).' 2>&1', $out, $code);

        $read = fn (string $f) => is_file("{$dir}/{$f}") ? file("{$dir}/{$f}", FILE_IGNORE_NEW_LINES) : [];
        $result = [$code, implode("\n", $out), $read('calls'), array_map('intval', $read('sleeps'))];
        array_map('unlink', glob("{$dir}/*") ?: []);
        rmdir($dir);

        return $result;
    }
}
