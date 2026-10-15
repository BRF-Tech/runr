<?php

namespace Tests\Feature\Observability;

use App\Models\ContainerConfig;
use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\Observability\ProjectObservabilitySetting;
use App\Models\Observability\RegionTracingConfig;
use App\Models\Project;
use App\Models\Version;
use App\Services\KnativeDeployService;
use App\Services\Observability\ReplayTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * Deploy-time instrumentation (ObservabilityPodSpec): OTEL_* env and RUNR_OTEL_AUTO only with
 * tracing on and the region's backend ready, the universal wrapper fronting built-in http
 * runtimes (init container, server command), exec runtimes keep their wrapper, replay capture
 * env with a verifiable token, CaaS gets the SDK variables only, native schedules no wrapper,
 * a project's own OTEL_* variables win, WebAssembly functions get the env without the wrapper.
 */
class ObservabilityPodSpecTest extends TestCase
{
    use RefreshDatabase, RegistersRuntimes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltinVersions();
        config(['observability.replay.ingest_url' => 'https://console.runr.test']);
    }

    private function manifests(Project $project, Version $version): array
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

    private function ready(KnativeRegion $region): void
    {
        RegionTracingConfig::forRegion($region)->fill(['mode' => 'managed', 'status' => 'ready'])->save();
    }

    private function settings(Project $project, array $values): ProjectObservabilitySetting
    {
        $s = ProjectObservabilitySetting::forProject($project);
        $s->fill($values);
        if (! empty($values['replay_enabled'])) {
            $s->rotateReplaySalt();
        }
        $s->save();

        return $s;
    }

    public function test_nothing_changes_while_tracing_and_replay_are_off(): void
    {
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'def handler(e, c): return 1']);
        $this->ready($project->knativeRegion);

        $container = $this->podSpec($this->manifests($project, $version))['containers'][0];

        $this->assertArrayNotHasKey('command', $container);
        $this->assertArrayNotHasKey('RUNR_TRACING', $this->env($container));
        $this->assertArrayNotHasKey('OTEL_SERVICE_NAME', $this->env($container));
    }

    public function test_tracing_needs_a_ready_region_backend(): void
    {
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $this->settings($project, ['tracing_enabled' => true]);
        RegionTracingConfig::forRegion($project->knativeRegion)->fill(['mode' => 'managed', 'status' => 'installing'])->save();

        $container = $this->podSpec($this->manifests($project, $version))['containers'][0];

        $this->assertArrayNotHasKey('RUNR_TRACING', $this->env($container));
        $this->assertArrayNotHasKey('command', $container);
    }

    public function test_a_built_in_http_runtime_is_fronted_by_the_wrapper_with_otel_env(): void
    {
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $this->ready($project->knativeRegion);
        $this->settings($project, ['tracing_enabled' => true, 'tracing_sample_ratio' => 0.25]);

        $spec = $this->podSpec($this->manifests($project, $version));
        $container = $spec['containers'][0];
        $env = $this->env($container);

        $this->assertSame(['/runr/runr-wrapper'], $container['command']);
        $this->assertSame('http', $env['RUNR_WRAPPER_MODE']);
        $this->assertSame('["/app/entrypoint.sh"]', $env['RUNR_COMMAND']);
        $this->assertContains(['name' => 'runr-wrapper', 'mountPath' => '/runr', 'readOnly' => true], $container['volumeMounts']);
        $this->assertSame('runr-wrapper', $spec['initContainers'][0]['name']);
        $this->assertSame(['/runr-wrapper', 'install', '/runr/runr-wrapper'], $spec['initContainers'][0]['command']);
        $this->assertContainsEquals(['name' => 'runr-wrapper', 'emptyDir' => new \stdClass], $spec['volumes']);

        $this->assertSame('1', $env['RUNR_TRACING']);
        $this->assertSame('1', $env['RUNR_OTEL_AUTO']);
        $this->assertSame($project->slug, $env['OTEL_SERVICE_NAME']);
        $this->assertSame($project->slug, $env['RUNR_PROJECT']);
        $this->assertSame('http://otel-collector.observability.svc.cluster.local:4318', $env['OTEL_EXPORTER_OTLP_ENDPOINT']);
        $this->assertSame('http/protobuf', $env['OTEL_EXPORTER_OTLP_PROTOCOL']);
        $this->assertSame('parentbased_traceidratio', $env['OTEL_TRACES_SAMPLER'], 'the runtime follows the wrapper span');
        $this->assertSame('0.25', $env['OTEL_TRACES_SAMPLER_ARG']);
        $this->assertSame('none', $env['OTEL_METRICS_EXPORTER']);
        $this->assertSame('true', $env['OTEL_PYTHON_LOG_CORRELATION']);
        $this->assertStringContainsString('runr.project_id='.$project->id, $env['OTEL_RESOURCE_ATTRIBUTES']);
        $this->assertStringContainsString('runr.runtime=python', $env['OTEL_RESOURCE_ATTRIBUTES']);
        $this->assertStringContainsString('runr.version=1', $env['OTEL_RESOURCE_ATTRIBUTES']);
        // the RunR contract env is still there
        $this->assertSame('main.py', $env['RUNR_FILENAME']);
    }

    public function test_an_exec_runtime_keeps_its_wrapper_and_gets_the_env_only(): void
    {
        $this->registerRuntime($this->bashManifest());
        [$project, $version] = $this->runtimeProject('bash', 'http', ['main.sh' => 'echo hi']);
        $this->ready($project->knativeRegion);
        $this->settings($project, ['tracing_enabled' => true]);

        $spec = $this->podSpec($this->manifests($project, $version));
        $env = $this->env($spec['containers'][0]);

        $this->assertSame('exec', $env['RUNR_WRAPPER_MODE']);
        $this->assertSame('["bash","/app/code/main.sh"]', $env['RUNR_COMMAND']);
        $this->assertSame('1', $env['RUNR_TRACING']);
        $this->assertCount(1, $spec['initContainers'], 'no second wrapper init container');
        $this->assertCount(1, array_filter($spec['volumes'], fn ($v) => $v['name'] === 'runr-wrapper'));
    }

    public function test_replay_capture_env_and_token(): void
    {
        [$project, $version] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $settings = $this->settings($project, [
            'replay_enabled' => true, 'replay_max_body_bytes' => 4096, 'replay_redact_headers' => ['x-signature'], 'replay_redact_query' => ['session'],
        ]);

        $container = $this->podSpec($this->manifests($project, $version))['containers'][0];
        $env = $this->env($container);

        $key = '_.'.$project->id;
        $this->assertSame('https://console.runr.test/api/internal/observability/replay/'.$key, $env['RUNR_REPLAY_URL']);
        $this->assertTrue(app(ReplayTokens::class)->verify($key, $settings->fresh(), $env['RUNR_REPLAY_TOKEN']));
        $this->assertSame('4096', $env['RUNR_REPLAY_MAX_BODY']);
        $this->assertStringContainsString('authorization', $env['RUNR_REPLAY_REDACT_HEADERS']);
        $this->assertStringContainsString('x-signature', $env['RUNR_REPLAY_REDACT_HEADERS']);
        $this->assertStringContainsString('session', $env['RUNR_REPLAY_REDACT_QUERY']);
        // capture needs the wrapper in front of the runtime; tracing stays off
        $this->assertSame(['/runr/runr-wrapper'], $container['command']);
        $this->assertArrayNotHasKey('RUNR_TRACING', $env);
    }

    public function test_a_native_schedule_gets_the_env_without_the_wrapper(): void
    {
        [$project, $version] = $this->runtimeProject('python', 'schedule', ['main.py' => 'x']);
        $this->ready($project->knativeRegion);
        $this->settings($project, ['tracing_enabled' => true, 'replay_enabled' => true]);

        $cron = $this->podSpec($this->manifests($project, $version), 'CronJob');
        $env = $this->env($cron['containers'][0]);

        $this->assertArrayNotHasKey('command', $cron['containers'][0]);
        $this->assertSame('1', $env['RUNR_TRACING']);
        $this->assertSame('traceidratio', $env['OTEL_TRACES_SAMPLER'], 'no wrapper parent: ratio by trace id');
        $this->assertArrayNotHasKey('RUNR_REPLAY_URL', $env, 'schedules are not captured');
    }

    public function test_caas_gets_the_sdk_variables_and_no_replay(): void
    {
        $region = KnativeRegion::create(['name' => 'c1', 'project_namespace' => 'fn', 'is_active' => true]);
        $this->ready($region);
        $project = Project::create(['name' => 'web', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create([
            'project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128,
            'timeout_seconds' => 30, 'trigger_type' => 'http', 'env_vars' => ['OTEL_SERVICE_NAME' => 'my-own-name'],
        ]);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'commit_id' => 'c', 'created_by' => 'dev', 'build_status' => 'ready']);
        $this->settings($project, ['tracing_enabled' => true, 'replay_enabled' => true]);

        $container = $this->podSpec($this->manifests($project->fresh(['containerConfig', 'knativeRegion']), $version))['containers'][0];
        $env = $this->env($container);

        $this->assertSame('nginx:1.27-alpine', $container['image']);
        $this->assertArrayNotHasKey('command', $container);
        $this->assertSame('traceidratio', $env['OTEL_TRACES_SAMPLER']);
        $this->assertSame('my-own-name', $env['OTEL_SERVICE_NAME'], 'the project\'s own variable wins');
        $this->assertStringContainsString('runr.runtime=container', $env['OTEL_RESOURCE_ATTRIBUTES']);
        $this->assertArrayNotHasKey('RUNR_REPLAY_URL', $env);
    }

    public function test_a_wasm_function_gets_the_env_but_no_wrapper_and_no_capture(): void
    {
        ExecutorImage::create(['runtime' => 'wasm-rust', 'version' => '4.1', 'docker_image' => 'ghcr.io/runrsh/exec-wasm-rust:4.1', 'is_default' => true, 'is_active' => true]);
        [$project, $version] = $this->runtimeProject('wasm-rust', 'http', ['lib.rs' => 'x'],
            ['sandbox_wasm_status' => 'ready', 'sandbox_wasm_shims' => ['spin']], '10.43.0.5:5000/runr-builds/hello:v1');
        $this->ready($project->knativeRegion);
        $this->settings($project, ['tracing_enabled' => true, 'replay_enabled' => true]);

        $spec = $this->podSpec($this->manifests($project, $version));
        $container = $spec['containers'][0];
        $env = $this->env($container);

        // runwasi runs the Spin artifact: the wrapper (a Linux binary) cannot front it
        $this->assertSame(['/'], $container['command']);
        $this->assertArrayNotHasKey('initContainers', $spec);
        $this->assertArrayNotHasKey('RUNR_WRAPPER_MODE', $env);
        $this->assertArrayNotHasKey('RUNR_REPLAY_URL', $env);
        $this->assertSame('1', $env['RUNR_TRACING']);
        $this->assertSame('traceidratio', $env['OTEL_TRACES_SAMPLER']);
        $this->assertStringStartsWith('http://otel-collector.observability.svc.cluster.local:4318', $env['OTEL_EXPORTER_OTLP_ENDPOINT']);
    }
}
