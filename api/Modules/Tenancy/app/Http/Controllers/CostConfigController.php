<?php

namespace Modules\Tenancy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Tenancy\Models\CostConfig;

class CostConfigController
{
    public function index(): JsonResponse
    {
        $configs = CostConfig::with('region:id,name')->get();

        return response()->json($configs);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'region_id' => 'nullable|exists:knative_regions,id',
            'cpu_price_per_mc_ms' => 'numeric|min:0',
            'memory_price_per_mib_ms' => 'numeric|min:0',
            // GPU-unit millisecond (GPUs × ms) price, docs/ai.md#quotas-and-billing
            'gpu_price_per_unit_ms' => 'numeric|min:0',
            'cpu_price_per_core_month' => 'numeric|min:0',
            'memory_price_per_gb_month' => 'numeric|min:0',
            'multiplier_http' => 'numeric|min:0',
            'multiplier_queue' => 'numeric|min:0',
            'multiplier_schedule' => 'numeric|min:0',
            'kv_price_per_gb_hour' => 'numeric|min:0',
            'object_price_per_gb_hour' => 'numeric|min:0',
            'postgres_price_per_gb_hour' => 'numeric|min:0',
            'postgres_instance_price_per_hour' => 'numeric|min:0',
            'currency' => 'string|size:3',
        ]);

        // Deactivate previous configs for the same scope
        CostConfig::where('region_id', $data['region_id'] ?? null)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $config = CostConfig::create($data + ['is_active' => true]);

        return response()->json($config->load('region:id,name'), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $config = CostConfig::findOrFail($id);

        $data = $request->validate([
            'cpu_price_per_mc_ms' => 'numeric|min:0',
            'memory_price_per_mib_ms' => 'numeric|min:0',
            // GPU-unit millisecond (GPUs × ms) price, docs/ai.md#quotas-and-billing
            'gpu_price_per_unit_ms' => 'numeric|min:0',
            'cpu_price_per_core_month' => 'numeric|min:0',
            'memory_price_per_gb_month' => 'numeric|min:0',
            'multiplier_http' => 'numeric|min:0',
            'multiplier_queue' => 'numeric|min:0',
            'multiplier_schedule' => 'numeric|min:0',
            'kv_price_per_gb_hour' => 'numeric|min:0',
            'object_price_per_gb_hour' => 'numeric|min:0',
            'postgres_price_per_gb_hour' => 'numeric|min:0',
            'postgres_instance_price_per_hour' => 'numeric|min:0',
            'currency' => 'string|size:3',
            'is_active' => 'boolean',
        ]);

        $config->update($data);

        return response()->json($config);
    }

    public function destroy(string $id): JsonResponse
    {
        $config = CostConfig::findOrFail($id);
        $config->delete();

        return response()->json(null, 204);
    }
}
