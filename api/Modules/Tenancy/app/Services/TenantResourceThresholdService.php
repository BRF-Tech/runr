<?php

namespace Modules\Tenancy\Services;

use App\Models\KnativeRegion;
use App\Models\Notification;
use App\Models\User;
use App\Services\Helpers\RegionCapacity;
use App\Services\Helpers\ResourceReservation;
use App\Services\ResourceThresholdService;
use Illuminate\Support\Collection;
use Modules\Tenancy\Models\Tenant;

class TenantResourceThresholdService extends ResourceThresholdService
{
    protected ?string $currentTenantId = null;

    public function checkAllRegions(): void
    {
        $tenants = Tenant::all();

        // Platform-level regions (central schema): sum usage across ALL tenants
        KnativeRegion::on('central')
            ->where('is_active', true)
            ->whereNull('owner_tenant_id')
            ->where(fn ($q) => $q->where('cluster_status', 'online')->orWhere('is_demo', true))
            ->each(fn (KnativeRegion $region) => $this->checkPlatformRegion($region, $tenants));

        // Tenant-owned regions: check within tenant context (tenant schema)
        $tenants->each(function (Tenant $tenant) {
            $tenant->run(function () use ($tenant) {
                KnativeRegion::where('is_active', true)
                    ->where('is_synced', false)
                    ->where(fn ($q) => $q->where('cluster_status', 'online')->orWhere('is_demo', true))
                    ->each(function (KnativeRegion $region) use ($tenant) {
                        $this->currentTenantId = $tenant->id;
                        $this->checkRegion($region);
                        $this->currentTenantId = null;
                    });
            });
        });
    }

    /**
     * For platform-level regions, collect what every tenant runs there (projects, model services,
     * sandboxes; RegionCapacity::usage() in each tenant schema) and measure both budgets.
     */
    protected function checkPlatformRegion(KnativeRegion $region, Collection $tenants): void
    {
        $usage = ResourceReservation::noDemand();

        foreach ($tenants as $tenant) {
            $tenant->run(function () use ($region, &$usage) {
                $usage = ResourceReservation::addDemand($usage, RegionCapacity::usage($region->id));
            });
        }

        $summary = RegionCapacity::summary($region, $usage);
        $this->checkResource($region, $summary['cpu_m'], 'cpu');
        $this->checkResource($region, $summary['memory_mb'], 'memory');
    }

    protected function getMailRecipients()
    {
        return User::on('central')->where('is_admin', true)->get();
    }

    protected function createNotification(
        KnativeRegion $region,
        string $type,
        string $level,
        float $percentage
    ): Notification {
        $notification = parent::createNotification($region, $type, $level, $percentage);

        if ($this->currentTenantId) {
            $notification->update(['tenant_id' => $this->currentTenantId]);
        }

        return $notification;
    }
}
