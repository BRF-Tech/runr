<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\KnativeRegion\UpdateRegionSupplyChainRequest;
use App\Jobs\InstallAdmissionPolicyJob;
use App\Models\ImageSecurityReport;
use App\Models\KnativeRegion;
use App\Models\RegionSupplyChainSetting;
use App\Models\RuntimeBuild;
use App\Services\SupplyChain\AdmissionPolicyService;
use App\Services\SupplyChain\ScannerDbMirrorService;
use App\Services\SupplyChain\SigningKeyService;
use App\Services\SupplyChain\SupplyChainManifests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Region → Supply chain (docs/supply-chain.md): SBOM / scan switches, scanner DB, policy gate,
 * signing key, admission policy, CaaS image policy and the region's data location.
 *
 * Reading: regions.view. Changing: regions.supply_chain.manage (+ `region.mutable` on the
 * routes; config/route_permissions.php). The private key and its password are never part of a response.
 */
class RegionSupplyChainController extends Controller
{
    /** GET /api/knative-regions/{knativeRegion}/supply-chain */
    public function show(KnativeRegion $knativeRegion): JsonResponse
    {
        Gate::authorize('regions.view');

        return response()->json(['data' => $this->payload($knativeRegion)]);
    }

    /** PUT /api/knative-regions/{knativeRegion}/supply-chain */
    public function update(UpdateRegionSupplyChainRequest $request, KnativeRegion $knativeRegion, AdmissionPolicyService $admission): JsonResponse
    {
        Gate::authorize('regions.supply_chain.manage', $knativeRegion);

        $settings = RegionSupplyChainSetting::firstOrCreateFor($knativeRegion);
        $data = $request->validated();

        foreach (['admission_exempt_images', 'caas_allowed_registries', 'caas_trusted_keys'] as $list) {
            if (array_key_exists($list, $data)) {
                $data[$list] = array_values(array_unique(array_filter(array_map('trim', $data[$list] ?? []))));
            }
        }
        if (array_key_exists('data_location', $data)) {
            $data['data_location'] = $data['data_location'] !== null && trim($data['data_location']) !== '' ? trim($data['data_location']) : null;
        }

        $settings->fill($data);
        $this->assertConsistent($settings, $knativeRegion);

        $changed = array_keys($settings->getDirty());
        $settings->updated_by = $request->user()?->username;
        $settings->save();   // audited by the route (region.supply_chain.update) with the model change

        // Policies follow the settings once installed (mode, exemptions, CaaS policy)
        $admissionKeys = ['admission_mode', 'admission_require_sbom', 'admission_exempt_images', 'caas_policy', 'caas_allowed_registries', 'caas_trusted_keys'];
        if ($settings->admission_status === 'ready' && array_intersect($changed, $admissionKeys)) {
            InstallAdmissionPolicyJob::dispatch($knativeRegion, $settings->admission_mode === 'off' ? 'uninstall' : 'sync', $request->user()?->getKey());
        }

        return response()->json(['data' => $this->payload($knativeRegion)]);
    }

    /** POST /api/knative-regions/{knativeRegion}/supply-chain/signing-key — generate, or rotate an existing key */
    public function generateKey(Request $request, KnativeRegion $knativeRegion, SigningKeyService $keys): JsonResponse
    {
        Gate::authorize('regions.supply_chain.manage', $knativeRegion);

        if (! $knativeRegion->kubeconfig || $knativeRegion->is_demo) {
            return response()->json(['message' => __('supply_chain.signing.no_cluster')], 422);
        }

        $current = RegionSupplyChainSetting::forRegion($knativeRegion);
        try {
            $keys->generate($knativeRegion, rotate: $current->hasSigningKey());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $this->payload($knativeRegion)], 202);
    }

    /** DELETE /api/knative-regions/{knativeRegion}/supply-chain/signing-key/retired */
    public function forgetRetiredKeys(KnativeRegion $knativeRegion, SigningKeyService $keys): JsonResponse
    {
        Gate::authorize('regions.supply_chain.manage', $knativeRegion);

        $settings = $keys->forgetRetired($knativeRegion);
        if ($settings->admission_status === 'ready') {
            InstallAdmissionPolicyJob::dispatch($knativeRegion, 'sync', auth()->id());
        }

        return response()->json(['data' => $this->payload($knativeRegion)]);
    }

    /** GET /api/knative-regions/{knativeRegion}/supply-chain/public-key — PEM (cosign verify --key) */
    public function publicKey(KnativeRegion $knativeRegion): Response
    {
        Gate::authorize('regions.view');

        $settings = RegionSupplyChainSetting::forRegion($knativeRegion);
        abort_unless($settings->hasSigningKey(), 404, __('supply_chain.signing.none'));

        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $knativeRegion->name);

        return response((string) $settings->signing_public_key, 200, [
            'Content-Type' => 'application/x-pem-file',
            'Content-Disposition' => "attachment; filename=\"runr-{$name}-cosign.pub\"",
        ]);
    }

    /** POST /api/knative-regions/{knativeRegion}/supply-chain/scanner-db/refresh */
    public function refreshScannerDb(KnativeRegion $knativeRegion, ScannerDbMirrorService $mirror): JsonResponse
    {
        Gate::authorize('regions.supply_chain.manage', $knativeRegion);

        try {
            $mirror->refresh($knativeRegion);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->payload($knativeRegion)], 202);
    }

    /** POST /api/knative-regions/{knativeRegion}/supply-chain/admission — install, or update an installed policy */
    public function installAdmission(Request $request, KnativeRegion $knativeRegion, AdmissionPolicyService $admission): JsonResponse
    {
        Gate::authorize('regions.supply_chain.manage', $knativeRegion);

        $data = $request->validate(['mode' => 'sometimes|in:enforce,warn']);
        if (isset($data['mode'])) {
            RegionSupplyChainSetting::firstOrCreateFor($knativeRegion)->forceFill(['admission_mode' => $data['mode']])->save();
        }

        try {
            $admission->requestInstall($knativeRegion);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->payload($knativeRegion)], 202);
    }

    /** DELETE /api/knative-regions/{knativeRegion}/supply-chain/admission */
    public function uninstallAdmission(KnativeRegion $knativeRegion, AdmissionPolicyService $admission): JsonResponse
    {
        Gate::authorize('regions.supply_chain.manage', $knativeRegion);

        $admission->requestUninstall($knativeRegion);

        return response()->json(['data' => $this->payload($knativeRegion)], 202);
    }

    /** GET /api/knative-regions/{knativeRegion}/supply-chain/admission/preview — what the install applies */
    public function admissionPreview(KnativeRegion $knativeRegion, AdmissionPolicyService $admission): JsonResponse
    {
        Gate::authorize('regions.supply_chain.manage', $knativeRegion);

        return response()->json(['data' => $admission->preview($knativeRegion)]);
    }

    /** GET /api/knative-regions/{knativeRegion}/supply-chain/runtime-images — reports of runtime images built here */
    public function runtimeImages(KnativeRegion $knativeRegion): JsonResponse
    {
        Gate::authorize('regions.view');

        $builds = RuntimeBuild::query()->where('knative_region_id', $knativeRegion->id)->orderBy('runtime_id')->orderBy('version')->get();
        $reports = ImageSecurityReport::onCentral()
            ->where('subject_type', ImageSecurityReport::SUBJECT_RUNTIME_BUILD)
            ->whereIn('subject_id', $builds->modelKeys())
            ->get()
            ->keyBy('subject_id');

        return response()->json(['data' => $builds->map(fn (RuntimeBuild $b) => [
            'build_id' => $b->id,
            'runtime' => $b->runtime_id,
            'version' => $b->version,
            'status' => $b->status,
            'image' => $b->image,
            'report' => $reports->get($b->id)?->summary(),
        ])->values()]);
    }

    /** GET /api/knative-regions/{knativeRegion}/supply-chain/runtime-images/{report}/sbom */
    public function runtimeImageSbom(Request $request, KnativeRegion $knativeRegion, string $report): Response
    {
        Gate::authorize('regions.view');

        $model = ImageSecurityReport::onCentral()
            ->where('subject_type', ImageSecurityReport::SUBJECT_RUNTIME_BUILD)
            ->where('knative_region_id', $knativeRegion->id)
            ->findOrFail($report);

        return ProjectSecurityController::download($model, (string) $request->query('format', 'spdx-json'), 'runtime-'.basename(str_replace([':', '@'], '-', $model->image_ref)));
    }

    /** Settings combinations that cannot work, refused before they are stored. */
    private function assertConsistent(RegionSupplyChainSetting $settings, KnativeRegion $region): void
    {
        $errors = [];

        if (in_array($settings->caas_policy, ['allowlist', 'signed'], true) && empty($settings->caas_allowed_registries)) {
            $errors['caas_allowed_registries'] = [__('supply_chain.validation.registries_required')];
        }
        if ($settings->caas_policy === 'signed' && empty($settings->caas_trusted_keys)) {
            $errors['caas_trusted_keys'] = [__('supply_chain.validation.keys_required')];
        }
        if (in_array($settings->scanner_db_mode, ['mirror', 'offline'], true) && ! $region->registry_address) {
            $errors['scanner_db_mode'] = [__('supply_chain.scanner_db.no_registry')];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Settings of a region for the UI: everything but the private key and its password. */
    private function payload(KnativeRegion $region): array
    {
        $settings = RegionSupplyChainSetting::forRegion($region);
        $data = $settings->toArray();   // signing_private_key / signing_key_password are $hidden

        unset($data['signing_job'], $data['scanner_db_job']);
        $data['signing_retired_keys'] = array_map(fn ($k) => [
            'fingerprint' => $k['fingerprint'] ?? null,
            'public_key' => $k['public_key'] ?? null,
            'retired_at' => $k['retired_at'] ?? null,
        ], $settings->signing_retired_keys ?? []);

        return $data + [
            'region' => [
                'id' => $region->id,
                'name' => $region->name,
                'has_registry' => $region->has_registry,
                'registry_mode' => $region->registry_mode,
                'project_namespace' => $region->project_namespace ?? $region->name,
            ],
            'scanner_db_repository' => $region->registry_address
                ? SupplyChainManifests::registryPath($region, (string) config('supply_chain.scan.db_repository'))
                : null,
            'scanner_db_default_source' => config('supply_chain.scan.db_source'),
            'supply_chain_namespace' => SupplyChainManifests::namespace(),
            'can_manage' => Gate::allows('regions.supply_chain.manage', $region),
        ];
    }
}
