<?php

namespace App\Services\Secrets\External;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\SecretStore;
use App\Services\KubernetesClient;
use App\Services\Secrets\SecretScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What External Secrets Operator reported, copied onto the references it syncs
 * (project_secrets.sync_status / sync_message / synced_at) and onto the stores
 * (secret_stores.region_status) — the console shows it next to each variable. RunR polls the
 * region's API server: one list of ExternalSecrets and one of SecretStores per region namespace
 * (`php artisan secrets:sync-status`, scheduled every two minutes; refresh() for one project on
 * demand). An ExternalSecret reports for all of its keys at once: every reference of the project
 * gets its status.
 */
class ExternalSecretStatusSync
{
    /**
     * Every region with references in the current database context.
     *
     * @return array{projects: int, regions: int, failed: list<string>}
     */
    public function syncAll(): array
    {
        $projects = Project::query()
            ->whereIn('id', ProjectSecret::query()->where('source', ProjectSecret::SOURCE_EXTERNAL)->select('project_id'))
            ->with(['knativeRegion', 'secrets'])
            ->get();

        $failed = [];
        $regions = 0;
        foreach ($projects->groupBy('knative_region_id') as $regionId => $group) {
            $region = $group->first()->knativeRegion;
            if (! $region instanceof KnativeRegion || ! $region->kubeconfig || $region->is_demo) {
                continue;
            }
            $regions++;
            try {
                $this->syncRegion($region, $group);
            } catch (Throwable $e) {
                $failed[] = "{$region->name}: {$e->getMessage()}";
                Log::info('External secret status not read', ['region' => $region->name, 'error' => $e->getMessage()]);
            }
        }

        return ['projects' => $projects->count(), 'regions' => $regions, 'failed' => $failed];
    }

    /**
     * One project now (POST …/secrets/refresh): its ExternalSecret is asked to sync again
     * (force-sync annotation) and its status read.
     */
    public function refresh(Project $project): void
    {
        $region = $project->knativeRegion;
        if (! $region instanceof KnativeRegion || ! $region->kubeconfig || $region->is_demo) {
            return;
        }

        $scope = SecretScope::forProject($project);
        $k8s = new KubernetesClient($region->kubeconfig);
        $k8s->mergePatchOrFail(ExternalSecretManifests::API_VERSION, 'ExternalSecret', $scope->externalName, $scope->namespace, [
            'metadata' => ['annotations' => ['force-sync' => (string) now()->getTimestamp()]],
        ]);
        $this->syncRegion($region, collect([$project->loadMissing('secrets')]), $k8s);
    }

    /**
     * @param  Collection<int, Project>  $projects  projects of the region with references
     */
    public function syncRegion(KnativeRegion $region, Collection $projects, ?KubernetesClient $k8s = null): void
    {
        $k8s ??= new KubernetesClient($region->kubeconfig);
        $namespace = (string) ($region->project_namespace ?? $region->name);

        $items = collect($k8s->listResourcesOrFail(ExternalSecretManifests::API_VERSION, 'ExternalSecret', $namespace, [
            ExternalSecretManifests::LABEL => 'external',
        ]))->keyBy(fn (array $item) => (string) ($item['metadata']['name'] ?? ''));

        foreach ($projects as $project) {
            $scope = SecretScope::forProject($project, $region);
            $item = $items->get($scope->externalName);
            [$status, $message, $syncedAt] = self::statusOf($item);

            foreach ($project->secrets as $secret) {
                if (! $secret->isExternal()) {
                    continue;
                }
                // Not applied yet (no deploy since the reference was set): stays pending
                [$own, $ownMessage, $ownSyncedAt] = $item === null || ! self::coversKey($item, $secret->name)
                    ? [ProjectSecret::SYNC_PENDING, __('api.secret.reference_not_applied'), null]
                    : [$status, $message, $syncedAt];
                $secret->forceFill([
                    'sync_status' => $own,
                    'sync_message' => $ownMessage !== null ? mb_substr($ownMessage, 0, 1000) : null,
                    'synced_at' => $ownSyncedAt,
                ]);
                if ($secret->isDirty()) {
                    // Status only: not a change of the variable (no audit record, updated_at stays)
                    $secret->timestamps = false;
                    $secret->saveQuietly();
                }
            }
        }

        $this->syncStores($region, $k8s, $namespace);
    }

    /** The Ready condition of the region's SecretStores onto the organization's stores. */
    private function syncStores(KnativeRegion $region, KubernetesClient $k8s, string $namespace): void
    {
        $live = collect($k8s->listResourcesOrFail(ExternalSecretManifests::API_VERSION, 'SecretStore', $namespace, [
            ExternalSecretManifests::LABEL => 'store',
        ]))->keyBy(fn (array $item) => (string) ($item['metadata']['name'] ?? ''));

        foreach (SecretStore::query()->get() as $store) {
            $item = $live->get($store->kubernetesName());
            if ($item === null) {
                continue;
            }
            $ready = ClusterIssuer::readyCondition($item);
            $before = $store->statusIn((string) $region->id);
            if (($before['status'] ?? null) !== $ready['status'] || ($before['message'] ?? null) !== $ready['message']) {
                $store->recordStatus((string) $region->id, $ready['status'], $ready['message']);
            }
        }
    }

    /**
     * synced | failed | pending, the message and the last sync of an ExternalSecret.
     *
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    public static function statusOf(?array $item): array
    {
        if ($item === null) {
            return [ProjectSecret::SYNC_PENDING, null, null];
        }

        $ready = ClusterIssuer::readyCondition($item);
        $status = match ($ready['status']) {
            'ready' => ProjectSecret::SYNC_SYNCED,
            'failed' => ProjectSecret::SYNC_FAILED,
            default => ProjectSecret::SYNC_PENDING,
        };
        $refreshed = $item['status']['refreshTime'] ?? null;

        return [$status, $ready['message'], is_string($refreshed) && $refreshed !== '' ? $refreshed : null];
    }

    private static function coversKey(array $item, string $name): bool
    {
        foreach ((array) ($item['spec']['data'] ?? []) as $entry) {
            if (($entry['secretKey'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
}
