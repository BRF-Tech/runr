<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\KubernetesClient;
use App\Services\Projects\LiveDeployments;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CheckDeploymentReadyJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, Queueable;

    public int $timeout = 30;

    public int $tries = 1;

    /** The project (or deployment) was deleted while the check was queued: nothing to check */
    public bool $deleteWhenMissingModels = true;

    /** First check ~1 s after the Knative Service was applied, then every 2 s */
    public const FIRST_CHECK_DELAY = 1;

    public const POLL_INTERVAL = 2;

    /** Overall timeout, unchanged (formerly 60 checks × 5 s): maxAttempts() checks on this schedule */
    public const TIMEOUT_SECONDS = 300;

    public function __construct(
        public Deployment $deployment,
        public Project $project,
        public int $attempt = 1,
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    /** The first check of an applied deployment. */
    public static function start(Deployment $deployment, Project $project, ?string $initiatorId = null): PendingDispatch
    {
        return self::dispatch($deployment, $project, 1, $initiatorId)->delay(now()->addSeconds(self::FIRST_CHECK_DELAY));
    }

    /** Checks that fit the timeout: the first one plus one per poll interval. */
    public static function maxAttempts(): int
    {
        return 1 + (int) ceil((self::TIMEOUT_SECONDS - self::FIRST_CHECK_DELAY) / self::POLL_INTERVAL);
    }

    public function handle(): void
    {
        $deployment = $this->deployment->fresh();

        if (! $deployment || ! in_array($deployment->status, ['pending', 'running'])) {
            return;
        }

        $region = $this->project->knativeRegion;
        if (! $region || ! $region->kubeconfig) {
            $this->markFailed($deployment, 'Region kubeconfig not available');

            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $namespace = $region->project_namespace ?? $region->name;
            $slug = $this->project->slug;

            $svc = $k8s->getResourceStatus('serving.knative.dev/v1', 'Service', $slug, $namespace);

            if (! $svc) {
                $this->retryOrTimeout($deployment, 'Knative Service not found');

                return;
            }

            $ready = $this->findReadyCondition($svc);

            // Right after an apply the controller may not have seen the new template yet: the
            // Ready condition still describes the previous revision until observedGeneration
            // catches up and the latest created revision is the ready one.
            if ($ready && $ready['status'] === 'True' && ! $this->reconciled($svc)) {
                $this->retryOrTimeout($deployment, 'Waiting for the new revision');

                return;
            }

            if ($ready && $ready['status'] === 'True') {
                // The Knative Service is the project's (by name): a newer deploy that replaced this
                // one while it started owns what is ready now — this deployment ends without going
                // live (a deploy supersedes every live deployment; this covers two deploys at once)
                $live = app(LiveDeployments::class);
                if ($live->hasNewer($deployment)) {
                    $live->retire($deployment);
                    Log::info('Deployment replaced while it started: not activated', ['deployment' => $deployment->id]);

                    return;
                }

                // Only a deployment that is still pending becomes active: one stopped (or replaced)
                // while it started stays shutting_down for its teardown (ProcessTeardown)
                if (! $deployment->transition(['pending', 'running'], [
                    'status' => 'active',
                    'knative_ready' => true,
                    'knative_message' => $ready['message'] ?? null,
                    'ready_at' => now(),
                ])) {
                    return;
                }
                Log::info('Deployment ready', [
                    'deployment' => $deployment->id,
                    'attempt' => $this->attempt,
                ]);

                $this->broadcastSuccess('deployment.ready', [
                    'project_id' => $this->project->id,
                    'project_name' => $this->project->name,
                    'deployment_id' => $deployment->id,
                    'status' => 'active',
                ], "Deployment ready: {$this->project->name}");

                return;
            }

            $message = $ready['message'] ?? $ready['reason'] ?? 'Not ready';
            $this->retryOrTimeout($deployment, $message);

        } catch (\Throwable $e) {
            $this->retryOrTimeout($deployment, $e->getMessage());
        }
    }

    private function reconciled(array $svc): bool
    {
        $generation = (int) ($svc['metadata']['generation'] ?? 0);
        $observed = (int) ($svc['status']['observedGeneration'] ?? 0);
        $created = $svc['status']['latestCreatedRevisionName'] ?? null;
        $readyRev = $svc['status']['latestReadyRevisionName'] ?? null;

        return $observed >= $generation && (! $created || $created === $readyRev);
    }

    /**
     * The check itself crashed or timed out (it catches cluster errors and retries on its own):
     * checked again while the overall timeout allows, then the deployment must not stay
     * "pending" forever.
     */
    public function failed(?\Throwable $e): void
    {
        $deployment = $this->deployment->fresh();

        if (! $deployment || ! in_array($deployment->status, ['pending', 'running'], true)) {
            return;
        }

        // A check that ran out of its $timeout (a slow region API server) says nothing about the
        // deployment: check again while the overall timeout allows
        if ($this->attempt < self::maxAttempts()) {
            Log::warning('Readiness check did not finish, checking again', [
                'deployment' => $deployment->id,
                'attempt' => $this->attempt,
                'error' => $e?->getMessage(),
            ]);
            self::dispatch($deployment, $this->project, $this->attempt + 1, $this->initiatorId)
                ->delay(now()->addSeconds(self::POLL_INTERVAL));

            return;
        }

        $this->markFailed($deployment, 'Readiness check failed: '.($e?->getMessage() ?? 'unknown error'));
    }

    private function findReadyCondition(?array $svc): ?array
    {
        foreach ($svc['status']['conditions'] ?? [] as $c) {
            if ($c['type'] === 'Ready') {
                return $c;
            }
        }

        return null;
    }

    private function retryOrTimeout(Deployment $deployment, string $message): void
    {
        // Stopped or shut down in the meantime: nothing left to wait for
        if (! $deployment->transition(['pending', 'running'], ['knative_message' => mb_substr($message, 0, 255)])) {
            return;
        }

        if ($this->attempt >= self::maxAttempts()) {
            $this->markFailed($deployment, 'Readiness timeout after '.self::TIMEOUT_SECONDS.'s: '.$message);

            return;
        }

        self::dispatch($deployment, $this->project, $this->attempt + 1, $this->initiatorId)
            ->delay(now()->addSeconds(self::POLL_INTERVAL));
    }

    private function markFailed(Deployment $deployment, string $reason): void
    {
        if (! $deployment->transition(['pending', 'running'], [
            'status' => 'failed',
            'knative_ready' => false,
            'knative_message' => mb_substr($reason, 0, 255),
        ])) {
            return;
        }

        Log::error('Deployment readiness failed', [
            'deployment' => $deployment->id,
            'reason' => $reason,
        ]);

        $this->broadcastFailure('deployment.failed', [
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'deployment_id' => $deployment->id,
            'status' => 'failed',
        ], new \RuntimeException($reason), "Deployment failed: {$this->project->name}");
    }
}
