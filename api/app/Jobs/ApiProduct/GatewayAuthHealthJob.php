<?php

namespace App\Jobs\ApiProduct;

use App\Models\ApiProduct\GatewayAuthScope;
use App\Models\Gateway;
use App\Services\ApiProduct\GatewayAuthDeployer;
use App\Services\GatewayService;
use App\Services\KubernetesClient;
use App\Services\Observability\TenantScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the key / JWT routes of the gateways in step with the region's gateway-auth (every
 * minute, docs/api-product.md#when-gateway-auth-is-not-ready): a gateway whose routes refuse
 * (pending, error) is synced again once a gateway-auth pod is ready — the routes open, or the
 * installation is tried again; an active one whose gateway-auth has no ready pod any more is
 * synced so its routes refuse (503 auth_unavailable) until it is back. One whose key / JWT routes
 * do not go through the metering proxy although it takes calls now (an upgrade's rollout ended),
 * or that sends them there while it does not, is synced as well. One Deployment and two
 * EndpointSlice reads per region and organization.
 */
class GatewayAuthHealthJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function handle(GatewayService $gateways, GatewayAuthDeployer $deployer): void
    {
        foreach (GatewayAuthScope::query()->orderBy('id')->get() as $row) {
            try {
                TenantScope::run($row->scope === GatewayAuthScope::CENTRAL ? null : $row->scope, fn () => $this->check($row->knative_region_id, $gateways, $deployer));
            } catch (\Throwable $e) {
                Log::warning('gateway-auth health check skipped', ['region' => $row->knative_region_id, 'scope' => $row->scope, 'error' => $e->getMessage()]);
            }
        }
    }

    private function check(string $regionId, GatewayService $gateways, GatewayAuthDeployer $deployer): void
    {
        $list = Gateway::query()
            ->where('knative_region_id', $regionId)
            ->with(['routes.project', 'knativeRegion'])
            ->get()
            ->filter(fn (Gateway $gateway) => $gateway->hasAuthenticatedRoutes());
        if ($list->isEmpty()) {
            return;
        }

        $region = $list->first()->knativeRegion;
        if (! $region || ! $region->kubeconfig || $region->is_demo) {
            return;
        }

        $k8s = new KubernetesClient($region->kubeconfig);
        $namespace = $region->project_namespace ?? $region->name;
        $ready = $deployer->readiness($k8s, $namespace)['ready'];
        $metering = $ready && $deployer->proxyReady($k8s, $namespace);
        foreach ($list as $gateway) {
            // active = its routes ask gateway-auth; anything else = they refuse. A gateway whose
            // routes do not go through the metering proxy while it is ready (or do while it is
            // not) is synced too
            $metered = Cache::get(GatewayService::meteredCacheKey((string) $gateway->id));
            if ($ready !== ($gateway->status === 'active') || ($ready && $metered !== $metering)) {
                $gateways->syncToCluster($gateway);
            }
        }
    }
}
