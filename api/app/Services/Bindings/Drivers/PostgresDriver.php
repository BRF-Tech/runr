<?php

namespace App\Services\Bindings\Drivers;

use App\Models\Binding;
use App\Models\BindingRegionService;
use App\Models\KnativeRegion;
use App\Services\Bindings\BindingException;
use App\Services\Bindings\BindingManifests;
use App\Services\Bindings\BindingsCluster;
use App\Services\Bindings\DataServices\PostgresService;
use App\Services\Bindings\Postgres\PostgresSql;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Postgres binding:
 *
 *  - shared    — database + role on the region's runr-pg-shared cluster (connection limit,
 *                statement timeout on the role); over its storage limit new sessions are
 *                read-only.
 *  - dedicated — its own CNPG cluster pg-<resource name> (instances, storage size, optional
 *                backups); the owner role is managed by the operator from the owner Secret, so a
 *                rotation is a Secret change. Suspended (deletion requested) = hibernated.
 */
class PostgresDriver implements BindingDriver
{
    public function __construct(
        private PostgresService $pg,
        private BindingsCluster $cluster,
    ) {}

    public function provision(Binding $binding): void
    {
        $this->assertService($binding);

        if ($binding->isDedicated()) {
            $this->provisionDedicated($binding);

            return;
        }

        $password = Str::random(32);
        $this->sql($binding, 'postgres-provision', PostgresSql::create($binding, $password));
        $binding->credentials = $this->credentials($binding, BindingManifests::PG_SHARED, $password);
    }

    private function provisionDedicated(Binding $binding): void
    {
        $region = $this->region($binding);
        $ns = BindingsCluster::dataNamespace();
        $password = Str::random(32);
        $cluster = $binding->dedicatedClusterName();
        $backup = $binding->setting('backups') ? $this->pg->backupTarget($region, $this->row($binding)) : null;

        $this->cluster->ensureDataNamespace($region);
        $this->cluster->apply($region, [BindingManifests::ownerSecret($binding, $ns, $password)]);

        $manifest = BindingManifests::dedicatedCluster($binding, $ns, (string) config('bindings.postgres.image'), (string) config('bindings.postgres.storage_class'), $backup);
        $k8s = $this->cluster->client($region);
        if (! $this->cluster->wait(fn () => $k8s->applyResource($manifest, $ns), 60)) {
            throw new BindingException(__('api.bindings.postgres_cluster_failed', ['cluster' => $cluster]));
        }
        if ($backup) {
            $this->cluster->apply($region, [BindingManifests::scheduledBackup($cluster, $ns, (string) config('bindings.postgres.backup.schedule'))]);
        }

        $instances = (int) $manifest['spec']['instances'];
        if (! $this->pg->waitClusterReady($region, $cluster, $instances, 900)) {
            throw new BindingException(__('api.bindings.service_timeout', ['service' => "Postgres ({$cluster})"]));
        }

        $binding->credentials = $this->credentials($binding, $cluster, $password);
    }

    public function rotate(Binding $binding): void
    {
        $password = Str::random(32);

        if ($binding->isDedicated()) {
            // The operator applies the owner Secret's new password to the managed role
            $this->cluster->apply($this->region($binding), [BindingManifests::ownerSecret($binding, BindingsCluster::dataNamespace(), $password)]);
        } else {
            $this->sql($binding, 'postgres-rotate', PostgresSql::password($binding, $password));
        }

        $binding->previous_credentials = null;
        $binding->credentials = array_merge((array) $binding->credentials, ['password' => $password]);
    }

    public function finishRotation(Binding $binding): void
    {
        $binding->previous_credentials = null;
    }

    public function suspend(Binding $binding): void
    {
        if ($binding->isDedicated()) {
            $this->hibernate($binding, true);

            return;
        }

        $this->sql($binding, 'postgres-suspend', PostgresSql::login($binding, false));
    }

    public function resume(Binding $binding): void
    {
        if ($binding->isDedicated()) {
            $this->hibernate($binding, false);

            return;
        }

        $this->sql($binding, 'postgres-resume', PostgresSql::login($binding, true));
    }

    public function purge(Binding $binding): void
    {
        $region = $this->region($binding);

        if ($binding->isDedicated()) {
            $k8s = $this->cluster->client($region);
            $ns = BindingsCluster::dataNamespace();
            $cluster = $binding->dedicatedClusterName();
            $k8s->deleteResource(BindingManifests::CNPG_API, 'ScheduledBackup', $cluster.'-daily', $ns);
            $k8s->deleteResource(BindingManifests::CNPG_API, 'Cluster', $cluster, $ns);
            $k8s->deleteResource('v1', 'Secret', $cluster.'-owner', $ns);

            return;
        }

        if (! BindingRegionService::forRegion($region, 'postgres')) {
            return;
        }

        $this->sql($binding, 'postgres-purge', PostgresSql::drop($binding));
    }

    public function applySettings(Binding $binding, array $old): void
    {
        if ($binding->isDedicated()) {
            $region = $this->region($binding);
            $ns = BindingsCluster::dataNamespace();
            $backup = $binding->setting('backups') ? $this->pg->backupTarget($region, $this->row($binding)) : null;
            $this->cluster->apply($region, [BindingManifests::dedicatedCluster($binding, $ns, (string) config('bindings.postgres.image'), (string) config('bindings.postgres.storage_class'), $backup)]);

            if (($old['statement_timeout'] ?? null) !== $binding->setting('statement_timeout')) {
                $this->sql($binding, 'postgres-settings', 'ALTER ROLE '.PostgresSql::ident($binding->pgName()).' SET statement_timeout = '
                    .PostgresSql::literal((string) $binding->setting('statement_timeout', config('bindings.postgres.default_statement_timeout'))).';');
            }

            return;
        }

        $this->sql($binding, 'postgres-settings', PostgresSql::settings($binding));
        $this->enforce($binding, (int) $binding->usage_bytes);
    }

    public function env(Binding $binding): array
    {
        $host = (string) $binding->credential('host');
        $port = (int) $binding->credential('port', BindingManifests::PG_PORT);
        $database = (string) $binding->credential('database');
        $user = (string) $binding->credential('username');
        $password = (string) $binding->credential('password');

        return [
            'DATABASE_URL' => sprintf('postgresql://%s:%s@%s:%d/%s?sslmode=require', rawurlencode($user), rawurlencode($password), $host, $port, rawurlencode($database)),
            'PGHOST' => $host,
            'PGPORT' => (string) $port,
            'PGDATABASE' => $database,
            'PGUSER' => $user,
            'PGPASSWORD' => $password,
            'PGSSLMODE' => 'require',
        ];
    }

    public function health(Binding $binding): array
    {
        $region = $this->region($binding);
        $cluster = $binding->isDedicated() ? $binding->dedicatedClusterName() : BindingManifests::PG_SHARED;
        $status = $this->pg->clusterStatus($region, $cluster);
        $details = [
            'cluster' => $cluster,
            'plan' => $binding->plan,
            'storage_gb' => (int) $binding->setting('storage_gb'),
            'used_bytes' => (int) $binding->usage_bytes,
            'connection_limit' => (int) $binding->setting('connection_limit', config('bindings.postgres.default_connection_limit')),
            'statement_timeout' => (string) $binding->setting('statement_timeout', config('bindings.postgres.default_statement_timeout')),
            'over_quota' => (bool) $binding->setting('over_quota', false),
        ];

        if (! $status) {
            return ['state' => 'error', 'message' => __('api.bindings.postgres_cluster_missing', ['cluster' => $cluster]), 'details' => $details];
        }

        $ready = (int) ($status['readyInstances'] ?? 0);
        $want = (int) ($status['instances'] ?? 1);
        $details += ['ready_instances' => $ready, 'instances' => $want, 'phase' => $status['phase'] ?? null];

        $state = match (true) {
            $ready === 0 => 'pending',
            $ready < $want => 'degraded',
            $details['over_quota'] => 'degraded',
            default => 'ready',
        };

        return ['state' => $state, 'message' => $details['over_quota'] ? __('api.bindings.over_quota') : ($status['phase'] ?? null), 'details' => $details];
    }

    public function measure(KnativeRegion $region, Collection $bindings): array
    {
        $usage = [];
        $shared = $bindings->filter(fn (Binding $b) => ! $b->isDedicated() && ! empty($b->credentials));
        $byDbName = $shared->keyBy(fn (Binding $b) => $b->pgName());

        if ($byDbName->isNotEmpty()) {
            $result = $this->pg->sql($region, BindingManifests::PG_SHARED, 'postgres-usage', PostgresSql::sizes($byDbName->keys()->all()));
            foreach ($result->marked('USAGE') as $line) {
                [$db, $bytes] = array_pad(explode(' ', $line), 2, '0');
                if ($binding = $byDbName->get($db)) {
                    $usage[$binding->id] = ['bytes' => (int) $bytes, 'instances' => 0];
                }
            }
        }

        foreach ($bindings->filter(fn (Binding $b) => $b->isDedicated() && ! empty($b->credentials)) as $binding) {
            $cluster = $binding->dedicatedClusterName();
            $status = $this->pg->clusterStatus($region, $cluster);
            $instances = $binding->status === Binding::STATUS_DELETING ? 0 : (int) ($status['readyInstances'] ?? 0);
            $bytes = 0;

            if ($instances > 0) {
                $result = $this->pg->sql($region, $cluster, 'postgres-usage', PostgresSql::sizes([$binding->pgName()]));
                $line = $result->marked('USAGE')[0] ?? null;
                $bytes = $line ? (int) (explode(' ', $line)[1] ?? 0) : 0;
            }

            $usage[$binding->id] = ['bytes' => $bytes, 'instances' => $instances];
        }

        return $usage;
    }

    public function enforce(Binding $binding, int $bytes): void
    {
        if ($binding->isDedicated() || ! $binding->isActive() || empty($binding->credentials)) {
            return; // a dedicated cluster is bounded by its volume
        }

        $limit = (int) $binding->setting('storage_gb', config('bindings.postgres.default_storage_gb')) * 1024 ** 3;
        $over = $limit > 0 && $bytes > $limit;

        if ($over === (bool) $binding->setting('over_quota', false)) {
            return;
        }

        $this->sql($binding, 'postgres-quota', PostgresSql::readOnly($binding, $over));

        $binding->settings = array_merge((array) $binding->settings, ['over_quota' => $over]);
        $binding->status_message = $over ? __('api.bindings.over_quota') : null;
    }

    public function accessTarget(Binding $binding): ?array
    {
        $cluster = $binding->isDedicated() ? $binding->dedicatedClusterName() : BindingManifests::PG_SHARED;

        return ['labels' => ['cnpg.io/cluster' => $cluster], 'port' => BindingManifests::PG_PORT];
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────

    private function credentials(Binding $binding, string $cluster, string $password): array
    {
        return [
            'cluster' => $cluster,
            'host' => BindingsCluster::serviceHost($cluster.'-rw'),
            'port' => BindingManifests::PG_PORT,
            'database' => $binding->pgName(),
            'username' => $binding->pgName(),
            'password' => $password,
        ];
    }

    private function hibernate(Binding $binding, bool $on): void
    {
        $this->cluster->client($this->region($binding))->mergePatch(
            BindingManifests::CNPG_API,
            'Cluster',
            $binding->dedicatedClusterName(),
            BindingsCluster::dataNamespace(),
            ['metadata' => ['annotations' => ['cnpg.io/hibernation' => $on ? 'on' : 'off']]],
        );
    }

    private function sql(Binding $binding, string $purpose, string $sql): void
    {
        $cluster = $binding->isDedicated() ? $binding->dedicatedClusterName() : BindingManifests::PG_SHARED;
        $result = $this->pg->sql($this->region($binding), $cluster, $purpose, $sql);

        if (! $result->ok) {
            throw new BindingException(__('api.bindings.postgres_sql_failed', ['error' => $result->tail()]));
        }
    }

    private function region(Binding $binding): KnativeRegion
    {
        return $binding->region ?? throw new BindingException(__('api.bindings.region_missing'));
    }

    private function row(Binding $binding): BindingRegionService
    {
        return BindingRegionService::forRegion($this->region($binding), 'postgres')
            ?? throw new BindingException(__('api.bindings.service_not_ready', ['service' => 'postgres', 'region' => $this->region($binding)->name]));
    }

    private function assertService(Binding $binding): void
    {
        if (! $this->row($binding)->isReady()) {
            throw new BindingException(__('api.bindings.service_not_ready', ['service' => 'postgres', 'region' => $this->region($binding)->name]));
        }
    }
}
