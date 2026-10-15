<?php

namespace App\Services\Sandboxes;

use App\Models\KnativeRegion;
use App\Models\SandboxRegionSetting;
use App\Services\Residency\RegionPolicy;
use Illuminate\Support\Collection;

/**
 * Regions a sandbox can run in, as the current organization sees them, with their Sandbox API
 * settings and sandbox classes.
 */
final class SandboxRegions
{
    public function __construct(
        private SandboxClassResolver $classes,
        private SandboxQuotaService $quotas,
    ) {}

    /** Regions of the current context that have a cluster (active, not demo, kubeconfig set). */
    public function available(): Collection
    {
        return KnativeRegion::query()
            ->where('is_active', true)
            ->where('is_demo', false)
            ->orderBy('name')
            ->get()
            ->filter(fn (KnativeRegion $r) => ! empty($r->kubeconfig) && $r->kubeconfig !== 'demo')
            ->values();
    }

    /**
     * The region of a create request: id or name; default the first available region with
     * sandboxes enabled.
     *
     * @throws SandboxException region_unavailable
     */
    public function resolve(?string $idOrName): KnativeRegion
    {
        $regions = $this->available();

        if ($idOrName !== null && $idOrName !== '') {
            $region = $regions->first(fn (KnativeRegion $r) => $r->id === $idOrName || $r->name === $idOrName);
            if (! $region) {
                $message = __('sandboxes.region_unknown', ['region' => $idOrName]);
                throw new SandboxException($message, 422, 'region_unavailable', ['errors' => ['region' => [$message]]]);
            }
            if (! SandboxRegionSetting::forRegion($region->id)->enabled) {
                $message = __('sandboxes.region_disabled', ['region' => $region->name]);
                throw new SandboxException($message, 422, 'region_unavailable', ['errors' => ['region' => [$message]]]);
            }

            return $region;
        }

        // Default: the first region with sandboxes on that data residency allows for the organization
        $residency = app(RegionPolicy::class);
        $region = $regions->first(fn (KnativeRegion $r) => SandboxRegionSetting::forRegion($r->id)->enabled
            && $residency->allows($r, RegionPolicy::SANDBOX_CREATE));
        if (! $region) {
            $message = __('sandboxes.no_region');
            throw new SandboxException($message, 422, 'region_unavailable', ['errors' => ['region' => [$message]]]);
        }

        return $region;
    }

    /** API shape of a region's sandbox settings for the current organization. */
    public function describe(KnativeRegion $region, bool $canManage, ?string $tenantId): array
    {
        $settings = SandboxRegionSetting::forRegion($region->id);
        $tenantAllowed = $this->quotas->limits($tenantId)['allowed_classes'];
        $effective = $this->classes->allowed($region, $settings, $tenantAllowed);

        return [
            'region' => ['id' => $region->id, 'name' => $region->name],
            'enabled' => (bool) $settings->enabled,
            'installed_classes' => $this->classes->installed($region),
            'allowed_classes' => $settings->allowed_classes,
            'effective_classes' => $effective,
            'default_class' => $settings->default_class,
            'effective_default_class' => $this->classes->effectiveDefault($region, $settings, $tenantAllowed),
            'allow_runc' => (bool) $settings->allow_runc,
            'default_ttl_seconds' => $settings->defaultTtl(),
            'max_ttl_seconds' => $settings->maxTtl(),
            'max_cpu_m' => $settings->maxCpuM(),
            'max_memory_mb' => $settings->maxMemoryMb(),
            'max_disk_mb' => $settings->maxDiskMb(),
            'can_manage' => $canManage,
        ];
    }
}
