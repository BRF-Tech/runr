<?php

namespace App\Services\Projects;

use App\Jobs\BuildVersionJob;
use App\Models\Project;
use App\Models\QueuedDeploy;
use App\Models\Version;
use Illuminate\Support\Facades\Log;

/**
 * A built version whose image is not in its region's registry: built before the region moved to
 * another cluster with a registry of its own (docs/regions.md#moving-a-region-to-another-cluster)
 * — or before the project moved to another region. The region's nodes do not pull from that
 * registry, so a deployment of it would fail at the image pull. Such a version is built again
 * into the region's registry from its code (BuildVersionJob: the editor's Kaniko or WebAssembly
 * build), and its deploy waits for that build (QueuedDeploys, REASON_REBUILD) — the console's
 * deploy and rollback, the API's and the CLI's (POST /projects/{project}/deploy) and a resume. A
 * git deploy follows its version itself (GitDeployService::continue()).
 */
final class VersionRebuilds
{
    public function __construct(private QueuedDeploys $deploys) {}

    /**
     * Whether $version has to be built again before it runs in the project's region: a ready
     * build of its own (a CaaS project runs its configured image, a version without a build runs
     * its runtime's) whose image reference names a registry other than the region's.
     */
    public static function needed(Project $project, Version $version): bool
    {
        if ($project->service_type === 'caas' || $version->build_status !== 'ready' || ! $version->build_image) {
            return false;
        }

        $region = $project->knativeRegion;
        $registry = rtrim((string) $region?->registry_address, '/');
        if (! $region || $region->is_demo || $registry === '') {
            return false;
        }

        return ! str_starts_with($version->build_image, $registry.'/');
    }

    /** What the deploy says while it waits: built again for the region's registry, deployed when ready. */
    public static function message(Version $version): string
    {
        $message = __('api.project.version_rebuilding', ['version' => $version->number]);

        return is_string($message) ? $message : "v{$version->number} is built again for this region's registry; it deploys when the build is ready.";
    }

    /**
     * Build $version again into the region's registry and queue its deploy: it starts once the
     * build is ready, through the deploy checks of every deploy (QueuedDeploys::process()). The
     * image it had stays in the queued deploy (previous_build_image): a region migration rollback
     * gives it back when the restored cluster's registry has it. Of two deploys of the version at
     * once, one submits the build; the newer queued deploy replaces the older. During a region
     * move it takes the place of the move's deploy of the project that still waits: when it
     * fails, the deployment still running on the cluster the region left is not moved
     * (QueuedDeploys::queue()).
     *
     * A runtime patch migration of a prebuilt version builds it again the same way, FROM the
     * patch's image (reason QueuedDeploys::REASON_RUNTIME_PATCH, RuntimePatchDeployer).
     *
     * @param  array{deploy_config?: array<string, mixed>|null, initiator_id?: string|null, reason?: string, message?: string, config_snapshot?: array<string, mixed>|null}  $options
     */
    public function queue(Project $project, Version $version, string $deployedBy, array $options = []): QueuedDeploy
    {
        $previous = (string) $version->build_image;
        $queued = $this->deploys->queue($project, $version, $deployedBy, [
            'deploy_config' => $options['deploy_config'] ?? null,
            'initiator_id' => $options['initiator_id'] ?? null,
            'reason' => $options['reason'] ?? QueuedDeploys::REASON_REBUILD,
            'previous_build_image' => $previous,
            'message' => $options['message'] ?? self::message($version),
            'config_snapshot' => $options['config_snapshot'] ?? null,
        ]);

        // The build is claimed by one deploy: the version leaves "ready" once
        $claimed = Version::query()->whereKey($version->getKey())
            ->where('build_status', 'ready')
            ->where('build_image', $previous)
            ->update(['build_status' => 'pending', 'build_error' => null, 'build_token' => null, 'updated_at' => now()]);
        $version->refresh();

        if ($claimed === 1) {
            BuildVersionJob::dispatch($version, $options['initiator_id'] ?? null);
            Log::info("v{$version->number} of {$project->slug} is built again for the registry of its region; its deploy waits for the build", [
                'queued_deploy' => $queued->id,
                'image' => $previous,
            ]);
        } elseif (! in_array($version->build_status, ['pending', 'building'], true)) {
            // Another deploy's build of it ended meanwhile (or it was pruned): the queued deploy
            // goes on with what that build ended with
            $this->deploys->versionBuilt($version);
        }

        return $queued;
    }
}
