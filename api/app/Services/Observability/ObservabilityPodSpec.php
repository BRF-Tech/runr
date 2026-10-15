<?php

namespace App\Services\Observability;

use App\Models\Observability\ProjectObservabilitySetting;
use App\Models\Observability\RegionTracingConfig;
use App\Models\Project;
use App\Services\Helpers\ManagedTracing;
use App\Services\Runtimes\BuiltinCatalog;
use App\Services\Runtimes\RuntimeContainerSpec;
use App\Services\Runtimes\RuntimeManifest;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Wasm\WasmRuntimes;

/**
 * Deploy hook (KnativeManifestTrait, after RuntimeContainerSpec): tracing and replay capture of
 * a project's function container, only when the project switched them on.
 *
 *  - tracing (region backend ready): RUNR_TRACING + the standard OTEL_* variables (collector
 *    endpoint, service name, resource attributes runr.project_id / runr.project / runr.tenant /
 *    runr.runtime / runr.version, sampler) and RUNR_OTEL_AUTO, which the built-in images'
 *    entrypoints turn into language auto-instrumentation (Python opentelemetry-instrument,
 *    Node.js @opentelemetry/auto-instrumentations-node through NODE_OPTIONS)
 *  - replay capture (http / queue triggers of code runtimes): RUNR_REPLAY_* (ingest URL, token,
 *    body limit, redaction lists)
 *  - an http-mode runtime that does not run through the universal wrapper yet is fronted by it
 *    (RUNR_WRAPPER_MODE=http, its server command in RUNR_COMMAND), so every runtime gets the
 *    wrapper's server span and failed-request capture; CaaS containers get the OTEL_* variables
 *    for their own SDK
 */
class ObservabilityPodSpec
{
    /** Image entrypoint of the built-in runtime images (runtime/<id>/Dockerfile, WORKDIR /app). */
    public const BUILTIN_ENTRYPOINT = ['/app/entrypoint.sh'];

    public function __construct(
        private RuntimeRegistry $runtimes,
        private BuiltinCatalog $builtins,
        private ReplayTokens $tokens,
        private IngestUrl $ingest,
    ) {}

    public function apply(array $podSpec, Project $project, $version, string $trigger): array
    {
        $settings = ProjectObservabilitySetting::forProject($project);
        if (! $settings->tracing_enabled && ! $settings->replay_enabled) {
            return $podSpec;
        }

        $region = $project->knativeRegion;
        $isCode = $project->service_type !== 'caas';
        // WebAssembly functions run under a runwasi shim, where the wrapper (a Linux binary) cannot
        // front them: they get the OTEL_* variables (for Spin's own OpenTelemetry export) and no capture
        $isWasm = $isCode && $this->isWasm($project);
        $tracing = $settings->tracing_enabled && $region && RegionTracingConfig::forRegion($region)->acceptsSpans();
        $replay = $settings->replay_enabled && $isCode && ! $isWasm && $trigger !== 'schedule' && $region && $this->ingest->forRegion($region) !== null;

        if (! $tracing && ! $replay) {
            return $podSpec;
        }

        $container = $podSpec['containers'][0];
        $env = self::envMap($container['env'] ?? []);
        $viaWrapper = ($container['command'] ?? null) === [RuntimeManifest::WRAPPER_PATH];
        $fronted = false;
        $add = [];

        if ($isCode && ! $isWasm && ! $viaWrapper && $trigger !== 'schedule') {
            $command = $this->serverCommand($project, $container, $env);
            if ($command !== null) {
                $add['RUNR_WRAPPER_MODE'] = 'http';
                $add['RUNR_COMMAND'] = json_encode($command, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $container['command'] = [RuntimeManifest::WRAPPER_PATH];
                unset($container['args']);
                $podSpec = $this->withWrapper($podSpec, $project, $container);
                $container = $podSpec['containers'][0];
                $fronted = true;
            } else {
                // No known server command: the wrapper cannot front it, nothing can capture
                $replay = false;
            }
        }

        if ($tracing) {
            // Behind the wrapper the runtime follows the wrapper's sampling decision exactly; without
            // it (CaaS, native schedules) the trace id ratio decides, ignoring a low-rate upstream
            $parentBased = $viaWrapper || $fronted;
            $add += $this->tracingEnv($project, $version, $settings, $parentBased);
        }

        if ($replay) {
            $add += $this->replayEnv($project, $settings, $region);
        }

        if (! $add) {
            return $podSpec;
        }

        $container['env'] = self::mergeEnv($container['env'] ?? [], $add);
        $podSpec['containers'][0] = $container;

        return $podSpec;
    }

    /** @return array<string, string> */
    public function tracingEnv(Project $project, $version, ProjectObservabilitySetting $settings, bool $parentBased): array
    {
        $ratio = max(0.0, min(1.0, (float) $settings->tracing_sample_ratio));
        $tenant = RuntimeRegistry::currentTenantId();

        $resource = array_filter([
            'runr.project_id' => $project->getKey(),
            'runr.project' => $project->slug,
            'runr.tenant' => $tenant,
            'runr.runtime' => $project->service_type === 'caas' ? 'container' : (string) $project->runtime,
            'runr.version' => $version ? (string) $version->number : null,
            'runr.service_type' => $project->service_type,
            'service.namespace' => 'runr',
        ], fn ($v) => $v !== null && $v !== '');

        return [
            'RUNR_TRACING' => '1',
            'RUNR_PROJECT' => $project->slug,
            'RUNR_OTEL_AUTO' => '1',
            'OTEL_SERVICE_NAME' => $project->slug,
            'OTEL_EXPORTER_OTLP_ENDPOINT' => ManagedTracing::collectorOtlpHttpUrl(),
            'OTEL_EXPORTER_OTLP_PROTOCOL' => 'http/protobuf',
            'OTEL_TRACES_EXPORTER' => 'otlp',
            'OTEL_METRICS_EXPORTER' => 'none',
            'OTEL_LOGS_EXPORTER' => 'none',
            'OTEL_PROPAGATORS' => 'tracecontext,baggage',
            'OTEL_TRACES_SAMPLER' => $parentBased ? 'parentbased_traceidratio' : 'traceidratio',
            'OTEL_TRACES_SAMPLER_ARG' => self::ratio($ratio),
            'OTEL_RESOURCE_ATTRIBUTES' => self::resourceAttributes($resource),
            'OTEL_PYTHON_LOG_CORRELATION' => 'true',
            'OTEL_NODE_ENABLED_INSTRUMENTATIONS' => (string) config('observability.tracing.node_instrumentations'),
            'OTEL_NODE_RESOURCE_DETECTORS' => 'env,host,os,process',
        ];
    }

    /** @return array<string, string> */
    public function replayEnv(Project $project, ProjectObservabilitySetting $settings, $region): array
    {
        $key = ReplayTokens::keyFor($project);

        return [
            'RUNR_REPLAY_URL' => $this->ingest->forRegion($region).'/api/internal/observability/replay/'.$key,
            'RUNR_REPLAY_TOKEN' => $this->tokens->token($key, $settings),
            'RUNR_REPLAY_MAX_BODY' => (string) $settings->replay_max_body_bytes,
            'RUNR_REPLAY_PER_MINUTE' => (string) max(1, (int) config('observability.replay.per_minute', 60)),
            'RUNR_REPLAY_REDACT_HEADERS' => implode(',', $settings->redactedHeaders()),
            'RUNR_REPLAY_REDACT_QUERY' => implode(',', $settings->redactedQuery()),
        ];
    }

    /** Failed requests of the project can be captured: code runtimes behind the wrapper (not CaaS, not Wasm). */
    public function capturable(Project $project): bool
    {
        return $project->service_type !== 'caas' && ! $this->isWasm($project);
    }

    private function isWasm(Project $project): bool
    {
        try {
            return WasmRuntimes::isWasm($this->runtimes->forProject($project));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The command that starts the runtime's own HTTP server: the container's command (code-mounted
     * http runtime), the manifest's run.command (built images have it as ENTRYPOINT) or the
     * built-in images' entrypoint; null when it is not known (an image entrypoint RunR did not build).
     */
    private function serverCommand(Project $project, array $container, array $env): ?array
    {
        if (! empty($container['command'])) {
            return array_values(array_merge($container['command'], $container['args'] ?? []));
        }

        try {
            $manifest = $this->runtimes->forProject($project);
        } catch (\Throwable) {
            return null;
        }

        $command = $manifest->renderedCommand([
            'version' => $env['RUNR_RUNTIME_VERSION'] ?? (string) $project->runtime_version,
            'file' => $env['RUNR_FILENAME'] ?? '',
            'handler' => $env['RUNR_HANDLER'] ?? '',
            'mode' => $env['RUNR_MODE'] ?? 'http',
            'trigger' => $env['RUNR_TRIGGER'] ?? 'http',
        ]);
        if ($command) {
            return $command;
        }

        return $this->builtins->has($manifest->id()) ? self::BUILTIN_ENTRYPOINT : null;
    }

    /** The wrapper binary in the pod: init container copying it into an emptyDir at /runr. */
    private function withWrapper(array $podSpec, Project $project, array $container): array
    {
        $mount = ['name' => RuntimeContainerSpec::WRAPPER_VOLUME, 'mountPath' => dirname(RuntimeManifest::WRAPPER_PATH), 'readOnly' => true];
        if (! in_array(RuntimeContainerSpec::WRAPPER_VOLUME, array_column($container['volumeMounts'] ?? [], 'name'), true)) {
            $container['volumeMounts'][] = $mount;
        }
        $podSpec['containers'][0] = $container;

        if (! in_array('runr-wrapper', array_column($podSpec['initContainers'] ?? [], 'name'), true)) {
            $podSpec['initContainers'][] = [
                'name' => 'runr-wrapper',
                'image' => RuntimeContainerSpec::wrapperImage($project->knativeRegion),
                'command' => ['/runr-wrapper', 'install', RuntimeManifest::WRAPPER_PATH],
                'volumeMounts' => [['name' => RuntimeContainerSpec::WRAPPER_VOLUME, 'mountPath' => dirname(RuntimeManifest::WRAPPER_PATH)]],
                'resources' => $container['resources'] ?? [],
            ];
        }
        if (! in_array(RuntimeContainerSpec::WRAPPER_VOLUME, array_column($podSpec['volumes'] ?? [], 'name'), true)) {
            $podSpec['volumes'][] = ['name' => RuntimeContainerSpec::WRAPPER_VOLUME, 'emptyDir' => new \stdClass];
        }

        return $podSpec;
    }

    /** OTEL_RESOURCE_ATTRIBUTES: k=v pairs, values percent-encoded (W3C baggage octets). */
    public static function resourceAttributes(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $k => $v) {
            $parts[] = $k.'='.rawurlencode((string) $v);
        }

        return implode(',', $parts);
    }

    private static function ratio(float $ratio): string
    {
        $s = rtrim(rtrim(number_format($ratio, 4, '.', ''), '0'), '.');

        return $s === '' ? '0' : $s;
    }

    /** @return array<string, string> */
    private static function envMap(array $env): array
    {
        $out = [];
        foreach ($env as $e) {
            if (isset($e['name']) && array_key_exists('value', $e)) {
                $out[$e['name']] = (string) $e['value'];
            }
        }

        return $out;
    }

    /**
     * Observability variables are added where the container has no variable of that name yet: a
     * project's own OTEL_* / RUNR_* setting wins.
     *
     * @param  array<string, string>  $add
     */
    private static function mergeEnv(array $existing, array $add): array
    {
        $present = array_column($existing, 'name');
        foreach ($add as $name => $value) {
            if (! in_array($name, $present, true)) {
                $existing[] = ['name' => $name, 'value' => (string) $value];
            }
        }

        return $existing;
    }
}
