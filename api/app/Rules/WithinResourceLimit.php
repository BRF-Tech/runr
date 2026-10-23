<?php

namespace App\Rules;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Helpers\InstanceSizes;
use App\Services\Helpers\RegionCapacity;
use App\Services\Helpers\ResourceReservation;
use App\Support\Projects\ScaleSchedule;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Collection;

/**
 * A project's CPU / memory must fit the region's budgets next to what already runs there
 * (RegionCapacity): an always-on project (scale to zero off or min_scale >= 1) reserves
 * (size + queue-proxy + Kata overhead) × min_scale against the real capacity; a scale-to-zero
 * project reserves only its Kata pod overhead (and queue consumer) there and counts one pod of
 * its size against the FaaS overcommit budget (capacity × the region's factor, minus the
 * always-on reservations). Model services and sandboxes count the same way.
 *
 * The check uses the effective size, also when the request has none: the value sent, else the
 * project's stored one (update / deploy), else the service type's default size — the size the
 * project is created and deployed with (InstanceSizes). The rule is implicit, so it runs for a
 * missing field too: a create or deploy without config.cpu / config.memory_mb is admitted like
 * one that sends the default (0.4.1 skipped the check — a test region reached 9100m used of 4505m).
 * A min_scale / scale_to_zero / trigger change or a region move without a size is checked the
 * same way. A timed warmer (config.scale_schedule) counts its peak in place of min_scale
 * (ScaleSchedule::reservedMinScale): the most containers it keeps warm at any time. The Kata
 * overhead of the incoming size is the region's mapping for the service type (the RuntimeClass
 * the next deploy gets).
 *
 * An existing project (update / deploy) that does not raise its own reservation always
 * passes, even when the region is already over capacity.
 *
 * Limits beyond the region capacity — the approved resources of a project in a fixed-price region
 * (Billing module) — are not checked here: ProjectAdmission's config limiters see a project update
 * as a whole (UpdateProjectRequest, runr.yaml) and its deploy guards every deploy with its
 * overrides.
 */
class WithinResourceLimit implements ValidationRule
{
    /** Also validated when the field is missing or null (the effective size is checked). */
    public bool $implicit = true;

    /**
     * @param  string  $resource  'cpu' | 'memory'
     * @param  string|null  $excludeProjectId  Update / deploy: the project itself, not counted as "used"
     * @param  string|null  $knativeRegionId  If set, only this region's limit is used
     * @param  string|null  $triggerType  Requested trigger; null = the project's stored one, else http
     * @param  mixed  $minScale  Requested min_scale; null = the project's stored one, else 0
     * @param  string|null  $serviceType  New project's service type; null = the project's (Kata pod overhead)
     * @param  mixed  $scaleToZero  Requested scale_to_zero; null = the project's stored one, else true
     * @param  mixed  $scaleSchedule  Requested timed warmer rows (null / [] = off); false = not in the request (the stored one)
     */
    public function __construct(
        private readonly string $resource,
        private readonly ?string $excludeProjectId = null,
        private readonly ?string $knativeRegionId = null,
        private readonly ?string $triggerType = null,
        private readonly mixed $minScale = null,
        private readonly ?string $serviceType = null,
        private readonly mixed $scaleToZero = null,
        private readonly mixed $scaleSchedule = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $regions = $this->regions();
        $limits = RegionCapacity::limits($regions);

        $project = $this->excludeProjectId
            ? Project::with('xaasConfig', 'faasConfig', 'containerConfig')->find($this->excludeProjectId)
            : null;
        $config = $project ? ResourceReservation::configOf($project) : null;
        // An existing project without a sizing config (the former AI types) deploys nothing
        if ($project && ! $config) {
            return;
        }

        $serviceType = $this->serviceType ?? $project?->service_type;
        // Kata pod overhead of the target region's mapping for this service type
        $region = $this->knativeRegionId ? $regions->first() : $project?->knativeRegion;
        $value = $this->effectiveValue($value, $config, $serviceType);

        $demand = ResourceReservation::demandForSize(
            $this->resource === 'cpu' ? ResourceReservation::parseCpu($value) : 0,
            $this->resource === 'memory' ? (int) $value : 0,
            $this->triggerType ?? $config?->trigger_type ?? 'http',
            $this->effectiveMinScale($config),
            $this->scaleToZero ?? ($config ? $config->scale_to_zero : null) ?? true,
            ResourceReservation::podOverhead($region, $serviceType),
        );

        // No limit defined: nothing to validate
        if ($limits['capacity'][$this->key()] <= 0) {
            return;
        }

        // Grandfathered: an existing project on this region whose reservation does not grow
        if ($project && (! $this->knativeRegionId || $project->knative_region_id === $this->knativeRegionId)
            && ! $this->grows($demand, ResourceReservation::demandForProject($project))) {
            return;
        }

        $usage = RegionCapacity::usage($this->knativeRegionId, $this->excludeProjectId);
        $shortfall = RegionCapacity::shortfalls($limits, $usage, $this->only($demand))[$this->key()] ?? null;

        if ($shortfall) {
            $fail(RegionCapacity::message($this->key(), $shortfall));
        }
    }

    /**
     * The size the project gets: the requested value, else the stored one, else the service
     * type's default (the same fallbacks as ProjectConfigTrait and ResourceReservation::forConfig).
     */
    private function effectiveValue(mixed $value, ?object $config, ?string $serviceType): mixed
    {
        if ($value !== null && $value !== '') {
            return $value;
        }

        $field = $this->resource === 'cpu' ? 'cpu' : 'memory_mb';
        $stored = $config?->{$field};

        return ($stored === null || $stored === '') ? InstanceSizes::defaultFor($serviceType)[$field] : $stored;
    }

    /**
     * The min_scale the project reserves with this change: the peak of the timed warmer it will
     * have (the requested one, else the stored one), else the requested min_scale, else the
     * stored one.
     */
    private function effectiveMinScale(?object $config): int
    {
        $schedule = $this->scaleSchedule === false
            ? ScaleSchedule::rows($config)
            : ScaleSchedule::normalize($this->scaleSchedule);
        if ($schedule !== null) {
            return ScaleSchedule::peak($schedule);
        }

        return max(0, (int) ($this->minScale ?? $config?->min_scale ?? 0));
    }

    /**
     * Whether the new demand needs more of this resource than the project holds now: more
     * always-on, or more in all.
     */
    private function grows(array $new, array $current): bool
    {
        $key = $this->key();

        return $new['hard'][$key] > $current['hard'][$key]
            || $new['hard'][$key] + $new['burst'][$key] > $current['hard'][$key] + $current['burst'][$key];
    }

    /** The demand of this rule's resource only (the other one has its own rule). */
    private function only(array $demand): array
    {
        $other = $this->resource === 'cpu' ? 'memory_mb' : 'cpu_m';
        $demand['hard'][$other] = 0;
        $demand['burst'][$other] = 0;

        return $demand;
    }

    private function key(): string
    {
        return $this->resource === 'cpu' ? 'cpu_m' : 'memory_mb';
    }

    /** The region checked against, or every active region. */
    private function regions(): Collection
    {
        if ($this->knativeRegionId) {
            return collect([KnativeRegion::find($this->knativeRegionId)])->filter()->values();
        }

        return KnativeRegion::where('is_active', true)->get();
    }
}
