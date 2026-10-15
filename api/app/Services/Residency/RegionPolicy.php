<?php

namespace App\Services\Residency;

use App\Models\AuditEvent;
use App\Models\KnativeRegion;
use App\Models\RegionSupplyChainSetting;
use App\Models\TenantSecurityPolicy;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\SupplyChain\SupplyChainAudit;

/**
 * Data residency: the single check every entry point that places tenant data or workloads in a
 * region calls (docs/supply-chain.md, "Data residency").
 *
 *   app(RegionPolicy::class)->authorize($region, RegionPolicy::DEPLOY);   // throws 422
 *   app(RegionPolicy::class)->allows($region, RegionPolicy::SANDBOX_CREATE); // bool
 *
 * A tenant whose policy (tenant_security_policies) is enforced may only use the regions listed
 * in allowed_region_ids and the regions whose data location (region_supply_chain_settings
 * .data_location) is listed in allowed_locations. Outside tenant context (single-tenant install,
 * platform admin in the central console) nothing is restricted.
 *
 * Entry points: project create, project move to another region, deploy, preview, sandbox and
 * binding creation (private databases, model bindings), AI model services and model snapshots,
 * and — for a region with a data location — region migration
 * (authorizeRegionMigration: the new cluster must be confirmed to be in the same location).
 */
class RegionPolicy
{
    public const PROJECT_CREATE = 'project.create';

    public const PROJECT_MOVE = 'project.move';

    public const DEPLOY = 'deploy';

    public const PREVIEW = 'preview';

    public const SANDBOX_CREATE = 'sandbox.create';

    public const BINDING_CREATE = 'binding.create';

    public const MODEL_SERVICE = 'model_service.deploy';   // AI model services (create / deploy / redeploy)

    public const MODEL_SNAPSHOT = 'model.snapshot';         // model weights downloaded / imported into a region

    public const IMAGE_UPLOAD = 'image.upload';           // an uploaded image pushed into a region registry (docs/images.md)

    public const REGION_MIGRATION = 'region.migration';

    private static ?\Closure $tenantResolver = null;

    /** Overrides how the current tenant is found (tests, CLI tools); null restores the default. */
    public static function resolveTenantUsing(?\Closure $resolver): void
    {
        self::$tenantResolver = $resolver;
    }

    /** Tenant of the current request / job, null outside tenant context. */
    public function tenantId(): ?string
    {
        if (self::$tenantResolver) {
            $id = (self::$tenantResolver)();

            return $id !== null ? (string) $id : null;
        }

        if ($id = RuntimeRegistry::currentTenantId()) {
            return $id;
        }

        $tenant = app()->bound('request') ? request()->attributes->get('tenant') : null;

        return $tenant ? (string) $tenant->getTenantKey() : null;
    }

    public function policy(?string $tenantId = null): ?TenantSecurityPolicy
    {
        $tenantId ??= $this->tenantId();

        return $tenantId ? TenantSecurityPolicy::query()->find($tenantId) : null;
    }

    /**
     * @return array{allowed: bool, reason: ?string, location: ?string}
     */
    public function check(KnativeRegion|string|null $region, string $action, ?string $tenantId = null): array
    {
        $policy = $this->policy($tenantId);
        if (! $policy || ! $policy->restrictsRegions() || $region === null) {
            return ['allowed' => true, 'reason' => null, 'location' => null];
        }

        $regionId = $region instanceof KnativeRegion ? (string) $region->getKey() : $region;
        $location = RegionSupplyChainSetting::forRegion($regionId)->data_location;

        if ($this->policyAllows($policy, $regionId, $location)) {
            return ['allowed' => true, 'reason' => null, 'location' => $location];
        }

        $name = $region instanceof KnativeRegion ? $region->name : (KnativeRegion::find($regionId)?->name ?? $regionId);

        return [
            'allowed' => false,
            'location' => $location,
            'reason' => __('supply_chain.residency.denied', [
                'region' => $name,
                'action' => self::actionLabel($action),
                'allowed' => $this->allowedLabel($policy),
            ]),
        ];
    }

    /**
     * The label of an entry point in a refusal. The action names contain dots (project.create), so
     * they are looked up in the actions list, not as a dotted translation key.
     */
    public static function actionLabel(string $action): string
    {
        $labels = __('supply_chain.residency.actions');

        return is_array($labels) && is_string($labels[$action] ?? null) ? $labels[$action] : $action;
    }

    /** Whether a policy (stored or not) lets a tenant use a region (by id, or by its data location). */
    public function policyAllows(TenantSecurityPolicy $policy, string $regionId, ?string $location = null): bool
    {
        if (! $policy->restrictsRegions()) {
            return true;
        }

        $location ??= RegionSupplyChainSetting::forRegion($regionId)->data_location;

        return in_array($regionId, $policy->allowed_region_ids ?? [], true)
            || ($location !== null && in_array(self::normalizeLocation($location), array_map([self::class, 'normalizeLocation'], $policy->allowed_locations ?? []), true));
    }

    public function allows(KnativeRegion|string|null $region, string $action, ?string $tenantId = null): bool
    {
        return $this->check($region, $action, $tenantId)['allowed'];
    }

    /** @throws RegionNotAllowedException */
    public function authorize(KnativeRegion|string|null $region, string $action, ?string $tenantId = null, string $field = 'knative_region_id'): void
    {
        $decision = $this->check($region, $action, $tenantId);
        if ($decision['allowed']) {
            return;
        }

        $regionId = $region instanceof KnativeRegion ? (string) $region->getKey() : $region;
        SupplyChainAudit::record('residency.denied', [
            'action' => $action,
            'tenant_id' => $tenantId ?? $this->tenantId(),
            'location' => $decision['location'],
        ], $regionId, AuditEvent::RESULT_DENIED);

        throw new RegionNotAllowedException((string) $decision['reason'], $action, $regionId, $field);
    }

    /**
     * Region migration of a region with a data location: the operator confirms the new cluster
     * is in the same location ($confirmedLocation); a different or missing one is refused, so a
     * region keeps the location every residency policy relies on.
     *
     * @throws RegionNotAllowedException
     */
    public function authorizeRegionMigration(KnativeRegion $region, ?string $confirmedLocation): void
    {
        $location = RegionSupplyChainSetting::forRegion($region)->data_location;
        if ($location === null || $location === '') {
            return;
        }

        if ($confirmedLocation !== null && self::normalizeLocation($confirmedLocation) === self::normalizeLocation($location)) {
            return;
        }

        $message = $confirmedLocation === null || trim($confirmedLocation) === ''
            ? __('supply_chain.residency.migration_location_required', ['region' => $region->name, 'location' => $location])
            : __('supply_chain.residency.migration_location_mismatch', ['region' => $region->name, 'location' => $location, 'given' => $confirmedLocation]);

        SupplyChainAudit::record('residency.migration_denied', ['location' => $location, 'given' => $confirmedLocation], (string) $region->getKey(), AuditEvent::RESULT_DENIED);

        throw new RegionNotAllowedException($message, self::REGION_MIGRATION, (string) $region->getKey(), 'data_location', 'region_location_mismatch');
    }

    /** @return list<string>|null region ids the tenant may use, null = every region */
    public function allowedRegionIds(iterable $regions, ?string $tenantId = null): ?array
    {
        $policy = $this->policy($tenantId);
        if (! $policy || ! $policy->restrictsRegions()) {
            return null;
        }

        $ids = [];
        foreach ($regions as $region) {
            if ($this->allows($region, self::PROJECT_CREATE, $tenantId)) {
                $ids[] = (string) $region->getKey();
            }
        }

        return $ids;
    }

    public function defaultRegionId(?string $tenantId = null): ?string
    {
        return $this->policy($tenantId)?->default_region_id;
    }

    public static function normalizeLocation(?string $location): string
    {
        return strtoupper(trim((string) $location));
    }

    private function allowedLabel(TenantSecurityPolicy $policy): string
    {
        $names = KnativeRegion::query()->whereIn('id', $policy->allowed_region_ids ?? [])->pluck('name')->all();
        $parts = array_merge($names, array_map(fn ($l) => __('supply_chain.residency.location', ['location' => $l]), $policy->allowed_locations ?? []));

        return $parts ? implode(', ', $parts) : '—';
    }
}
