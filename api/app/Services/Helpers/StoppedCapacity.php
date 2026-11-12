<?php

namespace App\Services\Helpers;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Observers\CacheInvalidationObserver;
use App\Services\Environments\EnvironmentReservations;
use App\Services\Environments\EnvironmentScope;
use App\Services\GitDeploy\TenantContext;
use App\Services\GlobalProjects\GlobalProjectRegions;
use App\Support\Projects\ProjectAdmission;
use App\Support\SchemaColumns;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\Tenancy\Services\TenantQuotaService;

/**
 * What a stopped project holds (docs/architecture.md → Region capacity and admission,
 * docs/billing.md#project-stop-and-resume):
 *
 *  - a pay-as-you-go region (every region while billing is off): the stopped project gives its
 *    capacity reservation back. Its own workload and its environments there count neither in the
 *    region's budgets (RegionCapacity::usage()) nor in its organization's quota
 *    (TenantQuotaService). RegionCapacity::stopped() is what they would hold again, the capacity
 *    endpoint shows it next to the budgets and the capacity alarm warns the region's provider
 *    when everything with the stopped projects back would cross the alarm's levels
 *    (ResourceThresholdService).
 *  - a fixed region (the Billing module, holdWhen()): the stopped project keeps its reservation,
 *    its allocation stays billed; its share of the organization's allocations there is lent to
 *    the organization's running projects (Modules\Billing\Services\Admission).
 *
 * A resume lifts the stop and marks the project (COLUMN, resumeAttributes()): it holds nothing of
 * that until a deploy admits it again. readmit() is the last check of
 * ProjectAdmission::assertDeployable(), so the resume's redeploy, a git push or a console deploy
 * that does not fit is refused — 422 capacity_exceeded (the region) or quota_exceeded (the
 * organization's quota); a resume answers it as redeploy_error / redeploy_code — and the project
 * stays resumed without a deployment, still holding nothing. Admitted, the mark is cleared and the
 * project holds its reservation again like any other, deployed or not.
 */
final class StoppedCapacity
{
    /** projects.capacity_released_at: resumed, not admitted again yet */
    public const COLUMN = 'capacity_released_at';

    /** The region's budgets have no room for the project's reservation */
    public const CAPACITY_EXCEEDED = 'capacity_exceeded';

    /** The organization's quota on the region has no room for it */
    public const QUOTA_EXCEEDED = 'quota_exceeded';

    /** @var (Closure(string): bool)|null */
    private static ?Closure $holds = null;

    /**
     * A module tells which regions keep a stopped project's reservation (the Billing module: fixed
     * regions, where the allocation stays billed). Null: none, every region releases it.
     *
     * @param  (Closure(string): bool)|null  $resolver  region id → whether it keeps the reservation
     */
    public static function holdWhen(?Closure $resolver): void
    {
        self::$holds = $resolver;
    }

    /** Whether $regionId keeps the reservation of a stopped project. */
    public static function holds(?string $regionId): bool
    {
        if ($regionId === null || $regionId === '' || self::$holds === null) {
            return false;
        }

        return (bool) (self::$holds)($regionId);
    }

    /** Whether the project gave back what it held: stopped, or resumed and not admitted since. */
    public static function awaitsAdmission(Project $project): bool
    {
        return $project->isStopped() || self::marked($project);
    }

    /** Resumed, not admitted again yet. */
    public static function marked(Project $project): bool
    {
        return $project->getAttribute(self::COLUMN) !== null;
    }

    /** Whether the project holds nothing of $regionId's capacity now ($regionId null: its own region). */
    public static function releases(Project $project, ?string $regionId = null): bool
    {
        return self::awaitsAdmission($project) && ! self::holds($regionId ?? $project->knative_region_id);
    }

    /**
     * The columns a resume sets next to lifting the stop: the project awaits admission (none on a
     * schema without the column, where a resume admits as before).
     *
     * @return array<string, mixed>
     */
    public static function resumeAttributes(Project $project): array
    {
        return SchemaColumns::has($project, self::COLUMN) ? [self::COLUMN => now()] : [];
    }

    /**
     * Admit a resumed project again before it is deployed: what it reserves in every region it runs
     * in (its own workload, a global project's copies, its environments) has to fit each region
     * that released it, and the organization's quota there. Admitted, it holds its reservation
     * again (the mark is cleared). A project that is not marked passes untouched.
     *
     * @throws HttpResponseException 422 {message, code: capacity_exceeded|quota_exceeded, region_id, …}
     */
    public static function readmit(Project $project): void
    {
        $base = EnvironmentScope::base($project);
        if (! self::marked($base)) {
            return;
        }

        if ($refusal = self::refusal($base)) {
            throw new HttpResponseException(response()->json($refusal, 422));
        }

        Project::query()->whereKey($base->getKey())->update([self::COLUMN => null]);
        foreach ([$base, $project] as $model) {
            $model->setAttribute(self::COLUMN, null);
            $model->syncOriginalAttribute(self::COLUMN);
        }
        // the region holds it again: its cached capacity answer and the project's go (ResponseCache)
        CacheInvalidationObserver::touched($base);
    }

    /**
     * Why a resumed project still waits (ProjectResource capacity_waiting, the project page's
     * banner), null when it does not wait: since when, and why its next deploy would be refused
     * now — a module's guard (a fixed region's allocation: fixed_allocation_exceeded), the region's
     * budgets (capacity_exceeded) or the organization's quota (quota_exceeded) — with what each
     * short resource needs, has free and lacks (shortfall). code null: it fits now, a deploy admits it.
     *
     * @return array{since: string|null, code: string|null, message: string|null, region_id: string|null, shortfall: array<string, array{needed: int, available: int, missing: int, budget?: string}>|null}|null
     */
    public static function waiting(Project $project): ?array
    {
        if ($project->isStopped() || ! self::marked($project)) {
            return null;
        }

        $refusal = ProjectAdmission::guardRefusal($project) ?? self::refusal($project);
        $since = $project->getAttribute(self::COLUMN);

        return [
            'since' => $since instanceof \DateTimeInterface ? $since->format(DATE_ATOM) : (is_string($since) ? $since : null),
            'code' => isset($refusal['code']) ? (string) $refusal['code'] : null,
            'message' => isset($refusal['message']) ? (string) $refusal['message'] : null,
            'region_id' => isset($refusal['region_id']) ? (string) $refusal['region_id'] : ($project->knative_region_id !== null ? (string) $project->knative_region_id : null),
            'shortfall' => is_array($refusal['shortfall'] ?? null) && $refusal['shortfall'] !== [] ? $refusal['shortfall'] : null,
        ];
    }

    /**
     * Why the project does not fit again, null when it does: the first region (that released it)
     * whose budgets or organization quota have no room for what it reserves there. shortfall: per
     * short resource what it needs, what the budget has free, what it lacks, and which budget.
     *
     * @return array{message: string, code: string, region_id: string, problems?: array<string, string>, shortfall?: array<string, array{budget: string, needed: int, available: int, missing: int}>, budget?: string, remaining_cpu_m?: int, remaining_memory_mb?: int}|null
     */
    public static function refusal(Project $project): ?array
    {
        foreach (self::demandByRegion($project) as $regionId => [$region, $demand]) {
            // A fixed region kept the reservation: the Billing module checks the allocation share
            if (self::holds($regionId)) {
                continue;
            }

            // usage() leaves the project out while it awaits admission
            $shortfalls = RegionCapacity::shortfalls(RegionCapacity::limits([$region]), RegionCapacity::usage($regionId), $demand);
            if ($shortfalls !== []) {
                $problems = [];
                foreach ($shortfalls as $key => $shortfall) {
                    $problems[$key] = RegionCapacity::message($key, $shortfall);
                }

                return [
                    'message' => (string) __('api.project.capacity_readmission', ['region' => $region->name, 'problems' => implode(' ', $problems)]),
                    'code' => self::CAPACITY_EXCEEDED,
                    'region_id' => $regionId,
                    'problems' => $problems,
                    'shortfall' => self::shortfallOf($shortfalls),
                ];
            }

            if ($quota = self::quotaRefusal($region, $demand)) {
                return $quota + ['region_id' => $regionId];
            }
        }

        return null;
    }

    /**
     * What the project reserves per region: its own workload in each region it runs in, and its
     * environments in theirs.
     *
     * @return array<string, array{0: KnativeRegion, 1: array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}}>
     */
    public static function demandByRegion(Project $project): array
    {
        $regions = [];
        $add = function (?KnativeRegion $region, array $demand) use (&$regions): void {
            if ($region === null) {
                return;
            }
            $id = (string) $region->getKey();
            $regions[$id] = [$region, ResourceReservation::addDemand($regions[$id][1] ?? ResourceReservation::noDemand(), $demand)];
        };

        foreach (GlobalProjectRegions::of($project, false) as $region) {
            $add($region, ResourceReservation::demandForProject($project, $region));
        }

        if (ProjectEnvironment::tablesReady()) {
            $environments = ProjectEnvironment::query()->where('project_id', $project->getKey())->where('is_primary', false)
                ->with('knativeRegion')->get();
            foreach ($environments as $environment) {
                $add($environment->knativeRegion ?? $project->knativeRegion, EnvironmentReservations::demand($project, $environment));
            }
        }

        return $regions;
    }

    /**
     * The organization's quota on the region (multi-tenant installs; its own regions and infinite
     * regions have none), the way CheckTenantQuota checks a new reservation.
     *
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}  $demand
     * @return array{message: string, code: string, budget?: string, remaining_cpu_m?: int, remaining_memory_mb?: int}|null
     */
    private static function quotaRefusal(KnativeRegion $region, array $demand): ?array
    {
        if (! TenantContext::enabled() || ! class_exists(TenantQuotaService::class) || $region->region_mode === 'infinite') {
            return null;
        }
        $tenant = TenantContext::find(TenantContext::currentId());
        if (! $tenant) {
            return null;
        }

        $result = app(TenantQuotaService::class)->checkReservation($tenant, $region, $demand);
        if ($result['allowed']) {
            return null;
        }

        return array_filter([
            'message' => (string) __('api.project.quota_readmission', ['region' => $region->name, 'message' => $result['message'] ?? __('api.tenant.quota_exceeded')]),
            'code' => self::QUOTA_EXCEEDED,
            'budget' => $result['budget'] ?? null,
            'remaining_cpu_m' => $result['remaining_cpu_m'] ?? null,
            'remaining_memory_mb' => $result['remaining_memory_mb'] ?? null,
            'shortfall' => isset($result['shortfalls']) ? self::shortfallOf($result['shortfalls']) : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Shortfalls of RegionCapacity::shortfalls() as the API shows them: per resource the budget,
     * what is needed, what it has free and what is missing.
     *
     * @param  array<string, array{budget: string, needed: int, available: int}>  $shortfalls
     * @return array<string, array{budget: string, needed: int, available: int, missing: int}>
     */
    private static function shortfallOf(array $shortfalls): array
    {
        $out = [];
        foreach ($shortfalls as $key => $shortfall) {
            $out[$key] = [
                'budget' => (string) $shortfall['budget'],
                'needed' => (int) $shortfall['needed'],
                'available' => (int) $shortfall['available'],
                'missing' => max(0, (int) $shortfall['needed'] - (int) $shortfall['available']),
            ];
        }

        return $out;
    }

    /** Test helper: no module keeps stopped reservations. */
    public static function reset(): void
    {
        self::$holds = null;
    }
}
