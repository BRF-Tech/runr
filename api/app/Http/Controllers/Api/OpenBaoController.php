<?php

namespace App\Http\Controllers\Api;

use App\Jobs\OpenBaoJob;
use App\Models\KnativeRegion;
use App\Models\RegionSecretSetting;
use App\Services\Secrets\OpenBao\OpenBaoInstallService;
use App\Services\Secrets\OpenBao\OpenBaoManifests;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The region's own OpenBao (docs/secret-stores.md, "OpenBao in the region"): RunR installs and
 * operates it; while it runs, new secret values of the region's projects go there (default, can be
 * turned off) and Postgres bindings can get dynamic credentials from it. Needs External Secrets
 * Operator in the region. Route rules: GET regions.view, POST / PATCH / DELETE regions.manage
 * (plus region.mutable).
 *
 * @tags Secrets
 */
class OpenBaoController extends Controller
{
    /** The API document's shape of OpenBao's status in the region (status()) */
    private const STATUS_TYPE = 'array{status: "not_installed"|"installing"|"ready"|"uninstalling"|"error", message: string|null, version: string|null, release_version: string, installed_at: string|null, default_for_values: bool, values: int, eso_status: "not_installed"|"installing"|"ready"|"uninstalling"|"error", can_manage: bool}';

    public function show(KnativeRegion $knativeRegion, OpenBaoInstallService $service): JsonResponse
    {
        return response()->json(['data' => $this->status($knativeRegion, RegionSecretSetting::forRegion($knativeRegion), $service)]);
    }

    /**
     * Install (or bring to this release's image): queued.
     *
     * @response JsonResponse<array{message: string, data: array<string, mixed>}, 202>
     */
    #[OpenApiResponse(202, 'Queued; the status as it is now', type: 'array{message: string, data: '.self::STATUS_TYPE.'}')]
    #[OpenApiResponse(422, 'External Secrets Operator does not run in the region, a demo region, a region without a kubeconfig, or an operation under way', type: 'array{message: string}')]
    public function install(KnativeRegion $knativeRegion, OpenBaoInstallService $service): JsonResponse
    {
        $settings = RegionSecretSetting::forRegion($knativeRegion);
        if ($blocked = $this->blocked($knativeRegion, $settings)) {
            return $blocked;
        }
        if (! $settings->esoReady()) {
            return response()->json(['message' => __('api.secret.openbao_needs_eso', ['region' => $knativeRegion->name])], 422);
        }

        $settings->fill(['openbao_status' => RegionSecretSetting::ESO_INSTALLING, 'openbao_message' => 'Installation queued...'])->save();
        OpenBaoJob::dispatch($knativeRegion, OpenBaoJob::INSTALL, auth()->id());

        return response()->json(['message' => __('api.secret.openbao_install_queued'), 'data' => $this->status($knativeRegion, $settings, $service)], 202);
    }

    /** PATCH {default_for_values: bool}: new secret values of the region's projects go to OpenBao or not. */
    public function update(Request $request, KnativeRegion $knativeRegion, OpenBaoInstallService $service): JsonResponse
    {
        $data = $request->validate(['default_for_values' => ['required', 'boolean']]);
        $settings = RegionSecretSetting::forRegion($knativeRegion);
        $settings->fill(['openbao_default' => (bool) $data['default_for_values']])->save();

        return response()->json(['data' => $this->status($knativeRegion, $settings, $service)]);
    }

    /**
     * Remove: refused while values or dynamic credentials of the region's projects live in it.
     *
     * @response JsonResponse<array{message: string, data: array<string, mixed>}, 202>
     */
    #[OpenApiResponse(202, 'Queued; the status as it is now', type: 'array{message: string, data: '.self::STATUS_TYPE.'}')]
    #[OpenApiResponse(409, 'Secret values of the region\'s projects live in it', type: 'array{message: string}')]
    #[OpenApiResponse(422, 'Not installed, a demo region, a region without a kubeconfig, or an operation under way', type: 'array{message: string}')]
    public function uninstall(KnativeRegion $knativeRegion, OpenBaoInstallService $service): JsonResponse
    {
        $settings = RegionSecretSetting::forRegion($knativeRegion);
        if ($blocked = $this->blocked($knativeRegion, $settings)) {
            return $blocked;
        }
        if ($settings->openbao_status === RegionSecretSetting::ESO_NOT_INSTALLED) {
            return response()->json(['message' => __('api.secret.openbao_not_installed', ['region' => $knativeRegion->name])], 422);
        }
        if ($service->valuesOn($knativeRegion) > 0) {
            return response()->json(['message' => __('api.secret.openbao_in_use', ['region' => $knativeRegion->name])], 409);
        }

        $settings->fill(['openbao_status' => RegionSecretSetting::ESO_UNINSTALLING, 'openbao_message' => 'Removal queued...'])->save();
        OpenBaoJob::dispatch($knativeRegion, OpenBaoJob::UNINSTALL, auth()->id());

        return response()->json(['message' => __('api.secret.openbao_uninstall_queued'), 'data' => $this->status($knativeRegion, $settings, $service)], 202);
    }

    private function status(KnativeRegion $region, RegionSecretSetting $settings, OpenBaoInstallService $service): array
    {
        return [
            /** @var "not_installed"|"installing"|"ready"|"uninstalling"|"error" */
            'status' => $settings->openbao_status,
            'message' => $settings->openbao_message,
            'version' => $settings->openbao_version,
            'release_version' => OpenBaoManifests::version((string) config('secrets.openbao.image')),
            /** @var string|null */
            'installed_at' => $settings->openbao_installed_at?->toISOString(),
            'default_for_values' => (bool) $settings->openbao_default,
            'values' => $service->valuesOn($region),
            /** @var "not_installed"|"installing"|"ready"|"uninstalling"|"error" */
            'eso_status' => $settings->eso_status,
            // POST / PATCH / DELETE require regions.manage (config/route_permissions.php)
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
        if ($settings->openbaoBusy()) {
            return response()->json(['message' => __('api.region.operation_in_progress')], 422);
        }

        return null;
    }
}
