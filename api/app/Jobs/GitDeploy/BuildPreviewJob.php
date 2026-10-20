<?php

namespace App\Jobs\GitDeploy;

use App\Models\Git\PreviewEnvironment;
use App\Services\FunctionBuildService;
use App\Services\GitDeploy\PreviewService;
use App\Services\GitDeploy\PreviewVersionScope;
use App\Services\GitDeploy\ProjectRemote;
use App\Services\GitDeploy\RemoteRepoFiles;
use App\Services\Runtimes\RuntimeImagePendingException;
use App\Services\Runtimes\RuntimeRegistry;
use App\Support\DeployGate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Build a preview version with the regular function build (Kaniko Job in the region, image in
 * the region registry); its files come from the preview commit of the project remote.
 */
class BuildPreviewJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public string $previewId,
        public string $versionId,
        public int $waited = 0,
    ) {}

    public function handle(ProjectRemote $remote, RuntimeRegistry $runtimes, PreviewService $previews): void
    {
        $preview = PreviewEnvironment::find($this->previewId);
        $version = PreviewVersionScope::query()->find($this->versionId);

        if (! $preview || ! $version || $preview->version_id !== $version->id || ! $preview->isLive()) {
            return;
        }

        $project = $version->project;
        if ($project->knativeRegion?->is_demo) {
            $version->update(['build_status' => 'ready']);
            DeployPreviewJob::dispatch($preview->id);

            return;
        }

        if ($blocked = DeployGate::blockedReason()) {
            $previews->fail($preview, $previews->currentDeploy($preview), $blocked);

            return;
        }

        try {
            (new FunctionBuildService(new RemoteRepoFiles($remote), $runtimes))->submitBuild($version);
        } catch (RuntimeImagePendingException $e) {
            if ($this->waited < 90) {
                self::dispatch($this->previewId, $this->versionId, $this->waited + 1)->delay(now()->addSeconds(10));

                return;
            }
            $previews->fail($preview, $previews->currentDeploy($preview), $e->getMessage());

            return;
        } catch (\Throwable $e) {
            $version->update(['build_status' => 'failed', 'build_error' => mb_substr($e->getMessage(), 0, 1000)]);
            $previews->fail($preview, $previews->currentDeploy($preview), 'Build failed: '.$e->getMessage());

            return;
        }

        // The checks of this submission (CheckPreviewBuildJob $token): an earlier submission's stop
        $token = Str::random(32);
        PreviewVersionScope::query()->whereKey($version->getKey())->update(['build_token' => $token]);
        CheckPreviewBuildJob::dispatch($this->previewId, $this->versionId, 1, $token)->delay(now()->addSeconds(5));
    }
}
