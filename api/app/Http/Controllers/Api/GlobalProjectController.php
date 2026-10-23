<?php

namespace App\Http\Controllers\Api;

use App\Jobs\GlobalDns\SyncGlobalZoneJob;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectRegion;
use App\Services\GlobalProjects\GlobalFailover;
use App\Services\GlobalProjects\GlobalProjectService;
use App\Services\GlobalProjects\GlobalReplicaDeployer;
use App\Services\GlobalProjects\GlobalRouteService;
use App\Services\Residency\RegionPolicy;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The regions of a global project (docs/global-projects.md):
 *
 *   GET  /projects/{project}/global       mode, host name, every region's copy (with a health check)
 *   PUT  /projects/{project}/global       mode, primary, regions
 *   POST /projects/{project}/global/sync  the active version rolled out again where a region is not
 *                                         ready, the routes applied again
 */
class GlobalProjectController extends Controller
{
    /** The API document's shape of a global project (GlobalProjectService::summary()) */
    private const GLOBAL_TYPE = 'array{mode: "ha"|"proximity", failover: "automatic"|"manual", failover_after_seconds: int, moving_to: string|null, hostname: string|null, primary_region_id: string, regions: list<array{region_id: string, region_name: string|null, role: "primary"|"secondary", position: int, status: "pending"|"building"|"deploying"|"ready"|"failed"|"removing"|"fenced", status_message: string|null, version: int|null, target_version: int|null, healthy: bool|null, health_checked_at: string|null, address: string|null, in_dns: bool, triggers_active: bool, data_status: string|null, data_message: string|null, unhealthy_since: string|null, fenced_at: string|null}>, bindings: list<array{binding_id: string, name: string, type: string, plan: string|null, home_region_id: string|null, home_region_name: string|null, access: "direct"|"link"|"replicas", regions: list<array{region_id: string, region_name: string|null, kind: string, status: string, status_message: string|null, lag_seconds: int|null, checked_at: string|null}>}>, warnings: list<string>}';

    public function __construct(private readonly GlobalProjectService $global) {}

    #[OpenApiResponse(200, 'The global project: mode, failover, host name, every region\'s copy with a health check, its bindings across the regions', type: self::GLOBAL_TYPE)]
    public function show(Project $project): JsonResponse
    {
        $this->assertGlobal($project);

        return response()->json($this->global->summary($project, true));
    }

    #[OpenApiResponse(200, 'The global project after the change', type: self::GLOBAL_TYPE)]
    public function update(Request $request, Project $project): JsonResponse
    {
        $this->assertGlobal($project);

        $data = $request->validate([
            'mode' => ['sometimes', 'string', 'in:'.implode(',', Project::GLOBAL_MODES)],
            'failover' => ['sometimes', 'string', 'in:'.implode(',', Project::GLOBAL_FAILOVERS)],
            'primary_region_id' => ['sometimes', 'string', 'max:26'],
            'region_ids' => ['sometimes', 'array'],
            'region_ids.*' => ['string', 'max:26'],
        ]);

        if ($errors = $this->global->updateErrors($project, $data)) {
            throw ValidationException::withMessages(array_map(fn (string $m) => [$m], $errors));
        }

        // Data residency: a new primary is a project move (the regions are checked as global regions)
        if (isset($data['primary_region_id']) && $data['primary_region_id'] !== $project->knative_region_id) {
            app(RegionPolicy::class)->authorize($data['primary_region_id'], RegionPolicy::PROJECT_MOVE);
        }

        $this->global->update($project, $data);

        return response()->json($this->global->summary($project->fresh() ?? $project));
    }

    /**
     * Failover of the primary to another region (docs/global-projects.md, "Failover"): for a
     * primary that does not answer — the target's replica clusters are promoted at once, the old
     * primary is fenced until it rejoins. region_id: the target, else the first healthy region in
     * HA order. A primary that answers moves with PUT primary_region_id (a switchover).
     */
    #[OpenApiResponse(202, 'The failover started (moving_to: the region the primary goes to)', type: self::GLOBAL_TYPE)]
    public function failover(Request $request, Project $project): JsonResponse
    {
        $this->assertGlobal($project);
        $data = $request->validate(['region_id' => ['sometimes', 'nullable', 'string', 'max:26']]);
        $failover = app(GlobalFailover::class);

        $regionId = $data['region_id'] ?? null;
        if ($errors = $failover->failoverErrors($project, $regionId)) {
            throw ValidationException::withMessages(array_map(fn (string $m) => [$m], $errors));
        }
        $target = $regionId !== null
            ? KnativeRegion::query()->findOrFail($regionId)
            : $failover->target($project, $project->projectRegions()->get()->all())?->knativeRegion;
        abort_if($target === null, 422, __('api.global.failover_no_target'));
        app(RegionPolicy::class)->authorize((string) $target->getKey(), RegionPolicy::PROJECT_MOVE);

        $failover->start($project, $target, 'failover');

        return response()->json($this->global->summary($project->fresh() ?? $project), 202);
    }

    public function sync(Project $project): JsonResponse
    {
        $this->assertGlobal($project);

        /** @var Deployment|null $active */
        $active = $project->activeDeployment()->first();
        if ($active && ! $project->isStopped()) {
            $deployer = app(GlobalReplicaDeployer::class);
            foreach ($project->projectRegions()->get() as $row) {
                /** @var ProjectRegion $row */
                // a region on its way out, or fenced until it rejoins (GlobalFailover)
                if (in_array($row->status, [ProjectRegion::STATUS_REMOVING, ProjectRegion::STATUS_FENCED], true)) {
                    continue;
                }
                $primary = (string) $row->knative_region_id === (string) $project->knative_region_id;
                if (! $primary && ($row->status !== ProjectRegion::STATUS_READY || $row->served_version_id !== $active->version_id)) {
                    $deployer->startRollout($project, $row, $active);

                    continue;
                }
                try {
                    if ($row->knativeRegion) {
                        app(GlobalRouteService::class)->apply($project, $row->knativeRegion);
                    }
                } catch (Throwable $e) {
                    $row->update(['status_message' => mb_substr($e->getMessage(), 0, 2000)]);
                }
            }
        }

        SyncGlobalZoneJob::dispatch();

        return response()->json(['queued' => true], 202);
    }

    private function assertGlobal(Project $project): void
    {
        abort_unless($project->isGlobal(), 404, __('api.global.not_global'));
    }
}
