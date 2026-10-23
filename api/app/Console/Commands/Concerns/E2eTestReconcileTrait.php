<?php

namespace App\Console\Commands\Concerns;

use App\Models\KnativeRegion;
use App\Services\Helpers\DedicatedWorkers;
use App\Services\Helpers\KubeQuantity;
use App\Services\Helpers\ManagedTracing;
use App\Services\KubernetesClient;
use App\Services\Manifests\ZotManifests;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionReconcile\Steps\DedicatedWorkerTolerationStep;
use App\Services\RegionReconcile\Steps\RegistryPrewarmStep;
use App\Services\RegionReconcile\Steps\TracingBackendStep;
use App\Services\ZotRegistryService;
use Illuminate\Support\Facades\Artisan;

/**
 * E2E of region:reconcile's tracing and registry-prewarm steps (test:e2e --suite=reconcile,
 * runtime/lib/test-reconcile.sh), on the e2e region's cluster:
 *  - rec-tracing-current  the tracing backend installed with Knative Serving is "ready" with this
 *                         release's Tempo (fix-permissions init container, requests 50m / 256Mi)
 *                         and collector (50m / 128Mi); the tracing step finds nothing to change
 *  - rec-tracing-update   a backend of an earlier release: Tempo without fix-permissions on a
 *                         volume owned by root, the earlier requests on both, a crash-looping
 *                         Tempo pod (it cannot write the volume where the driver ignores fsGroup;
 *                         where it applies fsGroup, as Kind's local-path does, an unknown flag
 *                         makes it crash); the dry run names the changes, the step updates the
 *                         backend (status "installing"), the stuck Tempo pod is replaced and the
 *                         backend is "ready" again with this release's templates; a second run
 *                         changes nothing
 *  - rec-prewarm-plan     the registry has its ghcr.io upstream; the dry run names the pre-warm Job
 *                         and the build base images Zot does not hold
 *  - rec-prewarm-failed   a failed Job of that name is deleted and started again
 *  - rec-prewarm-running  a second run while the Job runs starts nothing new
 *  - rec-prewarm-waits    the Job waits while Zot does not answer (its Service without endpoints)
 *                         and, once Zot answers, fetches every image: Zot's tags/list has them all
 *  - rec-prewarm-done     with the Job gone, a dry run finds no image missing
 *  - rec-zot-restart      Zot's node-bound volume on a dedicated worker (runr.dev/dedicated taint):
 *                         the node the suite selected, else its node selected as a worker through
 *                         the API (or, a control-plane, tainted and labelled as the selection does).
 *                         Zot as this release installs it tolerates the taint, and restarted as
 *                         registry-sync-config / image-registry restart it (a new pod template) it
 *                         runs again on that node
 *  - rec-zot-heal         a Zot of an earlier release (no toleration) restarted there waits Pending
 *                         on the taint; region:reconcile's dedicated-worker-toleration step names it
 *                         (dry run), adds the toleration and replaces the Pending pod; Zot runs on
 *                         that node again, and a second run leaves it alone
 *  - rec-reconcile-run    `php artisan region:reconcile --region=…`: no step fails,
 *                         dedicated-worker-toleration runs first, tracing and registry-prewarm are
 *                         up to date and registry-prewarm runs last
 */
trait E2eTestReconcileTrait
{
    /** cpu / memory requests of the backend of an earlier release (before 0.5.3) */
    private const REC_EARLIER_REQUESTS = ['cpu' => '200m', 'memory' => '512Mi'];

    private const REC_BUSYBOX = 'ghcr.io/brf-tech/runr/reqs/busybox:latest';

    private function runReconcileSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Region reconcile (tracing backend, registry pre-warm) ──');

        $k8s = $this->getE2eKubernetesClient();
        // the row as it is now (the steps read registry and tracing settings from it)
        $group = fn () => new ClusterGroup('e2e', [new ReconcileTarget(KnativeRegion::findOrFail($regionId))], $k8s);

        $tests = [];
        $tests[] = $this->recCheck('rec-tracing-current', fn () => $this->recTracingCurrent($regionId, $k8s, $group));
        $tests[] = $this->recCheck('rec-tracing-update', fn () => $this->recTracingUpdate($regionId, $k8s, $group));
        $tests = array_merge($tests, $this->recPrewarm($k8s, $group));
        $placement = null;
        $tests[] = $this->recCheck('rec-zot-restart', function () use ($k8s, $regionId, &$placement) {
            $placement = $this->recZotOnDedicatedWorker($k8s, $regionId);

            return is_array($placement) ? $this->recZotRestart($k8s, $placement['node']) : $placement;
        });
        if (is_array($placement)) {
            $tests[] = $this->recCheck('rec-zot-heal', fn () => $this->recZotHeal($k8s, $group, $placement['node']));
            $this->recZotRestorePlacement($k8s, $regionId, $placement);
        }
        $tests[] = $this->recCheck('rec-reconcile-run', fn () => $this->recFullRun($regionId));

        return $tests;
    }

    // ── Tracing backend ─────────────────────────────────────────────────────

    private function recTracingCurrent(string $regionId, KubernetesClient $k8s, callable $group): bool|string
    {
        $ready = $this->waitTracingBackend($regionId);
        if ($ready !== true) {
            return "tracing backend installed with Serving: {$ready}";
        }

        $problems = $this->recBackendProblems($k8s);
        if ($problems !== []) {
            return 'installed backend: '.implode('; ', $problems);
        }

        $lines = (new TracingBackendStep)->run($group(), false);
        $this->line('    region:reconcile [tracing] '.($lines === [] ? 'up to date' : implode(' | ', $lines)));

        return $lines === [] ?: 'the step changes the backend this release installed: '.implode(' | ', $lines);
    }

    private function recTracingUpdate(string $regionId, KubernetesClient $k8s, callable $group): bool|string
    {
        $ns = ManagedTracing::NAMESPACE;
        $set = $k8s->getResourceStatus('apps/v1', 'StatefulSet', ManagedTracing::TEMPO_RELEASE, $ns);
        $collector = $k8s->getResourceStatus('apps/v1', 'Deployment', ManagedTracing::COLLECTOR_RELEASE, $ns);
        if (! $set || ! $collector) {
            return 'no Tempo StatefulSet / collector Deployment';
        }

        // 1. Tempo's volume owned by root, as a storage driver that ignores fsGroup leaves it
        $claim = $this->recTempoClaim($k8s);
        if ($claim === null) {
            return 'no volume claim of the Tempo pod';
        }
        $chown = $this->recRunPod($k8s, $ns, 'rec-tempo-volume-root', [
            'containers' => [[
                'name' => 'chown',
                'image' => self::REC_BUSYBOX,
                'command' => ['sh', '-c', 'chown -R 0:0 '.ManagedTracing::TEMPO_DATA_PATH.' && chmod -R 0755 '.ManagedTracing::TEMPO_DATA_PATH.' && ls -ld '.ManagedTracing::TEMPO_DATA_PATH],
                'securityContext' => ['runAsUser' => 0, 'runAsNonRoot' => false],
                'volumeMounts' => [['name' => 'storage', 'mountPath' => ManagedTracing::TEMPO_DATA_PATH]],
            ]],
            'volumes' => [['name' => 'storage', 'persistentVolumeClaim' => ['claimName' => $claim]]],
        ]);
        if ($chown !== true) {
            return "volume of Tempo to root: {$chown}";
        }

        // 2. the templates of an earlier release: no fix-permissions, the earlier requests
        $tempoIdx = $this->recContainerIndex($set, ManagedTracing::TEMPO_CHART);
        $collectorIdx = $this->recContainerIndex($collector, ManagedTracing::COLLECTOR_CHART);
        $ops = [['op' => 'replace', 'path' => "/spec/template/spec/containers/{$tempoIdx}/resources/requests", 'value' => self::REC_EARLIER_REQUESTS]];
        if (! empty($set['spec']['template']['spec']['initContainers'])) {
            $ops[] = ['op' => 'remove', 'path' => '/spec/template/spec/initContainers'];
        }
        if (! $k8s->jsonPatch('apps/v1', 'StatefulSet', ManagedTracing::TEMPO_RELEASE, $ns, $ops)
            || ! $k8s->jsonPatch('apps/v1', 'Deployment', ManagedTracing::COLLECTOR_RELEASE, $ns, [
                ['op' => 'replace', 'path' => "/spec/template/spec/containers/{$collectorIdx}/resources/requests", 'value' => self::REC_EARLIER_REQUESTS],
            ])) {
            return 'the templates of an earlier release could not be applied';
        }

        // 3. the Tempo pod of that template crash-loops (it cannot write its root-owned volume);
        //    where the volume driver applies the pod's fsGroup (Kind's local-path) Tempo keeps
        //    running, and an unknown flag makes it crash instead: the pod is stuck either way
        $how = 'root-owned volume';
        if (! $this->recWaitTempoStuck($k8s, 240)) {
            $set = $k8s->getResourceStatus('apps/v1', 'StatefulSet', ManagedTracing::TEMPO_RELEASE, $ns);
            $args = $set['spec']['template']['spec']['containers'][$tempoIdx]['args'] ?? [];
            $args[] = '-e2e.not-a-flag';
            $k8s->jsonPatch('apps/v1', 'StatefulSet', ManagedTracing::TEMPO_RELEASE, $ns, [
                ['op' => 'add', 'path' => "/spec/template/spec/containers/{$tempoIdx}/args", 'value' => $args],
            ]);
            $how = 'unknown flag; Tempo kept running on the root-owned volume, its fsGroup applied';
            if (! $this->recWaitTempoStuck($k8s, 240)) {
                return 'the Tempo pod of the earlier template did not get stuck: '.$this->recTempoPodState($k8s);
            }
        }
        $this->line("    Tempo crash-loops with the earlier template ({$how}): ".$this->recTempoPodState($k8s));

        // 4. dry run, then the step
        $dry = implode(' | ', (new TracingBackendStep)->run($group(), true));
        $this->line("    region:reconcile --dry-run [tracing] {$dry}");
        foreach (['would update the tracing backend', 'init container '.ManagedTracing::TEMPO_FIX_PERMISSIONS, 'cpu request '.self::REC_EARLIER_REQUESTS['cpu'].' → 50m', 'memory request '.self::REC_EARLIER_REQUESTS['memory'].' → 128Mi', 'memory request '.self::REC_EARLIER_REQUESTS['memory'].' → 256Mi'] as $want) {
            if (! str_contains($dry, $want)) {
                return "dry run without \"{$want}\": {$dry}";
            }
        }

        $run = implode(' | ', (new TracingBackendStep)->run($group(), false));
        $this->line("    region:reconcile [tracing] {$run}");
        if (! str_starts_with($run, 'updated the tracing backend') || ! str_contains($run, 'tracing status "installing"')) {
            return "the step did not update the backend: {$run}";
        }
        $status = $this->api()->get("/api/observability/regions/{$regionId}/tracing")->json('data.status');
        if ($status !== 'installing' && $status !== 'ready') {
            return "tracing status after the update: {$status}";
        }

        // 5. ready again: the stuck pod replaced, the StatefulSet rolled out, this release's templates
        $ready = $this->waitTracingBackend($regionId);
        if ($ready !== true) {
            return "after the update: {$ready} (Tempo pod: ".$this->recTempoPodState($k8s).')';
        }
        $rolled = $this->recWaitTempoRolledOut($k8s, 300);
        if ($rolled !== true) {
            return $rolled;
        }
        $problems = $this->recBackendProblems($k8s);
        if ($problems !== []) {
            return 'updated backend: '.implode('; ', $problems);
        }

        $again = (new TracingBackendStep)->run($group(), false);
        $this->line('    region:reconcile [tracing] '.($again === [] ? 'up to date' : implode(' | ', $again)));

        return $again === [] ?: 'a second run changes the backend again: '.implode(' | ', $again);
    }

    /**
     * What the live backend lacks of this release: Tempo's fix-permissions init container, the
     * requests of Tempo and the collector, a ready Tempo pod.
     *
     * @return list<string>
     */
    private function recBackendProblems(KubernetesClient $k8s): array
    {
        $ns = ManagedTracing::NAMESPACE;
        $set = $k8s->getResourceStatus('apps/v1', 'StatefulSet', ManagedTracing::TEMPO_RELEASE, $ns);
        $collector = $k8s->getResourceStatus('apps/v1', 'Deployment', ManagedTracing::COLLECTOR_RELEASE, $ns);
        if (! $set || ! $collector) {
            return ['no Tempo StatefulSet / collector Deployment'];
        }

        $problems = [];
        foreach ([
            'Tempo' => [$set, ManagedTracing::TEMPO_CHART, ManagedTracing::TEMPO_REQUESTS],
            'collector' => [$collector, ManagedTracing::COLLECTOR_CHART, ManagedTracing::COLLECTOR_REQUESTS],
        ] as $label => [$workload, $name, $wanted]) {
            $containers = $workload['spec']['template']['spec']['containers'] ?? [];
            $requests = $containers[$this->recContainerIndex($workload, $name)]['resources']['requests'] ?? [];
            foreach ($wanted as $resource => $quantity) {
                $live = $requests[$resource] ?? null;
                $same = $live !== null && ($resource === 'cpu'
                    ? KubeQuantity::millicores($live) === KubeQuantity::millicores($quantity)
                    : KubeQuantity::bytes($live) === KubeQuantity::bytes($quantity));
                if (! $same) {
                    $problems[] = "{$label} {$resource} request ".($live ?? '(none)').", expected {$quantity}";
                }
            }
        }

        $init = array_column($set['spec']['template']['spec']['initContainers'] ?? [], 'name');
        if (! in_array(ManagedTracing::TEMPO_FIX_PERMISSIONS, $init, true)) {
            $problems[] = 'Tempo without the '.ManagedTracing::TEMPO_FIX_PERMISSIONS.' init container ('.($init ? implode(', ', $init) : 'none').')';
        }
        if ((int) ($set['status']['readyReplicas'] ?? 0) < (int) ($set['spec']['replicas'] ?? 1)) {
            $problems[] = 'Tempo not ready: '.$this->recTempoPodState($k8s);
        }

        return $problems;
    }

    /** @return list<array> the Tempo pods */
    private function recTempoPods(KubernetesClient $k8s): array
    {
        return $k8s->listResources('v1', 'Pod', ManagedTracing::NAMESPACE, [
            'app.kubernetes.io/name' => ManagedTracing::TEMPO_CHART,
            'app.kubernetes.io/instance' => ManagedTracing::TEMPO_RELEASE,
        ])['items'] ?? [];
    }

    private function recTempoClaim(KubernetesClient $k8s): ?string
    {
        foreach ($this->recTempoPods($k8s) as $pod) {
            foreach ($pod['spec']['volumes'] ?? [] as $volume) {
                if (($volume['name'] ?? '') === 'storage' && isset($volume['persistentVolumeClaim']['claimName'])) {
                    return $volume['persistentVolumeClaim']['claimName'];
                }
            }
        }

        return null;
    }

    /**
     * Waits for a Tempo pod of the StatefulSet's current template that is not ready and restarted.
     *
     * @phpstan-impure
     */
    private function recWaitTempoStuck(KubernetesClient $k8s, int $seconds): bool
    {
        $deadline = time() + $seconds;
        while (time() < $deadline) {
            $set = $k8s->getResourceStatus('apps/v1', 'StatefulSet', ManagedTracing::TEMPO_RELEASE, ManagedTracing::NAMESPACE);
            $revision = (string) ($set['status']['updateRevision'] ?? '');
            foreach ($this->recTempoPods($k8s) as $pod) {
                $restarts = (int) collect($pod['status']['containerStatuses'] ?? [])->sum('restartCount');
                if ($revision !== '' && ($pod['metadata']['labels']['controller-revision-hash'] ?? '') === $revision
                    && ! $this->recPodReady($pod) && $restarts >= 1) {
                    return true;
                }
            }
            sleep(5);
        }

        return false;
    }

    /** true once the Tempo StatefulSet runs its current template, ready. */
    private function recWaitTempoRolledOut(KubernetesClient $k8s, int $seconds): bool|string
    {
        $deadline = time() + $seconds;
        while (time() < $deadline) {
            $set = $k8s->getResourceStatus('apps/v1', 'StatefulSet', ManagedTracing::TEMPO_RELEASE, ManagedTracing::NAMESPACE);
            $revision = (string) ($set['status']['updateRevision'] ?? '');
            $pods = $this->recTempoPods($k8s);
            $current = $pods !== [] && collect($pods)->every(fn ($p) => ($p['metadata']['labels']['controller-revision-hash'] ?? '') === $revision && $this->recPodReady($p));
            if ($revision !== '' && $current && (int) ($set['status']['readyReplicas'] ?? 0) >= (int) ($set['spec']['replicas'] ?? 1)) {
                return true;
            }
            sleep(5);
        }

        return "Tempo did not roll out its current template in {$seconds}s: ".$this->recTempoPodState($k8s);
    }

    private function recTempoPodState(KubernetesClient $k8s): string
    {
        $states = [];
        foreach ($this->recTempoPods($k8s) as $pod) {
            $c = $pod['status']['containerStatuses'][0] ?? [];
            $reason = $c['state']['waiting']['reason'] ?? $c['state']['terminated']['reason'] ?? (isset($c['state']['running']) ? 'Running' : ($pod['status']['phase'] ?? '?'));
            $states[] = sprintf('%s %s, ready %s, %d restart(s), revision %s', $pod['metadata']['name'], $reason,
                $this->recPodReady($pod) ? 'yes' : 'no', (int) ($c['restartCount'] ?? 0), $pod['metadata']['labels']['controller-revision-hash'] ?? '?');
        }

        return $states ? implode('; ', $states) : 'no Tempo pod';
    }

    // ── Registry pre-warm ───────────────────────────────────────────────────

    /** @return list<array> result rows */
    private function recPrewarm(KubernetesClient $k8s, callable $group): array
    {
        $rows = [];
        $region = KnativeRegion::findOrFail($this->e2eRegion->id);
        $ns = $region->registry_namespace ?: ZotRegistryService::ZOT_DEFAULT_NS;
        $plan = null;

        // 1. the plan of the dry run: Job name and the images Zot lacks
        $rows[] = $this->recCheck('rec-prewarm-plan', function () use ($k8s, $region, $group, &$plan) {
            if (! app(ZotRegistryService::class)->syncsGhcr($region, $k8s)) {
                return 'the region registry has no ghcr.io upstream';
            }
            $lines = (new RegistryPrewarmStep)->run($group(), true);
            $this->line('    region:reconcile --dry-run [registry-prewarm] '.($lines === [] ? 'up to date' : implode(' | ', $lines)));
            $plan = $this->recPrewarmPlan($lines[0] ?? '');
            if ($plan === null || ! str_starts_with($lines[0], 'would fetch')) {
                return 'no pre-warm Job planned: '.($lines === [] ? 'up to date' : implode(' | ', $lines));
            }

            return true;
        });
        if ($plan === null) {
            return $rows;
        }
        [$jobNs, $job, $images] = $plan;
        $this->line('    Job '.$jobNs.'/'.$job.': '.count($images).' image(s): '.implode(', ', $images));

        // 2. a failed Job of that name is deleted and started again — while Zot does not answer (4.)
        $failedUid = null;
        $rows[] = $this->recCheck('rec-prewarm-failed', function () use ($k8s, $group, $jobNs, $job, $ns, &$failedUid) {
            $failed = $this->recFailedJob($k8s, $jobNs, $job);
            if (! is_array($failed)) {
                return $failed;
            }
            $failedUid = $failed['metadata']['uid'];

            $dry = implode(' | ', (new RegistryPrewarmStep)->run($group(), true));
            if (! str_contains($dry, 'again: the previous run failed')) {
                return "the dry run does not see the failed Job: {$dry}";
            }

            if (! $this->recZotOffline($k8s, $ns, true)) {
                return 'Zot could not be taken offline (its Service selector)';
            }
            $this->line('    Zot does not answer: its Service has no endpoints (the Job has to wait for it)');

            $run = implode(' | ', (new RegistryPrewarmStep)->run($group(), false));
            $this->line("    region:reconcile [registry-prewarm] {$run}");
            if (! str_starts_with($run, 'fetching') || ! str_contains($run, 'again: the previous run failed')) {
                return "the failed Job was not started again: {$run}";
            }
            $new = $k8s->getResourceStatus('batch/v1', 'Job', $job, $jobNs);
            $container = $new['spec']['template']['spec']['containers'][0]['name'] ?? null;
            if (! $new || $new['metadata']['uid'] === $failedUid || $container !== 'prewarm') {
                return 'the Job was not replaced by a pre-warm Job: '.json_encode(['uid' => $new['metadata']['uid'] ?? null, 'container' => $container]);
            }

            return true;
        });
        if (! $this->isTestPassed(end($rows))) {
            // Zot answers again for the checks after this one
            $this->recZotOffline($k8s, $ns, false);

            return $rows;
        }

        // 3. a run while the Job runs starts nothing new
        $rows[] = $this->recCheck('rec-prewarm-running', function () use ($k8s, $group, $jobNs, $job) {
            $before = $k8s->getResourceStatus('batch/v1', 'Job', $job, $jobNs)['metadata']['uid'] ?? null;
            $lines = (new RegistryPrewarmStep)->run($group(), false);
            $after = $k8s->getResourceStatus('batch/v1', 'Job', $job, $jobNs)['metadata']['uid'] ?? null;
            $this->line('    region:reconcile [registry-prewarm] '.($lines === [] ? 'up to date' : implode(' | ', $lines)));

            return ($lines === [] && $before !== null && $before === $after) ?: 'a second run while the Job runs: '.($lines === [] ? "Job {$before} → {$after}" : implode(' | ', $lines));
        });

        // 4. the Job waits for Zot, then fetches every image
        $rows[] = $this->recCheck('rec-prewarm-waits', function () use ($k8s, $jobNs, $job, $images, $ns) {
            try {
                sleep(45);
                $waiting = $k8s->getResourceStatus('batch/v1', 'Job', $job, $jobNs);
                if ((int) ($waiting['status']['failed'] ?? 0) > 0 || $this->recJobCondition($waiting, 'Failed')) {
                    return 'the Job failed while Zot did not answer: '.$this->recJobLog($k8s, $jobNs, $job);
                }
                $this->line('    after 45 s without Zot: Job active '.(int) ($waiting['status']['active'] ?? 0).', failed 0');
            } finally {
                $back = $this->recZotOffline($k8s, $ns, false);
            }
            if (! $back) {
                return 'Zot does not answer again (its Service selector could not be restored)';
            }
            $this->line('    Zot answers again, waiting for the Job...');

            $start = time();
            $deadline = $start + 2700;
            $state = null;
            while (time() < $deadline) {
                $state = $k8s->getResourceStatus('batch/v1', 'Job', $job, $jobNs);
                if ($state === null) {
                    return "the Job {$jobNs}/{$job} is gone";
                }
                if ($this->recJobCondition($state, 'Complete') || $this->recJobCondition($state, 'Failed')) {
                    break;
                }
                sleep(15);
            }
            $log = $this->recJobLog($k8s, $jobNs, $job);
            $this->line('    Job after '.(time() - $start).' s: '.($this->recJobCondition($state, 'Complete') ? 'Complete' : ($this->recJobCondition($state, 'Failed') ? 'Failed' : 'still running')));
            foreach (array_slice(explode("\n", trim($log)), -count($images) - 2) as $line) {
                $this->line("      {$line}");
            }
            if (! $this->recJobCondition($state, 'Complete')) {
                return 'the Job did not complete (failed pods: '.(int) ($state['status']['failed'] ?? 0).'): '.mb_substr(trim($log), -400);
            }

            $missing = [];
            foreach ($images as $image) {
                if (! str_contains($log, "{$image} in the registry after")) {
                    $missing[] = "{$image} (Job log)";
                }
                [$repo, $tag] = [substr($image, 0, strrpos($image, ':')), substr($image, strrpos($image, ':') + 1)];
                $tags = $k8s->serviceProxyRequest('GET', $jobNs, ZotRegistryService::ZOT_RELEASE_NAME, 5000, "v2/{$repo}/tags/list")->json('tags') ?? [];
                if (! in_array($tag, (array) $tags, true)) {
                    $missing[] = "{$image} (Zot tags/list)";
                }
            }

            return $missing === [] ?: 'not in the registry: '.implode(', ', $missing);
        });

        // 5. with the Job gone (its TTL), nothing is missing any more
        $rows[] = $this->recCheck('rec-prewarm-done', function () use ($k8s, $group, $jobNs, $job) {
            $k8s->deleteResource('batch/v1', 'Job', $job, $jobNs, 'Background');
            $lines = (new RegistryPrewarmStep)->run($group(), true);
            $this->line('    region:reconcile --dry-run [registry-prewarm] '.($lines === [] ? 'up to date' : implode(' | ', $lines)));

            return $lines === [] ?: 'images still missing after the pre-warm: '.implode(' | ', $lines);
        });

        return $rows;
    }

    /**
     * [Job namespace, Job name, images] of a pre-warm line ("… (Job ns/name[, again: …]): a:1, b:2").
     *
     * @return array{0: string, 1: string, 2: list<string>}|null
     */
    private function recPrewarmPlan(string $line): ?array
    {
        if (! preg_match('~\(Job ([a-z0-9-]+)/('.preg_quote(ZotManifests::PREWARM_JOB_PREFIX, '~').'[0-9a-f]+)(?:, [^)]*)?\): (.+)$~', $line, $m)) {
            return null;
        }

        return [$m[1], $m[2], array_map('trim', explode(', ', $m[3]))];
    }

    /** A Job of the pre-warm Job's name that failed, as an earlier run's Job that gave up. */
    private function recFailedJob(KubernetesClient $k8s, string $ns, string $name): array|string
    {
        $manifest = [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => ['name' => $name, 'namespace' => $ns, 'labels' => ['app.kubernetes.io/component' => 'registry-prewarm', 'runr.dev/e2e' => 'failed-prewarm']],
            'spec' => [
                'backoffLimit' => 0,
                'template' => ['spec' => [
                    'restartPolicy' => 'Never',
                    'automountServiceAccountToken' => false,
                    'securityContext' => ['runAsNonRoot' => true, 'runAsUser' => 65534, 'seccompProfile' => ['type' => 'RuntimeDefault']],
                    'containers' => [[
                        'name' => 'earlier-prewarm',
                        'image' => self::REC_BUSYBOX,
                        'command' => ['sh', '-c', 'echo "an earlier pre-warm that gave up"; exit 1'],
                        'securityContext' => ['allowPrivilegeEscalation' => false, 'capabilities' => ['drop' => ['ALL']]],
                    ]],
                ]],
            ],
        ];
        if (! $k8s->createResource($manifest, $ns)) {
            return "the failed Job {$ns}/{$name} could not be created";
        }

        $deadline = time() + 180;
        while (time() < $deadline) {
            $job = $k8s->getResourceStatus('batch/v1', 'Job', $name, $ns);
            if ($job && $this->recJobCondition($job, 'Failed')) {
                return $job;
            }
            sleep(3);
        }

        return "the Job {$ns}/{$name} did not fail in 180s";
    }

    private function recJobCondition(?array $job, string $type): bool
    {
        foreach ($job['status']['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') === $type && ($condition['status'] ?? '') === 'True') {
                return true;
            }
        }

        return false;
    }

    /** The log of the Job's latest pod. */
    private function recJobLog(KubernetesClient $k8s, string $ns, string $job): string
    {
        $pods = $k8s->listResources('v1', 'Pod', $ns, ['job-name' => $job])['items'] ?? [];
        usort($pods, fn ($a, $b) => strcmp($a['metadata']['creationTimestamp'] ?? '', $b['metadata']['creationTimestamp'] ?? ''));
        $pod = end($pods);

        return $pod ? (string) $k8s->getPodLogs($pod['metadata']['name'], $ns, null, 200) : '(no pod)';
    }

    /** @return array{0: string, 1: ?array} [kind, object] of Zot (StatefulSet with a PVC, Deployment with S3) */
    private function recZotWorkload(KubernetesClient $k8s, string $ns): array
    {
        foreach (['StatefulSet', 'Deployment'] as $kind) {
            if ($obj = $k8s->getResourceStatus('apps/v1', $kind, ZotRegistryService::ZOT_RELEASE_NAME, $ns)) {
                return [$kind, $obj];
            }
        }

        return ['StatefulSet', null];
    }

    /**
     * Zot stops answering (true) or answers again (false) without its pod moving: its Service
     * selects no pod while an extra selector key is set, so its address refuses connections.
     */
    private function recZotOffline(KubernetesClient $k8s, string $ns, bool $offline): bool
    {
        $patch = ['spec' => ['selector' => ['runr.dev/e2e-offline' => $offline ? 'true' : null]]];
        if (! $k8s->mergePatch('v1', 'Service', ZotRegistryService::ZOT_RELEASE_NAME, $ns, $patch)) {
            return false;
        }
        // Zot answers through the service proxy again (or no more) once the endpoints follow
        $deadline = time() + 60;
        while (time() < $deadline) {
            try {
                $up = $k8s->serviceProxyRequest('GET', $ns, ZotRegistryService::ZOT_RELEASE_NAME, 5000, 'v2/')->successful();
            } catch (\Throwable) {
                $up = false;
            }
            if ($up !== $offline) {
                return true;
            }
            sleep(2);
        }

        return false;
    }

    /**
     * Zot's pod on a dedicated worker, as on a region whose worker selection came after the
     * registry: the node its volume binds it to is selected through the API when the suite did not
     * select it (a control-plane, which the API does not offer, is tainted and labelled the way the
     * selection does it). Zot runs there.
     *
     * @return array{node: string, how: string, selected: list<string>}|string the placement, or why not
     */
    private function recZotOnDedicatedWorker(KubernetesClient $k8s, string $regionId): array|string
    {
        $region = KnativeRegion::findOrFail($regionId);
        $ns = $region->registry_namespace ?: ZotRegistryService::ZOT_DEFAULT_NS;
        [$kind] = $this->recZotWorkload($k8s, $ns);
        if ($kind !== 'StatefulSet') {
            return "Zot is a {$kind} (no node-bound volume): nothing to check";
        }
        $node = $this->recZotNode($k8s, $ns);
        if ($node === null) {
            return 'Zot runs on no node: '.$this->recZotPodState($k8s, $ns);
        }
        $selected = array_values((array) ($region->selected_workers ?? []));
        $nodeObject = $k8s->getClusterResource('v1', 'Node', $node) ?? [];

        if (DedicatedWorkers::isTainted($nodeObject)) {
            $how = 'selected by the suite';
        } elseif (in_array($node, array_column($this->api()->get("/api/knative-regions/{$regionId}/nodes")->json('nodes') ?? [], 'name'), true)) {
            $res = $this->api()->post("/api/knative-regions/{$regionId}/workers", ['nodes' => array_values(array_unique([...$selected, $node]))]);
            if (! $res->successful()) {
                return "selecting {$node} as a worker: HTTP {$res->status()} ".mb_substr($res->body(), 0, 200);
            }
            $how = 'selected as a worker through the API';
        } else {
            $k8s->strategicMergePatchCluster('v1', 'Node', $node, [
                'metadata' => ['labels' => [DedicatedWorkers::KEY => DedicatedWorkers::VALUE]],
                'spec' => ['taints' => [...($nodeObject['spec']['taints'] ?? []), ['key' => DedicatedWorkers::KEY, 'value' => DedicatedWorkers::VALUE, 'effect' => DedicatedWorkers::EFFECT]]],
            ]);
            $how = 'tainted and labelled as a dedicated worker (a control-plane the API does not select)';
        }

        // the worker selection restarts a component on a newly selected node once: Zot runs again first
        if (! $this->recWaitZotRolled($k8s, $ns, 'StatefulSet', 300)) {
            return "Zot not ready on {$node} after it became a dedicated worker ({$how}): ".$this->recZotPodState($k8s, $ns);
        }
        $this->line("    Zot's volume on {$node}, a dedicated worker now ({$how})");

        return ['node' => $node, 'how' => $how, 'selected' => $selected];
    }

    /**
     * Zot restarted the way registry-sync-config and image-registry restart it (a changed pod
     * template annotation) runs again on the dedicated worker its volume is on within 5 minutes:
     * its template tolerates the dedicated-worker taint, as this release installs it.
     */
    private function recZotRestart(KubernetesClient $k8s, string $node): bool|string
    {
        $region = KnativeRegion::findOrFail($this->e2eRegion->id);
        $ns = $region->registry_namespace ?: ZotRegistryService::ZOT_DEFAULT_NS;
        [$kind, $zot] = $this->recZotWorkload($k8s, $ns);
        if (! DedicatedWorkers::tolerated((array) ($zot['spec']['template']['spec']['tolerations'] ?? []))) {
            return "Zot's template does not tolerate ".DedicatedWorkers::taint().': '.json_encode($zot['spec']['template']['spec']['tolerations'] ?? []);
        }
        $restart = ['spec' => ['template' => ['metadata' => ['annotations' => [ZotRegistryService::CONFIG_HASH_ANNOTATION => 'e2e-restart-'.time()]]]]];
        if (! $k8s->mergePatch('apps/v1', $kind, ZotRegistryService::ZOT_RELEASE_NAME, $ns, $restart)) {
            return "the restart of {$kind} {$ns}/".ZotRegistryService::ZOT_RELEASE_NAME.' was rejected';
        }
        if (! $this->recWaitZotRolled($k8s, $ns, $kind, 300)) {
            return "Zot not ready 300 s after a restart on dedicated worker {$node}: ".$this->recZotPodState($k8s, $ns);
        }
        $now = $this->recZotNode($k8s, $ns);

        return $now === $node ?: "Zot runs on {$now} after the restart, its volume is on {$node}";
    }

    /**
     * A Zot installed by an earlier release (without the toleration) on that dedicated worker: its
     * next pod waits Pending on the taint, and region:reconcile's dedicated-worker-toleration step
     * heals it — the toleration added, the Pending pod replaced.
     */
    private function recZotHeal(KubernetesClient $k8s, callable $group, string $node): bool|string
    {
        $region = KnativeRegion::findOrFail($this->e2eRegion->id);
        $ns = $region->registry_namespace ?: ZotRegistryService::ZOT_DEFAULT_NS;
        $ref = 'StatefulSet '.$ns.'/'.ZotRegistryService::ZOT_RELEASE_NAME;
        [, $zot] = $this->recZotWorkload($k8s, $ns);
        $tolerations = (array) ($zot['spec']['template']['spec']['tolerations'] ?? []);
        $earlier = array_values(array_filter($tolerations, fn ($t) => ! DedicatedWorkers::tolerated([$t])));

        // 1. the template of an earlier release: its next pod cannot start on the dedicated worker
        $op = $earlier === []
            ? ['op' => 'remove', 'path' => '/spec/template/spec/tolerations']
            : ['op' => 'replace', 'path' => '/spec/template/spec/tolerations', 'value' => $earlier];
        if (! $k8s->jsonPatch('apps/v1', 'StatefulSet', ZotRegistryService::ZOT_RELEASE_NAME, $ns, [$op])) {
            return "the toleration could not be removed from {$ref}";
        }
        $stuck = false;
        $deadline = time() + 180;
        while (! $stuck && time() < $deadline) {
            sleep(5);
            foreach ($k8s->listResources('v1', 'Pod', $ns, ['app.kubernetes.io/instance' => ZotRegistryService::ZOT_RELEASE_NAME])['items'] ?? [] as $pod) {
                $cond = collect($pod['status']['conditions'] ?? [])->firstWhere('type', 'PodScheduled');
                $stuck = $stuck || (($pod['status']['phase'] ?? '') === 'Pending' && ($cond['status'] ?? '') === 'False'
                    && str_contains((string) ($cond['message'] ?? ''), 'untolerated taint'));
            }
        }
        if (! $stuck) {
            return "Zot without the toleration did not wait Pending on the taint of {$node}: ".$this->recZotPodState($k8s, $ns);
        }
        $this->line('    Zot without the toleration: '.$this->recZotPodState($k8s, $ns));

        // 2. the step: dry run, run, Zot back on its node, a second run leaves it alone
        $zotLine = fn (array $lines) => implode(' | ', array_filter($lines, fn ($l) => str_contains($l, $ref)));
        $dry = $zotLine((new DedicatedWorkerTolerationStep)->run($group(), true));
        $this->line('    region:reconcile --dry-run [dedicated-worker-toleration] '.($dry ?: '(nothing about Zot)'));
        if (! str_contains($dry, 'would add the toleration of '.DedicatedWorkers::taint()) || ! str_contains($dry, 'waits Pending on the taint')) {
            return "the dry run does not add the toleration to the Pending Zot: {$dry}";
        }
        $run = $zotLine((new DedicatedWorkerTolerationStep)->run($group(), false));
        $this->line('    region:reconcile [dedicated-worker-toleration] '.($run ?: '(nothing about Zot)'));
        if (! str_starts_with($run, 'added the toleration of '.DedicatedWorkers::taint())) {
            return "the step did not add the toleration to Zot: {$run}";
        }
        if (! $this->recWaitZotRolled($k8s, $ns, 'StatefulSet', 300)) {
            return 'Zot not ready 300 s after the step: '.$this->recZotPodState($k8s, $ns);
        }
        if (($now = $this->recZotNode($k8s, $ns)) !== $node) {
            return "Zot runs on {$now} after the step, its volume is on {$node}";
        }
        $again = $zotLine((new DedicatedWorkerTolerationStep)->run($group(), false));

        return $again === '' ?: "a second run changes Zot again: {$again}";
    }

    /** The worker selection (or the control-plane's taint and label) as the suite had it. */
    private function recZotRestorePlacement(KubernetesClient $k8s, string $regionId, array $placement): void
    {
        $region = KnativeRegion::findOrFail($regionId);
        $ns = $region->registry_namespace ?: ZotRegistryService::ZOT_DEFAULT_NS;
        if (str_starts_with($placement['how'], 'selected as a worker')) {
            $this->api()->post("/api/knative-regions/{$regionId}/workers", ['nodes' => $placement['selected']]);
        } elseif (str_starts_with($placement['how'], 'tainted')) {
            $node = $k8s->getClusterResource('v1', 'Node', $placement['node']) ?? [];
            $taints = array_values(array_filter($node['spec']['taints'] ?? [], fn ($t) => ($t['key'] ?? '') !== DedicatedWorkers::KEY));
            $k8s->strategicMergePatchCluster('v1', 'Node', $placement['node'], [
                'metadata' => ['labels' => [DedicatedWorkers::KEY => null]],
                'spec' => ['taints' => $taints === [] ? null : $taints],
            ]);
        } else {
            return;
        }
        $this->recWaitZotRolled($k8s, $ns, 'StatefulSet', 300);
        $this->line("    {$placement['node']} as the suite had it again ({$placement['how']} undone)");
    }

    /** Node of Zot's running pod (a StatefulSet: the node its volume is on). */
    private function recZotNode(KubernetesClient $k8s, string $ns): ?string
    {
        foreach ($k8s->listResources('v1', 'Pod', $ns, ['app.kubernetes.io/instance' => ZotRegistryService::ZOT_RELEASE_NAME])['items'] ?? [] as $pod) {
            if (empty($pod['metadata']['deletionTimestamp']) && ($pod['status']['phase'] ?? '') === 'Running' && ! empty($pod['spec']['nodeName'])) {
                return (string) $pod['spec']['nodeName'];
            }
        }

        return null;
    }

    private function recZotPodState(KubernetesClient $k8s, string $ns): string
    {
        $why = [];
        foreach ($k8s->listResources('v1', 'Pod', $ns, ['app.kubernetes.io/instance' => ZotRegistryService::ZOT_RELEASE_NAME])['items'] ?? [] as $pod) {
            $cond = collect($pod['status']['conditions'] ?? [])->firstWhere('type', 'PodScheduled');
            $why[] = $pod['metadata']['name'].' '.($pod['status']['phase'] ?? '?').' on '.($pod['spec']['nodeName'] ?? '(no node)')
                .(($cond['status'] ?? 'True') === 'False' ? ': '.($cond['message'] ?? $cond['reason'] ?? '') : '');
        }

        return $why ? implode('; ', $why) : 'no Zot pod';
    }

    /**
     * Zot runs its current template with a ready replica.
     *
     * @phpstan-impure
     */
    private function recWaitZotRolled(KubernetesClient $k8s, string $ns, string $kind, int $seconds): bool
    {
        $deadline = time() + $seconds;
        while (time() < $deadline) {
            $obj = $k8s->getResourceStatus('apps/v1', $kind, ZotRegistryService::ZOT_RELEASE_NAME, $ns);
            $status = $obj['status'] ?? [];
            $observed = (int) ($status['observedGeneration'] ?? 0) >= (int) ($obj['metadata']['generation'] ?? 1);
            $current = $kind === 'StatefulSet'
                ? ($status['updateRevision'] ?? '') !== '' && ($status['updateRevision'] ?? '') === ($status['currentRevision'] ?? '')
                : (int) ($status['updatedReplicas'] ?? 0) >= (int) ($obj['spec']['replicas'] ?? 1);
            if ($observed && $current && (int) ($status['readyReplicas'] ?? 0) >= (int) ($obj['spec']['replicas'] ?? 1)) {
                return true;
            }
            sleep(5);
        }

        return false;
    }

    // ── region:reconcile ────────────────────────────────────────────────────

    /**
     * Steps that must run before others (RegionReconciler::steps()): the private entry point before
     * the internal network's ForwardAuth on it, the global DNS server before the admission policy,
     * the admission policy before image-registry rolls the workloads onto RunR's image namespace.
     *
     * @param  list<string>  $tags  the step tags in the order region:reconcile printed them
     */
    private function recOrderProblem(array $tags): ?string
    {
        $first = [];
        foreach ($tags as $i => $tag) {
            $first[$tag] ??= $i;
        }
        foreach ([['private-ingress', 'internal-network'], ['global-dns', 'admission-policy'], ['admission-policy', 'image-registry']] as [$before, $after]) {
            if (! isset($first[$before], $first[$after])) {
                return "step {$before} or {$after} printed nothing: ".implode(', ', array_keys($first));
            }
            if ($first[$before] > $first[$after]) {
                return "{$before} ran after {$after}: ".implode(', ', array_keys($first));
            }
        }

        return null;
    }

    private function recFullRun(string $regionId): bool|string
    {
        $steps = app(RegionReconciler::class)->steps();
        if (! end($steps) instanceof RegistryPrewarmStep) {
            return 'registry-prewarm is not the last step: '.get_class(end($steps));
        }

        $code = Artisan::call('region:reconcile', ['--region' => $regionId]);
        $out = Artisan::output();
        foreach (explode("\n", trim($out)) as $line) {
            $this->line("    {$line}");
        }

        $tags = [];
        $lines = [];
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^\s+\[([a-z0-9-]+)\] (.*)$/', $line, $m)) {
                $tags[] = $m[1];
                $lines[$m[1]][] = $m[2];
            }
        }
        $failed = array_keys(array_filter($lines, fn ($l) => collect($l)->contains(fn ($x) => str_starts_with($x, 'failed:'))));
        $prewarm = $lines['registry-prewarm'] ?? [];

        $problem = match (true) {
            $code !== 0 || $failed !== [] => "region:reconcile exited {$code}, failed step(s): ".($failed ? implode(', ', $failed) : '(see the output)'),
            ($tags[0] ?? null) !== 'dedicated-worker-toleration' => 'the first step run: '.($tags[0] ?? 'none'),
            ($lines['dedicated-worker-toleration'] ?? []) !== ['up to date'] => 'dedicated-worker-toleration: '.implode(' | ', $lines['dedicated-worker-toleration'] ?? ['(no line)']),
            end($tags) !== 'registry-prewarm' => 'the last step run: '.(end($tags) ?: 'none'),
            ($order = $this->recOrderProblem($tags)) !== null => $order,
            ($lines['tracing'] ?? []) !== ['up to date'] => 'tracing: '.implode(' | ', $lines['tracing'] ?? ['(no line)']),
            $prewarm === ['up to date'] => null,
            // Zot restarted by an earlier step answers no tags/list for a moment: a Job is started,
            // and it finds every image in the registry at once
            count($prewarm) === 1 && str_starts_with($prewarm[0], 'fetching') && $this->recPrewarmPlan($prewarm[0]) !== null => null,
            default => 'registry-prewarm: '.implode(' | ', $prewarm ?: ['(no line)']),
        };
        if ($problem !== null || $prewarm === ['up to date']) {
            return $problem ?? true;
        }

        [$ns, $job] = $this->recPrewarmPlan($prewarm[0]);
        $deadline = time() + 600;
        while (time() < $deadline) {
            $state = $this->getE2eKubernetesClient()->getResourceStatus('batch/v1', 'Job', $job, $ns);
            if ($this->recJobCondition($state, 'Complete')) {
                return true;
            }
            if ($state === null || $this->recJobCondition($state, 'Failed')) {
                break;
            }
            sleep(10);
        }

        return "registry-prewarm started Job {$ns}/{$job} again, and it did not complete: ".mb_substr(trim($this->recJobLog($this->getE2eKubernetesClient(), $ns, $job)), -300);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Index of the container $name in a workload's template (the first one without it). */
    private function recContainerIndex(array $workload, string $name): int
    {
        foreach ($workload['spec']['template']['spec']['containers'] ?? [] as $i => $container) {
            if (($container['name'] ?? '') === $name) {
                return $i;
            }
        }

        return 0;
    }

    private function recPodReady(array $pod): bool
    {
        foreach ($pod['status']['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') === 'Ready') {
                return ($condition['status'] ?? '') === 'True';
            }
        }

        return false;
    }

    /** Runs a pod to its end in $ns: true when it succeeded, else why not. The pod is deleted. */
    private function recRunPod(KubernetesClient $k8s, string $ns, string $name, array $spec): bool|string
    {
        $k8s->deleteResource('v1', 'Pod', $name, $ns);
        $pod = ['apiVersion' => 'v1', 'kind' => 'Pod', 'metadata' => ['name' => $name, 'namespace' => $ns], 'spec' => ['restartPolicy' => 'Never'] + $spec];
        if (! $k8s->createResource($pod, $ns)) {
            return "pod {$name} could not be created";
        }
        $phase = null;
        $deadline = time() + 180;
        while (time() < $deadline) {
            $phase = $k8s->getResourceStatus('v1', 'Pod', $name, $ns)['status']['phase'] ?? null;
            if ($phase === 'Succeeded' || $phase === 'Failed') {
                break;
            }
            sleep(3);
        }
        $log = trim((string) $k8s->getPodLogs($name, $ns));
        $k8s->deleteResource('v1', 'Pod', $name, $ns);
        if ($phase === 'Succeeded') {
            $this->line("    {$name}: {$log}");

            return true;
        }

        return "pod {$name} {$phase}: ".mb_substr($log, -300);
    }

    /** One result row, timed: $check returns true or why it failed (an exception fails it too). */
    private function recCheck(string $name, callable $check): array
    {
        $entry = $this->makeTestEntry($name, ['service_type' => 'reconcile', 'trigger_type' => 'none'], (string) ($this->e2eRegion->id ?? ''));
        $entry['startTime'] = microtime(true);
        try {
            $result = $check();
        } catch (\Throwable $e) {
            $result = $e::class.': '.$e->getMessage();
        }
        $entry['steps'] = ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => $result === true ? true : (string) $result];
        $entry = $this->finishTest($entry);

        $state = $result === true ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $this->line("  {$state} {$name}".($result === true ? '' : ': '.mb_substr((string) $result, 0, 400)));

        return $entry;
    }
}
