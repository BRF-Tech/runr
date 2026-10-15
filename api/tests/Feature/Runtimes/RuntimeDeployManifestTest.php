<?php

namespace Tests\Feature\Runtimes;

use App\Models\Runtime;
use App\Models\RuntimeBuild;
use App\Services\KnativeDeployService;
use App\Services\Runtimes\RuntimeImageBuildService;
use App\Services\Runtimes\RuntimeImagePendingException;
use App\Services\Runtimes\RuntimeManifest;
use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * Knative Service / CronJob manifests per run mode (RuntimeContainerSpec): built-ins unchanged,
 * the universal wrapper for exec / persistent runtimes (init container for code-mounted
 * functions, baked in for built ones), http runtimes with their own command, the contract env,
 * the trigger, sandbox, RuntimeClass and GPU rules, and disabled / region-limited runtimes.
 */
class RuntimeDeployManifestTest extends TestCase
{
    use RefreshDatabase, RegistersRuntimes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltinVersions();
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
        return array_column($container['env'], 'value', 'name');
    }

    public function test_a_built_in_python_function_keeps_its_container(): void
    {
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'def handler(e, c): return 1']);

        $spec = $this->podSpec($this->manifests($project, $version));
        $container = $spec['containers'][0];
        $env = $this->env($container);

        $this->assertSame('ghcr.io/brf-tech/exec-python:3.14', $container['image']);
        $this->assertArrayNotHasKey('command', $container);
        $this->assertArrayNotHasKey('initContainers', $spec);
        $this->assertSame([['name' => 'code', 'configMap' => ['name' => $project->slug.'-v1']]], $spec['volumes']);
        $this->assertSame(['http', 'handler', 'main.py'], [$env['RUNR_MODE'], $env['RUNR_HANDLER'], $env['RUNR_FILENAME']]);
        // the uniform contract env every runtime gets
        $this->assertSame(['http', 'python', '3.14', '/app/code', '30'], [$env['RUNR_TRIGGER'], $env['RUNR_RUNTIME'], $env['RUNR_RUNTIME_VERSION'], $env['RUNR_CODE_DIR'], $env['RUNR_TIMEOUT']]);
        $this->assertArrayNotHasKey('RUNR_WRAPPER_MODE', $env);
        $this->assertSame([['containerPort' => 8080]], $container['ports']);
    }

    public function test_a_code_mounted_exec_runtime_gets_the_wrapper_from_an_init_container(): void
    {
        $this->registerRuntime($this->bashManifest());
        [$project, $version] = $this->runtimeProject('bash', 'http', ['main.sh' => 'echo hi']);

        $spec = $this->podSpec($this->manifests($project, $version));
        $container = $spec['containers'][0];
        $env = $this->env($container);

        $this->assertSame('docker.io/library/bash:5.2', $container['image']);
        $this->assertSame(['/runr/runr-wrapper'], $container['command']);
        $this->assertSame(['exec', '["bash","/app/code/main.sh"]', 'raw'], [$env['RUNR_WRAPPER_MODE'], $env['RUNR_COMMAND'], $env['RUNR_INPUT']]);
        $this->assertContains(['name' => 'runr-wrapper', 'mountPath' => '/runr', 'readOnly' => true], $container['volumeMounts']);

        $init = $spec['initContainers'][0];
        $this->assertSame('runr-wrapper', $init['name']);
        $this->assertSame('ghcr.io/brf-tech/runr-wrapper:1.1.0', $init['image']);
        $this->assertSame(['/runr-wrapper', 'install', '/runr/runr-wrapper'], $init['command']);
        // as large as the function container: no extra capacity, no slower copy
        $this->assertSame($container['resources'], $init['resources']);
        $this->assertContainsEquals(['name' => 'runr-wrapper', 'emptyDir' => new \stdClass], $spec['volumes']);
    }

    public function test_schedule_and_queue_triggers_of_a_custom_runtime(): void
    {
        $this->registerRuntime($this->bashManifest());

        [$project, $version] = $this->runtimeProject('bash', 'schedule', ['main.sh' => 'echo tick']);
        $cron = $this->podSpec($this->manifests($project, $version), 'CronJob');
        $env = $this->env($cron['containers'][0]);
        $this->assertSame(['schedule', 'schedule', 'exec'], [$env['RUNR_MODE'], $env['RUNR_TRIGGER'], $env['RUNR_WRAPPER_MODE']]);
        $this->assertSame('runr-wrapper', $cron['initContainers'][0]['name']);
        $this->assertSame('Never', $cron['restartPolicy']);

        [$project, $version] = $this->runtimeProject('bash', 'queue', ['main.sh' => 'cat']);
        $manifests = $this->manifests($project, $version);
        $env = $this->env($this->podSpec($manifests)['containers'][0]);
        $this->assertSame(['http', 'queue'], [$env['RUNR_MODE'], $env['RUNR_TRIGGER']]);
        $this->assertNotNull(collect($manifests)->firstWhere('kind', 'Deployment'), 'queue consumer');
    }

    public function test_an_http_runtime_runs_its_command_and_invokes_it_for_schedules(): void
    {
        $this->registerRuntime($this->serverManifest());

        [$project, $version] = $this->runtimeProject('srv', 'http');
        $container = $this->podSpec($this->manifests($project, $version))['containers'][0];
        $this->assertSame(['/srv', '--code', '/app/code/main.txt'], $container['command']);
        $this->assertArrayNotHasKey('RUNR_WRAPPER_MODE', $this->env($container));

        [$project, $version] = $this->runtimeProject('srv', 'schedule');
        $cron = $this->podSpec($this->manifests($project, $version), 'CronJob');
        $env = $this->env($cron['containers'][0]);
        $this->assertSame(['/runr/runr-wrapper'], $cron['containers'][0]['command']);
        $this->assertSame(['http', '["/srv","--code","/app/code/main.txt"]'], [$env['RUNR_WRAPPER_MODE'], $env['RUNR_COMMAND']]);
    }

    public function test_a_built_function_has_the_wrapper_baked_in(): void
    {
        $this->registerRuntime($this->cManifest());
        [$project, $version] = $this->runtimeProject('c', 'http', ['main.c' => 'x'], [], 'zot:5000/runr-builds/fn:v1');

        $spec = $this->podSpec($this->manifests($project, $version));
        $container = $spec['containers'][0];

        $this->assertSame('zot:5000/runr-builds/fn:v1', $container['image']);
        $this->assertArrayNotHasKey('initContainers', $spec);
        $this->assertArrayNotHasKey('volumes', $spec);
        $this->assertSame(['/runr/runr-wrapper'], $container['command']);
        $this->assertSame('["/app/handler"]', $this->env($container)['RUNR_COMMAND']);
        // a compiled runtime's container still gets the whole contract env
        $this->assertSame('main.c', $this->env($container)['RUNR_FILENAME']);
    }

    public function test_a_persistent_runtime_built_from_a_dockerfile_uses_the_region_image(): void
    {
        $runtime = $this->registerRuntime($this->qManifest(), $this->qFiles());
        [$project, $version] = $this->runtimeProject('q', 'http', ['main.q' => 'print "hi"'], [
            'registry_mode' => 'internal', 'registry_status' => 'ready', 'registry_address' => '10.0.0.9:5000',
        ]);

        $spec = (new RuntimeManifest($runtime->manifest))->version('1.0');
        RuntimeBuild::create([
            'runtime_id' => 'q',
            'version' => '1.0',
            'knative_region_id' => $project->knative_region_id,
            'status' => RuntimeBuild::STATUS_READY,
            'digest' => app(RuntimeImageBuildService::class)->digest($runtime, $spec),
            'image' => '10.0.0.9:5000/runr-runtimes/q:1.0-abc',
        ]);

        $container = $this->podSpec($this->manifests($project, $version))['containers'][0];
        $env = $this->env($container);

        $this->assertSame('10.0.0.9:5000/runr-runtimes/q:1.0-abc', $container['image']);
        $this->assertSame(['persistent', '2', '["q","/app/code/main.q"]'], [$env['RUNR_WRAPPER_MODE'], $env['RUNR_WORKERS'], $env['RUNR_COMMAND']]);

        // a changed Dockerfile makes that build stale: the deploy waits for the new image
        $runtime->update(['files' => ['Dockerfile' => 'FROM other'] + $this->qFiles()]);
        app(RuntimeRegistry::class)->forget();
        $this->expectException(RuntimeImagePendingException::class);
        $this->manifests($project->fresh(['xaasConfig', 'knativeRegion']), $version);
    }

    public function test_triggers_sandboxes_and_runtime_classes(): void
    {
        $this->registerRuntime($this->bashManifest(['triggers' => ['http'], 'sandbox' => ['runc']]));

        [$project, $version] = $this->runtimeProject('bash', 'schedule');
        try {
            $this->manifests($project, $version);
            $this->fail('schedule accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('does not support the schedule trigger', $e->getMessage());
        }

        [$project, $version] = $this->runtimeProject('bash', 'http', ['main.sh' => 'x'], ['sandbox_xaas_runtime' => 'gvisor']);
        try {
            $this->manifests($project, $version);
            $this->fail('gVisor accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('gvisor', $e->getMessage());
        }

        // a platform runtime with its own RuntimeClass (e.g. a WebAssembly shim) and a GPU
        $this->registerRuntime($this->serverManifest(['id' => 'wasm', 'runtimeClass' => 'wasmtime-spin', 'resources' => ['gpu' => ['count' => 1]]]));
        [$project, $version] = $this->runtimeProject('wasm', 'http', ['main.txt' => 'x'], ['sandbox_xaas_runtime' => 'gvisor']);
        $spec = $this->podSpec($this->manifests($project, $version));
        $this->assertSame('wasmtime-spin', $spec['runtimeClassName']);
        $this->assertSame('1', $spec['containers'][0]['resources']['limits']['nvidia.com/gpu']);
    }

    public function test_disabled_and_region_limited_runtimes_are_not_deployed(): void
    {
        $this->registerRuntime($this->bashManifest());
        [$project, $version] = $this->runtimeProject('bash', 'http', ['main.sh' => 'x']);

        Runtime::whereKey('bash')->update(['is_enabled' => false]);
        app(RuntimeRegistry::class)->forget();
        try {
            $this->manifests($project, $version);
            $this->fail('disabled runtime deployed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('disabled', $e->getMessage());
        }

        Runtime::whereKey('bash')->update(['is_enabled' => true, 'regions' => json_encode(['01SOMEOTHERREGION0000000000'])]);
        app(RuntimeRegistry::class)->forget();
        $this->expectExceptionMessage('not offered in this region');
        $this->manifests($project, $version);
    }
}
