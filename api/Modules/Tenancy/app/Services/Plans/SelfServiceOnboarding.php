<?php

namespace Modules\Tenancy\Services\Plans;

use App\Models\User;
use App\Services\WhiteLabel\SignupSettings;
use App\Support\Rbac\Rbac;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Tenancy\Models\CentralSyncableRegion;
use Modules\Tenancy\Models\Plan;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantOnboarding;
use Modules\Tenancy\Models\TenantSubscription;
use Modules\Tenancy\Services\Billing\BillingSettings;
use Modules\Tenancy\Support\TenantOrganization;

/**
 * Multi-tenant self-service signup → own tenant → onboarding wizard (docs/white-label.md):
 *
 *   signup        CreateTenantOnRegistration creates the tenant (name from the form or
 *                 "<name>'s workspace"); tenantCreated() opens the wizard and, with plans on, puts
 *                 the tenant on the default plan
 *   wizard        organization name, first region (asked only when several are offered)
 *   complete()    renames the tenant, assigns the region with the plan's quota or the default
 *                 signup quota (clamped to the free capacity)
 *
 * Other plans are put into effect by the platform administrator or the operator's billing system
 * (billing API, docs/billing.md).
 */
class SelfServiceOnboarding
{
    public function __construct(
        private readonly SignupSettings $signup,
        private readonly BillingSettings $billing,
        private readonly RegionQuotaAllocator $allocator,
        private readonly PlanService $plans,
    ) {}

    /**
     * Tenant name for a signup that did not give one; null when tenants are not created on signup.
     */
    public function defaultTenantName(User $user): ?string
    {
        if (! $this->signup->tenantAutoCreate()) {
            return null;
        }

        $base = trim((string) ($user->name ?: $user->username ?: strtok((string) $user->email, '@')));

        return mb_substr(__('whitelabel.onboarding.default_tenant_name', ['name' => $base !== '' ? $base : 'My']), 0, 255);
    }

    /**
     * Right after the signup's tenant was created (owner attached).
     */
    public function tenantCreated(Tenant $tenant, User $user): void
    {
        TenantOnboarding::query()->updateOrCreate(
            ['tenant_id' => (string) $tenant->id],
            ['status' => TenantOnboarding::STATUS_PENDING, 'created_by' => (string) $user->id],
        );

        if (! $this->billing->plansEnabled()) {
            return;
        }

        if ($plan = Plan::defaultPlan()) {
            $this->plans->assign($tenant, $plan, $plan->supportsInterval('month') ? 'month' : 'year', ['source' => 'signup']);
        }
    }

    public function status(Tenant $tenant): string
    {
        return TenantOnboarding::query()->find((string) $tenant->id)?->status ?? 'none';
    }

    /**
     * Platform regions offered in the wizard: the signup setting's list, or every active platform
     * region (demo regions never).
     *
     * @return Collection<int, CentralSyncableRegion>
     */
    public function offeredRegions(): Collection
    {
        $ids = $this->signup->selfServiceRegionIds();

        return CentralSyncableRegion::query()
            ->whereNull('owner_tenant_id')
            ->where('is_demo', false)
            ->where('is_active', true)
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')
            ->get();
    }

    public function payload(Tenant $tenant, User $user): array
    {
        $status = $this->status($tenant);
        $subscription = TenantSubscription::findForTenant((string) $tenant->id);

        return [
            'status' => $status,
            'can_complete' => $this->canComplete($tenant, $user),
            'tenant' => ['id' => (string) $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug],
            'regions' => $this->offeredRegions()->map(fn ($r) => [
                'id' => (string) $r->id, 'name' => $r->name, 'region_mode' => $r->region_mode ?? 'managed',
            ])->values()->all(),
            'region_assigned' => $tenant->regions()->exists(),
            'default_quota' => $this->signup->defaultQuota(),
            'plan' => $subscription?->plan && $subscription->status !== TenantSubscription::STATUS_CANCELED ? $subscription->plan->toPublicArray() : null,
        ];
    }

    /**
     * settings.manage in the tenant (the request's organization) — owners and admins by default.
     */
    public function canComplete(Tenant $tenant, User $user): bool
    {
        $rbac = app(Rbac::class);

        if ($rbac->isPlatformAdmin($user)) {
            return true;
        }

        $org = $rbac->organization();

        return $org instanceof TenantOrganization
            && (string) $org->tenant()->getTenantKey() === (string) $tenant->id
            && $rbac->allows($user, 'settings.manage');
    }

    /**
     * @return array{status: string, tenant: array}
     *
     * @throws ValidationException
     */
    public function complete(Tenant $tenant, array $data): array
    {
        $onboarding = TenantOnboarding::query()->find((string) $tenant->id)
            ?? new TenantOnboarding(['tenant_id' => (string) $tenant->id]);

        // 1. Organization name
        $name = trim((string) ($data['org_name'] ?? ''));
        if ($name !== '' && $name !== $tenant->name) {
            $tenant->update(['name' => mb_substr($name, 0, 255)]);
        }

        // 2. First region — with the quota of the tenant's plan (plans on), else the default signup quota
        $subscription = $this->billing->plansEnabled() ? TenantSubscription::findForTenant((string) $tenant->id) : null;
        $quotaPlan = $subscription && $subscription->status !== TenantSubscription::STATUS_CANCELED ? $subscription->plan : null;

        $offered = $this->offeredRegions();
        $regionId = $data['region_id'] ?? null;
        if (! $regionId && $offered->count() === 1) {
            $regionId = (string) $offered->first()->id;
        }

        if ($regionId && ! $tenant->regions()->where('knative_region_id', $regionId)->exists()) {
            $region = $offered->firstWhere('id', $regionId);
            if (! $region) {
                throw ValidationException::withMessages(['region_id' => __('whitelabel.onboarding.region_not_offered')]);
            }

            $quota = $quotaPlan ? PlanQuotaService::quotaOf($quotaPlan) : $this->signup->defaultQuota();
            $result = $this->allocator->allocate($tenant, $region, (int) $quota['cpu_quota_m'], (int) $quota['memory_quota_mb']);

            if ($result['clamped']) {
                Log::warning('Onboarding quota lowered to the free region capacity', ['tenant' => $tenant->slug, 'region' => $region->name] + $result);
            }
            $onboarding->region_id = (string) $region->id;
        }

        $onboarding->forceFill(['status' => TenantOnboarding::STATUS_COMPLETED, 'completed_at' => now()])->save();

        return [
            'status' => TenantOnboarding::STATUS_COMPLETED,
            'tenant' => ['id' => (string) $tenant->id, 'name' => $tenant->fresh()->name, 'slug' => $tenant->slug],
        ];
    }
}
