<?php

namespace Modules\Tenancy\Services;

use App\Models\KnativeRegion;
use App\Models\Setting;
use App\Services\Concerns\ConfigDomainTrait;
use App\Services\KubernetesClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Models\Tenant;

class TenantDomainService
{
    use ConfigDomainTrait;

    /**
     * Add tenant's custom domain to all assigned regions' config-domain ConfigMaps.
     */
    public function syncDomainToRegions(Tenant $tenant, string $baseDomain): void
    {
        $regions = $this->resolveRegions($tenant);

        foreach ($regions as $region) {
            $this->addDomainToRegion($tenant, $region, $baseDomain);
        }
    }

    /**
     * Remove tenant domain from a single region.
     */
    public function removeDomainFromRegion(Tenant $tenant, KnativeRegion $region): void
    {
        $baseDomain = null;
        $tenant->run(function () use (&$baseDomain) {
            $baseDomain = Setting::get('base_domain');
        });

        if (! $baseDomain || ! $region->kubeconfig) {
            return;
        }

        $tenantDomain = "{$region->name}.{$baseDomain}";
        $this->safeRemoveDomain($region, $tenantDomain);
    }

    /**
     * Remove tenant domain from all assigned regions.
     */
    public function removeDomainFromAllRegions(Tenant $tenant, string $oldDomain): void
    {
        $regions = $this->resolveRegions($tenant);

        foreach ($regions as $region) {
            $tenantDomain = "{$region->name}.{$oldDomain}";
            $this->safeRemoveDomain($region, $tenantDomain);
        }
    }

    public static function buildTenantSelector(string $tenantSlug): string
    {
        return "selector:\n  runr/tenant: {$tenantSlug}";
    }

    private function addDomainToRegion(Tenant $tenant, KnativeRegion $region, string $baseDomain): void
    {
        if (! $region->kubeconfig || ! $region->has_serving) {
            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $servingNs = $region->knative_serving_namespace ?? 'knative-serving';
            $tenantDomain = "{$region->name}.{$baseDomain}";
            $selector = self::buildTenantSelector($tenant->slug);
            $this->addConfigDomain($k8s, $servingNs, $tenantDomain, $selector);
        } catch (\Throwable $e) {
            Log::warning("TenantDomainService: failed to add domain to region {$region->name}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function safeRemoveDomain(KnativeRegion $region, string $domain): void
    {
        if (! $region->kubeconfig) {
            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $servingNs = $region->knative_serving_namespace ?? 'knative-serving';
            $this->removeConfigDomain($k8s, $servingNs, $domain);
        } catch (\Throwable $e) {
            Log::warning("TenantDomainService: failed to remove domain from region {$region->name}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return Collection<KnativeRegion>
     */
    private function resolveRegions(Tenant $tenant): Collection
    {
        return $tenant->regions()
            ->get()
            ->map(fn ($r) => KnativeRegion::on('central')->find($r->id))
            ->filter();
    }
}
