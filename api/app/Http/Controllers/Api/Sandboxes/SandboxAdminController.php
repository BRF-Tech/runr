<?php

namespace App\Http\Controllers\Api\Sandboxes;

use App\Models\ExecutorImage;
use App\Models\KnativeRegion;
use App\Models\SandboxPool;
use App\Models\SandboxQuota;
use App\Models\SandboxRegionSetting;
use App\Models\SandboxUsageRecord;
use App\Services\Residency\RegionPolicy;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Sandboxes\SandboxClassResolver;
use App\Services\Sandboxes\SandboxContext;
use App\Services\Sandboxes\SandboxException;
use App\Services\Sandboxes\SandboxMetrics;
use App\Services\Sandboxes\SandboxPoolService;
use App\Services\Sandboxes\SandboxQuotaService;
use App\Services\Sandboxes\SandboxRegions;
use App\Services\Sandboxes\Sizes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * @tags Sandboxes
 *
 * Sandbox API settings: region settings (classes, runc opt-in, TTL and size ceilings), warm pools,
 * organization quotas, metrics and usage.
 */
class SandboxAdminController extends SandboxApiController
{
    public function __construct(
        private SandboxRegions $regions,
        private SandboxPoolService $pools,
        private SandboxQuotaService $quotas,
        private SandboxMetrics $metrics,
        private SandboxClassResolver $classes,
        private RuntimeRegistry $runtimes,
    ) {}

    // ── Regions ─────────────────────────────────────────────────────────────────────────────

    public function regions(Request $request): JsonResponse
    {
        Gate::authorize('sandboxes.view');
        $tenantId = $this->tenantId();

        $data = $this->regions->available()
            ->map(fn (KnativeRegion $r) => $this->regions->describe($r, $this->canManageRegion($request, $r), $tenantId))
            ->values();

        return response()->json(['data' => $data]);
    }

    /** Settings of a region (route also carries region.mutable). */
    public function updateRegion(Request $request, KnativeRegion $knativeRegion): JsonResponse
    {
        Gate::authorize('sandboxes.manage');

        $known = array_merge(['kata', 'gvisor', SandboxClassResolver::RUNC], array_map(fn ($hv) => "kata-{$hv}", SandboxClassResolver::KATA_ORDER));
        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'allowed_classes' => 'sometimes|nullable|array',
            'allowed_classes.*' => ['string', Rule::in($known)],
            'default_class' => ['sometimes', 'nullable', 'string', Rule::in($known)],
            'allow_runc' => 'sometimes|boolean',
            'default_ttl_seconds' => 'sometimes|nullable|integer|min:10',
            'max_ttl_seconds' => 'sometimes|nullable|integer|min:60|max:'.(int) config('sandbox.limits.max_ttl_seconds', 86400),
            'max_cpu_m' => 'sometimes|nullable|integer|min:50|max:'.(int) config('sandbox.limits.max_cpu_m', 4000),
            'max_memory_mb' => 'sometimes|nullable|integer|min:64|max:'.(int) config('sandbox.limits.max_memory_mb', 8192),
            'max_disk_mb' => 'sometimes|nullable|integer|min:64|max:'.(int) config('sandbox.limits.max_disk_mb', 10240),
        ]);

        if (($data['default_class'] ?? null) === SandboxClassResolver::RUNC && ! ($data['allow_runc'] ?? SandboxRegionSetting::forRegion($knativeRegion->id)->allow_runc)) {
            return response()->json(['message' => __('sandboxes.runc_not_allowed'), 'errors' => ['default_class' => [__('sandboxes.runc_not_allowed')]]], 422);
        }

        $settings = SandboxRegionSetting::forRegion($knativeRegion->id);
        $settings->fill($data);
        $settings->knative_region_id = $knativeRegion->id;
        $settings->save();

        return response()->json(['data' => $this->regions->describe($knativeRegion, true, $this->tenantId())]);
    }

    // ── Pools ───────────────────────────────────────────────────────────────────────────────

    public function pools(Request $request): JsonResponse
    {
        Gate::authorize('sandboxes.view');

        $names = $this->regions->available()->pluck('name', 'id');
        $data = $this->pools->scope(SandboxPool::query(), $this->tenantId())->orderBy('created_at')->get()
            ->map(fn (SandboxPool $p) => $this->pools->describe($p, $names->has($p->knative_region_id) ? new KnativeRegion(['name' => $names[$p->knative_region_id]]) : null))
            ->values();

        return response()->json(['data' => $data]);
    }

    public function storePool(Request $request): JsonResponse
    {
        Gate::authorize('sandboxes.manage');

        $data = $request->validate([
            'region' => 'required|string|max:64',
            'runtime' => 'required|string|max:32',
            'version' => 'nullable|string|max:32',
            'size' => 'required|integer|min:0|max:'.(int) config('sandbox.pool.max_pool_size', 50),
            'cpu' => 'nullable',
            'memory' => 'nullable',
            'disk' => 'nullable',
            'sandbox_class' => 'nullable|string|max:64',
        ]);

        $tenantId = $this->tenantId();
        $region = $this->regions->resolve($data['region']);
        // Warm pods are sandboxes of the organization: data residency applies (docs/supply-chain.md)
        app(RegionPolicy::class)->authorize($region, RegionPolicy::SANDBOX_CREATE, $tenantId, 'region');

        if ($reason = $this->runtimes->unavailableReason($data['runtime'], $region->id)) {
            return response()->json(['message' => $reason, 'errors' => ['runtime' => [$reason]]], 422);
        }
        $executor = ExecutorImage::resolve($data['runtime'], $data['version'] ?? null);
        if (! $executor || (! empty($data['version']) && $executor->version !== $data['version'])) {
            $message = __('sandboxes.version_unknown', ['runtime' => $data['runtime'], 'version' => (string) ($data['version'] ?? '')]);

            return response()->json(['message' => $message, 'errors' => ['version' => [$message]]], 422);
        }

        $settings = SandboxRegionSetting::forRegion($region->id);
        $class = $data['sandbox_class'] ?? null;
        // A pinned class must be allowed now (null follows the region's default)
        if ($class !== null && $class !== 'auto') {
            $class = $this->classes->resolve($class, $region, $settings, $this->quotas->limits($tenantId)['allowed_classes'], $this->runtimes->manifest($data['runtime']));
        } else {
            $class = null;
        }

        $defaults = (array) config('sandbox.defaults');
        $cpu = Sizes::cpuMillicores($data['cpu'] ?? null) ?? (int) $defaults['cpu_m'];
        $mem = Sizes::mebibytes($data['memory'] ?? null) ?? (int) $defaults['memory_mb'];
        $disk = Sizes::mebibytes($data['disk'] ?? null) ?? (int) $defaults['disk_mb'];
        if ($cpu > $settings->maxCpuM() || $mem > $settings->maxMemoryMb() || $disk > $settings->maxDiskMb()) {
            return response()->json(['message' => __('sandboxes.pool_size_exceeds'), 'errors' => ['cpu' => [__('sandboxes.pool_size_exceeds')]]], 422);
        }

        $current = (int) $this->pools->scope(SandboxPool::query(), $tenantId)->where('is_enabled', true)->sum('size');
        $this->quotas->checkPoolSize($tenantId, $current + (int) $data['size']);

        $pool = SandboxPool::create([
            'tenant_id' => $tenantId,
            'knative_region_id' => $region->id,
            'runtime' => $data['runtime'],
            'runtime_version' => $executor->version,
            'sandbox_class' => $class,
            'cpu_m' => $cpu,
            'memory_mb' => $mem,
            'disk_mb' => $disk,
            'size' => (int) $data['size'],
            'is_enabled' => true,
            'created_by' => $this->userId($request),
        ]);

        $this->pools->dispatchRefill($pool->id);

        return response()->json(['data' => $this->pools->describe($pool, $region)], 201);
    }

    public function updatePool(Request $request, string $pool): JsonResponse
    {
        Gate::authorize('sandboxes.manage');
        $row = $this->findPool($pool);
        $data = $request->validate([
            'size' => 'sometimes|integer|min:0|max:'.(int) config('sandbox.pool.max_pool_size', 50),
            'is_enabled' => 'sometimes|boolean',
        ]);

        $tenantId = $this->tenantId();
        $others = (int) $this->pools->scope(SandboxPool::query(), $tenantId)->where('is_enabled', true)->whereKeyNot($row->id)->sum('size');
        $size = (int) ($data['size'] ?? $row->size);
        if (($data['is_enabled'] ?? $row->is_enabled)) {
            $this->quotas->checkPoolSize($tenantId, $others + $size);
        }

        $row->update($data);
        $this->pools->dispatchRefill($row->id);

        return response()->json(['data' => $this->pools->describe($row->refresh())]);
    }

    public function destroyPool(Request $request, string $pool): Response
    {
        Gate::authorize('sandboxes.manage');
        $this->pools->delete($this->findPool($pool));

        return response()->noContent();
    }

    // ── Quotas ──────────────────────────────────────────────────────────────────────────────

    /** The current organization's limits and usage. */
    public function quota(Request $request): JsonResponse
    {
        Gate::authorize('sandboxes.view');
        $tenantId = $this->tenantId();

        return response()->json(['data' => [
            'tenant_id' => $tenantId,
            'limits' => $this->quotas->limits($tenantId),
            'usage' => $this->quotas->usage($tenantId),
        ]]);
    }

    /** Every organization's limits and usage, plus the defaults row (platform). */
    public function quotaTenants(Request $request): JsonResponse
    {
        $this->authorizePlatform($request);

        $rows = [[
            'tenant' => null,
            'limits' => $this->quotas->limits(null),
            'usage' => SandboxContext::tenancyEnabled() ? null : $this->quotas->usage(null),
            'custom' => SandboxQuota::whereNull('tenant_id')->exists(),
        ]];

        if (SandboxContext::tenancyEnabled()) {
            $custom = SandboxQuota::whereNotNull('tenant_id')->pluck('tenant_id')->flip();
            foreach (Tenant::query()->orderBy('name')->get() as $tenant) {
                $id = (string) $tenant->getTenantKey();
                $rows[] = [
                    'tenant' => ['id' => $id, 'name' => $tenant->name, 'slug' => $tenant->slug],
                    'limits' => $this->quotas->limits($id),
                    'usage' => $this->quotas->usage($id),
                    'custom' => isset($custom[$id]),
                ];
            }
        }

        return response()->json(['data' => $rows]);
    }

    /** Set an organization's quota ("default": the defaults row). */
    public function updateQuota(Request $request, string $tenant): JsonResponse
    {
        $this->authorizePlatform($request);
        $tenantId = $this->quotaTenantId($tenant);

        $known = array_merge(['kata', 'gvisor', SandboxClassResolver::RUNC], array_map(fn ($hv) => "kata-{$hv}", SandboxClassResolver::KATA_ORDER));
        $data = $request->validate([
            'max_concurrent' => 'sometimes|nullable|integer|min:0|max:100000',
            'max_cpu_m' => 'sometimes|nullable|integer|min:0',
            'max_memory_mb' => 'sometimes|nullable|integer|min:0',
            'max_sandbox_seconds_month' => 'sometimes|nullable|integer|min:0',
            'max_pool_size' => 'sometimes|nullable|integer|min:0|max:10000',
            'allowed_classes' => 'sometimes|nullable|array',
            'allowed_classes.*' => ['string', Rule::in($known)],
        ]);

        $row = SandboxQuota::query()->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))->first()
            ?? new SandboxQuota(['tenant_id' => $tenantId]);
        $row->fill($data)->save();

        return response()->json(['data' => [
            'tenant_id' => $tenantId,
            'limits' => $this->quotas->limits($tenantId),
            'usage' => $this->quotas->usage($tenantId),
        ]]);
    }

    /** Back to the defaults. */
    public function destroyQuota(Request $request, string $tenant): Response
    {
        $this->authorizePlatform($request);
        $tenantId = $this->quotaTenantId($tenant);
        SandboxQuota::query()->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))->delete();

        return response()->noContent();
    }

    // ── Metrics & usage ─────────────────────────────────────────────────────────────────────

    public function metrics(Request $request): JsonResponse
    {
        Gate::authorize('sandboxes.view');
        $data = $request->validate([
            'window' => ['nullable', Rule::in(array_keys(SandboxMetrics::WINDOWS))],
            'region' => 'nullable|string|max:64',
            'all_tenants' => 'nullable|boolean',
        ]);

        $tenant = $request->boolean('all_tenants') && $this->platformView($request) ? false : $this->tenantId();

        return response()->json(['data' => $this->metrics->summary($tenant, $data['region'] ?? null, $data['window'] ?? '24h')]);
    }

    public function prometheus(Request $request): Response
    {
        $this->authorizePlatform($request);

        return response($this->metrics->prometheus(), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }

    /**
     * Daily sandbox usage of the organization (?from= &to=, default this month).
     *
     * Quantities only: what they cost is the Billing module's (GET /api/billing/usage, the account
     * bills).
     */
    public function usage(Request $request): JsonResponse
    {
        Gate::authorize('sandboxes.view');
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);
        $tenantId = $this->tenantId();
        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();

        $rows = SandboxUsageRecord::query()
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereDate('record_date', '>=', $from)
            ->whereDate('record_date', '<=', $to)
            ->orderBy('record_date')
            ->get()
            ->map(fn (SandboxUsageRecord $r) => [
                'record_date' => $r->record_date->toDateString(),
                'region_id' => $r->knative_region_id,
                'sandbox_count' => $r->sandbox_count,
                'sandbox_seconds' => $r->sandbox_seconds,
                'cpu_mc_ms' => $r->cpu_mc_ms,
                'memory_mib_ms' => $r->memory_mib_ms,
            ])->values();

        return response()->json(['data' => $rows]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────────

    private function findPool(string $id): SandboxPool
    {
        $pool = $this->pools->scope(SandboxPool::query(), $this->tenantId())->whereKey($id)->first();
        if (! $pool) {
            abort(404, __('sandboxes.pool_unknown'));
        }

        return $pool;
    }

    /** Whether the user may change the region's sandbox settings (sandboxes.manage + region.mutable semantics). */
    private function canManageRegion(Request $request, KnativeRegion $region): bool
    {
        $user = $request->user();
        if (! $user || ! Gate::forUser($user)->allows('sandboxes.manage')) {
            return false;
        }
        if (! SandboxContext::tenancyEnabled() || ($user->is_admin ?? false)) {
            return true;
        }
        if (SandboxContext::tenantId() === null) {
            return false;
        }

        // A tenant's own region (not a synced copy of a platform region) — what region.mutable lets through
        $attributes = $region->getAttributes();
        if ((bool) ($attributes['is_synced'] ?? false)) {
            return false;
        }

        return collect(['regions.manage', 'sandbox.manage', 'registry.manage'])
            ->contains(fn (string $permission) => Gate::forUser($user)->allows($permission));
    }

    /** Platform-level settings: every user of a single-tenant install, platform admins otherwise. */
    private function authorizePlatform(Request $request): void
    {
        Gate::authorize('sandboxes.manage');
        if (SandboxContext::tenancyEnabled() && ! ($request->user()?->is_admin ?? false)) {
            throw new SandboxException(__('sandboxes.platform_admin_required'), 403, 'forbidden');
        }
    }

    private function quotaTenantId(string $tenant): ?string
    {
        if ($tenant === 'default') {
            return null;
        }
        if (! SandboxContext::tenancyEnabled() || ! Tenant::find($tenant)) {
            abort(404, __('sandboxes.tenant_gone'));
        }

        return $tenant;
    }
}
