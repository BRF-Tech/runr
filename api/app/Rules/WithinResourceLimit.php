<?php

namespace App\Rules;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Ai\Models\ModelServiceReservations;
use App\Services\Helpers\InstanceSizes;
use App\Services\Helpers\ResourceReservation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A project's CPU / memory must fit the region capacity next to the other projects and the
 * running model services (ModelServiceReservations). Every
 * project counts with its admitted reservation — size × max(1, min_scale), queue-proxy per
 * Knative Service pod, queue consumer for queue projects (ResourceReservation).
 *
 * The check uses the effective size, also when the request has none: the value sent, else the
 * project's stored one (update / deploy), else the service type's default size — the size the
 * project is created and deployed with (InstanceSizes). The rule is implicit, so it runs for a
 * missing field too: a create or deploy without config.cpu / config.memory_mb is admitted like
 * one that sends the default (0.4.1 skipped the check — a test region reached 9100m used of 4505m).
 * A min_scale / trigger change or a region move without a size is checked the same way.
 *
 * An existing project (update / deploy) that does not raise its own reservation always
 * passes, even when the region is already over capacity.
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
     */
    public function __construct(
        private readonly string $resource,
        private readonly ?string $excludeProjectId = null,
        private readonly ?string $knativeRegionId = null,
        private readonly ?string $triggerType = null,
        private readonly mixed $minScale = null,
        private readonly ?string $serviceType = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $limit = $this->resolveLimit();

        // No limit defined: nothing to validate
        if ($limit <= 0) {
            return;
        }

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
        $region = $this->knativeRegionId ? KnativeRegion::find($this->knativeRegionId) : $project?->knativeRegion;
        $value = $this->effectiveValue($value, $config, $serviceType);

        $reservation = ResourceReservation::forSize(
            $this->resource === 'cpu' ? ResourceReservation::parseCpu($value) : 0,
            $this->resource === 'memory' ? (int) $value : 0,
            $this->triggerType ?? $config?->trigger_type ?? 'http',
            $this->minScale ?? $config?->min_scale ?? 0,
            ResourceReservation::podOverhead($region, $serviceType),
        );
        $incoming = $reservation[$this->key()];

        // Grandfathered: an existing project on this region that does not grow
        if ($project && $config && (! $this->knativeRegionId || $project->knative_region_id === $this->knativeRegionId)) {
            if ($incoming <= ResourceReservation::forProject($project)[$this->key()]) {
                return;
            }
        }

        $used = $this->usedWithoutProject();

        if ($used + $incoming > $limit) {
            $available = max(0, $limit - $used);
            $fail(match ($this->resource) {
                'cpu' => __('api.resource_limit.cpu_exceeded', ['available' => $available, 'limit' => $limit]),
                'memory' => __('api.resource_limit.memory_exceeded', ['available' => $available, 'limit' => $limit]),
            });
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

    private function key(): string
    {
        return $this->resource === 'cpu' ? 'cpu_m' : 'memory_mb';
    }

    private function resolveLimit(): int
    {
        if ($this->knativeRegionId) {
            $config = KnativeRegion::find($this->knativeRegionId);
            if (! $config) {
                return 0;
            }

            return $this->resource === 'cpu'
                ? (int) $config->total_cpu_m
                : (int) $config->total_memory_mb;
        }

        return $this->resource === 'cpu'
            ? (int) KnativeRegion::where('is_active', true)->sum('total_cpu_m')
            : (int) KnativeRegion::where('is_active', true)->sum('total_memory_mb');
    }

    private function usedWithoutProject(): int
    {
        $projects = Project::with('xaasConfig', 'faasConfig', 'containerConfig')
            ->when($this->excludeProjectId, fn ($q) => $q->where('id', '!=', $this->excludeProjectId))
            ->when($this->knativeRegionId, fn ($q) => $q->where('knative_region_id', $this->knativeRegionId))
            ->get();

        // Running model services of the region(s) reserve capacity too (docs/huggingface.md)
        $models = ModelServiceReservations::sumIn($this->knativeRegionId);

        return ResourceReservation::sum($projects)[$this->key()] + $models[$this->key()];
    }
}
