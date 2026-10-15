<?php

namespace Tests\Feature\Wasm;

use App\Models\ExecutorImage;
use App\Services\KnativeDeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * Deploy manifests of WebAssembly functions (WasmPodSpec via RuntimeContainerSpec): the Spin
 * shim's RuntimeClass whatever the region maps the project type to, the nodeSelector of the
 * shim's nodes, command "/" on the Spin artifact, the queue consumer for queue triggers and the
 * native-sidecar CronJob of the schedule trigger.
 */
class WasmDeployManifestTest extends TestCase
{
    use RefreshDatabase, RegistersRuntimes;

    private const IMAGE = '10.43.0.5:5000/runr-builds/hello:v1';

    private const WASM_REGION = ['sandbox_wasm_status' => 'ready', 'sandbox_wasm_shims' => ['spin']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltinVersions();
        foreach (['wasm-rust', 'wasm-js'] as $runtime) {
            ExecutorImage::create(['runtime' => $runtime, 'version' => '4.1', 'docker_image' => "ghcr.io/runrsh/exec-{$runtime}:4.1", 'is_default' => true, 'is_active' => true]);
        }
    }

    private function manifests($project, $version): array
    {
        return app(KnativeDeployService::class)->buildManifests($project, $version);
    }

    private function podSpec(array $manifests, string $kind = 'Service'): array
    {
        $m = collect($manifests)->firstWhere('kind', $kind);

        return $kind === 'CronJob' ? $m['spec']['jobTemplate']['spec']['template']['spec'] : $m['spec']['template']['spec'];
    }

    private function env(array $container): array
    {
        return array_column($container['env'] ?? [], 'value', 'name');
    }

    private function region(array $extra = []): array
    {
        return self::WASM_REGION + $extra;
    }

    public function test_an_http_function_runs_on_the_spin_shim_nodes(): void
    {
        // the region runs XaaS projects under gVisor: the Wasm RuntimeClass replaces it
        [$project, $version] = $this->runtimeProject('wasm-rust', 'http', ['lib.rs' => 'x'], $this->region(['sandbox_xaas_runtime' => 'gvisor']), self::IMAGE);

        $manifests = $this->manifests($project, $version);
        $this->assertSame(['Service'], array_column($manifests, 'kind'), 'no code ConfigMap: Wasm versions are built');

        $spec = $this->podSpec($manifests);
        $container = $spec['containers'][0];
        $env = $this->env($container);

        $this->assertSame('wasmtime-spin-v2', $spec['runtimeClassName']);
        $this->assertSame(['runr.dev/wasm-spin' => 'true'], $spec['nodeSelector']);
        $this->assertSame(self::IMAGE, $container['image']);
        $this->assertSame(['/'], $container['command']);
        $this->assertSame([['containerPort' => 8080]], $container['ports']);
        $this->assertSame('0.0.0.0:8080', $env['SPIN_HTTP_LISTEN_ADDR']);
        $this->assertSame(['http', 'wasm-rust', '4.1'], [$env['RUNR_TRIGGER'], $env['RUNR_RUNTIME'], $env['RUNR_RUNTIME_VERSION']]);
        $this->assertArrayNotHasKey('RUNR_WRAPPER_MODE', $env);
        $this->assertArrayNotHasKey('volumes', $spec);
        $this->assertArrayNotHasKey('volumeMounts', $container);
        $this->assertArrayNotHasKey('initContainers', $spec, 'no wrapper init container');
    }

    public function test_dedicated_workers_and_the_shim_nodes_are_both_selected(): void
    {
        [$project, $version] = $this->runtimeProject('wasm-js', 'http', ['index.js' => 'x'], $this->region(['selected_workers' => ['w1']]), self::IMAGE);

        $spec = $this->podSpec($this->manifests($project, $version));

        $this->assertSame(['runr.dev/dedicated' => 'projects', 'runr.dev/wasm-spin' => 'true'], $spec['nodeSelector']);
        $this->assertSame('runr.dev/dedicated', $spec['tolerations'][0]['key']);
    }

    public function test_a_queue_function_gets_its_consumer_and_a_cluster_local_service(): void
    {
        [$project, $version] = $this->runtimeProject('wasm-rust', 'queue', ['lib.rs' => 'x'], $this->region(), self::IMAGE);

        $manifests = $this->manifests($project, $version);
        // the consumer reads the Redis address and password from its Secret <slug>-qc-redis (docs/security.md)
        $this->assertSame(['Service', 'Secret', 'Deployment'], array_column($manifests, 'kind'));
        $this->assertSame("{$project->slug}-qc-redis", collect($manifests)->firstWhere('kind', 'Secret')['metadata']['name']);

        $service = collect($manifests)->firstWhere('kind', 'Service');
        $this->assertSame('cluster-local', $service['metadata']['labels']['networking.knative.dev/visibility']);
        $spec = $this->podSpec($manifests);
        $this->assertSame('wasmtime-spin-v2', $spec['runtimeClassName']);
        $this->assertSame('queue', $this->env($spec['containers'][0])['RUNR_TRIGGER']);
    }

    public function test_a_schedule_function_runs_the_app_as_a_sidecar_next_to_the_tick(): void
    {
        [$project, $version] = $this->runtimeProject('wasm-rust', 'schedule', ['lib.rs' => 'x'], $this->region(), self::IMAGE);

        $manifests = $this->manifests($project, $version);
        $this->assertSame(['CronJob'], array_column($manifests, 'kind'));
        $spec = $this->podSpec($manifests, 'CronJob');

        $this->assertSame('wasmtime-spin-v2', $spec['runtimeClassName']);
        $this->assertSame(['runr.dev/wasm-spin' => 'true'], $spec['nodeSelector']);
        $this->assertSame('Never', $spec['restartPolicy']);

        // the Spin app: a native sidecar that serves :8080 until the Job is done
        $this->assertCount(1, $spec['initContainers']);
        $app = $spec['initContainers'][0];
        $this->assertSame('handler', $app['name']);
        $this->assertSame('Always', $app['restartPolicy']);
        $this->assertSame(['/'], $app['command']);
        $this->assertSame(self::IMAGE, $app['image']);
        $this->assertSame(['tcpSocket' => ['port' => 8080], 'periodSeconds' => 1, 'failureThreshold' => 120], $app['startupProbe']);
        $this->assertSame([['containerPort' => 8080]], $app['ports']);
        $env = $this->env($app);
        $this->assertSame(['schedule', '8080', '0.0.0.0:8080'], [$env['RUNR_TRIGGER'], $env['PORT'], $env['SPIN_HTTP_LISTEN_ADDR']]);

        // the Job's container: the wrapper's tick (a Linux container under the same RuntimeClass)
        $this->assertCount(1, $spec['containers']);
        $tick = $spec['containers'][0];
        $this->assertSame('runr-tick', $tick['name']);
        $this->assertSame('ghcr.io/runrsh/runr-wrapper:1.1.0', $tick['image']);
        $this->assertSame(['/runr-wrapper', 'tick'], $tick['command']);
        $this->assertSame(['8080', '30'], [$this->env($tick)['PORT'], $this->env($tick)['RUNR_TIMEOUT']]);
    }

    public function test_a_region_without_webassembly_support_is_refused(): void
    {
        [$project, $version] = $this->runtimeProject('wasm-rust', 'http', ['lib.rs' => 'x'], [], self::IMAGE);

        $this->expectExceptionMessage('needs WebAssembly support with the Spin shim');
        $this->manifests($project, $version);
    }

    public function test_other_runtimes_are_untouched(): void
    {
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'def handler(e, c): return 1'], $this->region());

        $spec = $this->podSpec($this->manifests($project, $version));

        $this->assertArrayNotHasKey('runtimeClassName', $spec);
        $this->assertArrayNotHasKey('nodeSelector', $spec);
        $this->assertArrayNotHasKey('command', $spec['containers'][0]);
    }
}
