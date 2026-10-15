<?php

namespace Modules\Tenancy\Http\Middleware;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Helpers\ResourceReservation;
use Closure;
use Illuminate\Http\Request;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantQuotaService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant quota admission for project writes (quota model option C). The size
 * a request asks for is turned into the project's admitted reservation by
 * ResourceReservation (size × max(1, min_scale), queue-proxy per Knative Service pod, the Kata
 * RuntimeClass overhead per pod when the region maps the service type to kata-*, queue
 * consumer for queue projects; missing values fall back to the stored config, then to the
 * service type's default size).
 *
 * Existing projects are grandfathered: a write that does not raise the project's reservation
 * (deploy, redeploy, env edit, smaller size) is always allowed, even when the tenant is over
 * quota — e.g. after the reservation formula grew with an upgrade. Only increases and new
 * projects are checked against the remaining quota.
 */
class CheckTenantQuota
{
    public function __construct(
        protected TenantQuotaService $quotaService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('tenancy.enabled')) {
            return $next($request);
        }

        // Only check on mutating requests
        if ($request->isMethod('GET') || $request->isMethod('DELETE')) {
            return $next($request);
        }

        $tenant = $request->attributes->get('tenant');
        if (! $tenant instanceof Tenant) {
            return $next($request);
        }

        // Platform admins are not subject to tenant quotas
        if ($request->user()?->is_admin) {
            return $next($request);
        }

        // Case 1: New project creation
        if ($request->routeIs('projects.store') && $request->has('knative_region_id')) {
            return $this->checkNewProject($request, $next, $tenant);
        }

        // Case 2: Existing project edit/deploy
        $project = $request->route('project');
        if ($project) {
            if (is_string($project)) {
                $project = Project::find($project);
            }
            if ($project instanceof Project) {
                return $this->checkExistingProject($request, $next, $tenant, $project);
            }
        }

        return $next($request);
    }

    protected function checkNewProject(Request $request, Closure $next, Tenant $tenant): Response
    {
        $region = KnativeRegion::find($request->input('knative_region_id'));
        if (! $region || $region->region_mode === 'infinite') {
            return $next($request);
        }

        $config = (array) $request->input('config', []);
        $reservation = ResourceReservation::forConfig($config, $request->input('service_type'), $region);

        return $this->respond($request, $next, $tenant, $region, $reservation);
    }

    protected function checkExistingProject(Request $request, Closure $next, Tenant $tenant, Project $project): Response
    {
        $config = ResourceReservation::configOf($project);
        if (! $config) {
            return $next($request);
        }

        // A region change is a new reservation on the target region
        $targetRegionId = $request->input('knative_region_id') ?? $project->knative_region_id;
        $region = $targetRegionId === $project->knative_region_id
            ? $project->knativeRegion
            : KnativeRegion::find($targetRegionId);

        if (! $region || $region->region_mode === 'infinite') {
            return $next($request);
        }

        // NEW values from the request (project config, or a deploy's deploy_config overrides),
        // otherwise the stored config
        $pick = fn (string $key) => $request->input("config.{$key}")
            ?? $request->input("deploy_config.{$key}")
            ?? $config->{$key};

        $reservation = ResourceReservation::forConfig([
            'cpu' => $pick('cpu'),
            'memory_mb' => $pick('memory_mb'),
            'trigger_type' => $request->input('config.trigger_type') ?? $config->trigger_type,
            'min_scale' => $pick('min_scale'),
        ], $project->service_type, $region);

        if ($region->id === $project->knative_region_id) {
            $current = ResourceReservation::forProject($project, $region);

            if ($reservation['cpu_m'] <= $current['cpu_m'] && $reservation['memory_mb'] <= $current['memory_mb']) {
                return $next($request);
            }
        }

        // Exclude this project's current usage from the total
        return $this->respond($request, $next, $tenant, $region, $reservation, $project->id);
    }

    /**
     * @param  array{cpu_m: int, memory_mb: int}  $reservation
     */
    protected function respond(Request $request, Closure $next, Tenant $tenant, KnativeRegion $region, array $reservation, ?string $excludeProjectId = null): Response
    {
        $result = $this->quotaService->checkReservation($tenant, $region, $reservation, $excludeProjectId);

        if (! $result['allowed']) {
            return response()->json([
                'message' => $result['message'] ?? __('api.tenant.quota_exceeded'),
                'remaining_cpu_m' => $result['remaining_cpu_m'] ?? 0,
                'remaining_memory_mb' => $result['remaining_memory_mb'] ?? 0,
            ], 422);
        }

        return $next($request);
    }
}
