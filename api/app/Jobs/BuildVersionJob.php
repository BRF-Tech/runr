<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\Version;
use App\Services\FunctionBuildService;
use App\Services\Runtimes\RuntimeImagePendingException;
use App\Support\DeployGate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

class BuildVersionJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, Queueable;

    public int $timeout = 30;

    public int $tries = 1;

    /** The version (project) was deleted while the build was queued */
    public bool $deleteWhenMissingModels = true;

    /** A runtime image still building in the region: check again every WAIT_SECONDS, up to WAIT_ATTEMPTS times */
    public const WAIT_SECONDS = 10;

    public const WAIT_ATTEMPTS = 90;

    public function __construct(
        public Version $version,
        ?string $initiatorId = null,
        public int $waited = 0,
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(FunctionBuildService $builder): void
    {
        // Built or cancelled in the meantime (e.g. a region migration rollback gave the version
        // its image back): nothing to submit
        if (in_array($this->version->fresh()?->build_status, ['ready', Version::STATUS_CANCELLED, Version::STATUS_PRUNED], true)) {
            return;
        }

        $region = $this->version->project->knativeRegion;

        if ($region?->is_demo) {
            $this->version->update(['build_status' => 'ready']);

            return;
        }

        // e.g. the tenant is being deleted: no Kaniko Job may start behind the teardown
        if ($blocked = DeployGate::blockedReason()) {
            $this->version->update(['build_status' => 'failed', 'build_error' => mb_substr($blocked, 0, 1000), 'build_token' => null]);

            return;
        }

        try {
            $builder->submitBuild($this->version);
        } catch (RuntimeImagePendingException $e) {
            // The runtime version is built from source in this region first (RuntimeImageBuildService)
            if ($this->waited >= self::WAIT_ATTEMPTS) {
                throw $e;
            }
            self::dispatch($this->version, $this->initiatorId, $this->waited + 1)->delay(now()->addSeconds(self::WAIT_SECONDS));

            return;
        }

        CheckBuildReadyJob::start($this->version, $this->initiatorId);
    }

    public function failed(\Throwable $e): void
    {
        $this->version->update([
            'build_status' => 'failed',
            'build_error' => mb_substr($e->getMessage(), 0, 1000),
            'build_token' => null,
        ]);

        $project = $this->version->project;
        $this->broadcastFailure('build.failed', [
            'project_id' => $project->id,
            'project_name' => $project->name,
            'version' => $this->version->number,
        ], $e, "Build failed: {$project->name} v{$this->version->number}");
    }
}
