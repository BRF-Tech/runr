<?php

namespace App\Http\Controllers\Api;

use App\Models\Deployment;
use App\Models\ImageSbomDocument;
use App\Models\ImageSecurityReport;
use App\Models\Project;
use App\Models\RegionSupplyChainSetting;
use App\Models\SecurityPolicyOverride;
use App\Models\Version;
use App\Services\SupplyChain\DeployPolicyGate;
use App\Services\SupplyChain\SupplyChainPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security tab of a project (docs/supply-chain.md): the supply-chain report of every version
 * (SBOM, vulnerability counts and findings, signature), the deployed versions, the policy the
 * region applies and the overrides. Ability: security.view.
 */
class ProjectSecurityController extends Controller
{
    private const VERSIONS = 50;

    public function __construct(
        private SupplyChainPipeline $pipeline,
        private DeployPolicyGate $gate,
    ) {}

    /** GET /api/projects/{project}/security */
    public function index(Project $project): JsonResponse
    {
        Gate::authorize('security.view', $project);

        $region = $project->knativeRegion;
        $settings = $region ? RegionSupplyChainSetting::forRegion($region) : null;
        $versions = $project->versions()->orderByDesc('number')->limit(self::VERSIONS)->get();
        $reports = ImageSecurityReport::query()
            ->where('subject_type', ImageSecurityReport::SUBJECT_VERSION)
            ->whereIn('subject_id', $versions->modelKeys())
            ->get()
            ->keyBy('subject_id');

        $deployments = $project->deployments()->with('version')->latest()->limit(20)->get();
        $deployedReports = ImageSecurityReport::query()
            ->where('subject_type', ImageSecurityReport::SUBJECT_VERSION)
            ->whereIn('subject_id', $deployments->pluck('version_id')->unique()->all())
            ->get()
            ->keyBy('subject_id');

        return response()->json(['data' => [
            'region' => $region ? [
                'id' => $region->id,
                'name' => $region->name,
                'data_location' => $settings?->data_location,
            ] : null,
            'policy' => $region ? $this->gate->policyFor($region) : null,
            'pipeline' => $settings ? [
                'sbom' => $settings->sbom_enabled,
                'scan' => $settings->scan_enabled,
                'scan_external_images' => $settings->scan_external_images,
                'signing' => $settings->signing_enabled && $settings->hasSigningKey(),
                'signing_fingerprint' => $settings->signing_key_fingerprint,
                'admission' => $settings->admission_status === 'ready' ? $settings->admission_mode : 'off',
                'rescan_interval_hours' => $settings->rescan_interval_hours,
            ] : null,
            'versions' => $versions->map(fn (Version $v) => $this->versionRow($project, $v, $reports->get($v->id)))->values(),
            'deployments' => $deployments->map(fn (Deployment $d) => [
                'id' => $d->id,
                'status' => $d->status,
                'version_id' => $d->version_id,
                'version_label' => $d->version?->label,
                'created_at' => $d->created_at?->toISOString(),
                'report' => $deployedReports->get($d->version_id)?->summary(),
            ])->values(),
            'overrides' => SecurityPolicyOverride::query()
                ->where('project_id', $project->id)
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (SecurityPolicyOverride $o) => [
                    'id' => $o->id,
                    'version_id' => $o->version_id,
                    'deployment_id' => $o->deployment_id,
                    'username' => $o->username,
                    'reason' => $o->reason,
                    'violations' => $o->violations,
                    'created_at' => $o->created_at?->toISOString(),
                ])->values(),
        ]]);
    }

    /** GET /api/projects/{project}/versions/{version}/security */
    public function version(Project $project, Version $version): JsonResponse
    {
        Gate::authorize('security.view', $project);

        $report = ImageSecurityReport::forVersion($version);
        $decision = $this->gate->evaluate($project, $version);

        return response()->json(['data' => [
            'version' => $this->versionRow($project, $version, $report),
            'report' => $report?->detail(),
            'decision' => $decision->toArray(),
        ]]);
    }

    /** POST /api/projects/{project}/versions/{version}/security/scan — scan again (full run when the version has no SBOM yet) */
    public function rescan(Project $project, Version $version): JsonResponse
    {
        Gate::authorize('security.view', $project);

        $report = ImageSecurityReport::forVersion($version);
        if ($report && $report->isRunning()) {
            return response()->json(['message' => __('supply_chain.report.running')], 409);
        }

        $rescan = $report && $report->sbom_status === ImageSecurityReport::STEP_OK;
        $queued = $this->pipeline->queueForVersion($version, $rescan);
        if (! $queued) {
            return response()->json(['message' => __('supply_chain.report.not_applicable')], 422);
        }

        return response()->json(['data' => $queued->summary()], 202);
    }

    /** GET /api/projects/{project}/versions/{version}/sbom?format=spdx-json|cyclonedx-json */
    public function sbom(Request $request, Project $project, Version $version): Response
    {
        Gate::authorize('security.view', $project);

        $report = ImageSecurityReport::forVersion($version);
        abort_unless($report, 404, __('supply_chain.report.none'));

        return $this->download($report, (string) $request->query('format', 'spdx-json'), "{$project->slug}-v{$version->number}");
    }

    /** GET /api/projects/{project}/deployments/{deployment}/security */
    public function deployment(Project $project, Deployment $deployment): JsonResponse
    {
        Gate::authorize('security.view', $project);

        $version = $deployment->version;
        $report = $version ? ImageSecurityReport::forVersion($version) : null;

        return response()->json(['data' => [
            'deployment_id' => $deployment->id,
            'version' => $version ? $this->versionRow($project, $version, $report) : null,
            'report' => $report?->detail(),
        ]]);
    }

    /** SBOM document of a report as a file download. */
    public static function download(ImageSecurityReport $report, string $format, string $basename): Response
    {
        abort_unless(array_key_exists($format, ImageSbomDocument::FORMATS), 422, __('supply_chain.report.unknown_format'));

        $document = $report->sbomDocuments()->where('format', $format)->first();
        abort_unless($document, 404, __('supply_chain.report.no_sbom'));

        $meta = ImageSbomDocument::FORMATS[$format];
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $basename).'.'.$meta['extension'];

        return response($document->document(), 200, [
            'Content-Type' => $meta['media_type'],
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-SHA256' => $document->sha256,
        ]);
    }

    private function versionRow(Project $project, Version $version, ?ImageSecurityReport $report): array
    {
        $image = $this->pipeline->imageForVersion($project, $version);

        return [
            'id' => $version->id,
            'number' => $version->number,
            'label' => $version->label,
            'build_status' => $version->build_status,
            'created_at' => $version->created_at?->toISOString(),
            'image_kind' => $image[0] ?? null,
            'image' => $image[1] ?? null,
            'applicable' => $image !== null,
            'report' => $report?->summary(),
        ];
    }
}
