<?php

namespace Modules\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenancy\Models\Plan;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\Plans\PlanLimitService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan limits and feature flags on writes (plans on, tenant on a plan; platform admins exempt).
 * CPU / memory are not checked here — they are the tenant's region quotas (CheckTenantQuota).
 *
 *   POST /api/projects                          max_projects; gpu_quota + feature "gpu" with config.gpu
 *   PATCH /api/projects/{id} with config.gpu    gpu_quota + feature "gpu"
 *   POST / PATCH /api/model-services[/{id}]     gpu_quota + feature "gpu" on device gpu
 *   POST /api/projects/{id}/deploy              invocations_per_month used up → no new deploys
 *   POST /api/projects/{id}/code | files        storage_quota_mb full → no new code
 *   POST /api/projects/{id}/domains             feature custom_domains
 *   POST /api/projects/{id}/private-connections feature private_connections
 *   POST /api/tokens                            feature api_tokens
 *   POST /api/auth-providers                    feature sso
 *
 * Refusals: 422 {message, code: "plan_limit", limit, feature?, max?, used?}.
 */
class EnforcePlanLimits
{
    public function __construct(private readonly PlanLimitService $limits) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('tenancy.enabled') || $request->isMethodSafe() || $request->isMethod('DELETE')) {
            return $next($request);
        }

        $tenant = $request->attributes->get('tenant');
        if (! $tenant instanceof Tenant || $request->user()?->is_admin) {
            return $next($request);
        }

        $plan = $this->limits->planFor($tenant);
        if (! $plan) {
            return $next($request);
        }

        $refusal = $this->check($request, $tenant, $plan);

        return $refusal ?? $next($request);
    }

    private function check(Request $request, Tenant $tenant, Plan $plan): ?Response
    {
        $update = in_array($request->method(), ['PUT', 'PATCH'], true);

        if ($request->isMethod('POST') && ($request->routeIs('projects.store') || $request->is('api/projects'))) {
            if ($over = $this->limits->exceeds($tenant, $plan, 'max_projects')) {
                return $this->refuse('max_projects', $over);
            }
            $gpu = $this->projectGpus($request);

            return $gpu > 0 ? $this->gpu($tenant, $plan, $gpu) : null;
        }

        if ($update && preg_match('#^api/projects/([^/]+)$#', $request->path(), $m) && ($gpu = $this->projectGpus($request)) > 0) {
            return $this->gpu($tenant, $plan, $gpu, ['project' => $m[1]]);
        }

        $serviceId = $update && preg_match('#^api/model-services/([^/]+)$#', $request->path(), $s) ? $s[1] : null;
        if ($serviceId !== null || ($request->isMethod('POST') && $request->is('api/model-services'))) {
            $gpu = $this->serviceGpus($request);

            return $gpu > 0 ? $this->gpu($tenant, $plan, $gpu, ['service' => $serviceId]) : null;
        }

        if ($request->is('api/projects/*/deploy') && ($over = $this->limits->exceeds($tenant, $plan, 'invocations_per_month'))) {
            return $this->refuse('invocations_per_month', $over);
        }

        if ($request->is('api/projects/*/code', 'api/projects/*/files') && ($over = $this->limits->exceeds($tenant, $plan, 'storage_quota_mb'))) {
            return $this->refuse('storage_quota_mb', $over);
        }

        $features = [
            'custom_domains' => ['api/projects/*/domains'],
            'private_connections' => ['api/projects/*/private-connections'],
            'api_tokens' => ['api/tokens'],
            'sso' => ['api/auth-providers', 'api/auth-providers/*'],
        ];

        foreach ($features as $feature => $paths) {
            if ($request->isMethod('POST') && $request->is(...$paths) && ! $plan->allowsFeature($feature)) {
                return $this->refuseFeature($feature);
            }
        }

        return null;
    }

    /** GPUs a project create / update asks for: config.gpu.count × max(1, config.min_scale). */
    private function projectGpus(Request $request): int
    {
        $count = (int) ($request->input('config.gpu.count') ?? 0);

        return $count > 0 ? $count * max(1, (int) $request->input('config.min_scale', 0)) : 0;
    }

    /** GPUs a model service on device gpu asks for: gpu.count (default 1) × max(1, min_scale). */
    private function serviceGpus(Request $request): int
    {
        if ($request->input('device') !== 'gpu' && ! is_array($request->input('gpu'))) {
            return 0;
        }

        return max(1, (int) ($request->input('gpu.count') ?? 1)) * max(1, (int) $request->input('min_scale', 0));
    }

    /** @param array{project?: string|null, service?: string|null} $exclude the workload being changed */
    private function gpu(Tenant $tenant, Plan $plan, int $gpu, array $exclude = []): ?Response
    {
        if (! $plan->allowsFeature('gpu')) {
            return $this->refuseFeature('gpu');
        }

        if ($over = $this->limits->exceeds($tenant, $plan, 'gpu_quota', $gpu, $exclude)) {
            return $this->refuse('gpu_quota', $over);
        }

        return null;
    }

    private function refuse(string $limit, array $over): Response
    {
        return response()->json([
            'message' => __("whitelabel.plans.limit_{$limit}", ['max' => $over['max'], 'used' => $over['used']]),
            'code' => 'plan_limit',
            'limit' => $limit,
            'max' => $over['max'],
            'used' => $over['used'],
        ], 422);
    }

    private function refuseFeature(string $feature): Response
    {
        return response()->json([
            'message' => __('whitelabel.plans.feature_not_included', ['feature' => __("whitelabel.plans.feature_{$feature}")]),
            'code' => 'plan_limit',
            'limit' => 'feature',
            'feature' => $feature,
        ], 422);
    }
}
