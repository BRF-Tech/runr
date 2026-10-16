<?php

namespace App\Services\Wasm;

use App\Services\Helpers\ContainerRuntimeHelper;

/**
 * Kubernetes objects of RunR's WebAssembly support (WasmInstallService): the node DaemonSets
 * that install / remove the runwasi shims (WasmNodeScripts) and the RuntimeClasses.
 *
 * The DaemonSets run in runr-sandbox (next to the gVisor and Kata installers) only on the nodes
 * the service labelled: runr.dev/wasm-target=true (installer) and runr.dev/wasm-cleanup=true
 * (cleanup). Every RuntimeClass selects the nodes where its shim is installed and loaded
 * (runr.dev/wasm-<shim>=true, set by the service after the installer finished on the node), so a
 * WebAssembly pod never lands on a node without the shim.
 *
 * A shim shipped as an image (WasmShim::$image) gets an init container in the installer pod that
 * copies the image's /shim into a pod-local emptyDir (WasmNodeScripts::SHIM_FILES/<id>), where the
 * installer script takes the binary from.
 */
final class WasmManifests
{
    public const NAMESPACE = 'runr-sandbox';

    public const INSTALLER_DS = 'wasm-installer';

    public const CLEANUP_DS = 'wasm-cleanup';

    public const IMAGE = 'ghcr.io/brf-tech/runr/reqs/alpine:3.21';

    /** Installer pod template annotation: the shims it installs (drift check, UI). */
    public const SHIMS_ANNOTATION = 'runr.dev/wasm-shims';

    /**
     * @param  list<WasmShim>  $shims
     * @param  array  $runtime  ContainerRuntimeHelper::resolveCluster() of the target nodes
     */
    public static function installerDaemonSet(array $shims, array $runtime): array
    {
        $ds = self::nodeDaemonSet(self::INSTALLER_DS, 'installer', WasmNodeScripts::install(), WasmNodeScripts::READY_MARKER, WasmShim::TARGET_LABEL, $runtime, [
            // download + unpack of the shim tarballs; idle afterwards
            'requests' => ['cpu' => '50m', 'memory' => '64Mi'],
            'limits' => ['cpu' => '500m', 'memory' => '256Mi'],
        ], ['WASM_SHIMS' => self::shimLines($shims)]);

        $ds['spec']['template']['metadata']['annotations'][self::SHIMS_ANNOTATION] = implode(',', array_map(fn (WasmShim $s) => $s->id, $shims));

        $spec = &$ds['spec']['template']['spec'];
        foreach ($shims as $shim) {
            if ($shim->image === null) {
                continue;
            }
            $dir = WasmNodeScripts::SHIM_FILES.'/'.$shim->id;
            $spec['initContainers'][] = [
                'name' => "shim-{$shim->id}",
                'image' => $shim->image,
                // the binary, SHA256SUMS, VERSION, LICENSE and NOTICE of the image
                'command' => ['sh', '-c', "mkdir -p {$dir} && cp -a /shim/. {$dir}/"],
                'volumeMounts' => [['name' => 'shim-files', 'mountPath' => WasmNodeScripts::SHIM_FILES]],
                'resources' => [
                    'requests' => ['cpu' => '20m', 'memory' => '32Mi'],
                    'limits' => ['cpu' => '500m', 'memory' => '128Mi'],
                ],
            ];
        }
        if (! empty($spec['initContainers'])) {
            $spec['containers'][0]['volumeMounts'][] = ['name' => 'shim-files', 'mountPath' => WasmNodeScripts::SHIM_FILES, 'readOnly' => true];
            $spec['volumes'][] = ['name' => 'shim-files', 'emptyDir' => ['sizeLimit' => '512Mi']];
        }
        unset($spec);

        return $ds;
    }

    /** @param array $runtime ContainerRuntimeHelper::resolveCluster() of the nodes to clean */
    public static function cleanupDaemonSet(array $runtime): array
    {
        return self::nodeDaemonSet(self::CLEANUP_DS, 'cleanup', WasmNodeScripts::cleanup(), WasmNodeScripts::CLEAN_MARKER, WasmShim::CLEANUP_LABEL, $runtime, [
            'requests' => ['cpu' => '20m', 'memory' => '32Mi'],
            'limits' => ['cpu' => '200m', 'memory' => '128Mi'],
        ]);
    }

    public static function runtimeClass(WasmShim $shim): array
    {
        return [
            'apiVersion' => 'node.k8s.io/v1',
            'kind' => 'RuntimeClass',
            'metadata' => [
                'name' => $shim->runtimeClass,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/component' => 'wasm', 'runr.dev/wasm-shim' => $shim->id],
            ],
            'handler' => $shim->handler,
            // no pod overhead: there is no VM — the Wasm instance runs inside the shim process
            'scheduling' => ['nodeSelector' => [$shim->nodeLabel() => 'true']],
        ];
    }

    /** @param list<WasmShim> $shims */
    public static function shimLines(array $shims): string
    {
        return implode("\n", array_map(fn (WasmShim $s) => $s->scriptLine(), $shims));
    }

    /**
     * @param  array<string, string>  $extraEnv
     */
    private static function nodeDaemonSet(string $name, string $container, string $script, string $readyMarker, string $nodeLabel, array $runtime, array $resources, array $extraEnv = []): array
    {
        $flavor = $runtime['flavor'] ?? ContainerRuntimeHelper::FLAVOR_CONTAINERD;

        $env = [['name' => 'NODE_NAME', 'valueFrom' => ['fieldRef' => ['fieldPath' => 'spec.nodeName']]]];
        foreach ($extraEnv + ['WASM_ALL_SHIMS' => self::shimLines(WasmShim::all())] as $key => $value) {
            $env[] = ['name' => $key, 'value' => $value];
        }
        $env = array_merge($env, [
            ['name' => 'RUNTIME_FLAVOR',        'value' => $flavor],
            ['name' => 'CONTAINERD_MAJOR',      'value' => (string) ($runtime['containerd_major'] ?? '')],
            ['name' => 'CONTAINERD_CONFIG_DIR', 'value' => ContainerRuntimeHelper::configDir($flavor)],
            ['name' => 'CONTAINERD_SOCKET',     'value' => ContainerRuntimeHelper::socketPath($flavor)],
            ['name' => 'RESTART_UNITS',         'value' => implode(' ', ContainerRuntimeHelper::restartUnits($flavor))],
        ]);

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'DaemonSet',
            'metadata' => [
                'name' => $name,
                'namespace' => self::NAMESPACE,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/component' => 'wasm'],
            ],
            'spec' => [
                'selector' => ['matchLabels' => ['runr.dev/component' => $name]],
                // one node at a time: a configuration change restarts the node's container runtime
                'updateStrategy' => ['type' => 'RollingUpdate', 'rollingUpdate' => ['maxUnavailable' => 1]],
                'template' => [
                    'metadata' => ['labels' => ['runr.dev/component' => $name]],
                    'spec' => [
                        'hostPID' => true,
                        // sh (PID 1) ignores SIGTERM while it sleeps: don't wait 30 s on delete
                        'terminationGracePeriodSeconds' => 5,
                        'nodeSelector' => ['kubernetes.io/os' => 'linux', $nodeLabel => 'true'],
                        'tolerations' => [[
                            'key' => 'runr.dev/dedicated',
                            'operator' => 'Equal',
                            'value' => 'projects',
                            'effect' => 'NoSchedule',
                        ]],
                        'containers' => [[
                            'name' => $container,
                            'image' => self::IMAGE,
                            'securityContext' => ['privileged' => true],
                            'command' => ['sh', '-c', $script],
                            'env' => $env,
                            // "runr: ERROR ..." of a failed run ends up in the pod status
                            'terminationMessagePolicy' => 'FallbackToLogsOnError',
                            // Ready only after the script finished its work on the node
                            'readinessProbe' => [
                                'exec' => ['command' => ['cat', $readyMarker]],
                                'initialDelaySeconds' => 3,
                                'periodSeconds' => 3,
                            ],
                            'volumeMounts' => [['name' => 'host-root', 'mountPath' => '/host']],
                            'resources' => $resources,
                        ]],
                        'volumes' => [['name' => 'host-root', 'hostPath' => ['path' => '/']]],
                    ],
                ],
            ],
        ];
    }
}
