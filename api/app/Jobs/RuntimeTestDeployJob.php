<?php

namespace App\Jobs;

use App\Events\DeploymentCreated;
use App\Models\Version;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deploys the version of a runtime test project (POST /runtimes/{id}/test-deploy) as soon as it is
 * ready: right away for a code-mounted runtime, after the build for a compiled one (checked every
 * POLL_INTERVAL seconds while the build runs or waits for the runtime image).
 */
class RuntimeTestDeployJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 30;

    public int $tries = 1;

    public bool $deleteWhenMissingModels = true;

    public const POLL_INTERVAL = 5;

    public const MAX_ATTEMPTS = 240;

    public function __construct(
        public Version $version,
        public string $deployedBy,
        public int|string|null $initiatorId = null,
        public int $attempt = 1,
    ) {}

    public function handle(): void
    {
        $version = $this->version->fresh();
        if (! $version || in_array($version->build_status, ['failed', Version::STATUS_PRUNED], true)) {
            return;
        }

        if ($version->build_status !== 'ready') {
            if ($this->attempt < self::MAX_ATTEMPTS) {
                self::dispatch($version, $this->deployedBy, $this->initiatorId, $this->attempt + 1)
                    ->delay(now()->addSeconds(self::POLL_INTERVAL));
            }

            return;
        }

        $project = $version->project;
        $deployment = $project->deployments()->create([
            'version_id' => $version->id,
            'status' => 'pending',
            'k8s_job_name' => 'run-'.$project->slug.'-v'.$version->number,
            'deployed_by' => $this->deployedBy,
        ]);

        event(new DeploymentCreated($deployment, $project, $version, $this->initiatorId !== null ? (string) $this->initiatorId : null));
    }
}
