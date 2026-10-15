<?php

namespace App\Services\SupplyChain;

use App\Events\PlatformEvent;
use App\Jobs\CheckSupplyChainJob;
use App\Jobs\StartSupplyChainJob;
use App\Models\AiModelService;
use App\Models\ImageSbomDocument;
use App\Models\ImageSecurityReport;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\RegionSupplyChainSetting;
use App\Models\RuntimeBuild;
use App\Models\UploadedImage;
use App\Models\Version;
use App\Services\Ai\Models\ModelServiceManifests;
use App\Services\Images\UploadedImages;
use App\Services\KubernetesClient;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Wasm\WasmRuntimes;
use Illuminate\Support\Facades\Log;

/**
 * The supply-chain pipeline of an image (docs/supply-chain.md):
 *
 *   build ready (Version / RuntimeBuild observer) → queueFor*() → report "pending"
 *   → StartSupplyChainJob → submit(): namespace, key / registry Secrets, Job of the tools image
 *   → CheckSupplyChainJob → collect(): Job finished → its log (PipelineOutput) → record()
 *
 * Steps per image kind:
 *   built / runtime / uploaded  sbom, attach, scan, sign, attest (each per the region settings)
 *   external (CaaS)  sbom, scan — nothing is written to a registry RunR does not own
 *   wasm (Spin app)  sign — a WebAssembly OCI artifact has no container filesystem for syft and
 *                    Trivy to read; SBOM, referrers, scan and attestations are recorded as
 *                    skipped with that reason, and the policy gate does not require a scan of it
 *   re-scan          scan (+ attest of the new scan when the region signs); none for wasm
 */
class SupplyChainPipeline
{
    public function __construct(
        private TrivyReportParser $trivy,
        private SbomSummarizer $sboms,
    ) {}

    // ── Queueing ────────────────────────────────────────────────────────────

    /**
     * Report + run for a function version: its built image, or — CaaS, when the region scans
     * external images — the image the version deploys. Null when nothing is to be done
     * (code-mounted version, demo region, pipeline off).
     */
    public function queueForVersion(Version $version, bool $rescan = false): ?ImageSecurityReport
    {
        $project = $version->project;
        $region = $project?->knativeRegion;
        if (! $project || ! $region || $region->is_demo || ! $region->kubeconfig) {
            return null;
        }

        [$kind, $image] = $this->imageForVersion($project, $version) ?? [null, null];
        if (! $kind) {
            return null;
        }

        $settings = RegionSupplyChainSetting::forRegion($region);
        if ($kind === ImageSecurityReport::KIND_EXTERNAL && ! $settings->scan_external_images) {
            return null;
        }

        $steps = $this->stepsFor($kind, $settings, $region, $rescan);
        if (! $steps) {
            return null;
        }

        $report = ImageSecurityReport::forVersion($version) ?? new ImageSecurityReport([
            'subject_type' => ImageSecurityReport::SUBJECT_VERSION,
            'subject_id' => $version->getKey(),
        ]);

        $attributes = [
            'project_id' => $project->getKey(),
            'version_id' => $version->getKey(),
            'knative_region_id' => $region->getKey(),
            'image_kind' => $kind,
            'image_ref' => $image,
        ];
        if ($kind === ImageSecurityReport::KIND_WASM) {
            // why the steps a container image gets are not run for a Spin app
            $skipped = ['status' => ImageSecurityReport::STEP_SKIPPED, 'message' => __('supply_chain.report.wasm_skipped')];
            $attributes['step_results'] = array_fill_keys(['sbom', 'attach', 'scan', 'attest'], $skipped);
        }

        return $this->queue($report, $attributes, $steps, $rescan);
    }

    /** Report + run for a runtime image RunR built in a region (central schema). */
    public function queueForRuntimeBuild(RuntimeBuild $build, bool $rescan = false): ?ImageSecurityReport
    {
        if ($build->status !== RuntimeBuild::STATUS_READY || ! $build->image) {
            return null;
        }

        $region = KnativeRegion::find($build->knative_region_id);
        if (! $region || $region->is_demo || ! $region->kubeconfig) {
            return null;
        }

        $settings = RegionSupplyChainSetting::forRegion($region);
        $steps = $this->stepsFor(ImageSecurityReport::KIND_RUNTIME, $settings, $region, $rescan);
        if (! $steps) {
            return null;
        }

        $report = ImageSecurityReport::forRuntimeBuild($build);
        if (! $report) {
            $report = new ImageSecurityReport(['subject_type' => ImageSecurityReport::SUBJECT_RUNTIME_BUILD, 'subject_id' => $build->getKey()]);
            if ($connection = SupplyChainDb::central()) {
                $report->setConnection($connection);
            }
        }

        return $this->queue($report, [
            'knative_region_id' => $region->getKey(),
            'image_kind' => ImageSecurityReport::KIND_RUNTIME,
            'image_ref' => $build->image,
        ], $steps, $rescan);
    }

    /**
     * Report + run for an uploaded image (docs/images.md, central schema): the image is in the
     * region registry RunR owns, so it gets the full pipeline of a build — SBOM, referrers, scan,
     * signature, attestations — whatever the region's setting for external images.
     */
    public function queueForUploadedImage(UploadedImage $image, bool $rescan = false): ?ImageSecurityReport
    {
        $region = KnativeRegion::find($image->knative_region_id);
        if (! $region || $region->is_demo || ! $region->kubeconfig || $image->kind !== UploadedImage::KIND_IMAGE) {
            return null;
        }

        $settings = RegionSupplyChainSetting::forRegion($region);
        $steps = $this->stepsFor(ImageSecurityReport::KIND_UPLOADED, $settings, $region, $rescan);
        if (! $steps) {
            return null;
        }

        $report = ImageSecurityReport::forUploadedImage($image);
        $ref = $image->imageRef($region);
        if (! $report) {
            $report = new ImageSecurityReport(['subject_type' => ImageSecurityReport::SUBJECT_UPLOADED_IMAGE, 'subject_id' => $image->getKey()]);
            if ($connection = SupplyChainDb::central()) {
                $report->setConnection($connection);
            }
        }
        // another digest (the same name uploaded again): a fresh report, not a re-scan
        $rescan = $rescan && $report->exists && $report->image_ref === $ref;

        return $this->queue($report, [
            'knative_region_id' => $region->getKey(),
            'image_kind' => ImageSecurityReport::KIND_UPLOADED,
            'image_ref' => $ref,
        ], $steps, $rescan);
    }

    /**
     * Report + run for an AI model service's image (docs/ai.md): an external image like a CaaS
     * one — SBOM and scan only, when the region scans external images.
     */
    public function queueForModelService(AiModelService $service, bool $rescan = false): ?ImageSecurityReport
    {
        $region = $service->region;
        if (! $region || $region->is_demo || ! $region->kubeconfig) {
            return null;
        }

        $settings = RegionSupplyChainSetting::forRegion($region);
        $image = $this->modelServiceImage($service);
        if (! $settings->scan_external_images || ! $image) {
            return null;
        }

        $steps = $this->stepsFor(ImageSecurityReport::KIND_EXTERNAL, $settings, $region, $rescan);
        if (! $steps) {
            return null;
        }

        $report = ImageSecurityReport::forModelService($service) ?? new ImageSecurityReport([
            'subject_type' => ImageSecurityReport::SUBJECT_MODEL_SERVICE,
            'subject_id' => $service->getKey(),
        ]);

        // Another image (engine or custom image changed): a fresh report, not a re-scan
        $rescan = $rescan && $report->exists && $report->image_ref === $image;

        return $this->queue($report, [
            'knative_region_id' => $region->getKey(),
            'image_kind' => ImageSecurityReport::KIND_EXTERNAL,
            'image_ref' => $image,
        ], $steps, $rescan);
    }

    /** The image a model service runs (engine image, or its own image), fully qualified. */
    public function modelServiceImage(AiModelService $service): ?string
    {
        try {
            $image = app(ModelServiceManifests::class)->engineContainer($service, $service->model, null, $service->region, null)['image'] ?? null;
        } catch (\Throwable) {
            $image = $service->image;
        }

        return $image ? ImageRef::parse((string) $image)->canonical() : null;
    }

    /**
     * Kind and reference of the image a version runs, null for a code-mounted version (it runs
     * the runtime's own image with the code mounted; nothing is built for it).
     *
     * @return array{0: string, 1: string}|null
     */
    public function imageForVersion(Project $project, Version $version): ?array
    {
        if ($project->service_type === 'caas') {
            $image = $version->config_snapshot['image'] ?? $project->containerConfig?->image;
            if (! $image) {
                return null;
            }
            // An uploaded image (docs/images.md) is in RunR's own registry: scanned and signed like a build
            if (app(UploadedImages::class)->forImageRef($project->knativeRegion, (string) $image) !== null) {
                return [ImageSecurityReport::KIND_UPLOADED, (string) $image];
            }

            return [ImageSecurityReport::KIND_EXTERNAL, ImageRef::parse((string) $image)->canonical()];
        }

        if ($version->build_image && $version->build_status === 'ready') {
            // A WebAssembly runtime builds a Spin OCI artifact, not a container image (WasmBuildService)
            $kind = $this->isWasmProject($project) ? ImageSecurityReport::KIND_WASM : ImageSecurityReport::KIND_BUILT;

            return [$kind, $version->build_image];
        }

        return null;
    }

    /** The project runs on a WebAssembly runtime: its builds are Spin OCI artifacts. */
    public function isWasmProject(Project $project): bool
    {
        try {
            return WasmRuntimes::isWasm(app(RuntimeRegistry::class)->forProject($project));
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    public function stepsFor(string $kind, RegionSupplyChainSetting $settings, KnativeRegion $region, bool $rescan = false): array
    {
        $signs = $kind !== ImageSecurityReport::KIND_EXTERNAL && $settings->signing_enabled && $settings->hasSigningKey();

        // A Spin app: cosign signs any OCI artifact, syft and Trivy read container filesystems only
        if ($kind === ImageSecurityReport::KIND_WASM) {
            return ! $rescan && $signs ? ['sign'] : [];
        }

        if ($rescan) {
            return array_values(array_filter([
                $settings->scan_enabled ? 'scan' : null,
                $settings->scan_enabled && $signs && $settings->attest_enabled ? 'attest' : null,
            ]));
        }

        $sbom = $settings->sbom_enabled;
        $scan = $settings->scan_enabled;

        return array_values(array_filter([
            $sbom ? 'sbom' : null,
            $sbom && $kind !== ImageSecurityReport::KIND_EXTERNAL && $region->has_registry ? 'attach' : null,
            $scan ? 'scan' : null,
            $signs ? 'sign' : null,
            $signs && $settings->attest_enabled && ($sbom || $scan) ? 'attest' : null,
        ]));
    }

    private function queue(ImageSecurityReport $report, array $attributes, array $steps, bool $rescan): ImageSecurityReport
    {
        $reset = array_fill_keys(array_map(fn ($s) => self::statusColumn($s), ImageSecurityReport::STEPS), ImageSecurityReport::STEP_SKIPPED);
        foreach ($steps as $step) {
            $reset[self::statusColumn($step)] = ImageSecurityReport::STEP_PENDING;
        }

        if ($rescan && $report->exists) {
            // A re-scan keeps the SBOM, referrers and signature of the image
            $reset = array_intersect_key($reset, array_flip(array_map(fn ($s) => self::statusColumn($s), $steps)));
        }

        $report->fill($attributes + $reset + [
            'status' => 'pending',
            'steps' => $steps,
            'error' => null,
            'job_name' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);
        if (! $rescan || ! $report->exists) {
            $report->image_digest = null;
        }
        $report->save();

        StartSupplyChainJob::dispatch($report);

        return $report;
    }

    // ── Cluster ─────────────────────────────────────────────────────────────

    /** Creates the Job of a pending report in its region. */
    public function submit(ImageSecurityReport $report): void
    {
        $region = KnativeRegion::find($report->knative_region_id);
        if (! $region || ! $region->kubeconfig) {
            $this->fail($report, 'Region kubeconfig not available');

            return;
        }

        $settings = RegionSupplyChainSetting::forRegion($region);
        $steps = $report->steps ?? [];
        $k8s = $this->client($region);
        $internal = $report->image_kind !== ImageSecurityReport::KIND_EXTERNAL;

        $this->ensureNamespace($k8s);

        $registryAuth = $internal && $this->ensureRegistryAuth($k8s, $region);

        if (array_intersect($steps, ['sign', 'attest'])) {
            if (! $this->ensureKeySecret($k8s, $settings)) {
                $steps = array_values(array_diff($steps, ['sign', 'attest']));
                $report->forceFill(['signature_status' => ImageSecurityReport::STEP_FAILED, 'attestation_status' => ImageSecurityReport::STEP_FAILED, 'steps' => $steps]);
                $report->error = 'The region signing key is not available on the cluster';
            }
        }

        $name = self::jobName($report);
        $ns = SupplyChainManifests::namespace();
        $k8s->deleteResource('batch/v1', 'Job', $name, $ns, 'Background');

        $image = $report->image_digest && ! str_contains($report->image_ref, '@')
            ? ImageRef::parse($report->image_ref)->withDigest($report->image_digest)
            : $report->image_ref;

        $manifest = SupplyChainManifests::pipelineJob($name, $region, $settings, $image, $steps, $report->getKey(), $registryAuth, $internal);
        if (! $k8s->createResource($manifest, $ns)) {
            $this->fail($report, "Could not create the supply-chain Job {$ns}/{$name}");

            return;
        }

        $running = [];
        foreach ($steps as $step) {
            $running[self::statusColumn($step)] = ImageSecurityReport::STEP_RUNNING;
        }
        $report->fill($running + ['status' => 'running', 'job_name' => $name, 'started_at' => now()])->save();

        CheckSupplyChainJob::start($report);

        Log::info('Supply-chain Job submitted', ['report' => $report->getKey(), 'image' => $image, 'steps' => $steps, 'region' => $region->name]);
    }

    /**
     * One look at the report's Job. True when the report is final (results recorded, or failed).
     */
    public function collect(ImageSecurityReport $report): bool
    {
        $region = KnativeRegion::find($report->knative_region_id);
        if (! $region || ! $region->kubeconfig) {
            $this->fail($report, 'Region kubeconfig not available');

            return true;
        }

        $k8s = $this->client($region);
        $ns = SupplyChainManifests::namespace();
        $job = $k8s->getResourceStatus('batch/v1', 'Job', (string) $report->job_name, $ns);

        if (! $job) {
            $this->fail($report, "Supply-chain Job {$ns}/{$report->job_name} not found");

            return true;
        }

        $status = $job['status'] ?? [];
        $succeeded = ($status['succeeded'] ?? 0) >= 1;
        $failed = ($status['failed'] ?? 0) >= 1;
        if (! $succeeded && ! $failed) {
            return false;
        }

        $pods = $k8s->listResources('v1', 'Pod', $ns, ['job-name' => $report->job_name]);
        $pod = $pods['items'][0]['metadata']['name'] ?? null;
        $log = $pod ? $k8s->getPodLogs($pod, $ns, 'supply-chain', 2000) : null;

        $output = PipelineOutput::parse($log);
        if (! $output->done) {
            $reason = self::jobFailureReason($job) ?? 'The supply-chain Job ended without reporting its results';
            $this->record($report, $output);
            $this->fail($report, $reason);

            return true;
        }

        $this->record($report, $output);

        return true;
    }

    /** Stores what a run reported: digest, SBOMs + summary, scan results, signature, attestations. */
    public function record(ImageSecurityReport $report, PipelineOutput $output): void
    {
        $settings = RegionSupplyChainSetting::forRegion($report->knative_region_id);
        $steps = $report->steps ?? [];
        $results = $report->step_results ?? [];
        $update = [];

        foreach ($output->steps as $name => $result) {
            $results[$name] = $result;
        }

        if ($output->digest) {
            $update['image_digest'] = $output->digest;
        }

        foreach ($steps as $step) {
            $result = $output->step($step);
            $update[self::statusColumn($step)] = match ($result['status'] ?? null) {
                'ok' => ImageSecurityReport::STEP_OK,
                'skipped' => ImageSecurityReport::STEP_SKIPPED,
                default => ImageSecurityReport::STEP_FAILED,
            };
        }

        // SBOM documents (the downloadable copies) and their summary
        $spdx = $output->file('sbom-spdx');
        $cdx = $output->file('sbom-cdx');
        if (in_array('sbom', $steps, true) && ($spdx !== null || $cdx !== null)) {
            foreach (['spdx-json' => $spdx, 'cyclonedx-json' => $cdx] as $format => $document) {
                if ($document === null) {
                    continue;
                }
                $report->sbomDocuments()->updateOrCreate(['format' => $format], [
                    'content' => ImageSbomDocument::encode($document),
                    'size_bytes' => strlen($document),
                    'sha256' => hash('sha256', $document),
                ]);
            }
            $update['sbom_summary'] = $this->sboms->summarize($spdx, $cdx);
        }

        // Vulnerability scan
        $trivy = $output->file('trivy');
        if (in_array('scan', $steps, true) && $trivy !== null && $output->ok('scan')) {
            try {
                $parsed = $this->trivy->parse($trivy);
                $update += [
                    'critical_count' => $parsed['counts']['critical'],
                    'high_count' => $parsed['counts']['high'],
                    'medium_count' => $parsed['counts']['medium'],
                    'low_count' => $parsed['counts']['low'],
                    'unknown_count' => $parsed['counts']['unknown'],
                    'fixable_critical_count' => $parsed['fixable']['critical'],
                    'fixable_high_count' => $parsed['fixable']['high'],
                    'findings_total' => $parsed['total'],
                    'findings' => $parsed['findings'],
                    'scanned_at' => now(),
                ];
                $scanner = ['name' => 'trivy', 'os' => $parsed['os']];
                if ($version = $output->file('trivy-version')) {
                    $scanner += $this->trivy->parseVersion($version);
                }
                $scanner['db_mode'] = $settings->scanner_db_mode;
                $update['scanner'] = $scanner;
                $update['image_digest'] ??= $parsed['digest'];
            } catch (\Throwable $e) {
                $update['scan_status'] = ImageSecurityReport::STEP_FAILED;
                $results['scan'] = ['status' => 'failed', 'message' => 'Scan report could not be read: '.$e->getMessage()];
            }
        } elseif (in_array('scan', $steps, true) && $output->ok('scan')) {
            // Larger than the inline limit: the scan ran, its report did not come back
            $update['scan_status'] = ImageSecurityReport::STEP_FAILED;
            $results['scan'] = ['status' => 'failed', 'message' => 'The scan report exceeded the inline size limit'];
        }

        if (in_array('sign', $steps, true) && $output->ok('sign')) {
            $update['signed_at'] = now();
            $update['signature_key_fingerprint'] = $settings->signing_key_fingerprint;
        }

        $failedSteps = array_keys(array_filter($output->steps, fn ($r) => $r['status'] === 'failed'));

        $report->fill($update + [
            'step_results' => $results,
            'status' => 'completed',
            'finished_at' => now(),
            'error' => $failedSteps ? 'Failed: '.implode(', ', $failedSteps) : $report->error,
        ])->save();

        if (in_array('sign', $steps, true) && $output->ok('sign')) {
            SupplyChainAudit::record('image.signed', [
                'report_id' => $report->getKey(),
                'image' => $report->image_ref,
                'digest' => $report->image_digest,
                'fingerprint' => $settings->signing_key_fingerprint,
            ], $report->knative_region_id);
        }

        $this->broadcast($report);
    }

    public function fail(ImageSecurityReport $report, string $reason): void
    {
        $update = ['status' => 'failed', 'error' => mb_substr($reason, 0, 1000), 'finished_at' => now()];
        foreach (ImageSecurityReport::STEPS as $step) {
            $column = self::statusColumn($step);
            if (in_array($report->{$column}, [ImageSecurityReport::STEP_PENDING, ImageSecurityReport::STEP_RUNNING], true)) {
                $update[$column] = ImageSecurityReport::STEP_FAILED;
            }
        }
        $report->fill($update)->save();

        Log::warning('Supply-chain run failed', ['report' => $report->getKey(), 'image' => $report->image_ref, 'reason' => $reason]);
        $this->broadcast($report);
    }

    public static function jobName(ImageSecurityReport $report): string
    {
        return 'sc-'.strtolower((string) $report->getKey());
    }

    /** Report column of a step's status. */
    public static function statusColumn(string $step): string
    {
        return match ($step) {
            'sbom' => 'sbom_status',
            'attach' => 'referrer_status',
            'scan' => 'scan_status',
            'sign' => 'signature_status',
            'attest' => 'attestation_status',
            default => "{$step}_status",
        };
    }

    public function ensureNamespace(KubernetesClient $k8s): void
    {
        $k8s->applyClusterResource(SupplyChainManifests::namespaceManifest());
    }

    /**
     * The region signing key on the cluster: present, or restored from the API's encrypted copy
     * (a region moved to a new cluster, or the Secret was deleted).
     */
    public function ensureKeySecret(KubernetesClient $k8s, RegionSupplyChainSetting $settings): bool
    {
        $ns = SupplyChainManifests::namespace();
        $secret = $k8s->getResourceStatus('v1', 'Secret', SupplyChainManifests::keySecretName(), $ns);
        $wanted = (string) $settings->signing_key_fingerprint;

        if ($secret && ($secret['metadata']['annotations']['runr.sh/key-fingerprint'] ?? $wanted) === $wanted) {
            return true;
        }

        if (! $settings->hasSigningKey() || ! $settings->signing_private_key || ! $settings->signing_key_password) {
            return false;
        }

        return $k8s->applyResource(SupplyChainManifests::keySecret($settings), $ns);
    }

    /** Docker config Secret of an external registry with credentials; false when none is needed. */
    public function ensureRegistryAuth(KubernetesClient $k8s, KnativeRegion $region): bool
    {
        if ($region->registry_mode === 'internal' || ! $region->registry_username || ! $region->registry_password) {
            return false;
        }

        return $k8s->applyResource(SupplyChainManifests::registryAuthSecret($region), SupplyChainManifests::namespace());
    }

    protected function client(KnativeRegion $region): KubernetesClient
    {
        return new KubernetesClient($region->kubeconfig);
    }

    private static function jobFailureReason(array $job): ?string
    {
        foreach ($job['status']['conditions'] ?? [] as $condition) {
            if (($condition['type'] ?? '') === 'Failed') {
                return trim(($condition['reason'] ?? '').': '.($condition['message'] ?? ''), ': ');
            }
        }

        return null;
    }

    private function broadcast(ImageSecurityReport $report): void
    {
        try {
            event(new PlatformEvent('supply_chain.report.updated', [
                'project_id' => $report->project_id,
                'version_id' => $report->version_id,
                'report' => $report->summary(),
            ]));
        } catch (\Throwable $e) {
            Log::debug('Supply-chain report not broadcast', ['error' => $e->getMessage()]);
        }
    }
}
