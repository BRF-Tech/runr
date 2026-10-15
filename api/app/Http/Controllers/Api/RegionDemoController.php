<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RemoveRegionDemoJob;
use App\Jobs\SeedRegionDemoJob;
use App\Models\KnativeRegion;
use App\Services\RegionDemoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegionDemoController extends Controller
{
    public function store(Request $request, KnativeRegion $knativeRegion, RegionDemoService $service): JsonResponse
    {
        if ($knativeRegion->is_demo) {
            return response()->json(['message' => __('api.demo.cannot_seed_demo')], 422);
        }

        // Also after a failed removal (status error): a second set would duplicate the demo projects
        if (in_array($knativeRegion->region_demo_status, ['seeding', 'ready'])
            || $knativeRegion->projects()->where('is_region_demo', true)->exists()) {
            return response()->json(['message' => __('api.demo.already_exists')], 422);
        }

        try {
            $service->validatePrerequisites($knativeRegion);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $knativeRegion->update(['region_demo_status' => 'seeding']);

        SeedRegionDemoJob::dispatch($knativeRegion, $request->user()->id);

        return response()->json(['region_demo_status' => 'seeding']);
    }

    public function destroy(KnativeRegion $knativeRegion): JsonResponse
    {
        if ($knativeRegion->region_demo_status === 'removing') {
            return response()->json(['message' => __('api.demo.already_removing')], 422);
        }

        $knativeRegion->update(['region_demo_status' => 'removing']);

        RemoveRegionDemoJob::dispatch($knativeRegion, auth()->id());

        return response()->json(['region_demo_status' => 'removing']);
    }
}
