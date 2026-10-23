<?php

namespace App\Http\Controllers\Api;

use App\Jobs\ExternalSecretsJob;
use App\Models\KnativeRegion;
use App\Models\RegionSecretSetting;
use App\Services\Secrets\External\ExternalSecretsInstallService;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * External Secrets Operator of a region (docs/secret-stores.md): the optional region feature that
 * syncs project secrets which are references to an organization's secret store. Route rules: GET
 * regions.view, POST / DELETE regions.manage (plus region.mutable).
 *
 * @tags Secrets
 */
class ExternalSecretsController extends Controller
{
    /** The API document's shape of the operator's status in the region (status()) */
    private const STATUS_TYPE = 'array{status: "not_installed"|"installing"|"ready"|"uninstalling"|"error", message: string|null, chart_version: string|null, release_chart_version: string, installed_at: string|null, references: int, can_manage: bool}';

    public function show(KnativeRegion $knativeRegion, ExternalSecretsInstallService $service): JsonResponse
    {
        $settings = $knativeRegion->kubeconfig && ! $knativeRegion->is_demo
            ? $service->refreshStatus($knativeRegion)
            : RegionSecretSetting::forRegion($knativeRegion);

        return response()->json(['data' => $this->status($knativeRegion, $settings, $service)]);
    }

    /**
     * Install (or bring to this release's chart version): queued.
     *
     * @response JsonResponse<array{message: string, data: array<string, mixed>}, 202>
     */
    #[OpenApiResponse(202, 'Queued; the status as it is now', type: 'array{message: string, data: '.self::STATUS_TYPE.'}')]
    #[OpenApiResponse(422, 'A demo region, a region without a kubeconfig, or an operation under way', type: 'array{message: string}')]
    public function install(KnativeRegion $knativeRegion, ExternalSecretsInstallService $service): JsonResponse
    {
        $settings = RegionSecretSetting::forRegion($knativeRegion);
        if ($blocked = $this->blocked($knativeRegion, $settings)) {
            return $blocked;
        }

        $settings->fill(['eso_status' => RegionSecretSetting::ESO_INSTALLING, 'eso_message' => 'Installation queued...'])->save();
        ExternalSecretsJob::dispatch($knativeRegion, ExternalSecretsJob::INSTALL, auth()->id());

        return response()->json(['message' => __('api.secret.eso_install_queued'), 'data' => $this->status($knativeRegion, $settings, $service)], 202);
    }

    /**
     * Remove: refused while projects on the region have references.
     *
     * @response JsonResponse<array{message: string, data: array<string, mixed>}, 202>
     */
    #[OpenApiResponse(202, 'Queued; the status as it is now', type: 'array{message: string, data: '.self::STATUS_TYPE.'}')]
    #[OpenApiResponse(409, 'Project secrets refer to stores through it, or the region\'s OpenBao needs it', type: 'array{message: string}')]
    #[OpenApiResponse(422, 'Not installed, a demo region, a region without a kubeconfig, or an operation under way', type: 'array{message: string}')]
    public function uninstall(KnativeRegion $knativeRegion, ExternalSecretsInstallService $service): JsonResponse
    {
        $settings = RegionSecretSetting::forRegion($knativeRegion);
        if ($blocked = $this->blocked($knativeRegion, $settings)) {
            return $blocked;
        }
        if ($settings->eso_status === RegionSecretSetting::ESO_NOT_INSTALLED) {
            return response()->json(['message' => __('api.secret.eso_not_installed', ['region' => $knativeRegion->name])], 422);
        }
        if ($service->referencesOn($knativeRegion) > 0) {
            return response()->json(['message' => __('api.secret.eso_in_use', ['region' => $knativeRegion->name])], 409);
        }
        // The workloads read the region's OpenBao through it
        if ($settings->openbao_status !== RegionSecretSetting::ESO_NOT_INSTALLED) {
            return response()->json(['message' => __('api.secret.eso_needed_by_openbao', ['region' => $knativeRegion->name])], 409);
        }

        $settings->fill(['eso_status' => RegionSecretSetting::ESO_UNINSTALLING, 'eso_message' => 'Removal queued...'])->save();
        ExternalSecretsJob::dispatch($knativeRegion, ExternalSecretsJob::UNINSTALL, auth()->id());

        return response()->json(['message' => __('api.secret.eso_uninstall_queued'), 'data' => $this->status($knativeRegion, $settings, $service)], 202);
    }

    private function status(KnativeRegion $region, RegionSecretSetting $settings, ExternalSecretsInstallService $service): array
    {
        return [
            /** @var "not_installed"|"installing"|"ready"|"uninstalling"|"error" */
            'status' => $settings->eso_status,
            'message' => $settings->eso_message,
            'chart_version' => $settings->eso_chart_version,
            'release_chart_version' => (string) config('secrets.external.chart_version'),
            /** @var string|null */
            'installed_at' => $settings->eso_installed_at?->toISOString(),
            'references' => $service->referencesOn($region),
            // POST / DELETE require regions.manage (config/route_permissions.php)
            /** @var bool */
            'can_manage' => Gate::allows('regions.manage'),
        ];
    }

    private function blocked(KnativeRegion $region, RegionSecretSetting $settings): ?JsonResponse
    {
        if ($region->is_demo) {
            return response()->json(['message' => __('api.region.demo_not_modifiable')], 422);
        }
        if (! $region->kubeconfig) {
            return response()->json(['message' => __('api.region.kubeconfig_missing')], 422);
        }
        if ($settings->esoBusy()) {
            return response()->json(['message' => __('api.region.operation_in_progress')], 422);
        }

        return null;
    }
}
