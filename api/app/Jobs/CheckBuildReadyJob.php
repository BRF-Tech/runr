<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\KubernetesClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CheckBuildReadyJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, Queueable;

    public int $timeout = 30;

    public int $tries = 1;

    /** The version (project) was deleted while the check was queued: nothing to check */
    public bool $deleteWhenMissingModels = true;

    /** First check ~1 s after the Kaniko Job was submitted, then every 2 s */
    public const FIRST_CHECK_DELAY = 1;

    public const POLL_INTERVAL = 2;

    /** Overall timeout, unchanged (formerly 72 checks × 5 s): maxAttempts() checks on this schedule */
    public const TIMEOUT_SECONDS = 360;

    public function __construct(
        public Version $version,
        public int $attempt = 1,
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    /** The first check of a submitted build. */
    public static function start(Version $version, ?string $initiatorId = null): PendingDispatch
    {
        return self::dispatch($version, 1, $initiatorId)->delay(now()->addSeconds(self::FIRST_CHECK_DELAY));
    }

    /** Checks that fit the timeout: the first one plus one per poll interval. */
    public static function maxAttempts(): int
    {
        return 1 + (int) ceil((self::TIMEOUT_SECONDS - self::FIRST_CHECK_DELAY) / self::POLL_INTERVAL);
    }

    public function handle(): void
    {
        $version = $this->version->fresh();

        if (! $version || $version->build_status !== 'building') {
            return;
        }

        $project = $version->project;
        $region = $project->knativeRegion;

        if (! $region || ! $region->kubeconfig) {
            $this->markFailed($version, 'Region kubeconfig not available');

            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $namespace = $region->project_namespace ?? $region->name;
            $jobName = "build-{$project->slug}-v{$version->number}";

            $job = $k8s->getResourceStatus('batch/v1', 'Job', $jobName, $namespace);

            if (! $job) {
                $this->retryOrTimeout($version, 'Build Job not found');

                return;
            }

            $status = $job['status'] ?? [];

            if (($status['succeeded'] ?? 0) >= 1) {
                $version->update([
                    'build_status' => 'ready',
                    'build_error' => null,
                    'build_token' => null,
                ]);
                Log::info('Kaniko build ready', [
                    'version' => $version->id,
                    'attempt' => $this->attempt,
                ]);

                $this->broadcastSuccess('build.completed', [
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                    'version' => $version->number,
                ], "Build completed: {$project->name} v{$version->number}");

                $this->dispatchRegistryRetention($project, $region);

                return;
            }

            if (($status['failed'] ?? 0) >= 1) {
                $error = $this->extractErrorFromJob($k8s, $job, $namespace);
                $this->markFailed($version, $error);

                return;
            }

            $this->retryOrTimeout($version, 'Build in progress');

        } catch (\Throwable $e) {
            $this->retryOrTimeout($version, $e->getMessage());
        }
    }

    /**
     * Trim old build images in the internal registry now that a new one landed —
     * in its own queued job (RetainRegistryImagesJob), after build.completed went out. Never lets
     * a registry hiccup fail an otherwise-successful build.
     */
    private function dispatchRegistryRetention(Project $project, KnativeRegion $region): void
    {
        if ($region->registry_mode !== 'internal') {
            return;
        }

        try {
            RetainRegistryImagesJob::dispatch($project);
        } catch (\Throwable $e) {
            Log::warning('Registry retention could not be dispatched after build', [
                'project' => $project->slug,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extractErrorFromJob(KubernetesClient $k8s, array $job, string $namespace): string
    {
        // Try to get actual Kaniko container logs first — much more useful than Job conditions
        $podName = $this->findJobPodName($k8s, $job, $namespace);
        if ($podName) {
            // kaniko, or the toolchain container of a WebAssembly build (WasmBuildService)
            $logs = $k8s->getPodLogs($podName, $namespace, 'kaniko', 20) ?: $k8s->getPodLogs($podName, $namespace, 'build', 20);
            if ($logs) {
                // Extract the last meaningful line (usually the error)
                $lines = array_filter(explode("\n", trim($logs)));
                $errorLines = array_filter($lines, fn ($l) => str_contains($l, 'error') || str_contains($l, 'WARN') || str_contains($l, 'fatal'));
                $msg = implode("\n", array_slice($errorLines ?: $lines, -3));
                if ($msg) {
                    // Strip ANSI color codes
                    return mb_substr(preg_replace('/\x1b\[[0-9;]*m/', '', $msg), 0, 1000);
                }
            }
        }

        $conditions = $job['status']['conditions'] ?? [];
        foreach ($conditions as $c) {
            if (($c['type'] ?? '') === 'Failed' && ! empty($c['message'])) {
                return mb_substr($c['message'], 0, 1000);
            }
        }

        return 'Build failed (no details available)';
    }

    private function findJobPodName(KubernetesClient $k8s, array $job, string $namespace): ?string
    {
        $jobName = $job['metadata']['name'] ?? '';
        if (! $jobName) {
            return null;
        }

        $pods = $k8s->listResources('v1', 'Pod', $namespace, ['job-name' => $jobName]);

        return $pods['items'][0]['metadata']['name'] ?? null;
    }

    /**
     * The check itself crashed or ran out of its $timeout (handle() catches everything else — a
     * region API server that answers slowly gets here): the build is not known to have failed,
     * so it is checked again while the overall timeout allows; after that the version must not
     * stay "building" forever.
     */
    public function failed(?\Throwable $e): void
    {
        $version = $this->version->fresh();

        if (! $version || $version->build_status !== 'building') {
            return;
        }

        if ($this->attempt < self::maxAttempts()) {
            Log::warning('Build check did not finish, checking again', [
                'version' => $version->id,
                'attempt' => $this->attempt,
                'error' => $e?->getMessage(),
            ]);
            self::dispatch($version, $this->attempt + 1, $this->initiatorId)
                ->delay(now()->addSeconds(self::POLL_INTERVAL));

            return;
        }

        $this->markFailed($version, 'Build check failed: '.($e?->getMessage() ?? 'unknown error'));
    }

    private function retryOrTimeout(Version $version, string $message): void
    {
        if ($this->attempt >= self::maxAttempts()) {
            $this->markFailed($version, 'Build timeout after '.self::TIMEOUT_SECONDS.'s: '.$message);

            return;
        }

        self::dispatch($version, $this->attempt + 1, $this->initiatorId)
            ->delay(now()->addSeconds(self::POLL_INTERVAL));
    }

    private function markFailed(Version $version, string $reason): void
    {
        $version->update([
            'build_status' => 'failed',
            'build_error' => mb_substr($reason, 0, 1000),
            'build_token' => null,
        ]);

        Log::error('Kaniko build failed', [
            'version' => $version->id,
            'reason' => $reason,
        ]);

        $project = $version->project;
        $this->broadcastFailure('build.failed', [
            'project_id' => $project->id,
            'project_name' => $project->name,
            'version' => $version->number,
        ], new \RuntimeException($reason), "Build failed: {$project->name} v{$version->number}");
    }
}
