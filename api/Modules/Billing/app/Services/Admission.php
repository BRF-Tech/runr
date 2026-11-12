<?php

namespace Modules\Billing\Services;

use App\Models\Deployment;
use App\Models\Git\PreviewEnvironment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Environments\EnvironmentScope;
use App\Services\Helpers\ResourceReservation;
use App\Services\Helpers\StoppedCapacity;
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
 * and RuntimeClass overhead (WorkloadSizing::peak, a deploy's overrides included; a queue trigger's
 * consumer where queue consumers are charged, QueueConsumerCharging), every other
 * environment of the project that runs in the region (docs/environments.md: dev, staging, … each
 * with its live deployment's size) plus every live preview environment at its maximum
 * (WorkloadSizing::previewPeak), CPU, memory and GPUs. A Knative Service needs a max_scale there
 * (without one its worst case is unbounded). An environment's deploy is checked with the
 * environment's view (its region, its config): the allocation of the project in that region.
 *
 *  - deploy guard: production deploys, resumes, region migrations, runtime test deploys and
 *    previews need an approved allocation and must fit it;
 *  - config limiter: a project update or runr.yaml change of an allocated project must fit it;
 *  - running(): what the project can reach with what runs now — a smaller request is refused
 *    (FixedRequests: "scale down first"), so usage above the allocation cannot happen and is never
 *    billed as overage.
 *
 * The allocations cover the projects that run. A project that is stopped — or resumed and not
 * admitted again yet (StoppedCapacity) — runs nothing, and its allocation stays billed: its share
 * is lent to the organization's other projects in the region (lending()). A running project may
 * use, beyond its own allocation, what the organization's stopped projects there lend and its
 * other running projects do not use yet; its own allocation is always its own. A project that
 * comes back stops lending: its deploy (the resume's redeploy) has to fit its allocation minus
 * what the others still use of the lent shares, else fixed_allocation_exceeded, like any deploy.
 * The organization is the schema's own allocations (FixedRequest::own()): the installation on a
 * single-tenant install, the tenant, or the platform's own projects.
 */
class Admission
{
    public function __construct(
        private readonly RegionTypes $types,
        private readonly FixedRequests $requests,
        private readonly QueueConsumerCharging $consumers,
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
        $regionId = (string) $regionId;

        $allocation = $this->requests->active((string) $project->id, $regionId);
        if (! $allocation) {
            $this->refuse('fixed_allocation_required', __('billing.fixed_allocation_required'));
        }

        $sizing = WorkloadSizing::of($project, $intent->overrides);
        if (WorkloadSizing::unbounded($sizing)) {
            $this->refuse('fixed_max_scale_required', __('billing.fixed_max_scale_required'));
        }

        // Previews run with the project's config — a deploy's overrides (or an environment's) are not theirs
        $base = EnvironmentScope::base($project);
        $previewSizing = $intent->overrides === [] && ! $intent->isEnvironment() ? $sizing : WorkloadSizing::of($base);
        $previews = $this->previewsIn($base, $regionId, $previewSizing, $intent->isPreview() ? $intent->previewId : null);
        $footprint = $intent->isPreview()
            ? WorkloadSizing::add($this->runningIn($base, $regionId), $previews)
            // The environment being deployed at its new size, every other one of the project in the region as it runs
            : WorkloadSizing::add(
                WorkloadSizing::add(WorkloadSizing::peak($sizing, $this->plannedOverhead($project), $this->consumers->charged($regionId)), $this->runningIn($base, $regionId, $intent->environmentId ?? self::PRIMARY)),
                $previews,
            );

        // A resumed project that was not admitted again stops lending its share now
        $readmitting = StoppedCapacity::marked(EnvironmentScope::base($project));
        $lending = $this->lending($project, $regionId);
        if ($message = $this->exceededWithLending($footprint, $allocation, $lending, $readmitting)) {
            $this->refuse('fixed_allocation_exceeded', $message, ['region_id' => $regionId, 'shortfall' => $this->shortfall($footprint, $allocation, $lending, $readmitting)]);
        }
    }

    /**
     * Per resource the worst case needs above what the project may have (its allocation and what
     * is lent to it, allowance()): needed, available, missing — the resources that are short.
     *
     * @param  array{cpu_m: int, memory_mb: int, gpus: int, pods: int}  $footprint
     * @param  array{lent: array{cpu_m: int, memory_mb: int, gpus: int}, used: array{cpu_m: int, memory_mb: int, gpus: int}}  $lending
     * @return array<string, array{needed: int, available: int, missing: int}>
     */
    public function shortfall(array $footprint, FixedRequest $allocation, array $lending, bool $readmitting): array
    {
        $short = [];
        foreach ($this->allowance($allocation, $lending, $readmitting) as $key => $allowed) {
            if ($footprint[$key] > $allowed) {
                $short[$key] = ['needed' => $footprint[$key], 'available' => max(0, $allowed), 'missing' => $footprint[$key] - max(0, $allowed)];
            }
        }

        return $short;
    }

    /**
     * What the project may have at its worst case: its own allocation plus what is lent and not
     * used yet; coming back ($readmitting), minus what the others use of the lent shares beyond them.
     *
     * @param  array{lent: array{cpu_m: int, memory_mb: int, gpus: int}, used: array{cpu_m: int, memory_mb: int, gpus: int}}  $lending
     * @return array{cpu_m: int, memory_mb: int, gpus: int}
     */
    private function allowance(FixedRequest $allocation, array $lending, bool $readmitting): array
    {
        $allowed = [];
        foreach (['cpu_m' => (int) $allocation->cpu_m, 'memory_mb' => (int) $allocation->memory_mb, 'gpus' => (int) $allocation->gpu_count] as $key => $own) {
            $free = $lending['lent'][$key] - $lending['used'][$key];
            $allowed[$key] = $own + ($readmitting ? $free : max(0, $free));
        }

        return $allowed;
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

        $message = $this->exceededWithLending(
            WorkloadSizing::add(
                WorkloadSizing::add(WorkloadSizing::peak($sizing, $this->plannedOverhead($project), $this->consumers->charged($regionId)), $this->runningIn($project, $regionId, self::PRIMARY)),
                $this->previews($project, $sizing),
            ),
            $allocation,
            $this->lending($project, $regionId),
            false,
        );

        return $message === null ? null : ['code' => 'fixed_allocation_exceeded', 'message' => $message];
    }

    /**
     * The worst case of what the project runs now in its region: the live deployment of each of its
     * environments there (with the size it was applied with) and its live previews.
     *
     * @return array{cpu_m: int, memory_mb: int, gpus: int, pods: int}
     */
    public function running(Project $project): array
    {
        $regionId = $project->knative_region_id !== null ? (string) $project->knative_region_id : null;

        return WorkloadSizing::add(
            $regionId !== null ? $this->runningIn($project, $regionId) : WorkloadSizing::none(),
            $this->previews($project, WorkloadSizing::of($project)),
        );
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

    /**
     * The shares the organization's other projects with an allocation in the region lend the
     * project: lent — the allocations of those that run nothing because they were stopped (or
     * resumed and not admitted again, StoppedCapacity); used — what the running ones need above
     * their own allocation at their worst case (their live deployments and previews), out of
     * what is lent.
     *
     * @return array{lent: array{cpu_m: int, memory_mb: int, gpus: int}, used: array{cpu_m: int, memory_mb: int, gpus: int}}
     */
    public function lending(Project $project, string $regionId): array
    {
        $lending = ['lent' => self::noShare(), 'used' => self::noShare()];

        try {
            $allocations = FixedRequest::query()->own()->active()->where('region_id', $regionId)
                ->where('project_id', '!=', (string) EnvironmentScope::base($project)->getKey())
                ->orderByDesc('active_from')->get()->unique('project_id');
        } catch (QueryException) {
            return $lending;
        }
        if ($allocations->isEmpty()) {
            return $lending;
        }

        $projects = Project::query()->whereIn('id', $allocations->pluck('project_id'))->with('knativeRegion')->get()->keyBy('id');
        foreach ($allocations as $allocation) {
            $other = $projects->get($allocation->project_id);
            if (! $other instanceof Project) {
                continue;
            }
            $own = ['cpu_m' => (int) $allocation->cpu_m, 'memory_mb' => (int) $allocation->memory_mb, 'gpus' => (int) $allocation->gpu_count];

            if (StoppedCapacity::awaitsAdmission($other)) {
                $lending['lent'] = self::addShare($lending['lent'], $own);

                continue;
            }

            $runs = WorkloadSizing::add($this->runningIn($other, $regionId), $this->previewsIn($other, $regionId, WorkloadSizing::of($other)));
            foreach (array_keys($own) as $key) {
                $lending['used'][$key] += max(0, $runs[$key] - $own[$key]);
            }
        }

        return $lending;
    }

    /**
     * The refusal message when $footprint does not fit the allocation and what the organization's
     * stopped projects lend (lending()), null when it does. A project within its own allocation
     * always fits — unless it comes back ($readmitting) while the others use more of the lent
     * shares than the projects still stopped lend: then they use its own share.
     *
     * @param  array{cpu_m: int, memory_mb: int, gpus: int, pods: int}  $footprint
     * @param  array{lent: array{cpu_m: int, memory_mb: int, gpus: int}, used: array{cpu_m: int, memory_mb: int, gpus: int}}  $lending
     */
    public function exceededWithLending(array $footprint, FixedRequest $allocation, array $lending, bool $readmitting): ?string
    {
        $own = ['cpu_m' => (int) $allocation->cpu_m, 'memory_mb' => (int) $allocation->memory_mb, 'gpus' => (int) $allocation->gpu_count];
        $free = [];
        foreach (array_keys($own) as $key) {
            $free[$key] = $lending['lent'][$key] - $lending['used'][$key];
        }
        if ($this->shortfall($footprint, $allocation, $lending, $readmitting) === []) {
            return null;
        }

        // Nothing is lent or used: the project's own allocation decides
        if ($lending['lent'] === self::noShare() && $lending['used'] === self::noShare()) {
            return $this->exceeded($footprint, $allocation);
        }

        // Back from a stop within its own allocation: the others use its share
        if ($readmitting && $footprint['cpu_m'] <= $own['cpu_m'] && $footprint['memory_mb'] <= $own['memory_mb'] && $footprint['gpus'] <= $own['gpus']) {
            return __('billing.fixed_allocation_in_use', [
                'cpu' => max(0, -$free['cpu_m']), 'memory' => max(0, -$free['memory_mb']), 'gpus' => max(0, -$free['gpus']),
            ]);
        }

        return __('billing.fixed_allocation_exceeded_lent', [
            'cpu' => $footprint['cpu_m'], 'memory' => $footprint['memory_mb'], 'gpus' => $footprint['gpus'], 'pods' => $footprint['pods'],
            'allowed_cpu' => $own['cpu_m'], 'allowed_memory' => $own['memory_mb'], 'allowed_gpus' => $own['gpus'],
            'lent_cpu' => max(0, $free['cpu_m']), 'lent_memory' => max(0, $free['memory_mb']), 'lent_gpus' => max(0, $free['gpus']),
        ]);
    }

    /** @return array{cpu_m: int, memory_mb: int, gpus: int} */
    private static function noShare(): array
    {
        return ['cpu_m' => 0, 'memory_mb' => 0, 'gpus' => 0];
    }

    /**
     * @param  array{cpu_m: int, memory_mb: int, gpus: int}  $a
     * @param  array{cpu_m: int, memory_mb: int, gpus: int}  $b
     * @return array{cpu_m: int, memory_mb: int, gpus: int}
     */
    private static function addShare(array $a, array $b): array
    {
        return ['cpu_m' => $a['cpu_m'] + $b['cpu_m'], 'memory_mb' => $a['memory_mb'] + $b['memory_mb'], 'gpus' => $a['gpus'] + $b['gpus']];
    }

    /** Key of the project's own workload among its environments (runningIn's $except). */
    private const PRIMARY = 'primary';

    /**
     * The worst case of what the project's environments run in a region: per environment (the
     * project's own workload one of them) its newest live deployment whose region it is, at its
     * peak with the region's overhead. $except: the environment being deployed (its id, or
     * PRIMARY) — its new size is counted by the caller.
     *
     * @return array{cpu_m: int, memory_mb: int, gpus: int, pods: int}
     */
    private function runningIn(Project $project, string $regionId, ?string $except = null): array
    {
        $live = Deployment::query()->where('project_id', $project->id)->whereIn('status', Deployment::LIVE)
            ->orderByDesc('created_at')->orderByDesc('id')->get();

        $total = WorkloadSizing::none();
        $seen = [];
        $region = (string) $project->knative_region_id === $regionId ? $project->knativeRegion : KnativeRegion::query()->find($regionId);
        foreach ($live as $deployment) {
            $key = $deployment->isPrimary() ? self::PRIMARY : (string) $deployment->environment_id;
            if (isset($seen[$key]) || $key === $except) {
                continue;
            }
            $seen[$key] = true;

            $sizing = WorkloadSizing::ofDeployment($deployment, $project);
            if ((string) ($sizing['region_id'] ?? $project->knative_region_id) !== $regionId) {
                continue;
            }
            $class = $key === self::PRIMARY ? ResourceReservation::deployedClassOf($project) : null;
            // a queue-triggered environment's consumer, where the region charges queue consumers
            $total = WorkloadSizing::add($total, WorkloadSizing::peak($sizing, ResourceReservation::overheadFor($region, $project->service_type, $class), $this->consumers->charged($regionId)));
        }

        return $total;
    }

    /**
     * Previews of the project when $regionId is the project's region (previews run there),
     * nothing elsewhere.
     *
     * @param  array{region_id: string|null, cpu_m: int, memory_mb: int, trigger_type: string, min_scale: int, max_scale: int, scale_to_zero: bool, gpu_count: int}  $sizing
     * @return array{cpu_m: int, memory_mb: int, gpus: int, pods: int}
     */
    private function previewsIn(Project $project, string $regionId, array $sizing, ?string $deploying = null): array
    {
        return (string) $project->knative_region_id === $regionId ? $this->previews($project, $sizing, $deploying) : WorkloadSizing::none();
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

    /** @param  array<string, mixed>  $extra  more of the refusal (region_id, shortfall) */
    private function refuse(string $code, string $message, array $extra = []): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code] + $extra, 422));
    }
}
