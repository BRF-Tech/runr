<?php

namespace App\Services\RegionReconcile\Steps;

use App\Services\Concerns\KnativeAgentTrait;
use App\Services\KnativeInstallService;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\RegionReconcile\ReconcileTarget;
use RuntimeException;

/**
 * Regions installed before 0.3.8 exposed RunR's Prometheus through an unauthenticated NodePort
 * (observability/prometheus-nodeport, port 30090) and stored http://<node-ip>:30090 as
 * prometheus_url; their runr-agent got the same URL as PROMETHEUS_URL. This step, only for a
 * RunR-managed Prometheus:
 *  1. deletes the NodePort Service,
 *  2. rewrites a :30090 prometheus_url to the in-cluster Service URL (tenant copies of a platform
 *     region follow through the region sync),
 *  3. redeploys the cluster's runr-agent when its PROMETHEUS_URL still points at :30090.
 */
final class PrometheusExposureStep implements ReconcileStep
{
    use KnativeAgentTrait;

    private const NS = 'observability';

    private const NODEPORT_SERVICE = 'prometheus-nodeport';

    public function name(): string
    {
        return 'prometheus-exposure';
    }

    public static function isNodePortUrl(?string $url): bool
    {
        return $url !== null && preg_match('~:30090/?$~', trim($url)) === 1;
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $managed = array_values(array_filter(
            $cluster->targets,
            fn (ReconcileTarget $t) => $t->region->isPrometheusManaged(),
        ));

        // Operator-supplied Prometheus on every region of this cluster: nothing of RunR's to fix
        if (! $managed) {
            return [];
        }

        $k8s = $cluster->k8s;
        $lines = [];

        if ($k8s->getResourceStatus('v1', 'Service', self::NODEPORT_SERVICE, self::NS)) {
            if (! $dryRun && ! $k8s->deleteResource('v1', 'Service', self::NODEPORT_SERVICE, self::NS)) {
                throw new RuntimeException('Service '.self::NS.'/'.self::NODEPORT_SERVICE.' could not be deleted');
            }
            $lines[] = ($dryRun ? 'would delete' : 'deleted').' Service '.self::NS.'/'.self::NODEPORT_SERVICE.' (unauthenticated NodePort 30090)';
        }

        $clusterUrl = KnativeInstallService::prometheusClusterIpUrl(self::NS);

        foreach ($managed as $target) {
            $old = $target->region->prometheus_url;
            if (! self::isNodePortUrl($old)) {
                continue;
            }

            if (! $dryRun) {
                $target->run(fn () => $target->region->update(['prometheus_url' => $clusterUrl]));
            }
            $lines[] = ($dryRun ? 'would set' : 'set')." prometheus_url of {$target->label()}: {$old} → {$clusterUrl}";
        }

        $agentLine = $this->redeployStaleAgent($cluster, $dryRun, $clusterUrl);
        if ($agentLine) {
            $lines[] = $agentLine;
        }

        return $lines;
    }

    /**
     * One runr-agent per cluster (observability/runr-agent), owned by the region in its
     * RUNR_REGION_ID env. Redeployed through that region, so its token and URL settings apply.
     */
    private function redeployStaleAgent(ClusterGroup $cluster, bool $dryRun, string $clusterUrl): ?string
    {
        $deployment = $cluster->k8s->getResourceStatus('apps/v1', 'Deployment', 'runr-agent', self::NS);
        if (! $deployment) {
            return null;
        }

        $env = collect($deployment['spec']['template']['spec']['containers'][0]['env'] ?? []);
        $agentUrl = $env->firstWhere('name', 'PROMETHEUS_URL')['value'] ?? null;
        $ownerId = $env->firstWhere('name', 'RUNR_REGION_ID')['value'] ?? null;

        if (! self::isNodePortUrl($agentUrl)) {
            return null;
        }

        $owner = $ownerId ? $cluster->find($ownerId) : null;
        if (! $owner || ! $owner->region->isPrometheusManaged()) {
            return "skipped: runr-agent (region {$ownerId}) still uses {$agentUrl} — its region is not reconciled here; run php artisan agent:redeploy {$ownerId}";
        }

        if (! $dryRun) {
            $owner->run(fn () => $this->deployAgent($cluster->k8s, $owner->region, self::NS));
        }

        $newUrl = $dryRun && self::isNodePortUrl($owner->region->prometheus_url) ? $clusterUrl : $owner->region->prometheus_url;

        return ($dryRun ? 'would redeploy' : 'redeployed')." runr-agent of {$owner->label()}: PROMETHEUS_URL {$agentUrl} → {$newUrl}";
    }
}
