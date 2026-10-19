<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\KnativeRegionResource;
use App\Jobs\MigrateRegionJob;
use App\Models\KnativeRegion;
use App\Services\DomainMigrateService;
use App\Services\RegionMigrateService;
use App\Services\Residency\RegionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegionMigrateController extends Controller
{
    public function startMigration(Request $request, KnativeRegion $knativeRegion): JsonResponse
    {
        $request->validate([
            'kubeconfig' => 'required|string',
        ]);

        if ($knativeRegion->is_migrating) {
            return response()->json(['message' => __('api.migration.in_progress')], 422);
        }

        // Data residency (docs/supply-chain.md): a region with a data location moves only within it
        app(RegionPolicy::class)->authorizeRegionMigration($knativeRegion, $request->input('data_location'));

        MigrateRegionJob::dispatch($knativeRegion, $request->kubeconfig, auth()->id());

        return response()->json(['message' => __('api.migration.started'), 'status' => 'pending']);
    }

    public function migrationStatus(KnativeRegion $knativeRegion): JsonResponse
    {
        return response()->json([
            'migration_status' => $knativeRegion->migration_status,
            'migration_started_at' => $knativeRegion->migration_started_at?->toISOString(),
            'migration_old_ip' => $knativeRegion->migration_old_ip,
            'current_ip' => $knativeRegion->external_ip,
            'is_migrating' => $knativeRegion->is_migrating,
        ]);
    }

    public function completeMigration(KnativeRegion $knativeRegion, RegionMigrateService $service): JsonResponse
    {
        if ($knativeRegion->migration_status !== 'dns_check') {
            return response()->json(['message' => __('api.migration.not_dns_phase')], 422);
        }

        $service->completeMigration($knativeRegion);

        return response()->json(['message' => __('api.migration.completed')]);
    }

    /**
     * Complete a base-domain migration without waiting for its DNS check: the new function domain
     * becomes the region's, the old one is no longer served (DomainMigrateService). For DNS the
     * control plane resolves differently from the clients (split-horizon DNS).
     */
    public function completeDomainMigration(KnativeRegion $knativeRegion, DomainMigrateService $service): JsonResponse
    {
        if (! $knativeRegion->is_domain_migrating) {
            return response()->json(['message' => __('api.migration.no_domain_migration')], 422);
        }

        $service->completeMigration($knativeRegion);

        return response()->json([
            'message' => __('api.migration.domain_completed'),
            'region' => new KnativeRegionResource($knativeRegion->fresh()),
        ]);
    }

    public function rollbackMigration(KnativeRegion $knativeRegion, RegionMigrateService $service): JsonResponse
    {
        if (! in_array($knativeRegion->migration_status, ['dns_check', 'failed'])) {
            return response()->json(['message' => __('api.migration.cannot_rollback')], 422);
        }

        $service->rollbackMigration($knativeRegion);

        return response()->json(['message' => __('api.migration.rolled_back')]);
    }
}
