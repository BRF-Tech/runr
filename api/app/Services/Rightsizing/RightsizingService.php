<?php

namespace App\Services\Rightsizing;

use App\Models\DeploymentSession;
use App\Models\Project;
use App\Models\ResourcePeak;
use App\Models\RightsizingRecommendation;
use App\Models\User;
use App\Models\Version;
use App\Rules\KubernetesCpu;
use App\Rules\WithinResourceLimit;
use App\Services\Helpers\WorkloadSizing;
use App\Services\ProjectService;
use App\Support\Projects\ProjectAdmission;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * Rightsizing recommendations of the current organization context (docs/monitoring.md#rightsizing):
 *
 *  - refresh(): every night, after PeakCollector read the days, each running project's window is
 *    compared with its size (SizeRecommender) and its recommendation stored with the evidence,
 *    what the change frees and — with billing — what it saves per month (Savings), and whether
 *    applying it would be refused (region capacity, a fixed allocation)
 *  - apply(): the recommended size goes into the project's config through the same checks and the
 *    same path as a manual edit (WithinResourceLimit, ProjectAdmission, a new version); like every
 *    config change it reaches the workload with the next deploy
 *  - dismiss(): hidden while the recommended size stays the same, or snoozed for a number of days
 *
 * A recommendation whose project changed size since it was calculated is stale: it is not shown or
 * applied, and the next run calculates it again.
 */
class RightsizingService
{
    public function __construct(private ProjectService $projects) {}

    /**
     * Calculate the recommendation of every running project again.
     *
     * @return int recommendations open after the run
     */
    public function refresh(?Carbon $today = null): int
    {
        if (! config('rightsizing.enabled', true)) {
            return 0;
        }

        Project::query()
            ->whereNull('stopped_at')
            ->whereHas('activeDeployment')
            ->with(['xaasConfig', 'faasConfig', 'containerConfig', 'knativeRegion', 'activeDeployment'])
            ->chunkById(200, function (Collection $projects) use ($today) {
                foreach ($projects as $project) {
                    try {
                        $this->store($project, $this->assess($project, $today));
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        // Projects that stopped running (stopped, no live deployment) have nothing to recommend
        RightsizingRecommendation::query()
            ->whereIn('status', [RightsizingRecommendation::OPEN, RightsizingRecommendation::SNOOZED])
            ->whereDoesntHave('project', fn ($q) => $q->whereNull('stopped_at')->whereHas('activeDeployment'))
            ->delete();

        return RightsizingRecommendation::query()->where('status', RightsizingRecommendation::OPEN)->count();
    }

    /**
     * What the project's window says now, from the stored peaks (no Prometheus): not_eligible with
     * the reason, collecting (how much data is there), right_sized or recommended.
     *
     * @return array{status: string, reason?: string, result?: array<string, mixed>, sizing?: array<string, mixed>}
     */
    public function assess(Project $project, ?Carbon $today = null): array
    {
        if (! config('rightsizing.enabled', true)) {
            return ['status' => 'not_eligible', 'reason' => 'disabled'];
        }
        if (! $project->isSupportedType()) {
            return ['status' => 'not_eligible', 'reason' => 'unsupported'];
        }
        if ($project->isStopped()) {
            return ['status' => 'not_eligible', 'reason' => 'stopped'];
        }
        if (! $project->activeDeployment) {
            return ['status' => 'not_eligible', 'reason' => 'not_running'];
        }

        $sizing = WorkloadSizing::of($project);
        if ($sizing['gpu_count'] > 0) {
            return ['status' => 'not_eligible', 'reason' => 'gpu'];
        }
        if (! PeakCollector::readable($project->knativeRegion)) {
            return ['status' => 'not_eligible', 'reason' => 'no_metrics'];
        }

        $today = ($today ?? now())->copy()->startOfDay();
        $from = $today->copy()->subDays(max(1, (int) config('rightsizing.window_days', 14)));
        $to = $today->copy()->subDay();

        $days = ResourcePeak::query()
            ->where('project_id', $project->id)
            ->whereDate('day', '>=', $from->toDateString())
            ->whereDate('day', '<=', $to->toDateString())
            ->orderBy('day')
            ->get()
            ->map(fn (ResourcePeak $p) => [
                'day' => $p->day->toDateString(),
                'cpu_m' => $p->cpu_m,
                'memory_mb' => $p->memory_mb,
                'memory_max_mb' => $p->memory_max_mb,
                'memory_p95_mb' => $p->memory_p95_mb,
                'cpu_max_m' => $p->cpu_max_m,
                'cpu_p95_m' => $p->cpu_p95_m,
                'throttled_ratio' => $p->throttled_ratio,
                'oom_kills' => $p->oom_kills,
                'samples' => $p->samples,
                'pods' => $p->pods,
            ])
            ->values()
            ->all();

        $sessions = DeploymentSession::query()
            ->where('project_id', $project->id)
            ->where('started_at', '>=', $from)
            ->where('started_at', '<', $today)
            ->count();

        $result = (new SizeRecommender)->recommend(
            ['cpu_m' => $sizing['cpu_m'], 'memory_mb' => $sizing['memory_mb'], 'always_on' => WorkloadSizing::isAlwaysOn($sizing)],
            $days,
            $sessions,
            $from->toDateString(),
            $to->toDateString(),
        );

        return ['status' => $result['status'], 'result' => $result, 'sizing' => $sizing];
    }

    /**
     * The project's recommendation as the members see it — null when there is none or it is stale
     * (the project's size changed since it was calculated).
     */
    public function current(Project $project): ?RightsizingRecommendation
    {
        $recommendation = RightsizingRecommendation::query()->where('project_id', $project->id)->first();
        if (! $recommendation) {
            return null;
        }
        if ($recommendation->status !== RightsizingRecommendation::APPLIED && self::stale($recommendation, $project)) {
            return null;
        }

        return $recommendation;
    }

    /**
     * The open recommendations (snoozed ones whose day has come included) of the given projects —
     * all of the context's when $projectIds is null — largest saving first, then most memory freed.
     *
     * @param  list<string>|null  $projectIds
     * @return Collection<int, RightsizingRecommendation>
     */
    public function open(?array $projectIds = null): Collection
    {
        return RightsizingRecommendation::query()
            ->where(fn ($q) => $q->where('status', RightsizingRecommendation::OPEN)
                ->orWhere(fn ($q) => $q->where('status', RightsizingRecommendation::SNOOZED)->where('snoozed_until', '<=', now())))
            ->when($projectIds !== null, fn ($q) => $q->whereIn('project_id', $projectIds))
            ->whereHas('project')
            ->with(['project.xaasConfig', 'project.faasConfig', 'project.containerConfig', 'project.knativeRegion'])
            ->get()
            ->filter(fn (RightsizingRecommendation $r) => ! self::stale($r, $r->project))
            ->sort(function (RightsizingRecommendation $a, RightsizingRecommendation $b) {
                $byMoney = [$a->monthly_savings === null ? 1 : 0, -($a->monthly_savings ?? 0)] <=> [$b->monthly_savings === null ? 1 : 0, -($b->monthly_savings ?? 0)];

                return $byMoney !== 0 ? $byMoney : (($b->evidence['freed']['memory_mb'] ?? 0) <=> ($a->evidence['freed']['memory_mb'] ?? 0));
            })
            ->values();
    }

    /**
     * Put the recommended size into the project's config — refused like a manual edit (422 with
     * the validation error or the admission code), 409 when the project changed size since.
     *
     * @throws HttpResponseException
     */
    public function apply(RightsizingRecommendation $recommendation, User $user): Version
    {
        $project = Project::query()->with(['xaasConfig', 'faasConfig', 'containerConfig', 'knativeRegion'])->findOrFail($recommendation->project_id);

        if (self::stale($recommendation, $project)) {
            throw new HttpResponseException(response()->json([
                'message' => __('rightsizing.stale'),
                'code' => 'rightsizing_stale',
            ], 409));
        }

        $sizing = WorkloadSizing::of($project);
        $config = self::configChange($sizing, ['cpu_m' => $recommendation->recommended_cpu_m, 'memory_mb' => $recommendation->recommended_memory_mb]);

        // The checks of a manual edit (UpdateProjectRequest): CPU quantity, region capacity, then
        // the limits of optional modules (a fixed-price region's allocation)
        Validator::make(['config' => $config], self::rules($project))->validate();
        ProjectAdmission::assertConfigFits($project, $project->knative_region_id !== null ? (string) $project->knative_region_id : null, $config);

        $message = self::versionMessage($recommendation);
        $userId = (string) $user->getKey();
        $version = match ($project->service_type) {
            'caas' => $this->projects->updateCaaSConfig($project, $config, $userId, $message),
            'xaas' => $this->projects->updateXaaSConfig($project, $config, $userId, $message),
            default => $this->projects->updateFaaSConfig($project, $config, $userId, $message),
        };

        $recommendation->update([
            'status' => RightsizingRecommendation::APPLIED,
            'decided_by' => $userId,
            'decided_at' => now(),
            'snoozed_until' => null,
            'applied_version_id' => $version->id,
        ]);

        return $version;
    }

    /**
     * Hide the recommendation: snoozed for $snoozeDays, else dismissed while the recommended size
     * stays the same.
     */
    public function dismiss(RightsizingRecommendation $recommendation, User $user, ?int $snoozeDays = null): RightsizingRecommendation
    {
        $decision = ['decided_by' => (string) $user->getKey(), 'decided_at' => now()];
        $recommendation->update($decision + ($snoozeDays !== null
            ? ['status' => RightsizingRecommendation::SNOOZED, 'snoozed_until' => now()->addDays($snoozeDays)]
            : ['status' => RightsizingRecommendation::DISMISSED, 'snoozed_until' => null]));

        return $recommendation;
    }

    /**
     * Whether the deployment that runs now has the recommended size (an applied recommendation
     * reaches the workload with the next deploy).
     */
    public static function deployed(RightsizingRecommendation $recommendation, Project $project): bool
    {
        $live = $project->activeDeployment;
        if (! $live) {
            return false;
        }
        $sizing = WorkloadSizing::ofDeployment($live, $project);

        return $sizing['cpu_m'] === $recommendation->recommended_cpu_m && $sizing['memory_mb'] === $recommendation->recommended_memory_mb;
    }

    /** The project's size changed since the recommendation was calculated. */
    public static function stale(RightsizingRecommendation $recommendation, Project $project): bool
    {
        $sizing = WorkloadSizing::of($project);

        return $sizing['cpu_m'] !== $recommendation->current_cpu_m || $sizing['memory_mb'] !== $recommendation->current_memory_mb;
    }

    /**
     * Keep the project's recommendation in step with its assessment. Nothing to recommend: an open
     * or snoozed recommendation goes, a dismissed or applied one stays as the record of the
     * decision. A recommendation:
     *  - none yet, open, applied: stored (open)
     *  - snoozed: updated, open again once its day has come
     *  - dismissed: updated and kept dismissed while the sizes stay the same, open again otherwise
     *
     * @param  array{status: string, result?: array<string, mixed>, sizing?: array<string, mixed>}  $assessment
     */
    private function store(Project $project, array $assessment): void
    {
        $row = RightsizingRecommendation::query()->where('project_id', $project->id)->first();

        if ($assessment['status'] !== 'recommended') {
            if ($row && in_array($row->status, [RightsizingRecommendation::OPEN, RightsizingRecommendation::SNOOZED], true)) {
                $row->delete();
            }

            return;
        }

        $result = $assessment['result'];
        $sizing = $assessment['sizing'];
        $recommended = ['cpu_m' => (int) $result['cpu_m'], 'memory_mb' => (int) $result['memory_mb']];
        $estimate = Savings::estimate($project, $sizing, $recommended);
        $money = $estimate['money'];

        $values = [
            'region_id' => $sizing['region_id'],
            'direction' => $result['direction'],
            'current_cpu_m' => $sizing['cpu_m'],
            'current_memory_mb' => $sizing['memory_mb'],
            'recommended_cpu_m' => $recommended['cpu_m'],
            'recommended_memory_mb' => $recommended['memory_mb'],
            'reasons' => $result['reasons'],
            'evidence' => $result['evidence'] + ['changes' => $result['changes'], 'freed' => $estimate['freed'], 'peak' => $estimate['peak']],
            'savings' => $money,
            'monthly_savings' => is_numeric($money['monthly'] ?? null) ? (float) $money['monthly'] : null,
            'currency' => is_string($money['currency'] ?? null) ? $money['currency'] : null,
            'blocked' => $this->admissionProblem($project, self::configChange($sizing, $recommended)),
            'computed_at' => now(),
        ];

        if (! $row) {
            RightsizingRecommendation::query()->create($values + ['project_id' => $project->id, 'status' => RightsizingRecommendation::OPEN]);

            return;
        }

        $reopen = ['status' => RightsizingRecommendation::OPEN, 'snoozed_until' => null, 'decided_by' => null, 'decided_at' => null, 'applied_version_id' => null];
        $sameSizes = $row->current_cpu_m === $values['current_cpu_m'] && $row->current_memory_mb === $values['current_memory_mb']
            && $row->recommended_cpu_m === $values['recommended_cpu_m'] && $row->recommended_memory_mb === $values['recommended_memory_mb'];

        $row->update(match ($row->status) {
            RightsizingRecommendation::SNOOZED => $row->snoozed_until !== null && $row->snoozed_until->isFuture() ? $values : $values + $reopen,
            RightsizingRecommendation::DISMISSED => $sameSizes ? $values : $values + $reopen,
            default => $values + $reopen,
        });
    }

    /**
     * Why applying the change would be refused now — the region capacity or a module's limit (a
     * fixed allocation) — null when it would be admitted.
     *
     * @param  array<string, mixed>  $config
     * @return array{code: string, message: string}|null
     */
    private function admissionProblem(Project $project, array $config): ?array
    {
        $validator = Validator::make(['config' => $config], self::rules($project));
        if ($validator->fails()) {
            return ['code' => 'resource_limit_exceeded', 'message' => (string) $validator->errors()->first()];
        }

        return ProjectAdmission::configRefusal($project, $project->knative_region_id !== null ? (string) $project->knative_region_id : null, $config);
    }

    /**
     * The size rules of UpdateProjectRequest for the project in its region.
     *
     * @return array<string, mixed>
     */
    private static function rules(Project $project): array
    {
        $regionId = $project->knative_region_id !== null ? (string) $project->knative_region_id : null;

        return [
            'config.memory_mb' => ['nullable', 'integer', 'min:64', new WithinResourceLimit('memory', $project->id, $regionId)],
            'config.cpu' => ['bail', 'nullable', 'string', new KubernetesCpu, new WithinResourceLimit('cpu', $project->id, $regionId)],
        ];
    }

    /**
     * The config fields that change: cpu as millicores ("250m"), memory_mb.
     *
     * @param  array{cpu_m: int, memory_mb: int}  $current
     * @param  array{cpu_m: int, memory_mb: int}  $recommended
     * @return array<string, int|string>
     */
    private static function configChange(array $current, array $recommended): array
    {
        $config = [];
        if ($recommended['cpu_m'] !== $current['cpu_m']) {
            $config['cpu'] = $recommended['cpu_m'].'m';
        }
        if ($recommended['memory_mb'] !== $current['memory_mb']) {
            $config['memory_mb'] = $recommended['memory_mb'];
        }

        return $config;
    }

    /** Message of the version an applied recommendation saves (kept in the project's history). */
    private static function versionMessage(RightsizingRecommendation $r): string
    {
        $parts = [];
        if ($r->recommended_memory_mb !== $r->current_memory_mb) {
            $parts[] = "memory {$r->current_memory_mb} MB → {$r->recommended_memory_mb} MB";
        }
        if ($r->recommended_cpu_m !== $r->current_cpu_m) {
            $parts[] = "CPU {$r->current_cpu_m}m → {$r->recommended_cpu_m}m";
        }

        return 'Rightsizing: '.implode(', ', $parts);
    }
}
