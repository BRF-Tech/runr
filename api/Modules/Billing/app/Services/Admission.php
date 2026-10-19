<?php

namespace Modules\Billing\Services;

use App\Models\Deployment;
use App\Models\Git\PreviewEnvironment;
use App\Models\Project;
use App\Services\Helpers\ResourceReservation;
use App\Services\Helpers\WorkloadSizing;
use App\Support\Projects\DeployIntent;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\Billing\Models\FixedRequest;
use Modules\Billing\Support\BillingPolicy;

/**
 * Fixed regions and the core admission (docs/billing.md#what-a-fixed-project-may-use,
 * BillingPolicy::FIXED_HARD_LIMIT), registered with App\Support\Projects\ProjectAdmission. In a
 * fixed region the allocation is a real limit: everything the project can run there must fit it at
 * its worst case — the production workload at max(1, min_scale, max_scale) pods with the queue-proxy
 * and RuntimeClass overhead (WorkloadSizing::peak, a deploy's overrides included) plus every live
 * preview environment at its maximum (WorkloadSizing::previewPeak), CPU, memory and GPUs. A Knative
 * Service needs a max_scale there (without one its worst case is unbounded).
 *
 *  - deploy guard: production deploys, resumes, region migrations, runtime test deploys and
 *    previews need an approved allocation and must fit it;
 *  - config limiter: a project update or runr.yaml change of an allocated project must fit it;
 *  - running(): what the project can reach with what runs now — a smaller request is refused
 *    (FixedRequests: "scale down first"), so usage above the allocation cannot happen and is never
 *    billed as overage.
 */
class Admission
{
    public function __construct(
        private readonly RegionTypes $types,
        private readonly FixedRequests $requests,
    ) {}

    /** @throws HttpResponseException */
    public function guardDeploy(Project $project, DeployIntent $intent): void
    {
        if (! BillingPolicy::fixedHardLimit()) {
            return;
        }

        $regionId = $project->knative_region_id;
        if (! $regionId || ! $this->types->isFixed($regionId)) {
            return;
        }

        $allocation = $this->requests->active((string) $project->id, $regionId);
        if (! $allocation) {
            $this->refuse('fixed_allocation_required', __('billing.fixed_allocation_required'));
        }

        $sizing = WorkloadSizing::of($project, $intent->overrides);
        if (WorkloadSizing::unbounded($sizing)) {
            $this->refuse('fixed_max_scale_required', __('billing.fixed_max_scale_required'));
        }

        // Previews run with the project's config — a production deploy's overrides are not theirs
        $previewSizing = $intent->overrides === [] ? $sizing : WorkloadSizing::of($project);
        $footprint = $intent->isPreview()
            ? WorkloadSizing::add($this->runningProduction($project), $this->previews($project, $previewSizing, $intent->previewId))
            : WorkloadSizing::add(WorkloadSizing::peak($sizing, $this->plannedOverhead($project)), $this->previews($project, $previewSizing));

        if ($message = $this->exceeded($footprint, $allocation)) {
            $this->refuse('fixed_allocation_exceeded', $message);
        }
    }

    /**
     * The refusal — code and message, as a refused deploy answers — when a config change would
     * take an allocated project in a fixed region above its allocation at its worst case; null
     * otherwise (no allocation yet: the deploy guard decides; a move to another region ends the
     * allocation).
     *
     * @param  array<string, mixed>  $config  the requested config fields
     * @return array{code: string, message: string}|null
     */
    public function limitConfig(Project $project, ?string $regionId, array $config): ?array
    {
        if (! BillingPolicy::fixedHardLimit() || ! $regionId || $regionId !== (string) $project->knative_region_id || ! $this->types->isFixed($regionId)) {
            return null;
        }

        $allocation = $this->requests->active((string) $project->id, $regionId);
        if (! $allocation instanceof FixedRequest) {
            return null;
        }

        $sizing = WorkloadSizing::of($project, $config);
        if (WorkloadSizing::unbounded($sizing)) {
            return ['code' => 'fixed_max_scale_required', 'message' => (string) __('billing.fixed_max_scale_required')];
        }

        $message = $this->exceeded(
            WorkloadSizing::add(WorkloadSizing::peak($sizing, $this->plannedOverhead($project)), $this->previews($project, $sizing)),
            $allocation,
        );

        return $message === null ? null : ['code' => 'fixed_allocation_exceeded', 'message' => $message];
    }

    /**
     * The worst case of what the project runs now: its live production deployment (with the size
     * it was applied with) and its live previews.
     *
     * @return array{cpu_m: int, memory_mb: int, gpus: int, pods: int}
     */
    public function running(Project $project): array
    {
        return WorkloadSizing::add($this->runningProduction($project), $this->previews($project, WorkloadSizing::of($project)));
    }

    /**
     * Whether a live deployment of the project in its region is a Knative Service without
     * max_scale (applied before the region became fixed, or with an older config): Knative may start
     * any number of pods, so no allocation can be checked against what runs.
     */
    public function runsUnbounded(Project $project): bool
    {
        $region = $project->knative_region_id !== null ? (string) $project->knative_region_id : null;

        return Deployment::query()->where('project_id', $project->id)->whereIn('status', Deployment::LIVE)->get()
            ->contains(function (Deployment $live) use ($project, $region) {
                $sizing = WorkloadSizing::ofDeployment($live, $project);

                return ($sizing['region_id'] ?? $region) === $region && WorkloadSizing::unbounded($sizing);
            });
    }

    /**
     * The refusal message when $footprint does not fit the allocation, null when it does.
     *
     * @param  array{cpu_m: int, memory_mb: int, gpus: int, pods: int}  $footprint
     */
    public function exceeded(array $footprint, FixedRequest $allocation): ?string
    {
        if ($footprint['cpu_m'] > $allocation->cpu_m || $footprint['memory_mb'] > $allocation->memory_mb) {
            return __('billing.fixed_allocation_exceeded', [
                'cpu' => $footprint['cpu_m'], 'memory' => $footprint['memory_mb'], 'pods' => $footprint['pods'],
                'allowed_cpu' => $allocation->cpu_m, 'allowed_memory' => $allocation->memory_mb,
            ]);
        }

        if ($footprint['gpus'] > $allocation->gpu_count) {
            return __('billing.fixed_gpus_exceeded', ['gpus' => $footprint['gpus'], 'allowed' => $allocation->gpu_count]);
        }

        return null;
    }

    /** @return array{cpu_m: int, memory_mb: int, gpus: int, pods: int} */
    private function runningProduction(Project $project): array
    {
        $live = Deployment::query()->where('project_id', $project->id)->whereIn('status', Deployment::LIVE)->latest('created_at')->first();
        if (! $live) {
            return WorkloadSizing::none();
        }

        $overhead = ResourceReservation::overheadFor($project->knativeRegion, $project->service_type, ResourceReservation::deployedClassOf($project));

        return WorkloadSizing::peak(WorkloadSizing::ofDeployment($live, $project), $overhead);
    }

    /**
     * Live previews of the project at their maximum; $deploying: the preview being deployed now,
     * counted once whatever its state.
     *
     * @param  array{region_id: string|null, cpu_m: int, memory_mb: int, trigger_type: string, min_scale: int, max_scale: int, scale_to_zero: bool, gpu_count: int}  $sizing
     * @return array{cpu_m: int, memory_mb: int, gpus: int, pods: int}
     */
    private function previews(Project $project, array $sizing, ?string $deploying = null): array
    {
        try {
            $count = PreviewEnvironment::query()->where('project_id', $project->id)
                ->whereIn('status', PreviewEnvironment::LIVE)
                ->when($deploying !== null, fn ($q) => $q->whereKeyNot($deploying))
                ->count();
        } catch (QueryException) {
            $count = 0; // git deploy tables not migrated
        }
        $count += $deploying !== null ? 1 : 0;

        $total = WorkloadSizing::none();
        $one = WorkloadSizing::previewPeak($sizing, ResourceReservation::podOverhead($project->knativeRegion, $project->service_type));
        for ($i = 0; $i < $count; $i++) {
            $total = WorkloadSizing::add($total, $one);
        }

        return $total;
    }

    /** RuntimeClass overhead per pod of the next deploy: the region's mapping for the service type. */
    private function plannedOverhead(Project $project): array
    {
        return ResourceReservation::podOverhead($project->knativeRegion, $project->service_type);
    }

    private function refuse(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code], 422));
    }
}
