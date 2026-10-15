<?php

namespace App\Services\Wasm;

use App\Models\Project;
use App\Services\Runtimes\RuntimeContainerSpec;
use App\Services\Runtimes\RuntimeManifest;

/**
 * Pod spec of a WebAssembly function (applied last by RuntimeContainerSpec::apply()):
 *
 *  - runtimeClassName: the shim's RuntimeClass (the manifest's runtimeClass — set already) and a
 *    nodeSelector for the nodes with that shim (runr.dev/wasm-<shim>=true, the same key the
 *    RuntimeClass selects; Knative needs kubernetes.podspec-nodeselector, which the installer
 *    enables);
 *  - the function container's command is "/" — the image is a Spin OCI artifact (no filesystem,
 *    no entrypoint): runwasi runs its Wasm layers, the command only has to be something that is
 *    not an executable of the image;
 *  - http / queue: Knative's queue-proxy sidecar is an ELF binary, which runwasi runs as a regular
 *    Linux container in the same pod — nothing else to change;
 *  - schedule: the Spin app serves HTTP and never exits, so the CronJob pod runs it as a native
 *    sidecar (init container with restartPolicy Always and a startup probe on :8080) and the
 *    universal wrapper's `tick` (a Linux container) sends the one schedule CloudEvent to it and
 *    exits with the result; the Job then completes and the kubelet stops the sidecar.
 */
final class WasmPodSpec
{
    public const TICK_CONTAINER = 'runr-tick';

    public const PORT = 8080;

    public static function apply(array $podSpec, RuntimeManifest $manifest, Project $project, string $trigger, int $timeout): array
    {
        $shim = WasmRuntimes::shimOf($manifest);
        if ($shim === null) {
            return $podSpec;
        }

        $podSpec['runtimeClassName'] = $shim->runtimeClass;
        $podSpec['nodeSelector'] = array_merge($podSpec['nodeSelector'] ?? [], [$shim->nodeLabel() => 'true']);

        $app = $podSpec['containers'][0];
        $app['command'] = ['/'];
        unset($app['args']);
        // the code ConfigMap is never mounted: Wasm versions are always built
        $app['volumeMounts'] = array_values(array_filter($app['volumeMounts'] ?? [], fn ($m) => ($m['name'] ?? '') !== 'code'));
        if ($app['volumeMounts'] === []) {
            unset($app['volumeMounts']);
        }
        if (isset($podSpec['volumes'])) {
            $podSpec['volumes'] = array_values(array_filter($podSpec['volumes'], fn ($v) => ($v['name'] ?? '') !== 'code'));
            if ($podSpec['volumes'] === []) {
                unset($podSpec['volumes']);
            }
        }

        if ($trigger !== 'schedule') {
            $podSpec['containers'][0] = $app;

            return $podSpec;
        }

        // schedule: the app as a native sidecar, the tick as the Job's container
        $app['ports'] = [['containerPort' => self::PORT]];
        $app['restartPolicy'] = 'Always';
        $app['startupProbe'] = [
            'tcpSocket' => ['port' => self::PORT],
            'periodSeconds' => 1,
            'failureThreshold' => 120,
        ];
        $app['env'] = self::withEnv($app['env'] ?? [], ['PORT' => (string) self::PORT]);

        $podSpec['initContainers'] = array_merge($podSpec['initContainers'] ?? [], [$app]);
        $podSpec['containers'] = [self::tickContainer($project, $timeout)];

        return $podSpec;
    }

    public static function tickContainer(Project $project, int $timeout): array
    {
        return [
            'name' => self::TICK_CONTAINER,
            'image' => RuntimeContainerSpec::wrapperImage($project->knativeRegion),
            'command' => ['/runr-wrapper', 'tick'],
            'env' => [
                ['name' => 'PORT', 'value' => (string) self::PORT],
                ['name' => 'RUNR_TIMEOUT', 'value' => (string) max(1, $timeout)],
            ],
            'resources' => [
                'requests' => ['cpu' => '10m', 'memory' => '16Mi'],
                'limits' => ['cpu' => '100m', 'memory' => '32Mi'],
            ],
        ];
    }

    /** @param array<string, string> $values set (or replaced) by name */
    private static function withEnv(array $env, array $values): array
    {
        $env = array_values(array_filter($env, fn ($e) => ! array_key_exists($e['name'] ?? '', $values)));
        foreach ($values as $name => $value) {
            $env[] = ['name' => $name, 'value' => $value];
        }

        return $env;
    }
}
