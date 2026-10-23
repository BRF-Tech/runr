<?php

namespace App\Http\Controllers\Api;

use App\Models\EnvironmentPromotion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\Environments\EnvironmentResolver;
use App\Services\Environments\Promotions;
use App\Support\Rbac\Rbac;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Promotion between environments (docs/environments.md#promotion): the version an environment runs
 * goes to the next one (or the one named) — same code version, same image, the target's own
 * configuration. GET …/promote shows what will change; POST requests it. Into an environment that
 * requires approval it waits for someone else with environments.approve, who approves or rejects
 * it (the approvals inbox: GET /environment-promotions?status=pending_approval).
 *
 * @tags Environments
 */
class PromotionController extends Controller
{
    /** The API document's shape of a config change (EnvironmentConfig::diff()): env vars, secrets and bindings by name */
    private const CHANGE_TYPE = 'array{field: string, from?: mixed, to?: mixed, added?: list<string>, removed?: list<string>, changed?: list<string>}';

    /** The API document's shape of a version (Promotions::versionSummary()) */
    private const VERSION_TYPE = 'array{id: string, number: int, label: string, commit_message: string|null, image: string|null, created_at: string|null}';

    /** The API document's shape of a side of the promotion (Promotions::side()) */
    private const SIDE_TYPE = 'array{environment: string, environment_id: string, region_id: string|null, deployment_id: string|null, status: string|null}';

    /** The API document's shape of a promotion (present()) */
    private const PROMOTION_TYPE = 'array{id: string, project_id: string, project: array{id: string, name: string, slug: string}|null, source: string, target: string, version: array{id: string, number: int, label: string}|null, image: string|null, status: "pending_approval"|"approved"|"deploying"|"deployed"|"failed"|"rejected"|"cancelled"|"superseded", status_message: string|null, diff: array{code: array{from: '.self::VERSION_TYPE.'|null, to: '.self::VERSION_TYPE.'|null, changes: bool}, config: list<'.self::CHANGE_TYPE.'>}|null, message: string|null, requested_by: string|null, decided_by: string|null, decided_at: string|null, decision_comment: string|null, deployment_id: string|null, created_at: string|null, can_approve: bool, can_cancel: bool}';

    public function __construct(private Promotions $promotions) {}

    /** What a promotion from {environment} to ?to= (default: the next environment) changes. */
    #[QueryParameter('to', 'The target environment (name or id); default: the next one in the pipeline', type: 'string')]
    #[OpenApiResponse(200, 'What the promotion changes: the code version, the target\'s configuration and how the target differs from the source', type: 'array{data: array{source: '.self::SIDE_TYPE.', target: '.self::SIDE_TYPE.', version: '.self::VERSION_TYPE.'|null, code: array{from: '.self::VERSION_TYPE.'|null, to: '.self::VERSION_TYPE.'|null, changes: bool}, config: list<'.self::CHANGE_TYPE.'>, environment_differences: list<'.self::CHANGE_TYPE.'>, requires_approval: bool, can_promote: bool, problem: string|null, can_approve: bool}}')]
    public function preview(Request $request, Project $project, string $environment): JsonResponse
    {
        [$source, $target] = $this->pair($request, $project, $environment);

        return response()->json(['data' => $this->promotions->preview($project, $source, $target) + [
            'can_approve' => $this->promotions->canApprove($request->user(), $project),
        ]]);
    }

    /** Request a promotion of {environment}'s live version to `to` (default: the next environment). */
    #[OpenApiResponse(202, 'The promotion: deploying, or waiting for an approver', type: 'array{data: '.self::PROMOTION_TYPE.'}')]
    #[OpenApiResponse(422, 'Nothing to promote, or a deploy check refused it', type: 'array{message: string, code?: string}')]
    public function promote(Request $request, Project $project, string $environment): JsonResponse
    {
        $request->validate([
            'to' => ['nullable', 'string', 'max:64'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);
        [$source, $target] = $this->pair($request, $project, $environment);

        $promotion = $this->promotions->request($project, $source, $target, $request->user(), $request->input('message'));

        return response()->json(['data' => $this->present($promotion, $request)], 202);
    }

    /** Promotions of the project, newest first (?status= filters). */
    #[QueryParameter('status', 'Only promotions in this status', type: '"pending_approval"|"approved"|"deploying"|"deployed"|"failed"|"rejected"|"cancelled"|"superseded"')]
    #[QueryParameter('limit', '1–200, default 50', type: 'int')]
    #[OpenApiResponse(200, 'The project\'s promotions, newest first', type: 'array{data: list<'.self::PROMOTION_TYPE.'>}')]
    public function index(Request $request, Project $project): JsonResponse
    {
        $promotions = EnvironmentPromotion::query()->where('project_id', $project->id)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', (string) $status))
            ->with('version')
            ->orderByDesc('created_at')
            ->limit(min(200, max(1, $request->integer('limit', 50))))
            ->get();

        return response()->json(['data' => $this->presentAll($promotions, $request)]);
    }

    /**
     * The approvals inbox: promotions of every project the user may see, ?status=pending_approval
     * (the default) — each says whether the user may approve it.
     */
    #[QueryParameter('status', 'A promotion status, or all; default pending_approval', type: 'string')]
    #[QueryParameter('limit', '1–200, default 50', type: 'int')]
    #[OpenApiResponse(200, 'Promotions of the projects the user may see, newest first', type: 'array{data: list<'.self::PROMOTION_TYPE.'>}')]
    public function inbox(Request $request): JsonResponse
    {
        $visible = app(Rbac::class)->visibleProjectIds($request->user());
        $status = (string) ($request->query('status') ?: EnvironmentPromotion::PENDING_APPROVAL);

        $promotions = EnvironmentPromotion::query()
            ->when($visible !== null, fn ($q) => $q->whereIn('project_id', $visible))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with(['version', 'project:id,name,slug'])
            ->orderByDesc('created_at')
            ->limit(min(200, max(1, $request->integer('limit', 50))))
            ->get();

        return response()->json(['data' => $this->presentAll($promotions, $request)]);
    }

    #[OpenApiResponse(200, 'The promotion', type: 'array{data: '.self::PROMOTION_TYPE.'}')]
    public function approve(Request $request, Project $project, string $promotion): JsonResponse
    {
        $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $row = $this->find($project, $promotion);

        return response()->json(['data' => $this->present($this->promotions->approve($row, $request->user(), $request->input('comment')), $request)]);
    }

    #[OpenApiResponse(200, 'The promotion', type: 'array{data: '.self::PROMOTION_TYPE.'}')]
    public function reject(Request $request, Project $project, string $promotion): JsonResponse
    {
        $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $row = $this->find($project, $promotion);

        return response()->json(['data' => $this->present($this->promotions->reject($row, $request->user(), $request->input('comment')), $request)]);
    }

    /** The requester withdraws a promotion that still waits (an approver may too). */
    #[OpenApiResponse(200, 'The promotion', type: 'array{data: '.self::PROMOTION_TYPE.'}')]
    public function cancel(Request $request, Project $project, string $promotion): JsonResponse
    {
        $row = $this->find($project, $promotion);
        if ((string) $row->requested_by !== (string) $request->user()?->getKey()) {
            Gate::authorize('environments.approve', $project);
        }

        return response()->json(['data' => $this->present($this->promotions->cancel($row, $request->user()), $request)]);
    }

    /** @return array{0: ProjectEnvironment, 1: ProjectEnvironment} source and target */
    private function pair(Request $request, Project $project, string $environment): array
    {
        $source = EnvironmentResolver::resolveRow($project, $environment);
        $to = $request->input('to', $request->query('to'));
        $target = $to !== null && $to !== ''
            ? EnvironmentResolver::resolveRow($project, $to, 'to')
            : $this->promotions->nextAfter($project, $source);

        if ($target === null) {
            $message = (string) __('environments.promote_last', ['environment' => $source->name]);
            abort(response()->json(['message' => $message, 'code' => 'promotion_not_possible', 'errors' => ['to' => [$message]]], 422));
        }

        return [$source, $target];
    }

    private function find(Project $project, string $id): EnvironmentPromotion
    {
        return EnvironmentPromotion::query()->where('project_id', $project->id)->with('version')->findOrFail($id);
    }

    /**
     * @param  Collection<int, EnvironmentPromotion>  $promotions
     * @return list<array<string, mixed>>
     */
    private function presentAll(Collection $promotions, Request $request): array
    {
        $names = ProjectEnvironment::query()
            ->whereIn('id', $promotions->pluck('source_environment_id')->merge($promotions->pluck('target_environment_id'))->filter()->unique()->values())
            ->pluck('name', 'id');

        return $promotions->map(fn (EnvironmentPromotion $p) => $this->present($p, $request, $names->all()))->values()->all();
    }

    /**
     * @param  array<string, string>|null  $names  environment id => name
     * @return array<string, mixed>
     */
    private function present(EnvironmentPromotion $promotion, Request $request, ?array $names = null): array
    {
        $name = function (?string $id) use ($names): string {
            if ($id === null) {
                return ProjectEnvironment::PRIMARY_NAME;
            }

            return $names[$id] ?? (string) (ProjectEnvironment::query()->whereKey($id)->value('name') ?? $id);
        };
        $user = $request->user();
        $project = $promotion->relationLoaded('project') ? $promotion->project : null;

        return [
            'id' => $promotion->id,
            'project_id' => $promotion->project_id,
            'project' => $project ? ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug] : null,
            'source' => $name($promotion->source_environment_id),
            'target' => $name($promotion->target_environment_id),
            'version' => $promotion->version ? ['id' => $promotion->version->id, 'number' => $promotion->version->number, 'label' => $promotion->version->label] : null,
            'image' => $promotion->image,
            'status' => $promotion->status,
            'status_message' => $promotion->status_message,
            'diff' => $promotion->diff,
            'message' => $promotion->message,
            'requested_by' => $promotion->requested_by_name,
            'decided_by' => $promotion->decided_by_name,
            'decided_at' => $promotion->decided_at?->toIso8601String(),
            'decision_comment' => $promotion->decision_comment,
            'deployment_id' => $promotion->deployment_id,
            'created_at' => $promotion->created_at?->toIso8601String(),
            'can_approve' => $promotion->status === EnvironmentPromotion::PENDING_APPROVAL
                && (string) $promotion->requested_by !== (string) $user?->getKey()
                && app(Rbac::class)->allows($user, 'environments.approve', $promotion->project_id),
            'can_cancel' => $promotion->status === EnvironmentPromotion::PENDING_APPROVAL
                && (string) $promotion->requested_by === (string) $user?->getKey(),
        ];
    }
}
