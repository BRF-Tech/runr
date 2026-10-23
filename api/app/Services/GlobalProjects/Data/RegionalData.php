<?php

namespace App\Services\GlobalProjects\Data;

use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\BindingRegionService;
use App\Models\BindingReplica;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectRegion;
use App\Services\Bindings\BindingDrivers;
use App\Services\Bindings\BindingException;
use App\Services\Bindings\BindingManifests;
use App\Services\Bindings\BindingNetworkPolicies;
use App\Services\Bindings\BindingQuotas;
use App\Services\Bindings\BindingsCluster;
use App\Services\Bindings\DataServices\PostgresService;
use App\Services\GlobalDns\GlobalDnsServerService;
use App\Services\GlobalProjects\GlobalProjectRegions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Regional data of global projects (docs/global-projects.md, "Data"): how the copies of a global
 * project in every region reach its bindings.
 *
 *  - external bindings: as they are — every region reaches them over the network;
 *  - KV, object storage on the managed store, shared Postgres: the data stays in the binding's
 *    region (its home); the other regions reach it through the binding's data link
 *    (DataLinkManifests), with the same credentials;
 *  - a dedicated Postgres binding: a CloudNativePG replica cluster in every other region of the
 *    project (RegionalPostgresManifests) — DATABASE_URL_RO reads the region's own replica,
 *    DATABASE_URL writes go through the link to the home; a switchover or a failover of the
 *    project's primary promotes the replica of the new primary, which becomes the home.
 *
 * A binding's rows (binding_replicas) record each region's state; a region whose rows are not
 * ready is left out of the DNS answers (project_regions.data_status, ProjectRegion::isAnswerable).
 * Everything here applies objects and returns; refresh() — every minute and after each change —
 * reads back how far the regions got.
 */
class RegionalData
{
    /** Seconds a demotion token, a promotion or a fence may take. */
    public const MOVE_TIMEOUT = 600;

    /** @var array<string, bool> */
    private static array $tables = [];

    public function __construct(
        private readonly BindingsCluster $cluster,
        private readonly BindingDrivers $drivers,
        private readonly PostgresService $pg,
    ) {}

    public static function tableReady(): bool
    {
        $connection = (new BindingReplica)->getConnection();
        $key = $connection->getName().':'.$connection->getDatabaseName();

        try {
            return self::$tables[$key] ??= Schema::connection($connection->getName())->hasTable('binding_replicas');
        } catch (Throwable) {
            return false;
        }
    }

    public static function flush(): void
    {
        self::$tables = [];
    }

    // ── Rules ───────────────────────────────────────────────────────────────

    /**
     * Why $binding cannot be attached to the global project $project, null when it can
     * (docs/global-projects.md, "Data").
     */
    public function attachRefusal(Binding $binding, Project $project, bool $dynamic = false): ?string
    {
        if ($binding->type === 'external') {
            return null;
        }
        if ($dynamic) {
            return __('api.global.dynamic_credentials_refused');
        }

        $regionIds = array_map(fn (KnativeRegion $r) => (string) $r->getKey(), GlobalProjectRegions::of($project, false));
        if (! in_array((string) $binding->knative_region_id, $regionIds, true)) {
            return __('api.global.binding_outside_regions', ['name' => $binding->name]);
        }

        if ($binding->isDedicated()) {
            // the replicas follow the project's primary: the binding's data must be there
            if ((string) $binding->knative_region_id !== (string) $project->knative_region_id) {
                return __('api.global.dedicated_not_in_primary', ['name' => $binding->name]);
            }
            $other = BindingAttachment::query()->where('binding_id', $binding->id)->where('project_id', '!=', $project->getKey())
                ->pluck('project_id')->all();
            if ($other !== [] && Project::query()->whereIn('id', $other)->get()->contains(fn (Project $p) => $p->isGlobal())) {
                return __('api.global.dedicated_one_project', ['name' => $binding->name]);
            }
            // a copy of the data in every region of the project counts in the Postgres quota
            if ($reason = self::quotaRefusal($binding, count($regionIds))) {
                return $reason;
            }
        }

        $home = $binding->region;
        if ($home && $this->needsLink($binding) && GlobalDnsServerService::firstAddress($home) === null) {
            return __('api.global.home_without_address', ['region' => $home->name]);
        }

        return null;
    }

    /**
     * Why $copies copies of a dedicated binding's data (the home and its replicas) do not fit the
     * organization's Postgres quota, null when they do.
     */
    public static function quotaRefusal(Binding $binding, int $copies): ?string
    {
        if (! $binding->isDedicated()) {
            return null;
        }
        $exceeded = app(BindingQuotas::class)->exceeded($binding->type, (string) $binding->plan, (array) $binding->settings, (string) $binding->id, max(1, $copies));

        return $exceeded ? __('api.bindings.quota_exceeded', $exceeded) : null;
    }

    /**
     * A dedicated binding being attached to a regional project while a global project replicates
     * it: refused (its primary follows that project).
     */
    public function regionalAttachRefusal(Binding $binding, Project $project): ?string
    {
        if ($project->isGlobal() || ! $binding->isDedicated() || ! self::tableReady()) {
            return null;
        }

        return BindingReplica::query()->where('binding_id', $binding->id)->where('kind', BindingReplica::KIND_REPLICA)->exists()
            ? __('api.global.dedicated_one_project', ['name' => $binding->name])
            : null;
    }

    // ── What a region's copy gets ───────────────────────────────────────────

    /**
     * The in-cluster service a binding's env reaches in its home (host, port), null when the env
     * reaches something outside the cluster (an external S3 endpoint, a public Redis): every
     * region reaches that as it is.
     *
     * @param  array<string, string>|null  $env  the driver's env (unprefixed)
     * @return array{host: string, port: int, namespace: string, service: string}|null
     */
    public function target(Binding $binding, ?array $env = null): ?array
    {
        if ($binding->type === 'external' || empty($binding->credentials)) {
            return null;
        }

        try {
            $env ??= $this->drivers->for($binding)->env($binding);
        } catch (Throwable) {
            return null;
        }

        [$host, $port] = match ($binding->type) {
            'postgres' => [(string) ($env['PGHOST'] ?? ''), (int) ($env['PGPORT'] ?? BindingManifests::PG_PORT)],
            'kv' => self::hostPort((string) ($env['REDIS_URL'] ?? ''), BindingManifests::KV_PORT),
            'object' => self::hostPort((string) ($env['S3_ENDPOINT'] ?? ''), 0),
            default => ['', 0],
        };

        $service = DataLinkManifests::inClusterService($host);
        if ($service === null || $port <= 0) {
            return null;
        }

        return ['host' => $host, 'port' => $port] + $service;
    }

    /** Whether the binding needs its data link (an in-cluster service other regions cannot reach). */
    public function needsLink(Binding $binding): bool
    {
        return $binding->isDedicated() || $this->target($binding) !== null;
    }

    /**
     * The env of $binding as the copy of a global project in $region sees it: in the home as it
     * is, elsewhere the data link's host in place of the in-cluster one. A dedicated Postgres
     * binding adds the region's own replica for reads (DATABASE_URL_RO, PGHOST_RO).
     *
     * @param  array<string, string>  $env  unprefixed
     * @return array<string, string>
     */
    public function regionEnv(Binding $binding, array $env, ?KnativeRegion $region): array
    {
        if ($region === null || $binding->type === 'external') {
            return $env;
        }

        $target = $this->target($binding, $env);
        $home = (string) $region->getKey() === (string) $binding->knative_region_id;

        if ($target !== null && ! $home) {
            $link = DataLinkManifests::host((string) $binding->resource_name, BindingsCluster::dataNamespace());
            foreach ($env as $key => $value) {
                $env[$key] = str_replace($target['host'], $link, (string) $value);
            }
        }

        if ($binding->isDedicated() && self::replicated($binding)) {
            $readHost = BindingsCluster::serviceHost($binding->dedicatedClusterName().'-r');
            $env['PGHOST_RO'] = $readHost;
            $write = (string) ($env['DATABASE_URL'] ?? '');
            $env['DATABASE_URL_RO'] = $write !== '' ? self::withHost($write, $readHost) : '';
        }

        return $env;
    }

    /** Whether a binding has replica clusters (a dedicated binding of a global project). */
    public static function replicated(Binding $binding): bool
    {
        return self::tableReady()
            && BindingReplica::query()->where('binding_id', $binding->id)->where('kind', BindingReplica::KIND_REPLICA)->exists();
    }

    // ── Reconcile ───────────────────────────────────────────────────────────

    /**
     * The bindings of a global project in every region of it (after an attach or a detach — the
     * detached ones in $bindingIds — and a change of its regions).
     *
     * @param  list<string>  $bindingIds
     */
    public function syncProject(Project $project, array $bindingIds = []): void
    {
        if (! self::tableReady()) {
            return;
        }

        $ids = BindingAttachment::query()->where('project_id', $project->getKey())->whereNull('environment_id')->pluck('binding_id')->all();
        $ids = array_merge($ids, $bindingIds, BindingReplica::query()->where('project_id', $project->getKey())->pluck('binding_id')->all());
        foreach (Binding::query()->whereIn('id', array_values(array_unique($ids)))->with('region')->get() as $binding) {
            try {
                $this->syncBinding($binding);
            } catch (Throwable $e) {
                Log::warning('Regional data of a binding not synced', ['binding' => $binding->name, 'error' => $e->getMessage()]);
            }
        }

        $this->refreshProjectRegions($project);
    }

    /**
     * The regions $binding must be reached from — every region of the global projects it is
     * attached to — get their rows and objects; regions that no longer need it lose them.
     */
    public function syncBinding(Binding $binding): void
    {
        if (! self::tableReady() || $binding->type === 'external') {
            return;
        }

        $home = $binding->region;
        $wanted = $this->wantedRegions($binding);
        $rows = BindingReplica::query()->where('binding_id', $binding->id)->get()->keyBy(fn (BindingReplica $r) => (string) $r->knative_region_id);

        // a switchover or a failover owns the binding's clusters right now
        if ($rows->contains(fn (BindingReplica $r) => $r->status === BindingReplica::STATUS_PROMOTING)) {
            return;
        }

        if ($home === null || ! $binding->isActive() || count($wanted) < 2 || ! $this->needsLink($binding)) {
            foreach ($rows as $regionId => $row) {
                if ((string) $regionId !== (string) $binding->knative_region_id) {
                    $this->removeRegion($binding, (string) $regionId);
                }
            }
            if ($home !== null && $rows->has((string) $home->getKey())) {
                $this->removeServer($binding, $home);
                $rows->get((string) $home->getKey())?->delete();
                if ($binding->isDedicated()) {
                    $this->applyHomeCluster($binding, $home, plain: true);
                }
            }

            return;
        }

        $link = $this->ensureLink($binding);
        $projectId = $binding->isDedicated() ? $this->replicatingProjectId($binding) : null;

        // every region's row first: the topology of a dedicated binding names them all
        foreach ($wanted as $regionId => $region) {
            $isHome = $regionId === (string) $home->getKey();
            $row = $rows->get($regionId) ?? new BindingReplica(['binding_id' => $binding->id, 'knative_region_id' => $regionId, 'status' => BindingReplica::STATUS_PENDING]);
            $row->fill([
                'kind' => $isHome ? BindingReplica::KIND_HOME : ($binding->isDedicated() ? BindingReplica::KIND_REPLICA : BindingReplica::KIND_LINK),
                'project_id' => $projectId,
                'topology' => RegionalPostgresManifests::topologyName($regionId),
            ])->save();
            $rows->put($regionId, $row);
        }

        // the home: its link server (and, for a dedicated binding, its place in the topology)
        $homeRow = $rows->get((string) $home->getKey());
        try {
            $this->applyServer($binding, $home, $link);
            if ($binding->isDedicated()) {
                $this->applyHomeCluster($binding, $home);
            }
            $homeRow->fill(['status' => BindingReplica::STATUS_READY, 'status_message' => null]);
        } catch (Throwable $e) {
            $homeRow->fill(['status' => BindingReplica::STATUS_FAILED, 'status_message' => mb_substr($e->getMessage(), 0, 2000)]);
        }
        $homeRow->save();

        foreach ($wanted as $regionId => $region) {
            if ($regionId === (string) $home->getKey()) {
                continue;
            }
            $row = $rows->get($regionId);
            if ($row->status === BindingReplica::STATUS_RECLONING) {
                // the region lost the primary in a failover: rejoin() rebuilds it once it answers
                continue;
            }
            try {
                $this->applyClient($binding, $region, $home, $link);
                if ($binding->isDedicated()) {
                    $this->applyReplica($binding, $region, $home);
                }
                if (! $row->exists || $row->status === BindingReplica::STATUS_FAILED || $row->status === BindingReplica::STATUS_PENDING) {
                    $row->fill(['status' => BindingReplica::STATUS_PROVISIONING, 'status_message' => null]);
                }
            } catch (Throwable $e) {
                $row->fill(['status' => BindingReplica::STATUS_FAILED, 'status_message' => mb_substr($e->getMessage(), 0, 2000)]);
            }
            $row->save();
        }

        foreach ($rows as $regionId => $row) {
            if (! isset($wanted[(string) $regionId])) {
                $this->removeRegion($binding, (string) $regionId);
            }
        }

        $this->refresh($binding);
    }

    /**
     * How far each region got: a link's forwarder is available, a replica cluster runs, is in
     * recovery and streams from the home. Updates the rows and the regions' data status of the
     * projects the binding serves. Returns whether a row changed.
     */
    public function refresh(Binding $binding): bool
    {
        if (! self::tableReady()) {
            return false;
        }

        $changed = false;
        $ns = BindingsCluster::dataNamespace();
        foreach (BindingReplica::query()->where('binding_id', $binding->id)->with('knativeRegion')->get() as $row) {
            $region = $row->knativeRegion;
            if (! $region || in_array($row->status, [BindingReplica::STATUS_PROMOTING, BindingReplica::STATUS_REMOVING], true) || $row->kind === BindingReplica::KIND_HOME) {
                continue;
            }

            $before = [$row->status, $row->status_message, $row->lag_seconds];
            try {
                $k8s = $this->cluster->client($region);
                $forwarder = $k8s->getResourceStatus('apps/v1', 'Deployment', DataLinkManifests::name((string) $binding->resource_name), $ns);
                $linkUp = (int) ($forwarder['status']['availableReplicas'] ?? 0) > 0;

                if ($row->kind === BindingReplica::KIND_LINK) {
                    $row->fill($linkUp
                        ? ['status' => BindingReplica::STATUS_READY, 'status_message' => null]
                        : ['status' => BindingReplica::STATUS_PROVISIONING, 'status_message' => __('api.global.data_link_waiting')]);
                } else {
                    // ready: the replica runs in recovery with the data it cloned — also while it
                    // does not stream (the primary's region is down: then it is what a failover
                    // promotes); not streaming is said in its message
                    $state = $this->replicaState($binding, $region);
                    $ready = $state !== null && ! $state['primary'] && ($state['streaming'] || $row->status === BindingReplica::STATUS_READY);
                    $row->fill([
                        'status' => $ready ? BindingReplica::STATUS_READY : ($row->status === BindingReplica::STATUS_RECLONING ? BindingReplica::STATUS_RECLONING : BindingReplica::STATUS_PROVISIONING),
                        'status_message' => $state === null
                            ? __('api.global.replica_starting')
                            : ($state['streaming'] && $linkUp ? null : __('api.global.replica_not_streaming')),
                        'lag_seconds' => $state['lag_seconds'] ?? null,
                    ]);
                }
            } catch (Throwable $e) {
                $row->fill(['status_message' => mb_substr($e->getMessage(), 0, 2000)]);
            }
            $row->checked_at = now();
            $row->save();
            $changed = $changed || $before !== [$row->status, $row->status_message, $row->lag_seconds];
        }

        foreach ($this->projectsOf($binding) as $project) {
            $this->refreshProjectRegions($project);
        }

        return $changed;
    }

    /**
     * project_regions.data_status of every region of a global project: null without bindings that
     * need the link, ready when every one is reachable from the region, else syncing / failed.
     */
    public function refreshProjectRegions(Project $project): void
    {
        if (! self::tableReady() || ! GlobalProjectRegions::tableReady()) {
            return;
        }

        $bindings = Binding::query()->whereIn('id', BindingAttachment::query()->where('project_id', $project->getKey())->whereNull('environment_id')->pluck('binding_id'))->get()
            ->filter(fn (Binding $b) => $b->type !== 'external' && $b->isActive() && $this->needsLink($b));
        $rows = BindingReplica::query()->whereIn('binding_id', $bindings->pluck('id'))->get()->groupBy('knative_region_id');

        foreach (ProjectRegion::query()->where('project_id', $project->getKey())->get() as $projectRow) {
            $status = null;
            $message = null;
            if ($bindings->isNotEmpty()) {
                // what the region itself needs: a link or a replica of every binding that does not
                // live there (a binding's own region reaches it as it is; its home being down is
                // every region's problem alike, not this one's). A replica being promoted serves.
                $regionId = (string) $projectRow->knative_region_id;
                $needed = $bindings->filter(fn (Binding $b) => (string) $b->knative_region_id !== $regionId);
                $regionRows = $rows->get($regionId, collect())->filter(fn (BindingReplica $r) => $r->kind !== BindingReplica::KIND_HOME);
                $pending = $regionRows->filter(fn (BindingReplica $r) => ! in_array($r->status, [BindingReplica::STATUS_READY, BindingReplica::STATUS_PROMOTING], true));
                $failed = $regionRows->firstWhere('status', BindingReplica::STATUS_FAILED);
                $status = match (true) {
                    $needed->isEmpty() => ProjectRegion::DATA_READY,
                    $failed !== null => ProjectRegion::DATA_FAILED,
                    $needed->count() > $regionRows->count() || $pending->isNotEmpty() => ProjectRegion::DATA_SYNCING,
                    default => ProjectRegion::DATA_READY,
                };
                $message = $failed->status_message ?? $pending->first()?->status_message;
            }
            if ($projectRow->data_status !== $status || $projectRow->data_message !== $message) {
                $projectRow->forceFill(['data_status' => $status, 'data_message' => $message])->save();
            }
        }
    }

    // ── Moves of the home (switchover, failover) ────────────────────────────

    /**
     * Planned switchover of a dedicated binding's primary to $to (both regions answer):
     * CloudNativePG demotes the home — its last WAL reaches the replica — then promotes $to with
     * the demotion token, so nothing written is lost. Throws when a step does not finish.
     */
    public function switchover(Binding $binding, KnativeRegion $to, int $timeout = self::MOVE_TIMEOUT): void
    {
        $from = $binding->region ?? throw new BindingException(__('api.bindings.region_missing'));
        if ((string) $from->getKey() === (string) $to->getKey() || ! $binding->isDedicated()) {
            return;
        }
        $this->markPromoting($binding, $to);
        $link = $this->ensureLink($binding);
        $members = $this->members($binding);
        $cluster = $binding->dedicatedClusterName();
        $ns = BindingsCluster::dataNamespace();
        $toName = RegionalPostgresManifests::topologyName((string) $to->getKey());

        // $to answers the link from now on; the old home gets a forwarder to it and $to's
        // streaming certificate, so it can follow $to once demoted
        $this->applyServer($binding, $to, $link);
        $this->applyClient($binding, $from, $to, $link);
        $this->copySecrets($binding, $to, $from, passwords: false);
        $this->applyCluster($binding, $from, $members, primary: $toName, source: $toName);

        $token = null;
        $fromClient = $this->cluster->client($from);
        if (! $this->cluster->wait(function () use ($fromClient, $cluster, $ns, &$token) {
            $token = $fromClient->getResourceStatus(BindingManifests::CNPG_API, 'Cluster', $cluster, $ns)['status']['demotionToken'] ?? null;

            return is_string($token) && $token !== '';
        }, $timeout)) {
            throw new BindingException(__('api.global.demotion_timeout', ['region' => $from->name]));
        }

        $this->applyCluster($binding, $to, $members, primary: $toName, source: RegionalPostgresManifests::topologyName((string) $from->getKey()), token: $token);
        $this->waitPrimary($binding, $to);
        $this->finishMove($binding, $from, $to, $members);
    }

    /**
     * Failover of a dedicated binding's primary to $to: the home does not answer, $to is promoted
     * at once (what the home wrote after $to's last received WAL is lost); the old home is rebuilt
     * as a replica of $to when its cluster answers again (rejoin()).
     */
    public function failover(Binding $binding, KnativeRegion $to): void
    {
        $from = $binding->region;
        if ($from === null || (string) $from->getKey() === (string) $to->getKey() || ! $binding->isDedicated()) {
            return;
        }
        $this->markPromoting($binding, $to);
        $link = $this->ensureLink($binding);
        $members = $this->members($binding);
        $toName = RegionalPostgresManifests::topologyName((string) $to->getKey());

        $this->applyServer($binding, $to, $link);
        $this->applyCluster($binding, $to, $members, primary: $toName, source: RegionalPostgresManifests::topologyName((string) $from->getKey()));
        $this->waitPrimary($binding, $to);

        $this->finishMove($binding, $from, $to, $members, rebuildOld: true);
    }

    /**
     * The region that lost the primary in a failover answers again: its cluster — still a primary
     * of the old timeline — is replaced by a replica of the home (pg_basebackup through the link).
     * Returns false while the region does not answer.
     */
    public function rejoin(Binding $binding, KnativeRegion $region): bool
    {
        $row = BindingReplica::query()->where('binding_id', $binding->id)->where('knative_region_id', $region->getKey())->first();
        if (! $row || $row->status !== BindingReplica::STATUS_RECLONING || ! $binding->isDedicated() || ! $binding->region) {
            return true;
        }

        try {
            $k8s = $this->cluster->client($region);
            $ns = BindingsCluster::dataNamespace();
            $cluster = $binding->dedicatedClusterName();
            if ($k8s->getServerVersion() === null) {
                return false;
            }

            $live = $k8s->getResourceStatus(BindingManifests::CNPG_API, 'Cluster', $cluster, $ns);
            $promoted = $live !== null && ($live['spec']['replica']['self'] ?? null) === ($live['spec']['replica']['primary'] ?? null);
            if ($live !== null && ($promoted || ($live['spec']['bootstrap']['pg_basebackup'] ?? null) === null)) {
                // the old primary's cluster and volumes go: its timeline cannot follow the new one
                $k8s->deleteResource(BindingManifests::CNPG_API, 'Cluster', $cluster, $ns, 'Background');
                $this->cluster->wait(fn () => $k8s->getResourceStatus(BindingManifests::CNPG_API, 'Cluster', $cluster, $ns) === null, 180);
                foreach ($k8s->listResources('v1', 'PersistentVolumeClaim', $ns, ['cnpg.io/cluster' => $cluster])['items'] ?? [] as $pvc) {
                    $k8s->deleteResource('v1', 'PersistentVolumeClaim', (string) $pvc['metadata']['name'], $ns);
                }
            }

            $this->removeServer($binding, $region);
            $this->applyClient($binding, $region, $binding->region, $this->ensureLink($binding));
            $this->applyReplica($binding, $region, $binding->region, fresh: true);
            $row->update(['kind' => BindingReplica::KIND_REPLICA, 'status' => BindingReplica::STATUS_PROVISIONING, 'status_message' => __('api.global.replica_rebuilding')]);

            return true;
        } catch (Throwable $e) {
            Log::info('Regional data: the old primary region does not answer yet', ['binding' => $binding->name, 'region' => $region->name, 'error' => $e->getMessage()]);

            return false;
        }
    }

    // ── Lifecycle ───────────────────────────────────────────────────────────

    /** The binding's objects in one region (it left the project, the binding was detached). */
    public function removeRegion(Binding $binding, string $regionId): bool
    {
        $row = BindingReplica::query()->where('binding_id', $binding->id)->where('knative_region_id', $regionId)->first();
        $region = KnativeRegion::query()->find($regionId);
        $ok = true;

        if ($region && $region->kubeconfig && ! $region->is_demo) {
            try {
                $k8s = $this->cluster->client($region);
                $ns = BindingsCluster::dataNamespace();
                foreach (DataLinkManifests::targets((string) $binding->resource_name) as [$apiVersion, $kind, $name]) {
                    $k8s->deleteResource($apiVersion, $kind, $name, $ns);
                }
                if ($binding->isDedicated() && $regionId !== (string) $binding->knative_region_id) {
                    $cluster = $binding->dedicatedClusterName();
                    $ok = $k8s->deleteResource(BindingManifests::CNPG_API, 'Cluster', $cluster, $ns, 'Background');
                    foreach ([RegionalPostgresManifests::superuserSecret($cluster), $cluster.'-owner', RegionalPostgresManifests::sourceReplicationSecret($cluster), RegionalPostgresManifests::sourceCaSecret($cluster)] as $secret) {
                        $k8s->deleteResource('v1', 'Secret', $secret, $ns);
                    }
                }
            } catch (Throwable $e) {
                Log::warning('Regional data not removed from a region', ['binding' => $binding->name, 'region' => $region->name, 'error' => $e->getMessage()]);
                $ok = false;
            }
        }

        if ($ok) {
            $row?->delete();
        } else {
            $row?->update(['status' => BindingReplica::STATUS_REMOVING, 'status_message' => __('api.global.remove_incomplete')]);
        }

        return $ok;
    }

    /** Purge of the binding: every region's objects but the home's (the driver purges those). */
    public function forget(Binding $binding): void
    {
        if (! self::tableReady()) {
            return;
        }
        foreach (BindingReplica::query()->where('binding_id', $binding->id)->get() as $row) {
            if ((string) $row->knative_region_id === (string) $binding->knative_region_id) {
                if ($binding->region) {
                    $this->removeServer($binding, $binding->region);
                }
                $row->delete();

                continue;
            }
            $this->removeRegion($binding, (string) $row->knative_region_id);
        }
    }

    /** Deletion requested / restored: the replica clusters hibernate with the home's. */
    public function hibernate(Binding $binding, bool $on): void
    {
        if (! $binding->isDedicated() || ! self::tableReady()) {
            return;
        }
        foreach (BindingReplica::query()->where('binding_id', $binding->id)->where('kind', BindingReplica::KIND_REPLICA)->with('knativeRegion')->get() as $row) {
            try {
                if ($row->knativeRegion) {
                    $this->cluster->client($row->knativeRegion)->mergePatch(BindingManifests::CNPG_API, 'Cluster', $binding->dedicatedClusterName(), BindingsCluster::dataNamespace(),
                        ['metadata' => ['annotations' => ['cnpg.io/hibernation' => $on ? 'on' : 'off']]]);
                }
            } catch (Throwable $e) {
                Log::warning('Replica cluster hibernation not switched', ['binding' => $binding->name, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * The home's cluster manifest with its place in the topology when the binding has replicas
     * (PostgresDriver applies it on provision and on settings changes); as it is otherwise.
     */
    public function dedicatedManifest(Binding $binding, KnativeRegion $region, array $base): array
    {
        if (! self::replicated($binding)) {
            return $base;
        }

        $members = $this->members($binding);
        $home = RegionalPostgresManifests::topologyName((string) $binding->knative_region_id);
        $self = RegionalPostgresManifests::topologyName((string) $region->getKey());
        $live = $this->cluster->client($region)->getResourceStatus(BindingManifests::CNPG_API, 'Cluster', $binding->dedicatedClusterName(), BindingsCluster::dataNamespace());

        return RegionalPostgresManifests::cluster(
            $base, $self, $home, $self === $home ? $this->nextSource($members, $home) : $home, $members,
            DataLinkManifests::host((string) $binding->resource_name, BindingsCluster::dataNamespace()),
            $live['spec']['bootstrap'] ?? null,
        );
    }

    /**
     * After a rotation of a dedicated binding: the new owner password reaches the replicas through
     * the WAL; their copy of the owner Secret follows, so a promoted replica keeps it.
     */
    public function afterRotation(Binding $binding): void
    {
        if (! self::replicated($binding) || ! $binding->region) {
            return;
        }
        foreach (BindingReplica::query()->where('binding_id', $binding->id)->where('kind', BindingReplica::KIND_REPLICA)->with('knativeRegion')->get() as $row) {
            try {
                if ($row->knativeRegion) {
                    $this->copySecrets($binding, $binding->region, $row->knativeRegion);
                }
            } catch (Throwable $e) {
                $row->update(['status_message' => mb_substr($e->getMessage(), 0, 2000)]);
            }
        }
    }

    /** After a settings change of a dedicated binding: the replica clusters get them too. */
    public function afterSettings(Binding $binding): void
    {
        if (! self::replicated($binding)) {
            return;
        }
        foreach (BindingReplica::query()->where('binding_id', $binding->id)->where('kind', BindingReplica::KIND_REPLICA)->with('knativeRegion')->get() as $row) {
            if ($row->knativeRegion && $binding->region) {
                try {
                    $this->applyReplica($binding, $row->knativeRegion, $binding->region);
                } catch (Throwable $e) {
                    $row->update(['status_message' => mb_substr($e->getMessage(), 0, 2000)]);
                }
            }
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * The regions a binding must be reached from: its home plus every region of the global
     * projects it is attached to (copies on their way out excluded).
     *
     * @return array<string, KnativeRegion>
     */
    private function wantedRegions(Binding $binding): array
    {
        $regions = [];
        if ($binding->region) {
            $regions[(string) $binding->region->getKey()] = $binding->region;
        }
        foreach ($this->projectsOf($binding) as $project) {
            foreach (ProjectRegion::query()->where('project_id', $project->getKey())->with('knativeRegion')->get() as $row) {
                if ($row->knativeRegion && $row->status !== ProjectRegion::STATUS_REMOVING) {
                    $regions[(string) $row->knative_region_id] ??= $row->knativeRegion;
                }
            }
        }

        return $regions;
    }

    /** @return Collection<int, Project> the global projects the binding is attached to */
    private function projectsOf(Binding $binding): Collection
    {
        $ids = BindingAttachment::query()->where('binding_id', $binding->id)->whereNull('environment_id')->pluck('project_id');

        return Project::query()->whereIn('id', $ids)->get()->filter(fn (Project $p) => $p->isGlobal())->values();
    }

    private function replicatingProjectId(Binding $binding): ?string
    {
        return $this->projectsOf($binding)->first()?->getKey();
    }

    /** @return list<string> topology names of every region the binding's data lives in */
    private function members(Binding $binding): array
    {
        $names = [RegionalPostgresManifests::topologyName((string) $binding->knative_region_id)];
        foreach (BindingReplica::query()->where('binding_id', $binding->id)->orderBy('created_at')->pluck('knative_region_id') as $regionId) {
            $names[] = RegionalPostgresManifests::topologyName((string) $regionId);
        }

        return array_values(array_unique($names));
    }

    /** The member a primary would follow after a demotion: the first other one. */
    private function nextSource(array $members, string $primary): string
    {
        foreach ($members as $member) {
            if ($member !== $primary) {
                return $member;
            }
        }

        return $primary;
    }

    /** The link's certificates (issued once per binding, again when they get old). */
    private function ensureLink(Binding $binding): array
    {
        $link = (array) ($binding->replication ?? []);
        if (! DataLinkCertificates::fresh($link) || ($link['sni'] ?? null) !== DataLinkManifests::sni((string) $binding->resource_name)) {
            $link = DataLinkCertificates::issue(DataLinkManifests::sni((string) $binding->resource_name));
            $binding->forceFill(['replication' => $link])->save();
        }

        return $link;
    }

    private function applyServer(Binding $binding, KnativeRegion $region, array $link): void
    {
        $env = $this->drivers->for($binding)->env($binding);
        $target = $this->target($binding, $env);
        if ($target === null && $binding->isDedicated()) {
            $target = ['host' => BindingsCluster::serviceHost($binding->dedicatedClusterName().'-rw'), 'port' => BindingManifests::PG_PORT, 'namespace' => BindingsCluster::dataNamespace(), 'service' => $binding->dedicatedClusterName().'-rw'];
        }
        if ($target === null) {
            return;
        }
        if ($binding->isDedicated()) {
            // a promoted replica answers on its own -rw Service
            $target['service'] = $binding->dedicatedClusterName().'-rw';
            $target['namespace'] = BindingsCluster::dataNamespace();
        }

        $access = $this->drivers->for($binding)->accessTarget($binding);
        $this->cluster->ensureDataNamespace($region);
        $this->cluster->apply($region, DataLinkManifests::server(
            (string) $binding->resource_name,
            BindingsCluster::dataNamespace(),
            $link,
            $target + ['labels' => $access['labels'] ?? null],
            (string) ($region->knative_serving_namespace ?: 'knative-serving'),
        ));
    }

    private function removeServer(Binding $binding, KnativeRegion $region): void
    {
        try {
            $k8s = $this->cluster->client($region);
            $name = DataLinkManifests::name((string) $binding->resource_name);
            $ns = BindingsCluster::dataNamespace();
            foreach ([[DataLinkManifests::TRAEFIK_API, 'IngressRouteTCP', $name], [DataLinkManifests::TRAEFIK_API, 'TLSOption', $name], ['v1', 'Secret', $name.'-server'], ['v1', 'Secret', $name.'-ca'], ['networking.k8s.io/v1', 'NetworkPolicy', $name.'-traefik']] as [$apiVersion, $kind, $object]) {
                $k8s->deleteResource($apiVersion, $kind, $object, $ns);
            }
        } catch (Throwable $e) {
            Log::info('Data link server not removed', ['binding' => $binding->name, 'region' => $region->name, 'error' => $e->getMessage()]);
        }
    }

    private function applyClient(Binding $binding, KnativeRegion $region, KnativeRegion $home, array $link): void
    {
        $address = GlobalDnsServerService::firstAddress($home) ?? throw new BindingException(__('api.global.home_without_address', ['region' => $home->name]));
        $port = $this->target($binding)['port'] ?? BindingManifests::PG_PORT;
        $projectIds = $this->projectsOf($binding)->map(fn (Project $p) => (string) $p->getKey())->all();

        $this->cluster->ensureDataNamespace($region);
        $this->cluster->apply($region, DataLinkManifests::client(
            (string) $binding->resource_name,
            BindingsCluster::dataNamespace(),
            $link,
            $address,
            (int) config('global_dns.data_link.port', 443),
            (int) $port,
            $region->registryImageRef((string) config('global_dns.data_link.image')),
            BindingsCluster::projectNamespace($region),
            $projectIds,
        ));
    }

    /**
     * The Secrets a cluster in $target needs from the one in $source (the home, or the region
     * being promoted): its streaming certificate and CA as -src- copies and — $passwords — its
     * superuser and owner Secrets (the same passwords in every region).
     */
    private function copySecrets(Binding $binding, KnativeRegion $source, KnativeRegion $target, bool $passwords = true): void
    {
        $cluster = $binding->dedicatedClusterName();
        $ns = BindingsCluster::dataNamespace();
        $k8s = $this->cluster->client($source);
        $live = [
            'superuser' => $k8s->getResourceStatus('v1', 'Secret', RegionalPostgresManifests::superuserSecret($cluster), $ns),
            'owner' => $k8s->getResourceStatus('v1', 'Secret', $cluster.'-owner', $ns),
            'replication' => $k8s->getResourceStatus('v1', 'Secret', $cluster.'-replication', $ns),
            'ca' => $k8s->getResourceStatus('v1', 'Secret', $cluster.'-ca', $ns),
        ];
        if (! isset($live['replication']['data'], $live['ca']['data'], $live['superuser']['data'])) {
            throw new BindingException(__('api.global.replica_secrets_missing', ['cluster' => $cluster, 'region' => $source->name]));
        }

        $secrets = RegionalPostgresManifests::secrets($cluster, $ns, $live, BindingManifests::labels(['runr.dev/data-service' => 'postgres', 'runr.dev/binding' => (string) $binding->resource_name]));
        if (! $passwords) {
            $secrets = array_values(array_filter($secrets, fn (array $s) => str_contains((string) $s['metadata']['name'], '-src-')));
        }
        $this->cluster->ensureDataNamespace($target);
        $this->cluster->apply($target, $secrets);
    }

    private function applyReplica(Binding $binding, KnativeRegion $region, KnativeRegion $home, bool $fresh = false): void
    {
        $this->copySecrets($binding, $home, $region);
        $homeName = RegionalPostgresManifests::topologyName((string) $home->getKey());
        $this->applyCluster($binding, $region, $this->members($binding), primary: $homeName, source: $homeName, fresh: $fresh);
    }

    /** The home's cluster with (or, $plain, without) its place in the topology. */
    private function applyHomeCluster(Binding $binding, KnativeRegion $home, bool $plain = false): void
    {
        $members = $this->members($binding);
        $homeName = RegionalPostgresManifests::topologyName((string) $home->getKey());
        if ($plain) {
            $base = $this->baseManifest($binding, $home);
            $k8s = $this->cluster->client($home);
            $live = $k8s->getResourceStatus(BindingManifests::CNPG_API, 'Cluster', $binding->dedicatedClusterName(), BindingsCluster::dataNamespace());
            if ($live !== null) {
                $base['spec']['bootstrap'] = $live['spec']['bootstrap'] ?? $base['spec']['bootstrap'];
                $k8s->applyResource($base, BindingsCluster::dataNamespace());
            }

            return;
        }
        // its own streaming certificate as the -src- copies its externalClusters name
        $this->copySecrets($binding, $home, $home, passwords: false);
        $this->applyCluster($binding, $home, $members, primary: $homeName, source: $this->nextSource($members, $homeName));
    }

    private function applyCluster(Binding $binding, KnativeRegion $region, array $members, string $primary, string $source, ?string $token = null, bool $fresh = false): void
    {
        $k8s = $this->cluster->client($region);
        $ns = BindingsCluster::dataNamespace();
        $live = $fresh ? null : $k8s->getResourceStatus(BindingManifests::CNPG_API, 'Cluster', $binding->dedicatedClusterName(), $ns);
        $manifest = RegionalPostgresManifests::cluster(
            $this->baseManifest($binding, $region),
            RegionalPostgresManifests::topologyName((string) $region->getKey()),
            $primary,
            $source,
            $members,
            DataLinkManifests::host((string) $binding->resource_name, $ns),
            $live['spec']['bootstrap'] ?? null,
            $token,
        );

        $this->cluster->applyPostgresCluster($k8s, $manifest, $ns, 60);
    }

    /** The binding's dedicated cluster as the region would run it (its own backups target, if any). */
    private function baseManifest(Binding $binding, KnativeRegion $region): array
    {
        $backup = null;
        if ($binding->setting('backups') && (string) $region->getKey() === (string) $binding->knative_region_id) {
            try {
                $row = BindingRegionService::forRegion($region, 'postgres');
                $backup = $row ? $this->pg->backupTarget($region, $row) : null;
            } catch (Throwable) {
                $backup = null;
            }
        }

        return BindingManifests::dedicatedCluster($binding, BindingsCluster::dataNamespace(), (string) config('bindings.postgres.image'), (string) config('bindings.postgres.storage_class'), $backup);
    }

    /**
     * The replica cluster's own state, through an ops pod in its region (SQL on its -rw Service:
     * the designated primary): in recovery, the WAL receiver's status, the replay lag.
     *
     * @return array{primary: bool, streaming: bool, lag_seconds: ?int}|null null while it does not answer
     */
    private function replicaState(Binding $binding, KnativeRegion $region): ?array
    {
        $status = $this->pg->clusterStatus($region, $binding->dedicatedClusterName());
        if ($status === null || (int) ($status['readyInstances'] ?? 0) < 1) {
            return null;
        }

        $result = $this->pg->sql($region, $binding->dedicatedClusterName(), 'postgres-replica-state', RegionalPostgresManifests::stateSql());

        return $result->ok ? RegionalPostgresManifests::parseState($result->marked('STATE')[0] ?? null) : null;
    }

    private function waitPrimary(Binding $binding, KnativeRegion $region): void
    {
        if (! $this->cluster->wait(fn () => ($this->replicaState($binding, $region)['primary'] ?? false) === true, self::MOVE_TIMEOUT)) {
            throw new BindingException(__('api.global.promotion_timeout', ['region' => $region->name]));
        }
    }

    private function markPromoting(Binding $binding, KnativeRegion $to): void
    {
        BindingReplica::query()->where('binding_id', $binding->id)->where('knative_region_id', $to->getKey())
            ->update(['status' => BindingReplica::STATUS_PROMOTING, 'status_message' => __('api.global.replica_promoting')]);
    }

    /**
     * After the promotion: $to is the home (its link server, its NetworkPolicy, no forwarder),
     * the other replicas follow it, the old home follows it too (a switchover) or waits to be
     * rebuilt (a failover).
     *
     * @param  list<string>  $members
     */
    private function finishMove(Binding $binding, KnativeRegion $from, KnativeRegion $to, array $members, bool $rebuildOld = false): void
    {
        $ns = BindingsCluster::dataNamespace();
        $toName = RegionalPostgresManifests::topologyName((string) $to->getKey());
        $link = $this->ensureLink($binding);

        $binding->forceFill(['knative_region_id' => $to->getKey()])->save();
        $binding->setRelation('region', $to);

        // the new home: no forwarder of its own any more
        try {
            $k8s = $this->cluster->client($to);
            foreach ([['apps/v1', 'Deployment'], ['v1', 'Service'], ['v1', 'ConfigMap']] as [$apiVersion, $kind]) {
                $k8s->deleteResource($apiVersion, $kind, DataLinkManifests::name((string) $binding->resource_name), $ns);
            }
        } catch (Throwable) {
            // removed with the next sync
        }
        BindingReplica::query()->where('binding_id', $binding->id)->where('knative_region_id', $to->getKey())
            ->update(['kind' => BindingReplica::KIND_HOME, 'status' => BindingReplica::STATUS_READY, 'status_message' => null, 'lag_seconds' => null]);
        BindingReplica::query()->updateOrCreate(
            ['binding_id' => $binding->id, 'knative_region_id' => (string) $from->getKey()],
            [
                'kind' => BindingReplica::KIND_REPLICA,
                'topology' => RegionalPostgresManifests::topologyName((string) $from->getKey()),
                'status' => $rebuildOld ? BindingReplica::STATUS_RECLONING : BindingReplica::STATUS_PROVISIONING,
                'status_message' => $rebuildOld ? __('api.global.replica_rebuild_waits', ['region' => $from->name]) : null,
            ],
        );

        // every other replica follows the new home: its forwarder, its streaming certificate
        foreach (BindingReplica::query()->where('binding_id', $binding->id)->where('kind', BindingReplica::KIND_REPLICA)->with('knativeRegion')->get() as $row) {
            $region = $row->knativeRegion;
            if (! $region || ($rebuildOld && (string) $region->getKey() === (string) $from->getKey())) {
                continue;
            }
            try {
                $this->applyClient($binding, $region, $to, $link);
                $this->copySecrets($binding, $to, $region, passwords: false);
                $this->applyCluster($binding, $region, $members, primary: $toName, source: $toName);
            } catch (Throwable $e) {
                $row->update(['status' => BindingReplica::STATUS_FAILED, 'status_message' => mb_substr($e->getMessage(), 0, 2000)]);
            }
        }

        // the backups and the binding's NetworkPolicy belong to the home
        try {
            if ($binding->setting('backups')) {
                $this->cluster->apply($to, [BindingManifests::scheduledBackup($binding->dedicatedClusterName(), $ns, (string) config('bindings.postgres.backup.schedule'))]);
                $this->cluster->client($from)->deleteResource(BindingManifests::CNPG_API, 'ScheduledBackup', $binding->dedicatedClusterName().'-daily', $ns);
            }
        } catch (Throwable $e) {
            Log::info('Backups of a moved binding not moved yet', ['binding' => $binding->name, 'error' => $e->getMessage()]);
        }
        try {
            app(BindingNetworkPolicies::class)->sync($binding);
        } catch (Throwable $e) {
            Log::info('NetworkPolicy of a moved binding not applied yet', ['binding' => $binding->name, 'error' => $e->getMessage()]);
        }
    }

    /**
     * host and port of a URL (redis://user:pass@host:port/db, http://host:port).
     *
     * @return array{0: string, 1: int}
     */
    private static function hostPort(string $url, int $defaultPort): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return ['', 0];
        }
        $port = (int) ($parts['port'] ?? 0);
        if ($port === 0) {
            $port = match ($parts['scheme'] ?? '') {
                'https' => 443,
                'http' => 80,
                default => $defaultPort,
            };
        }

        return [(string) $parts['host'], $port];
    }

    /** $url with its host replaced (postgresql://user:pass@host:port/db?…). */
    private static function withHost(string $url, string $host): string
    {
        return (string) preg_replace('#^(\w+://(?:[^@/]*@)?)([^:/?]+)#', '${1}'.$host, $url, 1);
    }
}
