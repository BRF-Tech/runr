<?php

namespace App\Services\Sandboxes;

use App\Models\KnativeRegion;
use App\Models\Sandbox;

/**
 * Kubernetes objects of the Sandbox API (pure builders, docs/sandbox-api.md → Security model).
 *
 * Per organization and region, one namespace "<prefix>-<tenant slug>" with
 *  - Pod Security Admission "restricted" (enforced), so nothing in it can run privileged, as root,
 *    with host namespaces / paths or extra capabilities — whatever creates the pod
 *  - NetworkPolicy runr-sandbox-default-deny: every pod, no ingress, no egress
 *  - ResourceQuota runr-sandbox-quota (count/pods) when the organization's concurrency is limited
 *
 * Per sandbox, a plain Pod (restartPolicy Never; not Knative):
 *  - runtimeClassName = the sandbox class (Kata / gVisor; none for runc)
 *  - automountServiceAccountToken false, enableServiceLinks false, no host namespaces
 *  - pod and container security context: runAsNonRoot with a UID of its own (runAsUser = runAsGroup
 *    = fsGroup), seccomp RuntimeDefault, allowPrivilegeEscalation false, capabilities drop ALL,
 *    readOnlyRootFilesystem unless the request turned it off
 *  - requests == limits for CPU, memory and ephemeral storage; writable emptyDirs /workspace,
 *    /output, /tmp, /home/sandbox, each limited to the sandbox's disk
 *  - activeDeadlineSeconds: the kubelet stops the pod after the longest life the API allows it
 *    (max TTL + warm time + grace), even if the API never comes back to it
 *  - PID 1 is a /bin/sh sleep loop; commands run through the exec API
 * and, with an egress allowlist, a NetworkPolicy sbx-<id>-egress selecting only that pod; with
 * env, the Secret sbx-<id>-env the container reads through secretKeyRef entries.
 */
final class SandboxManifests
{
    public const CONTAINER = 'sandbox';

    public const LABEL_MANAGED = 'app.kubernetes.io/managed-by';

    public const LABEL_COMPONENT = 'app.kubernetes.io/component';

    public const LABEL_SANDBOX = 'runr.dev/sandbox';

    public const LABEL_SANDBOX_ID = 'runr.dev/sandbox-id';

    public const LABEL_POOL = 'runr.dev/sandbox-pool';

    public const LABEL_STATE = 'runr.dev/sandbox-state';

    public const LABEL_TENANT = 'runr.dev/tenant';

    public const LABEL_NAMESPACE = 'runr.dev/sandbox-namespace';

    public const DEFAULT_DENY_POLICY = 'runr-sandbox-default-deny';

    public const RESOURCE_QUOTA = 'runr-sandbox-quota';

    public const HOME = '/home/sandbox';

    public const WORKSPACE = '/workspace';

    public const OUTPUT = '/output';

    public const ENV_FILE = '/home/sandbox/.runr/env.sh';

    /** PID 1: no service, no shell prompt — a sleep loop that ends on TERM and reaps orphans. */
    public const KEEPALIVE = 'mkdir -p "$HOME/.runr" /tmp/.runr 2>/dev/null; trap "exit 0" TERM INT; while :; do sleep 5 & wait $!; done';

    /** Writable directories (emptyDir volumes), name => mount path */
    public const VOLUMES = [
        'workspace' => self::WORKSPACE,
        'output' => self::OUTPUT,
        'tmp' => '/tmp',
        'home' => self::HOME,
    ];

    public static function podName(string $sandboxId): string
    {
        return 'sbx-'.strtolower($sandboxId);
    }

    public static function egressPolicyName(string $sandboxId): string
    {
        return 'sbx-'.strtolower($sandboxId).'-egress';
    }

    /** "runr-sbx-acme" / "runr-sbx-default" — at most 63 characters, DNS-1123. */
    public static function namespaceName(?string $tenantSlug): string
    {
        $prefix = trim((string) config('sandbox.namespace_prefix', 'runr-sbx'), '-') ?: 'runr-sbx';
        $suffix = $tenantSlug === null ? 'default' : strtolower(preg_replace('/[^a-z0-9-]+/i', '-', $tenantSlug));
        $suffix = trim($suffix, '-') ?: 'default';
        $name = "{$prefix}-{$suffix}";

        if (strlen($name) > 63) {
            $hash = substr(sha1($suffix), 0, 8);
            $name = rtrim(substr($name, 0, 63 - 9), '-').'-'.$hash;
        }

        return $name;
    }

    /** Label value of an organization ("default" without one). */
    public static function tenantLabel(?string $tenantId): string
    {
        if ($tenantId === null) {
            return 'default';
        }

        $value = preg_replace('/[^A-Za-z0-9._-]/', '-', $tenantId);

        return trim(substr($value, 0, 63), '-._') ?: 'default';
    }

    public static function namespace(string $name, ?string $tenantId): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => [
                'name' => $name,
                'labels' => [
                    self::LABEL_MANAGED => 'runr',
                    self::LABEL_NAMESPACE => 'true',
                    self::LABEL_TENANT => self::tenantLabel($tenantId),
                    'pod-security.kubernetes.io/enforce' => 'restricted',
                    'pod-security.kubernetes.io/enforce-version' => 'latest',
                    'pod-security.kubernetes.io/warn' => 'restricted',
                    'pod-security.kubernetes.io/audit' => 'restricted',
                ],
            ],
        ];
    }

    /** Every pod of the namespace: no ingress, no egress. Allowlists add per-pod policies. */
    public static function defaultDenyPolicy(string $namespace): array
    {
        return [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'NetworkPolicy',
            'metadata' => [
                'name' => self::DEFAULT_DENY_POLICY,
                'namespace' => $namespace,
                'labels' => [self::LABEL_MANAGED => 'runr', self::LABEL_COMPONENT => 'sandbox'],
            ],
            'spec' => [
                'podSelector' => (object) [],
                'policyTypes' => ['Ingress', 'Egress'],
            ],
        ];
    }

    /** Egress NetworkPolicy of one sandbox; null for network: none. */
    public static function egressPolicy(Sandbox $sandbox): ?array
    {
        $egress = SandboxNetwork::egressRules($sandbox->network ?? []);
        if ($egress === []) {
            return null;
        }

        return [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'NetworkPolicy',
            'metadata' => [
                'name' => self::egressPolicyName($sandbox->id),
                'namespace' => $sandbox->namespace,
                'labels' => [
                    self::LABEL_MANAGED => 'runr',
                    self::LABEL_COMPONENT => 'sandbox',
                    self::LABEL_SANDBOX_ID => strtolower($sandbox->id),
                ],
            ],
            'spec' => [
                'podSelector' => ['matchLabels' => [self::LABEL_SANDBOX_ID => strtolower($sandbox->id)]],
                'policyTypes' => ['Egress'],
                'egress' => $egress,
            ],
        ];
    }

    /** Pod count ceiling of the namespace: the organization's concurrency + its warm pools. */
    public static function resourceQuota(string $namespace, int $pods): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'ResourceQuota',
            'metadata' => [
                'name' => self::RESOURCE_QUOTA,
                'namespace' => $namespace,
                'labels' => [self::LABEL_MANAGED => 'runr', self::LABEL_COMPONENT => 'sandbox'],
            ],
            'spec' => ['hard' => ['count/pods' => (string) max(1, $pods)]],
        ];
    }

    /**
     * The sandbox pod.
     *
     * @param  int  $deadlineSeconds  activeDeadlineSeconds
     */
    public static function pod(Sandbox $sandbox, KnativeRegion $region, int $deadlineSeconds): array
    {
        $id = strtolower($sandbox->id);
        $labels = [
            self::LABEL_MANAGED => 'runr',
            self::LABEL_COMPONENT => 'sandbox',
            self::LABEL_SANDBOX => 'true',
            self::LABEL_SANDBOX_ID => $id,
            self::LABEL_TENANT => self::tenantLabel($sandbox->tenant_id),
            self::LABEL_STATE => $sandbox->pool_id && in_array($sandbox->status, Sandbox::POOL_STATUSES, true) ? 'warm' : 'claimed',
        ];
        if ($sandbox->pool_id) {
            $labels[self::LABEL_POOL] = strtolower($sandbox->pool_id);
        }

        $cpu = $sandbox->cpu_m.'m';
        $mem = $sandbox->memory_mb.'Mi';
        $disk = $sandbox->disk_mb.'Mi';

        $volumes = [];
        $mounts = [];
        foreach (self::VOLUMES as $name => $path) {
            $volumes[] = ['name' => $name, 'emptyDir' => ['sizeLimit' => $disk]];
            $mounts[] = ['name' => $name, 'mountPath' => $path];
        }

        $container = [
            'name' => self::CONTAINER,
            'image' => $region->registryImageRef($sandbox->image),
            'imagePullPolicy' => 'IfNotPresent',
            'command' => ['/bin/sh', '-c', self::KEEPALIVE],
            'workingDir' => self::WORKSPACE,
            'env' => self::containerEnv($sandbox),
            'resources' => [
                'requests' => ['cpu' => $cpu, 'memory' => $mem, 'ephemeral-storage' => $disk],
                'limits' => ['cpu' => $cpu, 'memory' => $mem, 'ephemeral-storage' => $disk],
            ],
            'securityContext' => [
                'allowPrivilegeEscalation' => false,
                'privileged' => false,
                'readOnlyRootFilesystem' => (bool) $sandbox->read_only_root,
                'runAsNonRoot' => true,
                'runAsUser' => $sandbox->uid,
                'runAsGroup' => $sandbox->uid,
                'capabilities' => ['drop' => ['ALL']],
                'seccompProfile' => ['type' => 'RuntimeDefault'],
            ],
            'volumeMounts' => $mounts,
            'terminationMessagePolicy' => 'FallbackToLogsOnError',
        ];

        $spec = [
            'restartPolicy' => 'Never',
            'automountServiceAccountToken' => false,
            'enableServiceLinks' => false,
            'hostNetwork' => false,
            'hostPID' => false,
            'hostIPC' => false,
            'shareProcessNamespace' => false,
            'terminationGracePeriodSeconds' => 5,
            'activeDeadlineSeconds' => max(60, $deadlineSeconds),
            'securityContext' => [
                'runAsNonRoot' => true,
                'runAsUser' => $sandbox->uid,
                'runAsGroup' => $sandbox->uid,
                'fsGroup' => $sandbox->uid,
                'seccompProfile' => ['type' => 'RuntimeDefault'],
            ],
            'containers' => [$container],
            'volumes' => $volumes,
        ];

        if ($sandbox->sandbox_class !== SandboxClassResolver::RUNC) {
            $spec['runtimeClassName'] = $sandbox->sandbox_class;
        }

        // Regions with dedicated project workers: sandboxes run there, next to the functions
        if (! empty($region->selected_workers)) {
            $spec['tolerations'] = [['key' => 'runr.dev/dedicated', 'operator' => 'Equal', 'value' => 'projects', 'effect' => 'NoSchedule']];
            $spec['nodeSelector'] = ['runr.dev/dedicated' => 'projects'];
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => [
                'name' => $sandbox->pod_name,
                'namespace' => $sandbox->namespace,
                'labels' => $labels,
                'annotations' => array_filter([
                    'runr.dev/sandbox-runtime' => $sandbox->runtime ? trim($sandbox->runtime.':'.$sandbox->runtime_version, ':') : null,
                ]),
            ],
            'spec' => $spec,
        ];
    }

    /**
     * Container env: the static variables, then the sandbox's own env (the request's `env`) as
     * secretKeyRef entries of the Secret sbx-<id>-env — values never appear in the pod spec. A warm
     * pod is started before its env is known: its env reaches it after the claim through ENV_FILE
     * (written over the exec channel, sourced by every command and by interactive shells via $ENV).
     */
    public static function containerEnv(Sandbox $sandbox): array
    {
        $keys = array_keys($sandbox->env ?? []);
        $env = array_values(array_filter(self::baseEnv($sandbox), fn ($e) => ! in_array($e['name'], $keys, true)));

        foreach ($keys as $key) {
            $env[] = ['name' => $key, 'valueFrom' => ['secretKeyRef' => ['name' => self::envSecretName($sandbox->id), 'key' => $key]]];
        }

        return $env;
    }

    public static function envSecretName(string $sandboxId): string
    {
        return 'sbx-'.strtolower($sandboxId).'-env';
    }

    /** The Secret holding the sandbox's env; null without env. */
    public static function envSecret(Sandbox $sandbox): ?array
    {
        $env = $sandbox->env ?? [];
        if ($env === []) {
            return null;
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'type' => 'Opaque',
            'metadata' => [
                'name' => self::envSecretName($sandbox->id),
                'namespace' => $sandbox->namespace,
                'labels' => [
                    self::LABEL_MANAGED => 'runr',
                    self::LABEL_COMPONENT => 'sandbox',
                    self::LABEL_SANDBOX_ID => strtolower($sandbox->id),
                ],
            ],
            'data' => array_map(fn ($value) => base64_encode((string) $value), $env),
        ];
    }

    /** Static environment of every sandbox pod (no secrets). */
    public static function baseEnv(Sandbox $sandbox): array
    {
        return [
            ['name' => 'HOME', 'value' => self::HOME],
            ['name' => 'TMPDIR', 'value' => '/tmp'],
            ['name' => 'ENV', 'value' => self::ENV_FILE],
            ['name' => 'RUNR_SANDBOX', 'value' => '1'],
            ['name' => 'RUNR_SANDBOX_ID', 'value' => strtolower($sandbox->id)],
            ['name' => 'RUNR_OUTPUT_DIR', 'value' => self::OUTPUT],
            ['name' => 'MPLBACKEND', 'value' => 'Agg'],
            ['name' => 'PYTHONUNBUFFERED', 'value' => '1'],
        ];
    }

    /** Labels of a claimed warm pod (merge patch): the pool label removed, state claimed. */
    public static function claimLabels(): array
    {
        return ['metadata' => ['labels' => [self::LABEL_POOL => null, self::LABEL_STATE => 'claimed']]];
    }
}
