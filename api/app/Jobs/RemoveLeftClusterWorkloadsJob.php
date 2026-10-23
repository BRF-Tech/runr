<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Services\RegionMigrate\LeftClusterCleanup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * After a region migration rollback: the project workloads the move deployed on the cluster the
 * rollback left, removed from it (LeftClusterCleanup) — best effort, the rollback is done either
 * way. A few attempts while that cluster does not answer; the result goes to the API log and, as
 * a notification, to the operator who rolled back.
 *
 * Encrypted queue payload: carries the kubeconfig of the cluster the rollback left, which the
 * region no longer holds (docs/security.md).
 */
class RemoveLeftClusterWorkloadsJob implements ShouldBeEncrypted, ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public bool $deleteWhenMissingModels = true;

    /**
     * @param  string  $kubeconfig  the cluster the rollback left
     * @param  array<string, array<string, array{slug: string, secrets: list<string>, openbao?: bool, environments_only?: bool}>>  $targets  namespace => project id => its slug, the names of its Secrets (its environments' too) and whether only its environments ran there
     */
    public function __construct(
        public KnativeRegion $region,
        public string $kubeconfig,
        public array $targets,
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(LeftClusterCleanup $cleanup): void
    {
        $name = $this->region->name;

        // Moved again since the rollback: that cluster may be the region's again
        if ($this->region->migration_status !== null) {
            $this->reportResult(0, "Left cluster cleanup of {$name}: nothing removed",
                'The region is being moved again: what runs on the cluster the rollback left is not removed.');

            return;
        }

        // Another kubeconfig of the region's cluster (another address of its API server): the
        // projects the rollback deployed again run there
        if ($cleanup->clusterId($this->kubeconfig, 'the cluster the rollback left') === $cleanup->clusterId((string) $this->region->kubeconfig, 'the region\'s cluster')) {
            $this->reportResult(0, "Left cluster cleanup of {$name}: nothing removed",
                'The cluster the rollback left is the region\'s cluster (the same kube-system namespace): the region\'s projects run there.');

            return;
        }

        $result = $cleanup->remove($this->kubeconfig, $this->targets);
        if ($result['failed'] !== []) {
            throw new \RuntimeException('not removed: '.implode('; ', array_slice($result['failed'], 0, 10))
                .(count($result['failed']) > 10 ? ' and '.(count($result['failed']) - 10).' more' : '')
                .($result['removed'] !== [] ? ' (removed: '.LeftClusterCleanup::summary($result['removed']).')' : ''));
        }

        $workloads = LeftClusterCleanup::workloadCount($result['removed']);
        $this->reportResult(
            $workloads,
            "Left cluster cleanup of {$name}: ".match (true) {
                $result['removed'] === [] => 'no project workloads were left there',
                $workloads === 0 => 'removed '.LeftClusterCleanup::count(count($result['removed']), 'object'),
                default => 'removed '.LeftClusterCleanup::count($workloads, 'project workload'),
            },
            ($result['removed'] === []
                ? 'The cluster the rollback left runs nothing of the region\'s projects.'
                : 'Removed from the cluster the rollback left: '.LeftClusterCleanup::summary($result['removed']).'.')
                .' Its installs and data services stay.',
        );
    }

    public function failed(?\Throwable $e): void
    {
        $e ??= new \RuntimeException('unknown error');
        $title = "Left cluster cleanup of {$this->region->name} failed";
        $body = rtrim($e->getMessage(), '.').'; remove them yourself: the Knative Services, CronJobs and queue consumers'
            .' labelled runr/project-id, runr/preview-of or runr/environment-of with the region\'s projects in the namespace '
            .implode(', ', array_keys($this->targets)).' of the cluster the rollback left. Its installs and data services stay.';

        Log::warning("RegionMigrate: {$title}: {$body}");
        $this->broadcastFailure('region.left_cluster_cleanup_failed', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'message' => $body,
        ], $e, mb_substr($title, 0, 255), $body);
    }

    private function reportResult(int $removed, string $title, string $body): void
    {
        Log::info("RegionMigrate: {$title}. {$body}");
        $this->broadcastSuccess('region.left_cluster_cleaned', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'removed' => $removed,
            'message' => $body,
        ], mb_substr($title, 0, 255), $body);
    }
}
