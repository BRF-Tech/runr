<?php

namespace App\Services\RegionReconcile\Steps;

use App\Services\Contracts\KnativeConstants;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\SandboxInstallService;
use App\Services\Wasm\WasmManifests;
use App\Support\Images\RegistryMove;

/**
 * RunR's image namespace (App\Support\Images\RegistryMove): workloads RunR deployed into the
 * cluster before the upgrade run images under one of RunR's earlier namespaces — ghcr.io/runrsh/
 * (up to 0.5.0) or RunR's packages directly under ghcr.io/brf-tech/ (0.5.1). Their pod templates
 * move to the same images under ghcr.io/brf-tech/runr/ — every container and init container
 * image, and every env value, that RegistryMove::ref() moves (it starts with such a reference;
 * images of other registries, also the region registry's pull-through path {registry}/ghcr.io/…,
 * stay):
 *
 *  - Knative Services (functions with the wrapper init container or a runtime image, CaaS
 *    projects, model services, hosted MCP servers): a new revision; Knative moves the traffic
 *    to it once it is ready, the running revision serves until then
 *  - Deployments, StatefulSets, DaemonSets (the region agent, queue consumers, the registry and
 *    its node mirror, Loki, binding data services, image pre-pull, …): rolled once
 *  - CronJobs (schedule triggers): from their next run
 *  - Prometheus (prometheus-operator resource of the region's metrics): the operator rolls it once
 *
 * Two kinds of workloads get the moved pod template but keep their running pods; a pod created
 * later (on a new node, or for a pod that was deleted or evicted) starts from the moved images:
 *
 *  - the node installers of the sandbox runtimes (runr-sandbox: the Kata installer, Kata's
 *    devmapper setup, the gVisor installer) — rolling them would re-run the install on every node,
 *    and kata-deploy's cleanup and reinstall restart the container runtime. The same patch sets
 *    the DaemonSet's update strategy to OnDelete, so its controller replaces no pod. A gVisor
 *    install, a Kata install that re-runs kata-deploy and Kata's Firecracker setup apply their
 *    DaemonSet with RollingUpdate again (and re-run the installer, as an install does); Kata's
 *    in-place drop-in update keeps OnDelete (SandboxInstallService::syncKataDropIns()).
 *  - the region's queue Redis (runr-redis): its streams, consumer groups and the KV bindings' data
 *    live in memory only, a new pod starts empty. While the Deployment is paused, its active
 *    ReplicaSet gets the moved pod template (a ReplicaSet's template only shapes the pods it
 *    creates later); then the Deployment gets it and is resumed in the same patch — the
 *    Deployment controller takes the ReplicaSet with its pod template as its current one and
 *    starts no pod (moveKeepingPod()). A run that stops half-way leaves the Deployment paused
 *    (annotation PAUSED_ANNOTATION) and the next run finishes the move.
 *
 * One JSON patch per object, each replaced value guarded by a test operation (a concurrent change
 * makes the patch fail and the next run moves it). Idempotent: a moved object has nothing to patch.
 *
 * Not patched here:
 *  - objects a controller owns (a revision's Deployment, a Job of a CronJob, Prometheus's
 *    StatefulSet, a Deployment's ReplicaSet — the queue Redis's aside) — their owner is patched;
 *  - RunR's own Helm release (the control plane on the same cluster) — the chart maps its images;
 *  - the region Traefik (traefik-image) and the WebAssembly installer (wasm-sandbox re-runs it);
 *  - Jobs and standalone pods (builds, scans, uploads, sandboxes): short-lived, the next ones
 *    start from the moved settings.
 */
final class ImageRegistryStep implements ReconcileStep
{
    /** [apiVersion, kind, path of the pod spec, what the move does to it, served only with a CRD] */
    private const KINDS = [
        ['serving.knative.dev/v1', 'Service', ['spec', 'template', 'spec'], 'a new revision takes the traffic once it is ready', true],
        ['apps/v1', 'Deployment', ['spec', 'template', 'spec'], 'its pods roll once', false],
        ['apps/v1', 'StatefulSet', ['spec', 'template', 'spec'], 'its pods roll once', false],
        ['apps/v1', 'DaemonSet', ['spec', 'template', 'spec'], 'its pods roll once', false],
        ['batch/v1', 'CronJob', ['spec', 'jobTemplate', 'spec', 'template', 'spec'], 'from its next run', false],
        ['monitoring.coreos.com/v1', 'Prometheus', ['spec'], 'the operator rolls it once', true],
    ];

    /** Annotation of a queue Redis Deployment this step paused (moveKeepingPod()). */
    public const PAUSED_ANNOTATION = 'runr.dev/image-move-paused';

    /** How a workload moves (how()): patched, its pods roll */
    private const ROLL = 'roll';

    /** … node installer of a sandbox runtime: patched with update strategy OnDelete */
    private const PIN_PODS = 'pin-pods';

    /** … the queue Redis: its ReplicaSet first, the Deployment paused meanwhile */
    private const KEEP_POD = 'keep-pod';

    private const PIN_PODS_EFFECT = 'its pods keep running (update strategy OnDelete: rolling them would re-run the install on '
        .'every node, and Kata\'s install restarts the container runtime); a pod created later starts from the moved image';

    private const KEEP_POD_EFFECT = 'its pod keeps running (the queue Redis holds its streams and the KV data in memory only): '
        .'its ReplicaSet takes the moved image first, a pod created later starts from it';

    public function name(): string
    {
        return 'image-registry';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $k8s = $cluster->k8s;
        $lines = [];
        $failed = [];

        foreach (self::KINDS as [$apiVersion, $kind, $specPath, $effect, $optional]) {
            if ($optional && ! $this->serves($k8s, $apiVersion, $kind)) {
                continue;
            }

            $list = $k8s->listClusterResources($apiVersion, $kind);
            if ($list === null) {
                throw new \RuntimeException("cannot list {$kind}s ({$apiVersion})");
            }

            foreach ($list['items'] ?? [] as $object) {
                $how = $this->how($object, $kind, $cluster);
                if ($how === null) {
                    continue;
                }
                $ops = self::patch($object, $specPath);
                // a queue Redis this step paused is resumed even when nothing is left to move
                if ($ops === [] && ! ($how === self::KEEP_POD && self::pausedHere($object))) {
                    continue;
                }

                $ns = (string) ($object['metadata']['namespace'] ?? '');
                $name = (string) ($object['metadata']['name'] ?? '');
                $what = "{$kind} {$ns}/{$name}";
                $moves = implode(', ', array_map(fn ($i) => $i.' → '.RegistryMove::ref($i), self::movedImages($ops)));

                if ($how === self::KEEP_POD) {
                    [$ok, $line] = $this->moveKeepingPod($k8s, $object, $ops, $what, $moves, $dryRun);
                    if ($ok) {
                        $lines[] = $line;
                    } else {
                        $failed[] = "{$what} ({$line})";
                    }

                    continue;
                }

                $says = $effect;
                if ($how === self::PIN_PODS) {
                    $ops[] = ['op' => 'add', 'path' => '/spec/updateStrategy', 'value' => ['type' => 'OnDelete']];
                    $says = self::PIN_PODS_EFFECT;
                }

                $summary = "{$what}: {$moves} — {$says}";
                if ($dryRun) {
                    $lines[] = "would move {$summary}";

                    continue;
                }
                if (! $k8s->jsonPatch($apiVersion, $kind, $name, $ns, $ops)) {
                    $failed[] = $what;

                    continue;
                }
                $lines[] = "moved {$summary}";
            }
        }

        if ($failed) {
            throw new \RuntimeException(count($failed).' workload(s) not moved to '.RegistryMove::TO.' (patch refused, see the API log; the next run tries again): '
                .implode(', ', $failed).($lines ? ' — done: '.implode('; ', $lines) : ''));
        }

        return $lines;
    }

    /**
     * JSON patch operations that move the pod spec at $specPath: test + replace for every image
     * and env value RegistryMove::ref() moves. Empty when there is nothing to move.
     *
     * @param  list<string>  $specPath
     * @return list<array{op: string, path: string, value: string}>
     */
    public static function patch(array $object, array $specPath): array
    {
        $spec = $object;
        foreach ($specPath as $key) {
            $spec = is_array($spec) ? ($spec[$key] ?? null) : null;
        }
        if (! is_array($spec)) {
            return [];
        }

        $base = '/'.implode('/', $specPath);
        $moved = self::movedSpec($spec);
        $ops = [];
        foreach (['initContainers', 'containers'] as $list) {
            foreach ((array) ($spec[$list] ?? []) as $i => $container) {
                $path = "{$base}/{$list}/{$i}";
                $image = $container['image'] ?? null;
                if (is_string($image) && $moved[$list][$i]['image'] !== $image) {
                    array_push($ops, ['op' => 'test', 'path' => "{$path}/image", 'value' => $image], ['op' => 'replace', 'path' => "{$path}/image", 'value' => $moved[$list][$i]['image']]);
                }
                foreach ((array) ($container['env'] ?? []) as $j => $env) {
                    $value = $env['value'] ?? null;
                    if (is_string($value) && $moved[$list][$i]['env'][$j]['value'] !== $value) {
                        array_push($ops, ['op' => 'test', 'path' => "{$path}/env/{$j}/value", 'value' => $value], ['op' => 'replace', 'path' => "{$path}/env/{$j}/value", 'value' => $moved[$list][$i]['env'][$j]['value']]);
                    }
                }
            }
        }

        return $ops;
    }

    /** A pod spec as patch() leaves it: every image and env value through RegistryMove::ref(). */
    private static function movedSpec(array $spec): array
    {
        foreach (['initContainers', 'containers'] as $list) {
            foreach ((array) ($spec[$list] ?? []) as $i => $container) {
                if (is_string($container['image'] ?? null)) {
                    $spec[$list][$i]['image'] = RegistryMove::ref($container['image']);
                }
                foreach ((array) ($container['env'] ?? []) as $j => $env) {
                    if (is_string($env['value'] ?? null)) {
                        $spec[$list][$i]['env'][$j]['value'] = RegistryMove::ref($env['value']);
                    }
                }
            }
        }

        return $spec;
    }

    /**
     * How an object moves: null = another step or its owner takes care of it (nothing to say),
     * ROLL, PIN_PODS (a node installer of a sandbox runtime) or KEEP_POD (the queue Redis).
     */
    private function how(array $object, string $kind, ClusterGroup $cluster): ?string
    {
        $meta = $object['metadata'] ?? [];
        foreach ((array) ($meta['ownerReferences'] ?? []) as $owner) {
            if (! empty($owner['controller'])) {
                return null;
            }
        }

        // RunR's own release: `helm upgrade` renders its images (chart helper runr.image)
        if (str_starts_with((string) ($meta['labels']['helm.sh/chart'] ?? ''), 'runr-')) {
            return null;
        }

        $ns = (string) ($meta['namespace'] ?? '');
        $name = (string) ($meta['name'] ?? '');

        if ($kind === 'Deployment' && $name === KnativeConstants::TRAEFIK_RELEASE && in_array($ns, $cluster->servingNamespaces(), true)) {
            return null; // traefik-image
        }

        if ($kind === 'DaemonSet' && in_array($ns, [WasmManifests::NAMESPACE, SandboxInstallService::SANDBOX_NAMESPACE], true)) {
            return $name === WasmManifests::INSTALLER_DS ? null : self::PIN_PODS; // wasm-sandbox re-runs the WebAssembly installer
        }

        if ($kind === 'Deployment' && ($meta['labels']['app.kubernetes.io/name'] ?? null) === 'runr-redis') {
            return self::KEEP_POD;
        }

        return self::ROLL;
    }

    /**
     * The queue Redis keeps its pod (class doc): the Deployment is paused, its active ReplicaSet
     * gets the moved pod template, then the Deployment gets it and is resumed in one patch.
     * Read-only in a dry run. Kept as it is: a Deployment paused by someone else, a rollout in
     * progress (more than one ReplicaSet with pods, or a ReplicaSet of another pod template).
     *
     * @param  list<array>  $ops  patch() of the Deployment (empty: only the resume is left)
     * @return array{0: bool, 1: string} false: a patch was refused; the output line, or why
     */
    private function moveKeepingPod(KubernetesClient $k8s, array $deployment, array $ops, string $what, string $moves, bool $dryRun): array
    {
        $ns = (string) ($deployment['metadata']['namespace'] ?? '');
        $name = (string) ($deployment['metadata']['name'] ?? '');
        $pausedHere = self::pausedHere($deployment);
        $images = self::movedImages($ops);
        $kept = fn (string $why) => [true, "kept: {$what}".($images ? ' runs '.implode(', ', $images) : '')." — {$why}"];

        if (! empty($deployment['spec']['paused']) && ! $pausedHere) {
            return $kept('the Deployment is paused; it moves once it is resumed');
        }

        $replicaSets = $k8s->listResources('apps/v1', 'ReplicaSet', $ns, (array) ($deployment['spec']['selector']['matchLabels'] ?? []));
        if ($replicaSets === null) {
            return [false, 'cannot list its ReplicaSets'];
        }
        $active = array_values(array_filter((array) ($replicaSets['items'] ?? []), fn (array $rs) => self::ownedBy($rs, $deployment)
            && ((int) ($rs['spec']['replicas'] ?? 0) > 0 || (int) ($rs['status']['replicas'] ?? 0) > 0)));
        $resume = $pausedHere ? self::resumeOps() : [];

        // No pod to keep: the template moves as any other's
        if ($active === []) {
            if ($dryRun) {
                return [true, $ops ? "would move {$what}: {$moves} — no pod runs" : "would resume {$what} (paused by an earlier run)"];
            }

            return $k8s->jsonPatch('apps/v1', 'Deployment', $name, $ns, array_merge($ops, $resume))
                ? [true, $ops ? "moved {$what}: {$moves} — no pod runs" : "resumed {$what}"]
                : [false, 'patch refused'];
        }
        if (count($active) > 1) {
            return $kept(count($active).' of its ReplicaSets run pods (a rollout in progress)'.($pausedHere ? ', paused by an earlier run' : '').'; the next run moves it');
        }

        $rs = $active[0];
        $rsName = (string) ($rs['metadata']['name'] ?? '');
        $template = (array) ($deployment['spec']['template'] ?? []);
        $moved = ['spec' => self::movedSpec((array) ($template['spec'] ?? []))] + $template;
        $rsTemplate = self::withoutHashLabel((array) ($rs['spec']['template'] ?? []));
        // moved by an earlier run that stopped before the Deployment
        $rsMoved = $ops !== [] && $rsTemplate == $moved;

        if (! $rsMoved && $rsTemplate != $template) {
            return $kept("its ReplicaSet {$rsName} runs another pod template (a rollout in progress)"
                .($pausedHere ? ', paused by an earlier run: `kubectl -n '.$ns.' rollout resume deployment/'.$name.'` rolls it' : '').'; the next run moves it');
        }

        if ($dryRun) {
            return [true, $ops ? "would move {$what}: {$moves} — ".self::KEEP_POD_EFFECT : "would resume {$what} (paused by an earlier run; its ReplicaSet {$rsName} is moved)"];
        }
        if ($ops === []) {
            return $k8s->jsonPatch('apps/v1', 'Deployment', $name, $ns, $resume)
                ? [true, "resumed {$what} (paused by an earlier run; its ReplicaSet {$rsName} is moved)"]
                : [false, 'the resume was refused'];
        }

        $tests = array_values(array_filter($ops, fn (array $op) => $op['op'] === 'test'));

        // 1. paused: no new ReplicaSet while the Deployment's and its ReplicaSet's templates differ
        if (! $pausedHere && ! $k8s->jsonPatch('apps/v1', 'Deployment', $name, $ns, array_merge($tests, self::pauseOps($deployment)))) {
            return [false, 'the pause was refused, nothing changed'];
        }

        // 2. the ReplicaSet: a pod it creates from now on starts from the moved images, the running one stays
        if (! $rsMoved && ! $k8s->jsonPatch('apps/v1', 'ReplicaSet', $rsName, $ns, $ops)) {
            $resumed = $k8s->jsonPatch('apps/v1', 'Deployment', $name, $ns, array_merge($tests, self::resumeOps()));

            return [false, "its ReplicaSet {$rsName} refused the patch; "
                .($resumed ? 'the Deployment is resumed as it was' : 'the Deployment stays paused until the next run moves it')];
        }

        // 3. the Deployment: the pod template its ReplicaSet has, resumed in the same patch
        if (! $k8s->jsonPatch('apps/v1', 'Deployment', $name, $ns, array_merge($ops, self::resumeOps()))) {
            return [false, "the Deployment refused the patch; it stays paused (its ReplicaSet {$rsName} is moved) until the next run finishes the move"];
        }

        return [true, "moved {$what}: {$moves} — ".self::KEEP_POD_EFFECT];
    }

    /** Did this step pause the Deployment (a run that stopped half-way)? */
    private static function pausedHere(array $deployment): bool
    {
        return ! empty($deployment['spec']['paused'])
            && ($deployment['metadata']['annotations'][self::PAUSED_ANNOTATION] ?? null) === 'true';
    }

    /** @return list<array{op: string, path: string, value: mixed}> */
    private static function pauseOps(array $deployment): array
    {
        return [
            ['op' => 'add', 'path' => '/spec/paused', 'value' => true],
            is_array($deployment['metadata']['annotations'] ?? null)
                ? ['op' => 'add', 'path' => self::pausedAnnotationPath(), 'value' => 'true']
                : ['op' => 'add', 'path' => '/metadata/annotations', 'value' => [self::PAUSED_ANNOTATION => 'true']],
        ];
    }

    /** @return list<array{op: string, path: string, value?: mixed}> resume a Deployment this step paused */
    private static function resumeOps(): array
    {
        return [
            ['op' => 'test', 'path' => self::pausedAnnotationPath(), 'value' => 'true'],
            ['op' => 'add', 'path' => '/spec/paused', 'value' => false],
            ['op' => 'remove', 'path' => self::pausedAnnotationPath()],
        ];
    }

    private static function pausedAnnotationPath(): string
    {
        return '/metadata/annotations/'.str_replace(['~', '/'], ['~0', '~1'], self::PAUSED_ANNOTATION);
    }

    /** Is $rs a ReplicaSet of $deployment (its controller)? */
    private static function ownedBy(array $rs, array $deployment): bool
    {
        $uid = $deployment['metadata']['uid'] ?? null;
        foreach ((array) ($rs['metadata']['ownerReferences'] ?? []) as $owner) {
            if (! empty($owner['controller']) && ($owner['kind'] ?? null) === 'Deployment'
                && ($uid !== null ? ($owner['uid'] ?? null) === $uid : ($owner['name'] ?? null) === ($deployment['metadata']['name'] ?? null))) {
                return true;
            }
        }

        return false;
    }

    /** A ReplicaSet's pod template without the pod-template-hash label the Deployment controller adds. */
    private static function withoutHashLabel(array $template): array
    {
        unset($template['metadata']['labels']['pod-template-hash']);

        return $template;
    }

    /** @return list<string> the images (and image-like env values) a patch moves, unique */
    private static function movedImages(array $ops): array
    {
        $images = [];
        foreach ($ops as $op) {
            if ($op['op'] === 'test') {
                $images[$op['value']] = true;
            }
        }

        return array_keys($images);
    }

    /** Does the cluster serve $kind of $apiVersion (its CRD installed)? */
    private function serves(KubernetesClient $k8s, string $apiVersion, string $kind): bool
    {
        foreach ((array) ($k8s->getApiResources($apiVersion)['resources'] ?? []) as $resource) {
            if (($resource['kind'] ?? null) === $kind && ! str_contains((string) ($resource['name'] ?? ''), '/')) {
                return true;
            }
        }

        return false;
    }
}
