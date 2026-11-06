<?php

namespace App\Services\Concerns;

use App\Services\Helpers\ContainerRuntimeHelper;
use App\Services\Helpers\KataRuntime;

trait KataManifestTrait
{
    private const KATA_IMAGE = 'quay.io/kata-containers/kata-deploy:'.KataRuntime::KATA_VERSION;

    private const KATA_DS_NAME = 'kata-installer';

    private const KATA_SA_NAME = 'kata-installer-sa';

    private const KATA_CONFIG_MAP = 'kata-runr-config';

    /** Node DaemonSet that rewrites RunR's config.d drop-ins in place (no kata-deploy run, no restart). */
    private const KATA_DROPINS_DS = 'kata-dropins';

    /** ConfigMap keys of the drop-ins: dropin-<handler>.toml. */
    private const KATA_DROPIN_KEY_PREFIX = 'dropin-';

    /**
     * Pod template annotation of the kata-installer: hash of what kata-deploy installs — its pod
     * spec and the runtime handler list (kataInstallerHash()). A change rolls the DaemonSet, i.e.
     * kata-deploy's cleanup + reinstall (container runtime restarts). RunR 0.4.1 hashed the drop-ins
     * in as well (kataConfigHash()).
     */
    public const KATA_CONFIG_HASH_ANNOTATION = 'runr.dev/kata-config-hash';

    /**
     * kata-installer DaemonSet annotations (metadata, not the pod template: changing them rolls
     * nothing): the installer hash it runs and the hash of the drop-ins on its nodes.
     */
    public const KATA_INSTALLER_HASH_ANNOTATION = 'runr.dev/kata-installer-hash';

    public const KATA_DROPINS_HASH_ANNOTATION = 'runr.dev/kata-dropins-hash';

    /** Log line of the drop-in node script once every drop-in is in place. */
    public const KATA_DROPINS_LOG = 'runr: kata drop-ins updated';

    /**
     * What kata-deploy reaches on the node, as the upstream chart mounts it in daemonset mode
     * (kata-deploy.commonVolumes): volume => [host path, path in the pod, read-only, hostPath type].
     * It works inside the pod and enters no host namespace: /opt/kata has to be the host's or the
     * install ends up in the container, and without systemd's private socket it can neither tell
     * k3s / RKE2 from containerd nor restart the runtime. /run/lock is its node lock.
     */
    private const KATA_HOST_MOUNTS = [
        'kata-install' => ['/opt/kata', '/opt/kata', false, 'DirectoryOrCreate'],
        'systemd-system' => ['/etc/systemd/system', '/etc/systemd/system', false, 'DirectoryOrCreate'],
        'systemd-private' => ['/run/systemd/private', '/run/systemd/private', false, 'Socket'],
        'boot' => ['/boot', '/boot', true, null],
        'host-machine-id' => ['/etc/machine-id', '/host-machine-id', true, 'File'],
        'host-run-lock' => ['/run/lock', '/host-run-lock', false, 'DirectoryOrCreate'],
        'host-usr-bin' => ['/usr/bin', '/host-usr/bin', true, null],
        'host-usr-sbin' => ['/usr/sbin', '/host-usr/sbin', true, null],
        'host-usr-local-bin' => ['/usr/local/bin', '/host-usr-local/bin', true, null],
        'host-usr-local-sbin' => ['/usr/local/sbin', '/host-usr-local/sbin', true, null],
        'host-bin' => ['/bin', '/host-bin', true, null],
        'host-sbin' => ['/sbin', '/host-sbin', true, null],
    ];

    /**
     * @param  string  $flavor  ContainerRuntimeHelper::FLAVOR_* of the target nodes. kata-deploy detects
     *                          k3s/rke2 itself (config[-v3].toml.tmpl + k3s/rke2 restart) but needs their
     *                          containerd dir mounted at /etc/containerd/ — like the upstream chart's
     *                          `k8sDistribution` value.
     */
    private function buildKataInstallerDaemonSet(
        string $namespace,
        array $shims,
        bool $hasDedicatedNodes,
        string $flavor = ContainerRuntimeHelper::FLAVOR_CONTAINERD,
    ): array {
        $shimList = implode(' ', $shims);
        $default = $shims[0] ?? 'clh';
        $confDir = ContainerRuntimeHelper::configDir($flavor).'/';

        // Install on the dedicated nodes only if there are any, else on all workers
        $nodeSelector = $hasDedicatedNodes
            ? ['runr.dev/dedicated' => 'projects']
            : ['kubernetes.io/os' => 'linux'];

        $podSpec = [
            // for config-fixup's nsenter; kata-deploy itself needs no host PID namespace
            'hostPID' => true,
            'serviceAccountName' => self::KATA_SA_NAME,
            'nodeSelector' => $nodeSelector,
            'tolerations' => [[
                'key' => 'runr.dev/dedicated',
                'operator' => 'Equal',
                'value' => 'projects',
                'effect' => 'NoSchedule',
            ]],
            // kata-deploy undoes its install on SIGTERM (config, artifacts, runtime restart)
            'terminationGracePeriodSeconds' => 600,
            // k3s/rke2: config.toml is regenerated from the template on every start — no fixup needed
            'initContainers' => ContainerRuntimeHelper::isK3sFamily($flavor)
                ? []
                : [$this->buildConfigFixupContainer()],
            'containers' => [$this->buildKataDeployContainer($shimList, $default)],
            'volumes' => [
                ['name' => 'crio-conf',       'hostPath' => ['path' => '/etc/crio/']],
                ['name' => 'containerd-conf', 'hostPath' => ['path' => $confDir]],
                ...self::kataHostVolumes(),
                ['name' => 'custom-configs',  'configMap' => ['name' => self::KATA_CONFIG_MAP]],
            ],
        ];

        $configMap = $this->buildKataConfigMap($namespace, $shims, $flavor);
        $installer = self::kataInstallerHash($podSpec, $configMap['data']);

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'DaemonSet',
            'metadata' => [
                'name' => self::KATA_DS_NAME,
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/component' => 'kata'],
                'annotations' => [
                    self::KATA_INSTALLER_HASH_ANNOTATION => $installer,
                    self::KATA_DROPINS_HASH_ANNOTATION => self::kataDropInsHash($configMap['data']),
                ],
            ],
            'spec' => [
                'updateStrategy' => ['type' => 'RollingUpdate', 'rollingUpdate' => ['maxUnavailable' => 1]],
                'selector' => ['matchLabels' => ['runr.dev/component' => 'kata-installer']],
                'template' => [
                    'metadata' => [
                        'labels' => ['runr.dev/component' => 'kata-installer'],
                        // kata-deploy reads /custom-configs only when it starts: a changed pod spec or
                        // handler list must roll the pods. The drop-ins are not part of it — a
                        // drop-in-only change is written to the nodes in place (buildKataDropInsDaemonSet)
                        'annotations' => [self::KATA_CONFIG_HASH_ANNOTATION => $installer],
                    ],
                    'spec' => $podSpec,
                ],
            ],
        ];
    }

    /**
     * Node DaemonSet that writes RunR's drop-ins into kata-deploy's custom runtimes on the
     * kata-installer's nodes (custom-runtimes/<handler>/config.d/50-overrides.toml — the file
     * kata-deploy writes from the ConfigMap), each one replaced atomically (written next to
     * config.d, then renamed: the shim reads every file in config.d at each sandbox start).
     * Nothing restarts: new Kata pods start with the new drop-ins, running pods keep the VM they
     * have. The drop-ins travel in the script itself — a fresh ConfigMap volume could still show
     * the old content.
     *
     * @param  array<string, string>  $configMapData  buildKataConfigMap() data
     */
    private function buildKataDropInsDaemonSet(string $namespace, array $configMapData, bool $hasDedicatedNodes): array
    {
        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'DaemonSet',
            'metadata' => [
                'name' => self::KATA_DROPINS_DS,
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/component' => 'kata'],
            ],
            'spec' => [
                'selector' => ['matchLabels' => ['runr.dev/component' => self::KATA_DROPINS_DS]],
                'template' => [
                    'metadata' => ['labels' => ['runr.dev/component' => self::KATA_DROPINS_DS]],
                    'spec' => [
                        'automountServiceAccountToken' => false,
                        'terminationGracePeriodSeconds' => 5,
                        // the kata-installer's nodes
                        'nodeSelector' => $hasDedicatedNodes ? ['runr.dev/dedicated' => 'projects'] : ['kubernetes.io/os' => 'linux'],
                        'tolerations' => [[
                            'key' => 'runr.dev/dedicated',
                            'operator' => 'Equal',
                            'value' => 'projects',
                            'effect' => 'NoSchedule',
                        ]],
                        'containers' => [[
                            'name' => 'dropins',
                            'image' => 'ghcr.io/brf-tech/runr/reqs/alpine:3.21',
                            'command' => ['sh', '-c', $this->kataDropInsScript($configMapData)],
                            'env' => [
                                ['name' => 'NODE_NAME', 'valueFrom' => ['fieldRef' => ['fieldPath' => 'spec.nodeName']]],
                                ['name' => 'DIR', 'value' => '/custom-runtimes'],
                            ],
                            'terminationMessagePolicy' => 'FallbackToLogsOnError',
                            'readinessProbe' => [
                                'exec' => ['command' => ['cat', '/tmp/runr-kata-dropins-ready']],
                                'initialDelaySeconds' => 1,
                                'periodSeconds' => 2,
                            ],
                            'volumeMounts' => [['name' => 'custom-runtimes', 'mountPath' => '/custom-runtimes']],
                            'resources' => [
                                'requests' => ['cpu' => '10m', 'memory' => '8Mi'],
                                'limits' => ['cpu' => '100m', 'memory' => '32Mi'],
                            ],
                        ]],
                        // kata-deploy's custom runtimes only — present once it installed on the node
                        'volumes' => [['name' => 'custom-runtimes', 'hostPath' => ['path' => KataRuntime::CUSTOM_RUNTIMES_DIR, 'type' => 'Directory']]],
                    ],
                ],
            ],
        ];
    }

    /**
     * Node script of the drop-in DaemonSet: every drop-in of $configMapData to its handler's
     * config.d, then KATA_DROPINS_LOG. Errors are logged as "runr: ERROR: …" and the script stays
     * up (no restart loop); RunR removes the DaemonSet either way.
     */
    private function kataDropInsScript(array $configMapData): string
    {
        $script = <<<'SH'
            set -u
            NODE="${NODE_NAME:-?}"
            fail() { echo "runr: ERROR: $* (node $NODE)"; exec sleep infinity; }
            put() {
              d="$DIR/$1/config.d"
              [ -d "$d" ] || fail "$d does not exist: kata-deploy has not installed runtime handler $1 on this node"
              tmp="$DIR/$1/.50-overrides.toml.runr"
              cat > "$tmp" || fail "cannot write $tmp"
              if cmp -s "$tmp" "$d/50-overrides.toml"; then
                rm -f "$tmp"; echo "$1: drop-in unchanged"
              else
                mv -f "$tmp" "$d/50-overrides.toml" || fail "cannot replace $d/50-overrides.toml"
                echo "$1: drop-in updated ($d/50-overrides.toml)"
              fi
            }

            SH;

        $count = 0;
        ksort($configMapData);
        foreach ($configMapData as $key => $content) {
            if (! str_starts_with($key, self::KATA_DROPIN_KEY_PREFIX)) {
                continue;
            }
            $handler = substr($key, strlen(self::KATA_DROPIN_KEY_PREFIX), -strlen('.toml'));
            $script .= "put {$handler} <<'RUNR_DROPIN'\n".rtrim($content, "\n")."\nRUNR_DROPIN\n";
            $count++;
        }

        return $script.'echo "'.self::KATA_DROPINS_LOG." ({$count} runtime handler(s), node \$NODE)\"\n"
            ."touch /tmp/runr-kata-dropins-ready\n"
            ."exec sleep infinity\n";
    }

    /**
     * kata-deploy custom runtimes (mounted at /custom-configs): one "kata-<hv>-runr" handler per
     * hypervisor on top of kata-deploy's stock configuration, with RunR's drop-in
     * (KataRuntime::dropIn()).
     */
    private function buildKataConfigMap(string $namespace, array $shims, string $flavor = ContainerRuntimeHelper::FLAVOR_CONTAINERD): array
    {
        $shims = array_values(array_filter($shims, [KataRuntime::class, 'isSupported']));
        $staticSizing = $flavor !== ContainerRuntimeHelper::FLAVOR_CRIO;

        $data = ['custom-runtimes.list' => implode("\n", array_map([KataRuntime::class, 'customRuntimeLine'], $shims))."\n"];
        foreach ($shims as $shim) {
            $data['dropin-'.KataRuntime::handler($shim).'.toml'] = KataRuntime::dropIn($shim, $staticSizing);
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => [
                'name' => self::KATA_CONFIG_MAP,
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/component' => 'kata'],
            ],
            'data' => $data,
        ];
    }

    /**
     * RunR 0.4.1's template hash: installer pod spec + every custom runtime file, drop-ins
     * included. Still recognised on installers of that release (SandboxInstallService).
     */
    public static function kataConfigHash(array $podSpec, array $configMapData): string
    {
        ksort($configMapData);

        return substr(hash('sha256', json_encode([$podSpec, $configMapData], JSON_UNESCAPED_SLASHES)), 0, 16);
    }

    /** What kata-deploy itself installs: installer pod spec + custom runtime files except the drop-ins. */
    public static function kataInstallerHash(array $podSpec, array $configMapData): string
    {
        return 'i'.self::kataConfigHash($podSpec, array_filter(
            $configMapData,
            fn (string $key) => ! str_starts_with($key, self::KATA_DROPIN_KEY_PREFIX),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /** RunR's config.d drop-ins of every handler. */
    public static function kataDropInsHash(array $configMapData): string
    {
        $dropIns = array_filter($configMapData, fn (string $key) => str_starts_with($key, self::KATA_DROPIN_KEY_PREFIX), ARRAY_FILTER_USE_KEY);
        ksort($dropIns);

        return substr(hash('sha256', json_encode($dropIns, JSON_UNESCAPED_SLASHES)), 0, 16);
    }

    private function buildConfigFixupContainer(): array
    {
        // Fix duplicate TOML keys in containerd config that cause kata-deploy's parser to fail.
        // RunR <= 0.3.2's registry mirror appended a second config_path next to the empty default
        // one (`config_path = ""` in v2, `config_path = ''` in v3 configs) — drop the empty one.
        $script = 'F=/etc/containerd/config.toml; '
            .'[ -f "$F" ] && sed -i -E "/^[[:space:]]*config_path[[:space:]]*=[[:space:]]*(\"\"|\'\')[[:space:]]*\$/d" "$F"; '
            .'exit 0';

        return [
            'name' => 'config-fixup',
            'image' => 'ghcr.io/brf-tech/runr/reqs/busybox:latest',
            'securityContext' => ['privileged' => true],
            'command' => ['nsenter', '--target', '1', '--mount', '--uts', '--ipc', '--pid', '--', 'sh', '-c', $script],
        ];
    }

    /** @return list<array<string, mixed>> KATA_HOST_MOUNTS as pod volumes */
    private static function kataHostVolumes(): array
    {
        $volumes = [];
        foreach (self::KATA_HOST_MOUNTS as $name => [$path, , , $type]) {
            $volumes[] = ['name' => $name, 'hostPath' => array_filter(['path' => $path, 'type' => $type])];
        }

        return $volumes;
    }

    /** @return list<array<string, mixed>> KATA_HOST_MOUNTS as container mounts */
    private static function kataHostVolumeMounts(): array
    {
        $mounts = [];
        foreach (self::KATA_HOST_MOUNTS as $name => [, $mountPath, $readOnly]) {
            $mounts[] = ['name' => $name, 'mountPath' => $mountPath] + ($readOnly ? ['readOnly' => true] : []);
        }

        return $mounts;
    }

    private function buildKataDeployContainer(string $shimList, string $default): array
    {
        return [
            'name' => 'kata-deploy',
            'image' => self::KATA_IMAGE,
            'imagePullPolicy' => 'IfNotPresent',
            'command' => ['/usr/bin/kata-deploy', 'install'],
            // DAEMONSET_NAME stays unset: it would name kata-installer, and kata-deploy skips its
            // SIGTERM cleanup while that DaemonSet still selects the node. An update has to clean up:
            // reinstalling only adds to the containerd config, so a hypervisor the update takes
            // away would keep its runtime handler.
            'env' => [
                ['name' => 'NODE_NAME',            'valueFrom' => ['fieldRef' => ['fieldPath' => 'spec.nodeName']]],
                ['name' => 'DEBUG',                'value' => 'false'],
                ['name' => 'SHIMS_X86_64',         'value' => $shimList],
                ['name' => 'SHIMS_AARCH64',        'value' => $shimList],
                ['name' => 'DEFAULT_SHIM_X86_64',  'value' => $default],
                ['name' => 'DEFAULT_SHIM_AARCH64', 'value' => $default],
                ['name' => 'INSTALLATION_PREFIX',   'value' => ''],
                ['name' => 'MULTI_INSTALL_SUFFIX',  'value' => ''],
                // RunR's handlers (kata-<hv>-runr) with the config.d drop-in from the ConfigMap
                ['name' => 'CUSTOM_RUNTIMES_ENABLED', 'value' => 'true'],
            ],
            // the upstream chart runs it unprivileged, with an SELinux stage of its own on such nodes
            'securityContext' => ['privileged' => true],
            // A failed install shows its log tail in the pod status (RunR reports it)
            'terminationMessagePolicy' => 'FallbackToLogsOnError',
            'volumeMounts' => [
                ['name' => 'crio-conf',       'mountPath' => '/etc/crio/'],
                ['name' => 'containerd-conf', 'mountPath' => '/etc/containerd/'],
                ...self::kataHostVolumeMounts(),
                ['name' => 'custom-configs',  'mountPath' => '/custom-configs/', 'readOnly' => true],
            ],
        ];
    }

    /**
     * RuntimeClasses "kata-<hv>" → RunR's handler, with the pod overhead kubelet / scheduler /
     * ResourceQuota add per pod and a nodeSelector on the label kata-deploy sets once a node
     * can run Kata.
     */
    private function buildKataRuntimeClasses(array $shims): array
    {
        $classes = [];
        foreach ($shims as $shim) {
            if (! KataRuntime::isSupported($shim)) {
                continue;
            }
            $classes[] = [
                'apiVersion' => 'node.k8s.io/v1',
                'kind' => 'RuntimeClass',
                'metadata' => [
                    'name' => KataRuntime::runtimeClass($shim),
                    'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/component' => 'kata'],
                ],
                'handler' => KataRuntime::handler($shim),
                'overhead' => ['podFixed' => KataRuntime::overheadQuantities($shim)],
                'scheduling' => ['nodeSelector' => KataRuntime::nodeSelector($shim)],
            ];
        }

        return $classes;
    }

    private function buildKataRbac(string $namespace): array
    {
        $sa = [
            'apiVersion' => 'v1',
            'kind' => 'ServiceAccount',
            'metadata' => [
                'name' => self::KATA_SA_NAME,
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr'],
            ],
        ];

        $role = [
            'apiVersion' => 'rbac.authorization.k8s.io/v1',
            'kind' => 'ClusterRole',
            'metadata' => ['name' => 'kata-installer-role', 'labels' => ['app.kubernetes.io/managed-by' => 'runr']],
            // The upstream chart's rules of 3.28.0 (kata-rbac.yaml), more than later releases ask
            // for: an update starts with the SIGTERM cleanup of the pods it replaces, which may be
            // that release's. kata-deploy's SIGTERM cleanup reads DaemonSets first — without that
            // verb it stops there and leaves the node configured (label, containerd handlers,
            // /opt/kata).
            'rules' => [
                ['apiGroups' => [''], 'resources' => ['nodes'], 'verbs' => ['get', 'list', 'patch']],
                ['apiGroups' => ['node.k8s.io'], 'resources' => ['runtimeclasses'], 'verbs' => ['get', 'list', 'create', 'update', 'patch']],
                ['apiGroups' => ['apiextensions.k8s.io'], 'resources' => ['customresourcedefinitions'], 'verbs' => ['get', 'list']],
                ['apiGroups' => ['nfd.k8s-sigs.io'], 'resources' => ['nodefeaturerules'], 'verbs' => ['create', 'delete', 'get', 'list', 'patch', 'update', 'watch']],
                ['apiGroups' => ['apps'], 'resources' => ['daemonsets'], 'verbs' => ['get', 'list', 'watch']],
            ],
        ];

        $binding = [
            'apiVersion' => 'rbac.authorization.k8s.io/v1',
            'kind' => 'ClusterRoleBinding',
            'metadata' => ['name' => 'kata-installer-binding', 'labels' => ['app.kubernetes.io/managed-by' => 'runr']],
            'roleRef' => ['apiGroup' => 'rbac.authorization.k8s.io', 'kind' => 'ClusterRole', 'name' => 'kata-installer-role'],
            'subjects' => [['kind' => 'ServiceAccount', 'name' => self::KATA_SA_NAME, 'namespace' => $namespace]],
        ];

        return [$sa, $role, $binding];
    }
}
