<?php

namespace App\Services\RegionReconcile\Steps;

use App\Services\CertManager\CertManager;
use App\Services\CertManager\CertManagerService;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\RegionReconcile\ReconcileTarget;

/**
 * cert-manager of existing regions (docs/regions.md#cert-manager), what a region installed now
 * gets with its Knative Serving (CertManagerService::install()):
 *  - a cluster without cert-manager gets RunR's release (the pinned chart, images from the reqs
 *    mirror) — unless Helm knative.certManager.install is false: then nothing, and TLS mode
 *    cert-manager stays unavailable there;
 *  - a cluster whose cert-manager RunR did not install keeps it as it is: the regions reuse it;
 *  - RunR's release of another chart version or other images is brought to this release's
 *    (Helm upgrade; the three Deployments roll once, certificates and issuers stay);
 *  - every region of the cluster that is not "ready" with a ClusterIssuer that exists gets its
 *    issuer: the operator's (region setting cert_manager_cluster_issuer or
 *    RUNR_CERT_MANAGER_CLUSTER_ISSUER), else RunR's default — ACME where the region is reachable
 *    from the internet and an ACME e-mail is configured, else the region CA.
 * The install waits for cert-manager's Deployments (CertManagerService::WAIT_SECONDS); one still
 * starting leaves the region "installing", and the region's status check finishes it.
 */
final class CertManagerStep implements ReconcileStep
{
    public function name(): string
    {
        return 'cert-manager';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $service = app(CertManagerService::class);
        $k8s = $cluster->k8s;
        $found = $service->detect($k8s);

        $clusterChange = null;
        if ($found['present'] && ! $found['controller']) {
            // cert-manager's CRDs without a controller: the operator's to sort out, never a second one
            return ['warning: '.__('api.cert_manager.crds_without_controller')];
        }
        if (! $found['present']) {
            if (! CertManager::installEnabled()) {
                return ['skipped: the cluster runs no cert-manager and Helm knative.certManager.install is false — custom domains there cannot use TLS mode cert-manager until one is installed'];
            }
            $clusterChange = 'install cert-manager '.CertManager::chartVersion().' (chart '.CertManager::CHART.', images from '.CertManager::imagePrefix().') in namespace '.CertManager::NAMESPACE;
        } elseif ($found['runr']) {
            $drift = $service->drift($k8s, $found['namespace'] ?? CertManager::NAMESPACE);
            if ($drift !== []) {
                $clusterChange = "update RunR's cert-manager to ".CertManager::chartVersion().': '.implode('; ', $drift).' (its Deployments roll once; certificates and issuers stay)';
            }
        }

        /** @var list<ReconcileTarget> $pending */
        $pending = [];
        foreach ($cluster->targets as $target) {
            $region = $target->region;
            $issuer = $service->effectiveIssuer($region);
            $issuerExists = $issuer !== null && $found['present'] && $k8s->hasApiVersion(CertManager::API)
                && $k8s->getClusterResource(CertManager::API, 'ClusterIssuer', $issuer) !== null;

            if ($clusterChange === null && $region->cert_manager_status === CertManagerService::STATUS_READY && $issuerExists) {
                continue;
            }
            $pending[] = $target;
        }

        if ($clusterChange === null && $pending === []) {
            return [];
        }

        // A cluster change with every region ready goes with the first region (the install of the
        // first pending region makes it otherwise; the others then find RunR's release current)
        if ($pending === []) {
            $pending = [$cluster->primary()];
        }

        $lines = [];
        if ($dryRun) {
            if ($clusterChange !== null) {
                $lines[] = "would {$clusterChange}";
            }
            foreach ($pending as $target) {
                $region = $target->region;
                $issuer = $service->operatorIssuer($region);
                $plan = $issuer === null ? $service->plan($region) : null;
                $lines[] = 'would '.($found['present'] && ! $found['runr'] ? "reuse the cluster's cert-manager".($found['namespace'] ? " ({$found['namespace']})" : '').' for' : 'set up cert-manager for')
                    ." region {$region->name} (now {$region->cert_manager_status}) with ClusterIssuer "
                    .($issuer ?? (($plan['kind'] ?? 'ca') === 'acme' ? CertManager::ACME_ISSUER.' (ACME' : CertManager::CA_ISSUER.' (the region CA').': '.($plan['reason'] ?? '').')');
            }

            return $lines;
        }

        if ($clusterChange !== null) {
            $lines[] = 'restart: '.$clusterChange;
        }
        foreach ($pending as $target) {
            $region = $target->run(fn () => $service->install($target->region, null, $k8s));
            $issuer = $service->effectiveIssuer($region);
            $lines[] = ($region->cert_manager_status === CertManagerService::STATUS_ERROR ? 'warning: ' : '')
                ."region {$region->name}: cert-manager {$region->cert_manager_status}"
                .($region->cert_manager_managed === false ? " (the cluster's own, reused)" : '')
                .($issuer !== null ? ", ClusterIssuer {$issuer}" : '')
                .($region->cert_manager_status === CertManagerService::STATUS_INSTALLING ? ' — still starting; the region status check adds the issuer once it runs' : '')
                .($region->cert_manager_status === CertManagerService::STATUS_ERROR ? ': '.(CertManagerService::lastLine($region->cert_manager_status_message) ?? 'see the region\'s cert-manager log') : '');
        }

        return $lines;
    }
}
