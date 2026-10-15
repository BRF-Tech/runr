<?php

namespace Modules\Tenancy\Services;

use Modules\Tenancy\Models\CostConfig;

/**
 * Calculates costs for scale-to-zero (per-session) and custom-scale (per-day) deployments.
 *
 * Scale-to-Zero formula:
 *   session_cost = (cpu_mc × duration_ms × cpu_price + memory_mib × duration_ms × memory_price) × multiplier
 *
 * Custom Scale (always-on) formula:
 *   daily_cost = (min_scale × cpu_mc × 86_400_000 × cpu_price + min_scale × memory_mib × 86_400_000 × memory_price) × multiplier
 *
 * GPUs (docs/ai.md#quotas-and-billing): GPU-unit milliseconds (GPUs × ms) × gpu_price_per_unit_ms
 * are added to the raw cost — sessions × the project's GPUs, min_scale × GPUs × a day for custom
 * scale, metered pod time for model services.
 */
class CostCalculationService
{
    /**
     * Calculate cost for a scale-to-zero session.
     */
    public function calculateSessionCost(
        int $cpuMcMs,
        int $memoryMibMs,
        string $entryPoint,
        ?string $regionId = null,
        int $gpuUnitMs = 0,
    ): array {
        $config = CostConfig::forRegion($regionId);
        $multiplier = $config->getMultiplier($entryPoint);

        $cpuCost = $cpuMcMs * (float) $config->cpu_price_per_mc_ms;
        $memoryCost = $memoryMibMs * (float) $config->memory_price_per_mib_ms;
        $rawCost = $cpuCost + $memoryCost + $this->gpuCost($gpuUnitMs, $config);
        $finalCost = $rawCost * $multiplier;

        return [
            'raw_cost' => round($rawCost, 6),
            'multiplier' => $multiplier,
            'final_cost' => round($finalCost, 6),
            'currency' => $config->effectiveCurrency(),
        ];
    }

    /**
     * Calculate daily cost for a custom-scale (always-on) deployment.
     */
    public function calculateDailyCost(
        int $minScale,
        int $cpuMc,
        int $memoryMb,
        string $entryPoint,
        ?string $regionId = null,
        int $gpuCount = 0,
    ): array {
        $config = CostConfig::forRegion($regionId);
        $multiplier = $config->getMultiplier($entryPoint);
        $msPerDay = 86_400_000;

        $cpuCost = $minScale * $cpuMc * $msPerDay * (float) $config->cpu_price_per_mc_ms;
        $memoryCost = $minScale * $memoryMb * $msPerDay * (float) $config->memory_price_per_mib_ms;
        $rawCost = $cpuCost + $memoryCost + $this->gpuCost($minScale * $gpuCount * $msPerDay, $config);
        $finalCost = $rawCost * $multiplier;

        return [
            'raw_cost' => round($rawCost, 6),
            'multiplier' => $multiplier,
            'final_cost' => round($finalCost, 6),
            'currency' => $config->effectiveCurrency(),
        ];
    }

    /** GPU-unit milliseconds (GPUs × ms) × the region's GPU price. */
    public function gpuCost(int $gpuUnitMs, ?CostConfig $config = null, ?string $regionId = null): float
    {
        if ($gpuUnitMs <= 0) {
            return 0.0;
        }

        $config ??= CostConfig::forRegion($regionId);

        return $gpuUnitMs * (float) $config->gpu_price_per_unit_ms;
    }
}
