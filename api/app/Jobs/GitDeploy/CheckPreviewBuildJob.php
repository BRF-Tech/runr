<?php

namespace App\Jobs\GitDeploy;

use App\Models\Git\PreviewEnvironment;
use App\Services\GitDeploy\PreviewService;
use App\Services\GitDeploy\PreviewVersionScope;
use App\Services\KubernetesClient;
use App\Services\Manifests\BuildManifests;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Poll the Kaniko Job of a preview build (build-<slug>-v<N>) every POLL seconds, up to
 * maxAttempts() (the build Job's deadline and a minute); ready → DeployPreviewJob, failed or
 * timed out → the preview fails. Unlike a project build, no "build completed" notification goes
 * out: the PR comment carries the status.
 */
class CheckPreviewBuildJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const POLL = 5;

    public int $tries = 1;

    public int $timeout = 30;

    /**
     * @param  string|null  $token  the submission these checks follow (BuildPreviewJob): the
     *                              version's build_token. A build submitted again (a region
     *                              migration builds a preview on the new cluster) has its own, and
     *                              the earlier checks stop instead of timing it out; null for
     *                              checks queued before tokens existed
     */
    public function __construct(
        public string $previewId,
        public string $versionId,
        public int $attempt = 1,
        public ?string $token = null,
    ) {}

    /** Checks until the build Job's deadline (BuildManifests::deadlineSeconds()) and a minute have passed. */
    public static function maxAttempts(): int
    {
        return (int) ceil((BuildManifests::deadlineSeconds() + 60) / self::POLL);
    }

    public function handle(PreviewService $previews): void
    {
        $preview = PreviewEnvironment::find($this->previewId);
        $version = PreviewVersionScope::query()->find($this->versionId);

        if (! $preview || ! $version || $version->build_status !== 'building' || $preview->version_id !== $version->id || ! $preview->isLive()) {
            return;
        }
        if ($this->token !== null && $version->getAttribute('build_token') !== $this->token) {
            return; // checks of an earlier submission
        }

        $project = $version->project;
        $region = $project->knativeRegion;

        if (! $region || ! $region->kubeconfig) {
            $previews->fail($preview, $previews->currentDeploy($preview), 'Region kubeconfig not available');

            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $job = $k8s->getResourceStatus('batch/v1', 'Job', "build-{$project->slug}-v{$version->number}", $region->project_namespace ?? $region->name);
        } catch (\Throwable $e) {
            $job = null;
        }

        $status = $job['status'] ?? [];

        if (($status['succeeded'] ?? 0) >= 1) {
            $version->update(['build_status' => 'ready', 'build_error' => null, 'build_token' => null]);
            DeployPreviewJob::dispatch($preview->id);

            return;
        }

        if (($status['failed'] ?? 0) >= 1) {
            $version->update(['build_status' => 'failed', 'build_error' => 'Build Job failed']);
            $previews->fail($preview, $previews->currentDeploy($preview), 'The build failed; see the build logs in the region');

            return;
        }

        if ($this->attempt >= self::maxAttempts()) {
            $version->update(['build_status' => 'failed', 'build_error' => 'Build timeout']);
            $previews->fail($preview, $previews->currentDeploy($preview), 'The build did not finish in time');

            return;
        }

        self::dispatch($this->previewId, $this->versionId, $this->attempt + 1, $this->token)->delay(now()->addSeconds(self::POLL));
    }
}
