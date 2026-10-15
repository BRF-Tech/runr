<?php

namespace App\Jobs;

use App\Models\KnativeRegion;
use App\Models\RuntimeBuild;
use App\Services\KubernetesClient;
use App\Services\Runtimes\RuntimeImageBuildService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Follows the Kaniko Job of a runtime image build (RuntimeImageBuildService) until it succeeds,
 * fails or runs past its deadline; one check per POLL_INTERVAL seconds, re-dispatched.
 */
class CheckRuntimeImageBuildJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 30;

    public int $tries = 1;

    public bool $deleteWhenMissingModels = true;

    public const POLL_INTERVAL = 3;

    public function __construct(public RuntimeBuild $build, public int $attempt = 1) {}

    public static function start(RuntimeBuild $build): PendingDispatch
    {
        return self::dispatch($build)->delay(now()->addSeconds(self::POLL_INTERVAL));
    }

    public static function maxAttempts(): int
    {
        return (int) ceil((RuntimeImageBuildService::JOB_DEADLINE_SECONDS + 60) / self::POLL_INTERVAL);
    }

    public function handle(): void
    {
        $build = $this->build->fresh();
        if (! $build || $build->status !== RuntimeBuild::STATUS_BUILDING) {
            return;
        }

        $region = KnativeRegion::find($build->knative_region_id);
        if (! $region || ! $region->kubeconfig) {
            $build->update(['status' => RuntimeBuild::STATUS_FAILED, 'error' => 'Region kubeconfig not available']);

            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $namespace = $region->project_namespace ?? $region->name;
            $job = $k8s->getResourceStatus('batch/v1', 'Job', $build->job_name, $namespace);
            $status = $job['status'] ?? [];

            if (($status['succeeded'] ?? 0) >= 1) {
                $build->update(['status' => RuntimeBuild::STATUS_READY, 'error' => null]);
                $k8s->deleteResource('v1', 'ConfigMap', $build->job_name, $namespace);
                Log::info('Runtime image build ready', ['runtime' => $build->runtime_id, 'version' => $build->version, 'image' => $build->image]);

                return;
            }

            if (($status['failed'] ?? 0) >= 1) {
                $build->update(['status' => RuntimeBuild::STATUS_FAILED, 'error' => $this->failureReason($k8s, $build, $namespace)]);
                $k8s->deleteResource('v1', 'ConfigMap', $build->job_name, $namespace);

                return;
            }
        } catch (\Throwable $e) {
            Log::warning('Runtime image build check failed', ['build' => $build->id, 'error' => $e->getMessage()]);
        }

        if ($this->attempt >= self::maxAttempts()) {
            $build->update(['status' => RuntimeBuild::STATUS_FAILED, 'error' => 'Build timed out']);

            return;
        }

        self::dispatch($build, $this->attempt + 1)->delay(now()->addSeconds(self::POLL_INTERVAL));
    }

    private function failureReason(KubernetesClient $k8s, RuntimeBuild $build, string $namespace): string
    {
        $pods = $k8s->listResources('v1', 'Pod', $namespace, ['job-name' => $build->job_name]);
        $pod = $pods['items'][0]['metadata']['name'] ?? null;
        $logs = $pod ? (string) $k8s->getPodLogs($pod, $namespace, 'kaniko', 20) : '';
        $logs = trim(preg_replace('/\x1b\[[0-9;]*m/', '', $logs));

        return mb_substr($logs !== '' ? $logs : 'Kaniko build failed', -1000);
    }
}
