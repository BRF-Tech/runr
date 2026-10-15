<?php

namespace App\Services\SupplyChain;

use App\Models\AiModelService;
use App\Models\Deployment;
use App\Models\ImageSecurityReport;
use App\Models\RegionSupplyChainSetting;
use App\Models\RuntimeBuild;
use App\Models\UploadedImage;
use App\Models\Version;
use Illuminate\Support\Facades\Log;

/**
 * Re-scans of deployed images on a schedule: new CVEs are published for images that did not
 * change. Every image a live deployment or model service runs (and every ready runtime image) whose last scan is
 * older than its region's rescan_interval_hours is scanned again — with the current DB, its SBOM
 * and signature untouched.
 */
class RescanService
{
    /** @var array<string, int> region id → re-scan interval (hours), per run */
    private array $intervals = [];

    public function __construct(private SupplyChainPipeline $pipeline) {}

    /** @return int re-scans queued */
    public function rescanDue(): int
    {
        $count = 0;
        $this->intervals = [];

        SupplyChainContext::each(function ($tenant) use (&$count) {
            $count += $this->rescanVersions();
            $count += $this->rescanModelServices();
            if ($tenant === null) {
                $count += $this->rescanRuntimeImages();
                $count += $this->rescanUploadedImages();
            }
        });

        return $count;
    }

    private function rescanVersions(): int
    {
        $versionIds = Deployment::query()
            ->whereIn('status', ['pending', 'running', 'active'])
            ->pluck('version_id')
            ->unique()
            ->all();
        if (! $versionIds) {
            return 0;
        }

        $count = 0;
        $reports = ImageSecurityReport::query()
            ->where('subject_type', ImageSecurityReport::SUBJECT_VERSION)
            ->whereIn('subject_id', $versionIds)
            ->whereNotIn('status', ['pending', 'running'])
            ->get();

        foreach ($reports as $report) {
            if (! $this->due($report)) {
                continue;
            }
            $version = Version::find($report->subject_id);
            if ($version && $this->queue(fn () => $this->pipeline->queueForVersion($version, rescan: true), $report)) {
                $count++;
            }
        }

        return $count;
    }

    /** Images of AI model services that run (or are on their way). */
    private function rescanModelServices(): int
    {
        $active = AiModelService::query()
            ->whereIn('status', [AiModelService::STATUS_DEPLOYING, AiModelService::STATUS_READY, AiModelService::STATUS_WAITING_SNAPSHOT])
            ->pluck('id')
            ->all();
        if (! $active) {
            return 0;
        }

        $count = 0;
        $reports = ImageSecurityReport::query()
            ->where('subject_type', ImageSecurityReport::SUBJECT_MODEL_SERVICE)
            ->whereIn('subject_id', $active)
            ->whereNotIn('status', ['pending', 'running'])
            ->get();

        foreach ($reports as $report) {
            if (! $this->due($report)) {
                continue;
            }
            $service = AiModelService::with('model', 'region')->find($report->subject_id);
            if ($service && $this->queue(fn () => $this->pipeline->queueForModelService($service, rescan: true), $report)) {
                $count++;
            }
        }

        return $count;
    }

    private function rescanRuntimeImages(): int
    {
        $count = 0;
        $reports = ImageSecurityReport::onCentral()
            ->where('subject_type', ImageSecurityReport::SUBJECT_RUNTIME_BUILD)
            ->whereNotIn('status', ['pending', 'running'])
            ->get();

        foreach ($reports as $report) {
            if (! $this->due($report)) {
                continue;
            }
            $build = RuntimeBuild::find($report->subject_id);
            if ($build && $this->queue(fn () => $this->pipeline->queueForRuntimeBuild($build, rescan: true), $report)) {
                $count++;
            }
        }

        return $count;
    }

    /** Uploaded images (central schema, docs/images.md): every one that is still uploaded. */
    private function rescanUploadedImages(): int
    {
        $count = 0;
        $reports = ImageSecurityReport::onCentral()
            ->where('subject_type', ImageSecurityReport::SUBJECT_UPLOADED_IMAGE)
            ->whereNotIn('status', ['pending', 'running'])
            ->get();

        foreach ($reports as $report) {
            if (! $this->due($report)) {
                continue;
            }
            $image = UploadedImage::find($report->subject_id);
            if ($image && $this->queue(fn () => $this->pipeline->queueForUploadedImage($image, rescan: true), $report)) {
                $count++;
            }
        }

        return $count;
    }

    /** Whether a report's last scan is older than its region's re-scan interval. */
    public function due(ImageSecurityReport $report): bool
    {
        $interval = $this->intervals[$report->knative_region_id] ??= RegionSupplyChainSetting::forRegion($report->knative_region_id)->rescan_interval_hours;

        if ($interval <= 0) {
            return false;
        }

        $last = $report->scanned_at ?? $report->finished_at;

        return ! $last || $last->lt(now()->subHours($interval));
    }

    private function queue(callable $start, ImageSecurityReport $report): bool
    {
        try {
            return $start() !== null;
        } catch (\Throwable $e) {
            Log::warning('Re-scan could not be queued', ['report' => $report->getKey(), 'error' => $e->getMessage()]);

            return false;
        }
    }
}
