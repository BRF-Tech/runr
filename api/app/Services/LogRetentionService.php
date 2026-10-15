<?php

namespace App\Services;

use App\Models\KnativeRegion;
use App\Models\LogRetentionConfig;
use App\Models\Project;
use App\Services\Helpers\MonitoringEndpoint;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Log retention: Loki delete requests for the log streams of a region's projects that are older
 * than the region's retention_days.
 *
 * The region's Loki is the one the log view reads (KnativeLokiService): loki_url — RunR's
 * loki-stack in 'observability' by default (loki_managed, KnativeLokiTrait), or an existing
 * in-cluster Loki the region reuses — reached through the region's Kubernetes API service
 * proxy, so a remote region's Loki is addressed on that region's cluster, never by an
 * in-cluster name resolved inside RunR's own cluster. loki_org_id is sent as X-Scope-OrgID.
 */
class LogRetentionService
{
    /** Loki log deletion API (POST, query string: query / start / end) */
    private const DELETE_PATH = 'loki/api/v1/delete';

    /** Start of the deleted window (Unix seconds): everything up to the end */
    private const DELETE_FROM = 1;

    /**
     * Enforce log retention policy for a region.
     * Deletes Loki log streams older than retention_days,
     * but always protects the last N deployments per project.
     */
    public function enforceRetention(KnativeRegion $region): void
    {
        $config = LogRetentionConfig::where('region_id', $region->id)->first();
        $days = $config->retention_days ?? 90;
        $keepN = $config->keep_last_n_deployments ?? 5;

        // Same gate as the log view: no Loki (not installed / not up yet), nothing to trim
        if (! $region->kubeconfig || $region->loki_status !== 'ready') {
            return;
        }

        $projects = Project::where('knative_region_id', $region->id)->get();
        if ($projects->isEmpty()) {
            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
        } catch (\Throwable $e) {
            Log::warning('Log retention enforcement failed', [
                'region' => $region->name,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $loki = MonitoringEndpoint::loki($region);

        foreach ($projects as $project) {
            $this->enforceProjectRetention($project, $k8s, $loki, $days, $keepN, $region);
        }
    }

    /**
     * Protect recent deployments from log cleanup.
     */
    public function protectRecentDeployments(Project $project, int $keepCount = 5): array
    {
        return $project->deployments()
            ->orderByDesc('created_at')
            ->take($keepCount)
            ->pluck('id')
            ->toArray();
    }

    private function enforceProjectRetention(
        Project $project,
        KubernetesClient $k8s,
        MonitoringEndpoint $loki,
        int $days,
        int $keepN,
        KnativeRegion $region,
    ): void {
        $protectedIds = $this->protectRecentDeployments($project, $keepN);
        $cutoff = now()->subDays($days);
        $end = $this->deleteUntil($project, $protectedIds, $cutoff);
        // The namespace the project's pods run in — the stream selector of the log view
        $ns = $region->project_namespace ?? $region->name ?? 'runr';
        $headers = $region->loki_org_id ? ['X-Scope-OrgID' => $region->loki_org_id] : [];

        if ($end->getTimestamp() <= self::DELETE_FROM) {
            return;
        }

        try {
            $response = $k8s->serviceProxyRequest(
                'POST', $loki->namespace, $loki->service, $loki->port, $loki->proxyPath(self::DELETE_PATH),
                headers: $headers,
                query: [
                    'query' => "{namespace=\"{$ns}\",pod=~\"{$project->slug}-.*\"}",
                    'start' => self::DELETE_FROM,
                    'end' => $end->getTimestamp(),
                ],
                scheme: $loki->scheme,
            );

            if (! $response->successful()) {
                Log::warning('Log retention enforcement failed', [
                    'project' => $project->slug,
                    'loki' => "{$loki->namespace}/{$loki->service}:{$loki->port}",
                    'status' => $response->status(),
                    'error' => mb_substr($response->body(), 0, 500),
                ]);

                return;
            }

            Log::info('Log retention enforced', [
                'project' => $project->slug,
                'cutoff' => $cutoff->toIso8601String(),
                'until' => $end->toIso8601String(),
                'protected' => count($protectedIds),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Log retention enforcement failed', [
                'project' => $project->slug,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * End of the deleted time window: the retention cutoff, but never later than the start of
     * the oldest protected deployment (the last keep_last_n_deployments). Every line a protected
     * deployment wrote is newer than its start, so it survives — Loki's stream labels (namespace,
     * pod, container) carry no deployment, which is why the protection is a time bound.
     *
     * @param  list<string>  $protectedIds  protectRecentDeployments()
     */
    private function deleteUntil(Project $project, array $protectedIds, CarbonInterface $cutoff): CarbonInterface
    {
        if ($protectedIds === []) {
            return $cutoff;
        }

        $oldestProtected = $project->deployments()->whereIn('id', $protectedIds)->min('created_at');
        if ($oldestProtected === null) {
            return $cutoff;
        }

        $since = Carbon::parse($oldestProtected);

        return $since->lt($cutoff) ? $since : $cutoff;
    }
}
