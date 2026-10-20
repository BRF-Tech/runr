<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\KnativeRegionResource;
use App\Jobs\MigrateRegionJob;
use App\Models\KnativeRegion;
use App\Services\DomainMigrateService;
use App\Services\RegionMigrateService;
use App\Services\Residency\RegionPolicy;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
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

    /**
     * The move's state and, while it can still be rolled back, what it did with each project of the
     * region (RegionMigrateService::migrationReport(): moved, building, deployed, not_moved,
     * cancelled — with the reason). Never answered from the response cache: the builds and
     * deploys it follows change no region setting.
     */
    #[OpenApiResponse(200, 'The move\'s state and what it did with each project (projects: empty once the move is completed or rolled back)', type: 'array{migration_status: string|null, migration_started_at: string|null, migration_old_ip: string|null, current_ip: string|null, is_migrating: bool, projects: list<array{organization: string|null, project_id: string, project: string, outcome: "moved"|"building"|"deployed"|"not_moved"|"cancelled", version: int|null, deployment_status: string|null, message: string|null}>}')]
    public function migrationStatus(KnativeRegion $knativeRegion, RegionMigrateService $service): JsonResponse
    {
        return response()->json([
            'migration_status' => $knativeRegion->migration_status,
            'migration_started_at' => $knativeRegion->migration_started_at?->toISOString(),
            'migration_old_ip' => $knativeRegion->migration_old_ip,
            'current_ip' => $knativeRegion->external_ip,
            'is_migrating' => $knativeRegion->is_migrating,
            'projects' => $service->migrationReport($knativeRegion),
        ])->header('Cache-Control', 'no-store, private');
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

    /**
     * The region goes back to its old cluster, its projects are deployed there again; what the move
     * deployed for them on the cluster it leaves is removed from there in the background, and the
     * operator gets a notification with the result (RegionMigrateService::rollbackMigration()).
     */
    public function rollbackMigration(KnativeRegion $knativeRegion, RegionMigrateService $service): JsonResponse
    {
        if (! in_array($knativeRegion->migration_status, ['dns_check', 'failed'])) {
            return response()->json(['message' => __('api.migration.cannot_rollback')], 422);
        }

        $operator = auth()->id();
        $cleanup = $service->rollbackMigration($knativeRegion, $operator === null ? null : (string) $operator);

        return response()->json(['message' => __($cleanup ? 'api.migration.rolled_back_cleanup' : 'api.migration.rolled_back')]);
    }
}
