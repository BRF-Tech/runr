<?php

namespace App\Http\Controllers\Api;

use App\Models\AiModelService;
use App\Models\ImageSecurityReport;
use App\Models\RegionSupplyChainSetting;
use App\Services\SupplyChain\SupplyChainPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Supply-chain report of an AI model service's image (docs/supply-chain.md): the image is an
 * external one (engine image or the service's own), scanned like a CaaS image when its region
 * scans external images. Ability: models.view.
 */
class ModelServiceSecurityController extends Controller
{
    public function __construct(private SupplyChainPipeline $pipeline) {}

    /** GET /api/model-services/{service}/security */
    public function show(AiModelService $service): JsonResponse
    {
        Gate::authorize('models.view');

        $service->load('model', 'region');
        $settings = $service->region ? RegionSupplyChainSetting::forRegion($service->region) : null;
        $report = ImageSecurityReport::forModelService($service);
        $image = $this->pipeline->modelServiceImage($service);

        return response()->json(['data' => [
            'image' => $image,
            'scanned' => (bool) ($settings?->scan_enabled && $settings?->scan_external_images),
            'current' => $report !== null && $report->image_ref === $image,
            'report' => $report?->detail(),
        ]]);
    }

    /** GET /api/model-services/{service}/sbom?format=spdx-json|cyclonedx-json */
    public function sbom(Request $request, AiModelService $service): Response
    {
        Gate::authorize('models.view');

        $report = ImageSecurityReport::forModelService($service);
        abort_unless($report, 404, __('supply_chain.report.none'));

        return ProjectSecurityController::download($report, (string) $request->query('format', 'spdx-json'), "model-service-{$service->name}");
    }
}
