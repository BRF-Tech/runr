<?php

namespace App\Services\RegionReconcile\Steps;

use App\Models\Gateway;
use App\Services\GatewayService;
use App\Services\Observability\TenantScope;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\RegionReconcile\ReconcileTarget;
use Illuminate\Support\Facades\Log;

/**
 * Every gateway of the cluster's regions gets the routes of this release (docs/api-product.md),
 * without waiting for its next deploy or route change: the routes of a gateway are rebuilt when
 * what RunR would apply differs from what its IngressRoute carries (GatewayService::routing(),
 * label runr.dev/routing-revision) — an IngressRoute of an earlier release has none. That brings
 * the Host rewrite per route (gw-host-<route>), the forwardAuth Middlewares of key / JWT routes,
 * the direct routes of access mode allowlist and "only through the gateway", the metering proxy
 * and one Method() matcher per method; a route to an environment (docs/environments.md) is built
 * for the environment's workload.
 *
 * Gateways of every organization on the region: a central region's gateways live in the central
 * database and in the schema of each organization that uses the region (TenantScope::each), an
 * organization's own region's in its schema. After gateway-auth (GatewayAuthStep): a gateway whose
 * metering proxy is still rolling out gets its routes unmetered, and GatewayAuthHealthJob meters
 * them once it is ready.
 */
final class GatewayRoutesStep implements ReconcileStep
{
    public function name(): string
    {
        return 'gateway-routes';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $service = app(GatewayService::class);
        $lines = [];
        $failures = [];
        $seen = [];

        foreach ($cluster->targets as $target) {
            $visit = function () use ($cluster, $service, $target, $dryRun, &$lines, &$failures, &$seen) {
                $gateways = Gateway::query()->where('knative_region_id', $target->region->id)->with(['routes.project', 'knativeRegion'])->get();
                foreach ($gateways as $gateway) {
                    $key = (TenantScope::current() ?? '_').'|'.$gateway->id;
                    if (isset($seen[$key]) || ! $gateway->knativeRegion || ! $gateway->knativeRegion->kubeconfig || $gateway->knativeRegion->is_demo) {
                        continue;
                    }
                    $seen[$key] = true;
                    $label = "gateway {$gateway->subdomain} ({$gateway->id}".(TenantScope::current() ? ', organization '.TenantScope::current() : '').')';

                    try {
                        $wanted = $service->routing($cluster->k8s, $gateway)['revision'];
                        if ($service->appliedRoutingRevision($cluster->k8s, $gateway) === $wanted) {
                            continue;
                        }
                        if ($dryRun) {
                            $lines[] = "would rebuild the routes of {$label}";

                            continue;
                        }
                        $service->syncToCluster($gateway);
                        $gateway->refresh();
                        $lines[] = $gateway->status === 'error'
                            ? "warning: the routes of {$label} were rebuilt, the gateway shows: {$gateway->status_message}"
                            : "rebuilt the routes of {$label}";
                    } catch (\Throwable $e) {
                        $failures[] = "{$label}: {$e->getMessage()}";
                        Log::warning('Gateway routes not rebuilt by region:reconcile', ['gateway' => $gateway->id, 'error' => $e->getMessage()]);
                    }
                }
            };

            $this->inScopes($target, $visit);
        }

        if ($failures !== []) {
            throw new \RuntimeException(implode('; ', $failures));
        }

        return $lines;
    }

    /**
     * Run $visit where the region's gateways live: every schema for a central region (the platform's
     * own and each organization's that uses it), the organization's schema for its own region.
     */
    private function inScopes(ReconcileTarget $target, \Closure $visit): void
    {
        if ($target->scope === 'central') {
            TenantScope::each(fn () => $visit());

            return;
        }

        $target->run($visit);
    }
}
