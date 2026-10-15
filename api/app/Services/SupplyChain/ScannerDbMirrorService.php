<?php

namespace App\Services\SupplyChain;

use App\Jobs\RefreshScannerDbJob;
use App\Models\KnativeRegion;
use App\Models\RegionSupplyChainSetting;
use App\Services\KubernetesClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Trivy vulnerability DB in the region registry, for regions that scan without reaching the
 * internet at scan time:
 *
 *  - mirror   RunR copies the DB (OCI artifact, mirror.gcr.io/aquasec/trivy-db:2 by default)
 *             into <registry>/runr-supply-chain/trivy-db:2 on a schedule
 *             (supply_chain.scan.db_refresh_hours) while the region has internet access;
 *             scans read that copy.
 *  - offline  scans read the copy in the region registry; RunR never refreshes it by itself (an
 *             operator pushes it, e.g. `oras cp` from a connected machine, or presses Refresh
 *             while a connection is available).
 *  - online   scans download the DB from Trivy's default sources.
 */
class ScannerDbMirrorService
{
    public function refresh(KnativeRegion $region): RegionSupplyChainSetting
    {
        $settings = RegionSupplyChainSetting::firstOrCreateFor($region);

        if (! $region->has_registry || ! $region->registry_address) {
            throw new \RuntimeException(__('supply_chain.scanner_db.no_registry'));
        }
        if ($settings->scanner_db_status === 'refreshing') {
            return $settings;
        }

        $settings->forceFill([
            'scanner_db_status' => 'refreshing',
            'scanner_db_message' => null,
            'scanner_db_job' => 'trivy-db-'.now()->format('YmdHis'),
        ])->save();

        RefreshScannerDbJob::dispatch($region);

        return $settings;
    }

    /** Creates the mirror Job (RefreshScannerDbJob, first run). */
    public function submit(KnativeRegion $region): void
    {
        $settings = RegionSupplyChainSetting::firstOrCreateFor($region);
        $k8s = $this->client($region);
        $ns = SupplyChainManifests::namespace();
        $job = (string) $settings->scanner_db_job;

        $k8s->applyClusterResource(SupplyChainManifests::namespaceManifest());
        $auth = app(SupplyChainPipeline::class)->ensureRegistryAuth($k8s, $region);
        $k8s->deleteResource('batch/v1', 'Job', $job, $ns, 'Background');

        if (! $k8s->createResource(SupplyChainManifests::mirrorJob($job, $region, $settings, $auth), $ns)) {
            throw new \RuntimeException("Could not create the scanner DB Job {$ns}/{$job}");
        }
    }

    /** One look at the mirror Job; true when it finished (status recorded). */
    public function collect(KnativeRegion $region): bool
    {
        $settings = RegionSupplyChainSetting::firstOrCreateFor($region);
        $k8s = $this->client($region);
        $ns = SupplyChainManifests::namespace();
        $job = $k8s->getResourceStatus('batch/v1', 'Job', (string) $settings->scanner_db_job, $ns);

        if (! $job) {
            $this->finish($settings, false, 'Scanner DB Job not found');

            return true;
        }

        $status = $job['status'] ?? [];
        if (($status['succeeded'] ?? 0) < 1 && ($status['failed'] ?? 0) < 1) {
            return false;
        }

        $pods = $k8s->listResources('v1', 'Pod', $ns, ['job-name' => $settings->scanner_db_job]);
        $pod = $pods['items'][0]['metadata']['name'] ?? null;
        $output = PipelineOutput::parse($pod ? $k8s->getPodLogs($pod, $ns, 'supply-chain', 200) : null);

        if (! $output->ok('mirror')) {
            $this->finish($settings, false, $output->step('mirror')['message'] ?? 'The Trivy DB could not be copied');

            return true;
        }

        $meta = [];
        if ($version = $output->file('trivy-version')) {
            $meta = app(TrivyReportParser::class)->parseVersion($version);
        }

        $warnings = array_filter([
            $output->ok('mirror-java') || ! $output->step('mirror-java') ? null : 'Java DB: '.($output->step('mirror-java')['message'] ?? 'failed'),
            $output->ok('verify') ? null : 'Verification: '.($output->step('verify')['message'] ?? 'failed'),
        ]);

        $this->finish($settings, true, $warnings ? implode("\n", $warnings) : null, $meta['db_updated_at'] ?? null);

        SupplyChainAudit::record('region.scanner_db.refreshed', [
            'db_updated_at' => $meta['db_updated_at'] ?? null,
            'source' => $settings->scanner_db_source ?: config('supply_chain.scan.db_source'),
        ], $region->getKey());

        return true;
    }

    public function finish(RegionSupplyChainSetting $settings, bool $ok, ?string $message, ?string $dbUpdatedAt = null): void
    {
        $update = [
            'scanner_db_status' => $ok ? 'ready' : 'failed',
            'scanner_db_message' => $message ? mb_substr($message, 0, 1000) : null,
            'scanner_db_refreshed_at' => now(),
        ];
        if ($dbUpdatedAt) {
            $update['scanner_db_updated_at'] = Carbon::parse($dbUpdatedAt);
        }

        $settings->forceFill($update)->save();
    }

    /**
     * Scheduler: refreshes every "mirror" region whose copy is older than the refresh interval.
     *
     * @return int regions refreshed
     */
    public function refreshDue(): int
    {
        $hours = max(1, (int) config('supply_chain.scan.db_refresh_hours', 12));
        $due = RegionSupplyChainSetting::query()
            ->where('scanner_db_mode', 'mirror')
            ->where('scanner_db_status', '!=', 'refreshing')
            ->where(fn ($q) => $q->whereNull('scanner_db_refreshed_at')->orWhere('scanner_db_refreshed_at', '<', now()->subHours($hours)))
            ->get();

        $count = 0;
        foreach ($due as $settings) {
            try {
                SupplyChainContext::forRegion($settings, function (KnativeRegion $region) use (&$count) {
                    if ($region->kubeconfig && $region->has_registry) {
                        $this->refresh($region);
                        $count++;
                    }
                });
            } catch (\Throwable $e) {
                Log::warning('Scanner DB refresh could not start', ['region' => $settings->knative_region_id, 'error' => $e->getMessage()]);
            }
        }

        return $count;
    }

    protected function client(KnativeRegion $region): KubernetesClient
    {
        return new KubernetesClient($region->kubeconfig);
    }
}
