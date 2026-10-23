<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ProjectResource;
use App\Http\Resources\RightsizingRecommendationResource;
use App\Models\Project;
use App\Models\RightsizingRecommendation;
use App\Services\Concerns\BroadcastsModelEvents;
use App\Services\Rightsizing\RightsizingService;
use App\Support\Rbac\EnvVisibility;
use App\Support\Rbac\Rbac;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rightsizing recommendations (docs/monitoring.md#rightsizing): the organization's open ones
 * (metrics.view on the projects), one project's with the state of its data, and applying (the
 * size goes into the config like a manual edit — projects.update) or dismissing / snoozing one.
 */
class RightsizingController extends Controller
{
    use BroadcastsModelEvents;

    public function __construct(private RightsizingService $rightsizing) {}

    /**
     * GET /api/rightsizing — the open recommendations of the projects the user may read the
     * metrics of, largest monthly saving first (then the most memory freed), with their totals.
     */
    #[OpenApiResponse(200, 'The open recommendations and their totals (monthly_savings and currency: null without billing)', type: 'array{data: list<RightsizingRecommendationResource>, summary: array{count: int, monthly_savings: float|null, currency: string|null, freed_memory_mb: int, freed_cpu_m: int}}')]
    public function index(Request $request): JsonResponse
    {
        $visible = app(Rbac::class)->visibleProjectIds($request->user(), 'metrics.view');
        $open = $this->rightsizing->open($visible);

        $currencies = $open->pluck('currency')->filter()->unique()->values();
        $saving = $open->filter(fn (RightsizingRecommendation $r) => $r->monthly_savings !== null && $r->monthly_savings > 0);

        return response()->json([
            'data' => RightsizingRecommendationResource::collection($open)->resolve($request),
            'summary' => [
                'count' => $open->count(),
                // one currency per installation; null without billing
                'monthly_savings' => $currencies->count() === 1 ? round((float) $saving->sum('monthly_savings'), 2) : null,
                'currency' => $currencies->count() === 1 ? $currencies->first() : null,
                'freed_memory_mb' => (int) $open->sum(fn (RightsizingRecommendation $r) => max(0, (int) ($r->evidence['freed']['memory_mb'] ?? 0))),
                'freed_cpu_m' => (int) $open->sum(fn (RightsizingRecommendation $r) => max(0, (int) ($r->evidence['freed']['cpu_m'] ?? 0))),
            ],
        ]);
    }

    /**
     * GET /api/projects/{project}/rightsizing — the project's recommendation, or why there is none:
     *
     *   status  open | snoozed | dismissed | applied (data: the recommendation)
     *           collecting (collecting: days and sessions so far and needed) | right_sized
     *           not_eligible (reason: gpu | stopped | not_running | unsupported | no_metrics | disabled)
     *
     * pending_deploy: an applied size not yet running (it takes effect with the next deploy).
     */
    #[OpenApiResponse(200, 'The project\'s recommendation, or why there is none', type: 'array{status: "open"|"snoozed"|"dismissed"|"applied"|"collecting"|"right_sized"|"not_eligible", reason: "gpu"|"stopped"|"not_running"|"unsupported"|"no_metrics"|"disabled"|null, data: RightsizingRecommendationResource|null, collecting: array{days: int, required_days: int, sessions: int, required_sessions: int, window_days: int}|null, pending_deploy: bool}')]
    public function show(Request $request, Project $project): JsonResponse
    {
        $project->load(['xaasConfig', 'faasConfig', 'containerConfig', 'knativeRegion', 'activeDeployment']);

        $recommendation = $this->rightsizing->current($project);
        $assessment = $this->rightsizing->assess($project);

        $status = $recommendation
            ? ($recommendation->isVisible() ? RightsizingRecommendation::OPEN : $recommendation->status)
            : $assessment['status'];

        return response()->json([
            'status' => $status,
            'reason' => $recommendation ? null : ($assessment['reason'] ?? null),
            'data' => $recommendation ? (new RightsizingRecommendationResource($recommendation))->resolve($request) : null,
            'collecting' => ! $recommendation && $assessment['status'] === 'collecting' ? $assessment['result']['collecting'] : null,
            'pending_deploy' => $recommendation?->status === RightsizingRecommendation::APPLIED
                && ! RightsizingService::deployed($recommendation, $project),
        ]);
    }

    /**
     * POST /api/projects/{project}/rightsizing/apply — the open recommendation's size becomes the
     * project's config (a new version; the next deploy runs it). 404 without an open
     * recommendation, 409 when the project changed size since, 422 when the region capacity or a
     * fixed allocation refuses it (as a manual edit).
     */
    #[OpenApiResponse(200, 'The recommendation, applied, and the new version that carries its size', type: 'array{data: RightsizingRecommendationResource, version: array{id: string, number: int, label: string}, deploy_required: bool, message: string}')]
    public function apply(Request $request, Project $project): JsonResponse
    {
        $project->load(['xaasConfig', 'faasConfig', 'containerConfig']);
        $recommendation = $this->rightsizing->current($project);
        if (! $recommendation || ! $recommendation->isVisible()) {
            return response()->json(['message' => __('rightsizing.none'), 'code' => 'rightsizing_none'], 404);
        }

        $version = $this->rightsizing->apply($recommendation, $request->user());

        // deleted while the request ran: 404, as for an unknown project
        $fresh = $project->fresh([
            'xaasConfig', 'faasConfig', 'containerConfig',
            'aaasConfig', 'agentFlowConfig', 'maasConfig',
            'knativeRegion', 'latestVersion', 'latestDeployment.version',
            'gatewayRoute.gateway', 'creator:id,name', 'owner:id,name,username',
        ]) ?? throw $project->notFound();
        try {
            $this->broadcastModel('project.updated', ['project' => EnvVisibility::withoutEnv(fn () => (new ProjectResource($fresh))->resolve())]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'data' => (new RightsizingRecommendationResource($recommendation->fresh()))->resolve($request),
            'version' => ['id' => $version->id, 'number' => $version->number, 'label' => $version->label],
            'deploy_required' => true,
            'message' => __('rightsizing.applied', ['version' => $version->label]),
        ]);
    }

    /**
     * POST /api/projects/{project}/rightsizing/dismiss — hide the open recommendation: with
     * snooze_days for that many days, without while the recommended size stays the same.
     */
    #[OpenApiResponse(200, 'The recommendation, dismissed or snoozed', type: 'array{data: RightsizingRecommendationResource, message: string}')]
    public function dismiss(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'snooze_days' => 'nullable|integer|min:1|max:365',
        ]);

        $project->load(['xaasConfig', 'faasConfig', 'containerConfig']);
        $recommendation = $this->rightsizing->current($project);
        if (! $recommendation || ! in_array($recommendation->status, [RightsizingRecommendation::OPEN, RightsizingRecommendation::SNOOZED], true)) {
            return response()->json(['message' => __('rightsizing.none'), 'code' => 'rightsizing_none'], 404);
        }

        $snooze = isset($data['snooze_days']) ? (int) $data['snooze_days'] : null;
        $recommendation = $this->rightsizing->dismiss($recommendation, $request->user(), $snooze);

        return response()->json([
            'data' => (new RightsizingRecommendationResource($recommendation))->resolve($request),
            'message' => $snooze !== null ? __('rightsizing.snoozed', ['days' => $snooze]) : __('rightsizing.dismissed'),
        ]);
    }
}
