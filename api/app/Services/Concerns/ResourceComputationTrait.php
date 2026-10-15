<?php

namespace App\Services\Concerns;

use App\Models\KnativeRegion;
use App\Services\Ai\Models\ModelServiceReservations;
use App\Services\Helpers\ResourceReservation;
use Illuminate\Support\Collection;

/**
 * Region usage (region cards, capacity alerts): the sum of the projects' admitted reservations
 * — the same formula as tenant quota admission and region capacity validation
 * (ResourceReservation).
 */
trait ResourceComputationTrait
{
    protected function computeUsedCpu(Collection $projects): int
    {
        return ResourceReservation::sum($projects)['cpu_m'];
    }

    protected function computeUsedMemory(Collection $projects): int
    {
        return ResourceReservation::sum($projects)['memory_mb'];
    }

    /**
     * Running model services of a region (ModelServiceReservations): region usage and capacity
     * alerts count them next to the projects.
     *
     * @return array{cpu_m: int, memory_mb: int}
     */
    protected function modelServiceUsage(?KnativeRegion $region): array
    {
        return $region ? ModelServiceReservations::sum($region) : ResourceReservation::zero();
    }

    protected function parseCpu(mixed $value): int
    {
        return ResourceReservation::parseCpu($value);
    }
}
