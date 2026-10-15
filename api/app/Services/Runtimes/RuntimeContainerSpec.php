<?php

namespace App\Services\Runtimes;

use App\Models\ExecutorImage;
use App\Models\Project;
use App\Models\Version;
use App\Services\Wasm\WasmPodSpec;

/**
 * Applies a code project's runtime manifest to the pod spec of its Knative Service / CronJob
 * (built by KnativeManifestTrait from ContainerManifests): the RunR contract env, the trigger and
 * sandbox checks, GPU limits, the RuntimeClass of the runtime, the run command and — for exec /
 * persistent runtimes and an http runtime's invoked schedule — the universal wrapper.
 *
 * A code-mounted function gets the wrapper from an init container that copies it into an
 * emptyDir at /runr; a built function has it baked in at the same path (FunctionBuildService).
 */
class RuntimeContainerSpec
{
    public const WRAPPER_VOLUME = 'runr-wrapper';

    public function __construct(private RuntimeRegistry $runtimes) {}

    /**
     * @throws \RuntimeException the runtime does not support the trigger or the region's sandbox
     */
    public function apply(array $podSpec, Project $project, Version $version, string $trigger, int $timeout): array
    {
        $manifest = $this->runtimes->forProject($project);
        // The wrapper knows http | queue | schedule: a `workflow` project runs as http
        $trigger = RuntimeManifest::runtimeTrigger($trigger);

        if ($reason = $this->runtimes->deployBlockedReason($manifest->id(), $project->knative_region_id)) {
            throw new \RuntimeException($reason);
        }

        if (in_array($trigger, RuntimeManifest::TRIGGERS, true) && ! $manifest->supportsTrigger($trigger)) {
            throw new \RuntimeException(__('api.runtimes.trigger_unsupported', ['name' => $manifest->name(), 'trigger' => $trigger]));
        }

        if ($manifest->runtimeClass() !== null) {
            $podSpec['runtimeClassName'] = $manifest->runtimeClass();
        } elseif (! $manifest->supportsRuntimeClass($podSpec['runtimeClassName'] ?? null)) {
            throw new \RuntimeException(__('api.runtimes.sandbox_unsupported', [
                'name' => $manifest->name(),
                'sandbox' => $podSpec['runtimeClassName'] ?? 'runc',
                'type' => strtoupper((string) $project->service_type),
            ]));
        }

        $entrypoint = ProjectEntrypoint::for($project, $manifest);
        $built = (bool) $version->build_image;
        $mode = $trigger === 'schedule' ? 'schedule' : 'http';
        $runtimeVersion = (string) (ExecutorImage::resolve($manifest->id(), $project->runtime_version)?->version ?? $project->runtime_version ?? '');
        $values = [
            'version' => $runtimeVersion,
            'file' => $entrypoint->filename,
            'handler' => $entrypoint->handler,
            'mode' => $mode,
            'trigger' => $trigger,
        ];

        $container = $podSpec['containers'][0];
        $env = [
            'RUNR_MODE' => $mode,
            'RUNR_TRIGGER' => $trigger,
            'RUNR_HANDLER' => $entrypoint->handler,
            'RUNR_FILENAME' => $entrypoint->filename,
            'RUNR_CODE_DIR' => '/app/code',
            'RUNR_RUNTIME' => $manifest->id(),
            'RUNR_RUNTIME_VERSION' => $runtimeVersion,
            'RUNR_TIMEOUT' => (string) $timeout,
        ] + array_map(fn ($v) => Placeholders::render($v, $values), $manifest->runEnv());

        $command = $manifest->renderedCommand($values);

        if ($manifest->needsWrapper($trigger)) {
            $env += self::wrapperEnv($manifest, $command);
            $container['command'] = [RuntimeManifest::WRAPPER_PATH];
            unset($container['args']);

            if (! $built) {
                $podSpec = $this->withWrapperInit($podSpec, $project, $container['resources'] ?? null);
                $container['volumeMounts'][] = ['name' => self::WRAPPER_VOLUME, 'mountPath' => dirname(RuntimeManifest::WRAPPER_PATH), 'readOnly' => true];
            }
        } elseif (! $built && $command !== null) {
            // http runtime on its own image: the server command (built images carry it as ENTRYPOINT)
            $container['command'] = $command;
        }

        $container['env'] = self::mergeEnv($env, $container['env'] ?? []);

        if ($gpu = $manifest->gpu()) {
            $container['resources']['limits'][$gpu['resource']] = (string) $gpu['count'];
        }

        $podSpec['containers'][0] = $container;

        // WebAssembly runtimes: shim nodes, "/" command, schedule tick as a Linux container (WasmPodSpec)
        return WasmPodSpec::apply($podSpec, $manifest, $project, $trigger, $timeout);
    }

    /**
     * Env of the wrapper: mode, command and the mode's options.
     *
     * @param  list<string>|null  $command
     * @return array<string, string>
     */
    public static function wrapperEnv(RuntimeManifest $manifest, ?array $command): array
    {
        $env = [
            'RUNR_WRAPPER_MODE' => $manifest->wrapperMode(),
            'RUNR_COMMAND' => json_encode($command ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        if ($manifest->runMode() === 'exec') {
            $env['RUNR_INPUT'] = $manifest->input();
        }
        if ($manifest->runMode() === 'persistent') {
            $env['RUNR_WORKERS'] = (string) $manifest->workers();
        }

        return $env;
    }

    /** Wrapper image of a region (through its registry mirror for Docker Hub names). */
    public static function wrapperImage($region): string
    {
        $image = (string) config('executor.wrapper_image');

        return $region ? $region->registryImageRef($image) : $image;
    }

    private function withWrapperInit(array $podSpec, Project $project, ?array $resources): array
    {
        // Same size as the function container: the pod's effective request is the larger of the
        // two, so the copy costs no capacity and is not throttled below the function's CPU
        $podSpec['initContainers'][] = [
            'name' => 'runr-wrapper',
            'image' => self::wrapperImage($project->knativeRegion),
            'command' => ['/runr-wrapper', 'install', RuntimeManifest::WRAPPER_PATH],
            'volumeMounts' => [['name' => self::WRAPPER_VOLUME, 'mountPath' => dirname(RuntimeManifest::WRAPPER_PATH)]],
            'resources' => $resources ?? [],
        ];
        $podSpec['volumes'][] = ['name' => self::WRAPPER_VOLUME, 'emptyDir' => new \stdClass];

        return $podSpec;
    }

    /**
     * RunR contract env first, the container's own entries after (user env vars win over the
     * defaults; RUNR_* entries the container already sets are kept as they are).
     *
     * @param  array<string, string>  $contract
     */
    private static function mergeEnv(array $contract, array $existing): array
    {
        $present = array_column($existing, 'name');
        $env = [];

        foreach ($contract as $name => $value) {
            if (! in_array($name, $present, true)) {
                $env[] = ['name' => $name, 'value' => (string) $value];
            }
        }

        return array_merge($env, $existing);
    }
}
