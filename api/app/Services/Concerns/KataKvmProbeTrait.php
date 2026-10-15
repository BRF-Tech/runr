<?php

namespace App\Services\Concerns;

use App\Services\CacheVersionService;
use App\Services\Helpers\KataRuntime;
use App\Services\KubernetesClient;

/**
 * Whether the Kata target nodes can run VMs. Kata needs hardware virtualization: the /dev/kvm
 * device and the CPU's vmx / svm flag. Cloud VMs without nested virtualization (e.g. many cloud
 * worker flavors) have neither — Kata installs there, but its pods never start.
 *
 * Per node (nodeKvm()): RunR's own check (label KataRuntime::KVM_LABEL) when it ran, else NFD's
 * CPU labels (feature.node.kubernetes.io/cpu-cpuid.VMX / .SVM) as a fast path, else unknown.
 *
 * The check (probeKvm()) is a short-lived DaemonSet on the kata-installer's nodes: no privileges,
 * the node's /dev mounted read-only to stat /dev/kvm, the CPU flags from /proc/cpuinfo and the
 * loaded KVM module from /sys/module. Its result is kept on the nodes — label runr.dev/kvm
 * (true | false, also what every Kata RuntimeClass selects) and annotation runr.dev/kvm-probe
 * (what was seen, when) — so the console's kata-shims call reads it with the node list. Nodes
 * without the label (new nodes) are checked when the console asks (ProbeKvmJob), before an
 * install, and at region:reconcile.
 */
trait KataKvmProbeTrait
{
    private const KVM_PROBE_DS = 'kata-kvm-probe';

    /** The check's result line. */
    public const KVM_PROBE_LOG = 'runr: kvm probe:';

    /** Default wait for the check (image already on the nodes: a few seconds). */
    public int $kvmProbeSeconds = 60;

    /**
     * Per-node verdict: true / false, null = not known (no RunR check, no NFD CPU labels).
     */
    public static function nodeKvm(array $node): ?bool
    {
        $labels = $node['metadata']['labels'] ?? [];

        $checked = $labels[KataRuntime::KVM_LABEL] ?? null;
        if ($checked === 'true' || $checked === 'false') {
            return $checked === 'true';
        }

        if (isset($labels['feature.node.kubernetes.io/cpu-cpuid.VMX']) || isset($labels['feature.node.kubernetes.io/cpu-cpuid.SVM'])) {
            return true;
        }
        // NFD labelled the CPU's features without VMX / SVM: no hardware virtualization
        foreach (array_keys($labels) as $key) {
            if (str_starts_with($key, 'feature.node.kubernetes.io/cpu-cpuid.')) {
                return false;
            }
        }

        return null;
    }

    /**
     * The nodes Kata is installed on (the kata-installer DaemonSet's): Linux nodes, only the
     * runr.dev/dedicated=projects workers when there are any, without a NoSchedule / NoExecute
     * taint other than runr.dev/dedicated.
     *
     * @param  list<array>  $nodes  items of a v1 NodeList
     * @return list<array>
     */
    public static function kataTargetNodes(array $nodes): array
    {
        $linux = array_values(array_filter($nodes, fn ($n) => ($n['metadata']['labels']['kubernetes.io/os'] ?? 'linux') === 'linux'));
        $dedicated = array_values(array_filter($linux, fn ($n) => ($n['metadata']['labels']['runr.dev/dedicated'] ?? null) === 'projects'));
        $nodes = $dedicated ?: $linux;

        return array_values(array_filter($nodes, function (array $n) {
            foreach ($n['spec']['taints'] ?? [] as $taint) {
                if (in_array($taint['effect'] ?? '', ['NoSchedule', 'NoExecute'], true) && ($taint['key'] ?? '') !== 'runr.dev/dedicated') {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * KVM over the Kata target nodes.
     *
     * state: yes (every node) | mixed (some nodes; the others without or not checked) |
     *        no (none — Kata cannot run) | unknown (none known to have it, some not checked)
     *
     * @param  list<array>  $nodes  items of a v1 NodeList (all nodes; the targets are picked here)
     * @return array{state: string, nodes: array<string, ?bool>, unprobed: list<string>, kvm_nodes: list<string>, checked: array<string, string>}
     */
    public static function kvmSummary(array $nodes): array
    {
        $verdicts = [];
        $unprobed = [];
        $checked = [];
        foreach (self::kataTargetNodes($nodes) as $node) {
            $name = (string) ($node['metadata']['name'] ?? '?');
            $verdicts[$name] = self::nodeKvm($node);
            if (! isset($node['metadata']['labels'][KataRuntime::KVM_LABEL])) {
                $unprobed[] = $name;
            }
            if (isset($node['metadata']['annotations'][KataRuntime::KVM_PROBE_ANNOTATION])) {
                $checked[$name] = (string) $node['metadata']['annotations'][KataRuntime::KVM_PROBE_ANNOTATION];
            }
        }

        $yes = count(array_filter($verdicts, fn ($v) => $v === true));
        $no = count(array_filter($verdicts, fn ($v) => $v === false));
        $nulls = count($verdicts) - $yes - $no;

        $state = match (true) {
            $verdicts === [] => 'unknown',
            $yes === count($verdicts) => 'yes',
            $yes > 0 => 'mixed',
            $nulls === 0 => 'no',
            default => 'unknown',
        };

        return [
            'state' => $state,
            'nodes' => $verdicts,
            'unprobed' => $unprobed,
            'kvm_nodes' => array_keys(array_filter($verdicts, fn ($v) => $v === true)),
            'checked' => $checked,
        ];
    }

    /** kvmSummary() of the cluster now (labels only — no check is run). */
    public function kvmStatus(KubernetesClient $k8s): array
    {
        return self::kvmSummary($k8s->listClusterResources('v1', 'Node')['items'] ?? []);
    }

    /**
     * Run the check where it has not run yet (or, with $all, on every target node again) and
     * return the summary afterwards. Nodes the check could not reach stay unprobed.
     */
    public function ensureKvmProbed(KubernetesClient $k8s, bool $all = false, ?int $timeoutSeconds = null): array
    {
        $summary = $this->kvmStatus($k8s);
        if (! $all && $summary['unprobed'] === []) {
            return $summary + ['changed' => []];
        }

        $changed = $this->probeKvm($k8s, $timeoutSeconds);

        return $this->kvmStatus($k8s) + ['changed' => $changed];
    }

    /**
     * Run the check DaemonSet on the target nodes and record each result on its node.
     *
     * @return array<string, bool> nodes whose label was set or changed → their KVM
     */
    public function probeKvm(KubernetesClient $k8s, ?int $timeoutSeconds = null): array
    {
        $hasDedicated = $this->hasDedicatedWorkerNodes($k8s);

        $this->deleteNodeDaemonSet($k8s, self::KVM_PROBE_DS);
        $results = [];
        try {
            if (! $k8s->applyResource($this->buildKvmProbeDaemonSet(self::SANDBOX_NAMESPACE, $hasDedicated), self::SANDBOX_NAMESPACE)) {
                throw new \RuntimeException('cannot apply DaemonSet '.self::SANDBOX_NAMESPACE.'/'.self::KVM_PROBE_DS);
            }

            // What every node reported within the wait — a node that did not answer stays unprobed
            $this->waitFor(function () use ($k8s, &$results) {
                $ds = $k8s->getResourceStatus('apps/v1', 'DaemonSet', self::KVM_PROBE_DS, self::SANDBOX_NAMESPACE);
                if (! $ds) {
                    return false;
                }
                $state = $this->nodeScriptPodState($k8s, self::KVM_PROBE_DS, [self::KVM_PROBE_LOG], $ds);
                $results = $state['nodes'];

                return $state['pending'] === [];
            }, $timeoutSeconds ?? $this->kvmProbeSeconds, max(1, min(3, $this->kataPollSeconds)));
        } finally {
            $this->deleteNodeDaemonSet($k8s, self::KVM_PROBE_DS, false);
        }

        $changed = [];
        $now = now()->utc()->format('Y-m-d\TH:i:s\Z');
        foreach ($results as $node => $line) {
            $probe = self::parseKvmProbeLine($line);
            if ($probe === null) {
                continue;
            }

            $live = $k8s->getClusterResource('v1', 'Node', $node);
            $value = $probe['kvm'] ? 'true' : 'false';
            if (($live['metadata']['labels'][KataRuntime::KVM_LABEL] ?? null) !== $value) {
                $changed[$node] = $probe['kvm'];
            }

            $k8s->strategicMergePatchCluster('v1', 'Node', $node, ['metadata' => [
                'labels' => [KataRuntime::KVM_LABEL => $value],
                'annotations' => [KataRuntime::KVM_PROBE_ANNOTATION => sprintf(
                    'kvm=%s dev_kvm=%s cpu=%s module=%s at=%s',
                    $probe['kvm'] ? 'yes' : 'no', $probe['dev_kvm'] ? 'yes' : 'no', $probe['cpu'], $probe['module'], $now,
                )],
            ]]);
        }

        // kata-shims answers are cached with the region settings
        try {
            app(CacheVersionService::class)->bump('settings');
        } catch (\Throwable) {
        }

        return $changed;
    }

    /**
     * "runr: kvm probe: kvm=yes dev_kvm=yes cpu=vmx module=kvm_intel node=…" → fields; null when
     * the line is not a result.
     *
     * @return array{kvm: bool, dev_kvm: bool, cpu: string, module: string}|null
     */
    public static function parseKvmProbeLine(string $line): ?array
    {
        $pos = strpos($line, self::KVM_PROBE_LOG);
        if ($pos === false) {
            return null;
        }

        $fields = [];
        foreach (preg_split('/\s+/', trim(substr($line, $pos + strlen(self::KVM_PROBE_LOG)))) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $fields[$key] = $value;
        }

        if (! isset($fields['kvm'], $fields['dev_kvm']) || ! in_array($fields['kvm'], ['yes', 'no'], true)) {
            return null;
        }

        return [
            'kvm' => $fields['kvm'] === 'yes',
            'dev_kvm' => $fields['dev_kvm'] === 'yes',
            'cpu' => preg_replace('/[^a-z]/', '', $fields['cpu'] ?? '') ?: 'none',
            'module' => preg_replace('/[^a-z_]/', '', $fields['module'] ?? '') ?: 'none',
        ];
    }

    /**
     * The check: one pod per Kata target node, unprivileged (no hostPID, no capabilities, non-root,
     * read-only root filesystem), the node's /dev mounted read-only.
     */
    private function buildKvmProbeDaemonSet(string $namespace, bool $hasDedicatedNodes): array
    {
        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'DaemonSet',
            'metadata' => [
                'name' => self::KVM_PROBE_DS,
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/component' => 'kata'],
            ],
            'spec' => [
                'selector' => ['matchLabels' => ['runr.dev/component' => self::KVM_PROBE_DS]],
                'template' => [
                    'metadata' => ['labels' => ['runr.dev/component' => self::KVM_PROBE_DS]],
                    'spec' => [
                        'automountServiceAccountToken' => false,
                        'terminationGracePeriodSeconds' => 1,
                        // the kata-installer's nodes
                        'nodeSelector' => $hasDedicatedNodes ? ['runr.dev/dedicated' => 'projects'] : ['kubernetes.io/os' => 'linux'],
                        'tolerations' => [[
                            'key' => 'runr.dev/dedicated',
                            'operator' => 'Equal',
                            'value' => 'projects',
                            'effect' => 'NoSchedule',
                        ]],
                        'securityContext' => ['runAsNonRoot' => true, 'runAsUser' => 65534, 'runAsGroup' => 65534],
                        'containers' => [[
                            'name' => 'kvm-probe',
                            'image' => 'ghcr.io/runrsh/reqs/alpine:3.21',
                            'command' => ['sh', '-c', self::kvmProbeScript()],
                            'env' => [['name' => 'NODE_NAME', 'valueFrom' => ['fieldRef' => ['fieldPath' => 'spec.nodeName']]]],
                            'securityContext' => [
                                'allowPrivilegeEscalation' => false,
                                'readOnlyRootFilesystem' => true,
                                'capabilities' => ['drop' => ['ALL']],
                            ],
                            'volumeMounts' => [['name' => 'host-dev', 'mountPath' => '/host-dev', 'readOnly' => true]],
                            'resources' => [
                                'requests' => ['cpu' => '10m', 'memory' => '8Mi'],
                                'limits' => ['cpu' => '50m', 'memory' => '16Mi'],
                            ],
                        ]],
                        'volumes' => [['name' => 'host-dev', 'hostPath' => ['path' => '/dev', 'type' => 'Directory']]],
                    ],
                ],
            ],
        ];
    }

    /**
     * KVM needs the CPU's vmx / svm flag (absent on VMs without nested virtualization) and the
     * kernel side: the /dev/kvm device or a loaded kvm_intel / kvm_amd module. /dev/kvm alone does
     * not count (it can be a static device node without hardware support); the module counts where
     * the host's SELinux policy hides the device from an unprivileged pod.
     */
    public static function kvmProbeScript(): string
    {
        return <<<'SH'
DEV=no; [ -c /host-dev/kvm ] && DEV=yes
CPU=none
if grep -qw vmx /proc/cpuinfo 2>/dev/null; then CPU=vmx; elif grep -qw svm /proc/cpuinfo 2>/dev/null; then CPU=svm; fi
MOD=none
if [ -d /sys/module/kvm_intel ]; then MOD=kvm_intel; elif [ -d /sys/module/kvm_amd ]; then MOD=kvm_amd; fi
KVM=no
if [ "$CPU" != none ] && { [ "$DEV" = yes ] || [ "$MOD" != none ]; }; then KVM=yes; fi
echo "runr: kvm probe: kvm=$KVM dev_kvm=$DEV cpu=$CPU module=$MOD node=${NODE_NAME:-?}"
exec sleep infinity
SH;
    }
}
