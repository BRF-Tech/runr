<?php

namespace Modules\Tenancy\Services;

use App\Models\KnativeRegion;
use App\Models\Notification;
use App\Models\User;
use App\Services\Helpers\RegionCapacity;
use App\Services\Helpers\ResourceReservation;
use App\Services\ResourceThresholdService;
use App\Support\Rbac\Rbac;
use App\Support\SchemaColumns;
use Illuminate\Support\Collection;
use Modules\Tenancy\Models\Tenant;

/**
 * The capacity alarm on a multi-tenant installation:
 *
 *  - a platform region — the regions shared with tenants (region offers) included — adds up what
 *    every tenant holds there and what the platform's own projects hold (measureAcross()), and
 *    tells the platform administrators: a notification in the central schema, a mail. A check
 *    after a tenant's deploy to it (CheckResourceAfterDeploy runs in the tenant) is the same
 *    platform check;
 *  - a tenant's own region is measured in its schema; the notification is the tenant's and its
 *    members who manage its regions (regions.manage) get it — in the app and by mail. The
 *    platform administrators get no copy: the tenant provides that region's nodes.
 *
 * measure() is the same figure for the capacity endpoint, whichever schema it is asked from.
 */
class TenantResourceThresholdService extends ResourceThresholdService
{
    /** Who provides a tenant's own region and is told to add nodes there */
    public const TENANT_RECIPIENT_PERMISSION = 'regions.manage';

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
            $tenant->run(function () {
                KnativeRegion::where('is_active', true)
                    ->where('is_synced', false)
                    ->where(fn ($q) => $q->where('cluster_status', 'online')->orWhere('is_demo', true))
                    ->each(fn (KnativeRegion $region) => parent::checkRegion($region));
            });
        });
    }

    /**
     * A platform region checked from a tenant (its copy, after a deploy there) is the platform's
     * check, in the platform's schema; anything else as measured where it is.
     */
    public function checkRegion(KnativeRegion $region): void
    {
        if (tenancy()->initialized && $region->getAttribute('is_synced')) {
            $id = (string) $region->getKey();
            tenancy()->central(function () use ($id) {
                $central = KnativeRegion::query()->whereKey($id)->whereNull('owner_tenant_id')->where('is_active', true)->first();
                if ($central) {
                    $this->checkPlatformRegion($central, Tenant::all());
                }
            });

            return;
        }

        parent::checkRegion($region);
    }

    /**
     * From the platform (central): a platform region across every tenant (measureAcross()), a
     * tenant's own region in that tenant's schema. In a tenant: its own schema.
     */
    public function measure(KnativeRegion $region): array
    {
        if (! tenancy()->initialized && ! $region->getAttribute('is_synced')) {
            $owner = $region->getAttribute('owner_tenant_id');
            if ($owner === null || $owner === '') {
                return $this->measureAcross($region, Tenant::all());
            }
            if ($tenant = Tenant::query()->find($owner)) {
                return $tenant->run(fn () => parent::measure($region));
            }
        }

        return parent::measure($region);
    }

    /**
     * A platform region's figures: the platform's own projects, environments and model services
     * with every sandbox and warm pool pod of the region (the Sandbox API's table is central:
     * counted once, here), plus what each tenant's projects, environments and model services hold
     * (RegionCapacity::usage() in its schema) and what each tenant's stopped projects would hold
     * again (named "tenant / project").
     *
     * @param  Collection  $tenants  the tenants (Tenant::all())
     * @return array{usage: array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}, stopped: array{demand: array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}, projects: list<array<string, mixed>>}}
     */
    public function measureAcross(KnativeRegion $region, Collection $tenants): array
    {
        $regionId = (string) $region->getKey();
        $usage = RegionCapacity::usage($regionId);
        $stopped = RegionCapacity::stopped($regionId);

        foreach ($tenants as $tenant) {
            $tenant->run(function () use ($regionId, $tenant, &$usage, &$stopped) {
                $usage = ResourceReservation::addDemand($usage, RegionCapacity::usage($regionId, sandboxes: false));

                $own = RegionCapacity::stopped($regionId);
                $stopped['demand'] = ResourceReservation::addDemand($stopped['demand'], $own['demand']);
                foreach ($own['projects'] as $project) {
                    $stopped['projects'][] = ['name' => $tenant->slug.' / '.$project['name'], 'tenant' => (string) $tenant->id] + $project;
                }
            });
        }
        usort($stopped['projects'], fn (array $a, array $b) => [$b['cpu_m'], $b['memory_mb']] <=> [$a['cpu_m'], $a['memory_mb']]);

        return ['usage' => $usage, 'stopped' => $stopped];
    }

    /** @param  Collection  $tenants  the tenants (Tenant::all()) */
    protected function checkPlatformRegion(KnativeRegion $region, Collection $tenants): void
    {
        $measure = $this->measureAcross($region, $tenants);

        $this->checkMeasured($region, $measure['usage'], $measure['stopped']);
    }

    /**
     * A tenant's own region (checked in its schema): its members who manage its regions; a
     * platform region: the platform administrators.
     *
     * @return iterable<User>
     */
    protected function getMailRecipients()
    {
        if (tenancy()->initialized) {
            $ids = app(Rbac::class)->memberIdsHolding(self::TENANT_RECIPIENT_PERMISSION, true);

            return $ids === [] ? collect() : User::on('central')->whereIn('id', $ids)->get();
        }

        return User::on('central')->where('is_admin', true)->get();
    }

    /**
     * A tenant's own region: the notification is the tenant's — its schema keeps it apart; a
     * notifications table with a tenant_id column carries the tenant too.
     */
    protected function tagNotification(Notification $notification): Notification
    {
        $tenant = tenancy()->initialized ? tenant() : null;
        if ($tenant && SchemaColumns::has($notification, 'tenant_id')) {
            $notification->forceFill(['tenant_id' => (string) $tenant->getTenantKey()])->save();
        }

        return $notification;
    }
}
