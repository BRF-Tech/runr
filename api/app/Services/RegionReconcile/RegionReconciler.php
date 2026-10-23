<?php

namespace App\Services\RegionReconcile;

use App\Models\KnativeRegion;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\Steps\AdmissionPolicyStep;
use App\Services\RegionReconcile\Steps\CertManagerStep;
use App\Services\RegionReconcile\Steps\DedicatedWorkerTolerationStep;
use App\Services\RegionReconcile\Steps\ExternalSecretsStep;
use App\Services\RegionReconcile\Steps\GatewayAuthStep;
use App\Services\RegionReconcile\Steps\GatewayRoutesStep;
use App\Services\RegionReconcile\Steps\GlobalDnsStep;
use App\Services\RegionReconcile\Steps\ImageRegistryStep;
use App\Services\RegionReconcile\Steps\IngressTrustStep;
use App\Services\RegionReconcile\Steps\InternalNetworkStep;
use App\Services\RegionReconcile\Steps\KataSandboxStep;
use App\Services\RegionReconcile\Steps\KnativeServingConfigStep;
use App\Services\RegionReconcile\Steps\LokiDeletionStep;
use App\Services\RegionReconcile\Steps\NamespaceQuotaStep;
use App\Services\RegionReconcile\Steps\NetworkPolicyStep;
use App\Services\RegionReconcile\Steps\OpenBaoStep;
use App\Services\RegionReconcile\Steps\PrivateIngressStep;
use App\Services\RegionReconcile\Steps\PrometheusExposureStep;
use App\Services\RegionReconcile\Steps\RegistryPrewarmStep;
use App\Services\RegionReconcile\Steps\RegistrySyncConfigStep;
use App\Services\RegionReconcile\Steps\RequestTimeoutStep;
use App\Services\RegionReconcile\Steps\TracingBackendStep;
use App\Services\RegionReconcile\Steps\TraefikImageStep;
use App\Services\RegionReconcile\Steps\WasmSandboxStep;
use App\Services\RegionStatusService;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Upgrade-time reconcile of existing regions (`php artisan region:reconcile`, run by the Helm
 * bootstrap job after every install/upgrade). Region-side changes of a release otherwise only
 * reach regions installed after it.
 *
 * Regions: active central rows with a kubeconfig and a ready Knative Serving, plus whatever the
 * registered region sources add (the Tenancy module adds each tenant's own regions). Regions on
 * the same cluster are reconciled once, as a group. A failing step or cluster is logged and
 * counted; the rest still run. Then the status block of every central region with a ready
 * Knative Serving, whatever its status (blockable()), plus the regions the registered status-block
 * sources add (the Tenancy module adds each tenant's own regions, inactive ones included).
 *
 * With a deadline no step starts after it: the step running then runs to its end, so a run that
 * stops on time leaves no change half-done; what is left is reported (ReconcileOutcome::notRun)
 * and the next run does it — every step is idempotent. The Helm bootstrap job sets the deadline
 * and a time limit above it (helm/templates/bootstrap-job.yaml).
 */
class RegionReconciler
{
    /** @var array<string, Closure(?string): iterable<ReconcileTarget>> */
    private static array $sources = [];

    /** @var array<string, Closure(?string): iterable<ReconcileTarget>> */
    private static array $blockSources = [];

    /** @var array<string, bool> whether a cluster answers, by kubeconfig (reachabilityKey()) */
    private array $reachability = [];

    public function __construct(private readonly RunrClusterIdentity $clusterIdentity) {}

    /**
     * The steps, in order. A new upgrade-time region change = one more line here
     * (a ReconcileStep implementation, e.g. a namespace ResourceQuota step).
     *
     * @return list<ReconcileStep>
     */
    public function steps(): array
    {
        return [
            // before every step that restarts a component with a node-bound volume (registry,
            // Loki, Tempo): their next pod may have to start on a dedicated worker
            new DedicatedWorkerTolerationStep,
            new KnativeServingConfigStep,
            new PrometheusExposureStep,
            new NamespaceQuotaStep,
            new RegistrySyncConfigStep,
            new IngressTrustStep,
            new TraefikImageStep,
            // the Traefik read / write timeouts and Knative's maximum revision timeout
            new RequestTimeoutStep,
            // cluster-local Knative routes off the LoadBalancer (the KIngresses follow), then the
            // NetworkPolicies that leave Traefik and Knative the only way to the project pods
            new PrivateIngressStep,
            new NetworkPolicyStep,
            // the internal network's authz, runr.internal DNS and routes, policies of the services
            // (after private-ingress: its ForwardAuth is on the private entry point)
            new InternalNetworkStep,
            new LokiDeletionStep,
            // the tracing backend where tracing is enabled (the region's or the platform's mode)
            new TracingBackendStep,
            // cert-manager (installed, or the cluster's own reused) and the ClusterIssuer of custom
            // domains; after the Traefik steps: the ACME issuer's HTTP-01 solver goes through Traefik
            new CertManagerStep,
            new KataSandboxStep,
            new WasmSandboxStep,
            // the pinned chart of External Secrets Operator where a region has it (secret store references)
            new ExternalSecretsStep,
            // the region's OpenBao: pinned image, TLS certificate (after the operator it needs)
            new OpenBaoStep,
            // the API product's gateway-auth where a region runs it: a new image of the release
            new GatewayAuthStep,
            // every gateway's routes as this release builds them (after gateway-auth: its metering
            // proxy; after the Traefik and internal-network steps: the routes they serve)
            new GatewayRoutesStep,
            // the PowerDNS server of the global zone where the region runs one (docs/global-projects.md)
            new GlobalDnsStep,
            // RunR's image namespace (ghcr.io/brf-tech/runr): the admission policy admits it before
            // image-registry rolls the region's workloads onto it
            new AdmissionPolicyStep,
            new ImageRegistryStep,
            // after every step that restarts the registry (registry-sync-config, image-registry):
            // its Job asks Zot for the build base images
            new RegistryPrewarmStep,
        ];
    }

    /**
     * Extra regions outside the central table. The source receives the --region filter (or null)
     * and yields ReconcileTargets whose run() switches into the row's database context.
     *
     * Keyed by $name: registering again (a provider booted twice) replaces, never duplicates.
     *
     * @param  Closure(?string): iterable<ReconcileTarget>  $source
     */
    public static function addRegionSource(string $name, Closure $source): void
    {
        self::$sources[$name] = $source;
    }

    /**
     * Extra regions outside the central table whose status block the reconcile keeps as their
     * status wants it (blockable() rows, active or not — reconcilable() targets are active only).
     * Same contract as addRegionSource(); keyed by $name.
     *
     * @param  Closure(?string): iterable<ReconcileTarget>  $source
     */
    public static function addStatusBlockSource(string $name, Closure $source): void
    {
        self::$blockSources[$name] = $source;
    }

    public static function clearRegionSources(): void
    {
        self::$sources = [];
        self::$blockSources = [];
    }

    /**
     * Base filter for a region that can be reconciled (central table and tenant schemas alike).
     */
    public static function reconcilable($query)
    {
        return $query->where('is_active', true)
            ->where('is_demo', false)
            ->whereNotNull('kubeconfig')
            ->where('knative_serving_status', 'ready');
    }

    /**
     * Base filter for a region whose status block (RegionStatusService::syncBlock) region:reconcile
     * keeps as its status wants it — whatever the status: an inactive or maintenance region gets
     * its block (one created inactive, or whose Serving came up after the status changed), an
     * active one loses a leftover.
     */
    public static function blockable($query)
    {
        return $query->where('is_demo', false)
            ->whereNotNull('kubeconfig')
            ->where('knative_serving_status', 'ready');
    }

    /**
     * @return list<ReconcileTarget>
     */
    public function targets(?string $regionId = null): array
    {
        // syncedQuery(): a central platform region's writes (prometheus_url) reach tenant copies
        $query = self::reconcilable(KnativeRegion::syncedQuery());
        if ($regionId) {
            $query->where('id', $regionId);
        }

        $targets = [];
        foreach ($query->get() as $region) {
            $targets[] = new ReconcileTarget($region, 'central');
        }

        foreach (self::$sources as $source) {
            foreach ($source($regionId) as $target) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /**
     * Group targets by cluster: kube-system namespace UID (what RunrClusterIdentity compares),
     * falling back to the API server URL when the UID can't be read.
     *
     * @param  list<ReconcileTarget>  $targets
     * @return array<string, list<ReconcileTarget>>
     */
    public function groupByCluster(array $targets): array
    {
        $grouped = [];

        foreach ($targets as $target) {
            $uid = $this->clusterIdentity->regionClusterUid($target->region);
            $key = $uid ? "uid:{$uid}" : 'server:'.($target->region->server_url ?? $target->region->id);
            $grouped[$key][] = $target;
        }

        return $grouped;
    }

    /**
     * @param  Closure(string $level, string $message): void  $out  level: info|warn|error
     * @param  int|null  $deadline  Unix time after which no step starts (the one running then
     *                              runs to its end); what is left is reported in notRun
     */
    public function reconcile(?string $regionId, bool $dryRun, Closure $out, ?int $deadline = null): ReconcileOutcome
    {
        $outcome = new ReconcileOutcome;
        $targets = $this->targets($regionId);
        if (! $targets) {
            $out('info', 'No regions to reconcile.');
        } else {
            $this->reconcileClusters($targets, $dryRun, $out, $deadline, $outcome);
        }

        if ($deadline !== null && time() >= $deadline) {
            $outcome->notRun[] = 'the status blocks of the regions';
        } else {
            $this->reconcileStatusBlocks($regionId, $dryRun, $out, $outcome);
        }

        return $outcome;
    }

    /**
     * The status block of every region with Knative Serving ready, active or not (blockable()):
     * the central rows, then those of the status-block sources, each in its own database context.
     *
     * @param  Closure(string $level, string $message): void  $out
     */
    private function reconcileStatusBlocks(?string $regionId, bool $dryRun, Closure $out, ReconcileOutcome $outcome): void
    {
        $query = self::blockable(KnativeRegion::syncedQuery());
        if ($regionId) {
            $query->where('id', $regionId);
        }

        foreach ($query->get() as $region) {
            $this->reportStatusBlock($region, $region->name, app(RegionStatusService::class)->syncBlock($region, $dryRun), $out, $outcome);
        }

        foreach (self::$blockSources as $source) {
            foreach ($source($regionId) as $target) {
                try {
                    $lines = $target->run(fn () => app(RegionStatusService::class)->syncBlock($target->region, $dryRun));
                } catch (Throwable $e) {
                    $lines = ["failed: {$e->getMessage()}"];
                }
                $this->reportStatusBlock($target->region, "{$target->region->name} ({$target->scope})", $lines, $out, $outcome);
            }
        }
    }

    /**
     * A block that could not be synced is a failure — or, when the region's cluster does not
     * answer, an unreachable region (as an unreachable cluster in the steps).
     *
     * @param  list<string>  $lines  RegionStatusService::syncBlock()
     * @param  Closure(string $level, string $message): void  $out
     */
    private function reportStatusBlock(KnativeRegion $region, string $label, array $lines, Closure $out, ReconcileOutcome $outcome): void
    {
        foreach ($lines as $line) {
            $failed = str_starts_with($line, 'failed:');
            if ($failed && ! $this->regionReachable($region)) {
                $outcome->unreachable[] = "region {$label}";
                $outcome->regions[(string) $region->getKey()][] = 'unreachable';
                $out('warn', "[status-block] region {$label}: unreachable — not synced ({$line})");

                continue;
            }
            $outcome->failures += $failed ? 1 : 0;
            if ($failed) {
                $outcome->regions[(string) $region->getKey()][] = "[status-block] {$line}";
            }
            $level = $failed ? 'error' : (str_starts_with($line, 'skipped') ? 'warn' : 'info');
            $out($level, "[status-block] region {$label}: {$line}");
        }
    }

    /**
     * @param  list<ReconcileTarget>  $targets
     * @param  Closure(string $level, string $message): void  $out
     */
    private function reconcileClusters(array $targets, bool $dryRun, Closure $out, ?int $deadline, ReconcileOutcome $outcome): void
    {
        $steps = $this->steps();
        $past = fn () => $deadline !== null && time() >= $deadline;

        foreach ($this->groupByCluster($targets) as $key => $members) {
            $label = $members[0]->label();
            if ($past()) {
                $outcome->notRun[] = "cluster {$key} ({$label}): every step";
                $outcome->region($members, 'not run: every step (the deadline passed)');
                $out('error', "Cluster {$key}: {$label} — not reconciled: the deadline passed");

                continue;
            }

            try {
                $cluster = new ClusterGroup($key, $members, new KubernetesClient($members[0]->region->kubeconfig));
            } catch (Throwable $e) {
                $outcome->unreachable[] = "cluster {$key} ({$label})";
                $outcome->region($members, 'unreachable');
                $out('error', "Cluster {$key}: invalid kubeconfig of {$label} — skipped ({$e->getMessage()})");

                continue;
            }

            $out('info', "Cluster {$cluster->key}: {$cluster->label()}");

            if (! $this->reachable($cluster)) {
                $outcome->unreachable[] = "cluster {$cluster->key} ({$cluster->label()})";
                $outcome->region($members, 'unreachable');
                $this->reachability[$this->reachabilityKey($members[0]->region)] = false;
                $out('error', '  unreachable — skipped');

                continue;
            }

            foreach ($steps as $i => $step) {
                if ($past()) {
                    $left = array_map(fn (ReconcileStep $s) => $s->name(), array_slice($steps, $i));
                    $outcome->notRun[] = "cluster {$cluster->key} ({$cluster->label()}): ".implode(', ', $left);
                    $outcome->region($members, 'not run: '.implode(', ', $left).' (the deadline passed)');
                    $out('error', '  the deadline passed — not run: '.implode(', ', $left));

                    break;
                }
                try {
                    $lines = $step->run($cluster, $dryRun);
                    foreach ($lines ?: ['up to date'] as $line) {
                        // "waiting: …" — the step could not finish its change yet (e.g. the internal
                        // network's authz has no ready pod): the region is not at this release yet
                        $waiting = ! $dryRun && str_starts_with($line, 'waiting:');
                        if ($waiting) {
                            $outcome->failures++;
                            $outcome->region($members, "[{$step->name()}] {$line}");
                        }
                        // "restart: …" — a step that restarts (or would restart) something on the nodes;
                        // "warning: …" — something the operator has to act on (e.g. Kata without /dev/kvm)
                        $level = match (true) {
                            $waiting => 'error',
                            str_starts_with($line, 'skipped'), str_starts_with($line, 'restart:'), str_starts_with($line, 'warning:'),
                            str_starts_with($line, 'waiting:') => 'warn',
                            default => 'info',
                        };
                        $out($level, "  [{$step->name()}] {$line}");
                    }
                } catch (Throwable $e) {
                    $outcome->failures++;
                    $outcome->region($members, "[{$step->name()}] failed: {$e->getMessage()}");
                    $out('error', "  [{$step->name()}] failed: {$e->getMessage()}");
                    Log::warning('Region reconcile step failed', [
                        'cluster' => $cluster->key,
                        'regions' => array_map(fn (ReconcileTarget $t) => $t->region->id, $cluster->targets),
                        'step' => $step->name(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function reachable(ClusterGroup $cluster): bool
    {
        try {
            return $cluster->k8s->getServerVersion() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /** Whether the region's cluster answers (status blocks of regions the steps did not reach) */
    private function regionReachable(KnativeRegion $region): bool
    {
        $key = $this->reachabilityKey($region);
        if (! array_key_exists($key, $this->reachability)) {
            try {
                $this->reachability[$key] = (new KubernetesClient((string) $region->kubeconfig))->getServerVersion() !== null;
            } catch (Throwable) {
                $this->reachability[$key] = false;
            }
        }

        return $this->reachability[$key];
    }

    private function reachabilityKey(KnativeRegion $region): string
    {
        return sha1((string) $region->kubeconfig);
    }
}
