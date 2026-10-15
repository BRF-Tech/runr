<?php

namespace App\Jobs\GitDeploy;

use App\Models\Git\PreviewEnvironment;
use App\Services\GitDeploy\PreviewService;
use App\Services\KubernetesClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Poll the preview's Knative Service until its latest revision is ready (the readiness rule of
 * CheckDeploymentReadyJob: Ready=True for the current generation), up to TIMEOUT seconds.
 */
class CheckPreviewReadyJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const POLL = 3;

    public const TIMEOUT = 300;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public string $previewId,
        public int $attempt = 1,
    ) {}

    public static function start(string $previewId): PendingDispatch
    {
        return self::dispatch($previewId, 1)->delay(now()->addSeconds(1));
    }

    public function handle(PreviewService $previews): void
    {
        $preview = PreviewEnvironment::with('project.knativeRegion')->find($this->previewId);
        if (! $preview || $preview->status !== PreviewEnvironment::DEPLOYING) {
            return;
        }

        $region = $preview->project?->knativeRegion;
        if (! $region || ! $region->kubeconfig) {
            $previews->fail($preview, $previews->currentDeploy($preview), 'Region kubeconfig not available');

            return;
        }

        $message = 'Not ready';
        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $svc = $k8s->getResourceStatus('serving.knative.dev/v1', 'Service', $preview->service_name, $region->project_namespace ?? $region->name);

            if ($svc) {
                $ready = collect($svc['status']['conditions'] ?? [])->firstWhere('type', 'Ready');
                $observed = (int) ($svc['status']['observedGeneration'] ?? 0) >= (int) ($svc['metadata']['generation'] ?? 0);
                $latest = ($svc['status']['latestCreatedRevisionName'] ?? null) === ($svc['status']['latestReadyRevisionName'] ?? null);

                if (($ready['status'] ?? null) === 'True' && $observed && $latest) {
                    $previews->ready($preview);

                    return;
                }
                if (($ready['status'] ?? null) === 'False' && $observed && ($ready['reason'] ?? null) === 'RevisionFailed') {
                    $previews->fail($preview, $previews->currentDeploy($preview), $ready['message'] ?? 'The revision failed');

                    return;
                }
                $message = $ready['message'] ?? $ready['reason'] ?? $message;
            } else {
                $message = 'Knative Service not found';
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        }

        if ($this->attempt * self::POLL >= self::TIMEOUT) {
            $previews->fail($preview, $previews->currentDeploy($preview), 'Readiness timeout: '.$message);

            return;
        }

        self::dispatch($this->previewId, $this->attempt + 1)->delay(now()->addSeconds(self::POLL));
    }
}
