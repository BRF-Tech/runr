<?php

namespace Modules\Tenancy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Tenancy\Models\CapacityDeal;
use Modules\Tenancy\Services\CapacityDealService;

class CapacityDealController
{
    public function __construct(private CapacityDealService $dealService) {}

    public function index(string $tenantId): JsonResponse
    {
        $deals = CapacityDeal::where('tenant_id', $tenantId)
            ->with('region:id,name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($deals);
    }

    public function store(Request $request, string $tenantId): JsonResponse
    {
        $data = $request->validate([
            'region_id' => 'required|exists:knative_regions,id',
            'cpu_m' => 'required|integer|min:100',
            'memory_mb' => 'required|integer|min:64',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'monthly_price' => 'required|numeric|min:0',
            'currency' => 'string|size:3',
            'notes' => 'nullable|string',
            'infra_cost' => 'nullable|numeric|min:0',
        ]);

        $deal = $this->dealService->createDeal($tenantId, $data['region_id'], $data);

        return response()->json($deal, 201);
    }

    public function update(Request $request, string $tenantId, string $id): JsonResponse
    {
        $deal = CapacityDeal::where('tenant_id', $tenantId)->findOrFail($id);

        $data = $request->validate([
            'monthly_price' => 'numeric|min:0',
            'end_date' => 'date',
            'notes' => 'nullable|string',
            'infra_cost' => 'nullable|numeric|min:0',
            'status' => 'in:active,expired,cancelled',
        ]);

        $deal->update($data);

        return response()->json($deal);
    }

    public function profitLoss(Request $request, string $regionId): JsonResponse
    {
        $from = $request->from ?? now()->startOfMonth()->toDateString();
        $to = $request->to ?? now()->endOfMonth()->toDateString();

        $result = $this->dealService->getProfitLoss($regionId, $from, $to);

        return response()->json($result);
    }
}
