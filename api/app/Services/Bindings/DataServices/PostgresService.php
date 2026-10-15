<?php

namespace App\Services\Bindings\DataServices;

use App\Models\BindingRegionService;
use App\Models\KnativeRegion;
use App\Services\Bindings\BindingException;
use App\Services\Bindings\BindingManifests;
use App\Services\Bindings\BindingsCluster;
use App\Services\Bindings\Ops\BindingOpsRunner;
use App\Services\Bindings\Ops\OpsResult;
use App\Services\Bindings\Ops\OpsTask;
use App\Services\HelmService;

/**
 * Postgres data service of a region: the CloudNativePG operator (Helm chart, operator namespace)
 * and the shared cluster runr-pg-shared in the data namespace — one database + role per binding.
 * Dedicated clusters (plan "dedicated") are created per binding by the Postgres driver.
 * Optional backups go to a bucket of the region's object store (barman-cloud, daily + WAL).
 */
class PostgresService implements DataService
{
    public const BACKUP_SECRET = 'runr-pg-backup-s3';

    public function __construct(
        private BindingsCluster $cluster,
        private BindingOpsRunner $ops,
        private ObjectStoreService $objects,
    ) {}

    public function install(KnativeRegion $region, BindingRegionService $row, array $options): void
    {
        $instances = max(1, (int) ($options['instances'] ?? $row->configValue('instances') ?? config('bindings.postgres.shared.instances')));
        $storage = (string) ($options['storage'] ?? $row->configValue('storage') ?? config('bindings.postgres.shared.storage'));
        $backups = (bool) ($options['backups'] ?? $row->configValue('backups', false));
        $ns = BindingsCluster::dataNamespace();

        $this->installOperator($region);
        $this->cluster->ensureDataNamespace($region);

        $row->mode = 'managed';
        $row->config = [
            'instances' => $instances,
            'storage' => $storage,
            'backups' => $backups,
            'chart_version' => (string) config('bindings.postgres.chart_version'),
        ];

        $backup = $backups ? $this->backupTarget($region, $row) : null;
        $cluster = BindingManifests::sharedCluster(
            $ns,
            $instances,
            $storage,
            (string) config('bindings.postgres.image'),
            (int) config('bindings.postgres.shared.max_connections'),
            (string) config('bindings.postgres.storage_class'),
            $backup,
        );

        $k8s = $this->cluster->client($region);
        // The operator's webhook can answer a moment after its Deployment is ready
        if (! $this->cluster->wait(fn () => $k8s->applyResource($cluster, $ns), 120)) {
            throw new BindingException(__('api.bindings.postgres_cluster_failed', ['cluster' => BindingManifests::PG_SHARED]));
        }
        if ($backup) {
            $this->cluster->apply($region, [BindingManifests::scheduledBackup(BindingManifests::PG_SHARED, $ns, (string) config('bindings.postgres.backup.schedule'))]);
        } else {
            $k8s->deleteResource(BindingManifests::CNPG_API, 'ScheduledBackup', BindingManifests::PG_SHARED.'-daily', $ns);
        }

        if (! $this->waitClusterReady($region, BindingManifests::PG_SHARED, $instances, 600)) {
            throw new BindingException(__('api.bindings.service_timeout', ['service' => 'Postgres ('.BindingManifests::PG_SHARED.')']));
        }

        // Bindings connect to their own database only: nobody else may connect to the defaults
        $result = $this->sql($region, BindingManifests::PG_SHARED, 'postgres-init', implode("\n", [
            'REVOKE CONNECT ON DATABASE postgres FROM PUBLIC;',
            "SELECT 'REVOKE CONNECT ON DATABASE app FROM PUBLIC' WHERE EXISTS (SELECT FROM pg_database WHERE datname = 'app')\\gexec",
        ]));
        if (! $result->ok) {
            throw new BindingException(__('api.bindings.postgres_sql_failed', ['error' => $result->tail()]));
        }
    }

    /** CloudNativePG operator through its Helm chart (upgrade --install, waits for it). */
    public function installOperator(KnativeRegion $region): void
    {
        $helm = app(HelmService::class);
        $helm->repoAdd((string) config('bindings.postgres.chart_repo_name'), (string) config('bindings.postgres.chart_repo'));

        $result = $helm->install(
            'cnpg',
            (string) config('bindings.postgres.chart'),
            (string) config('bindings.postgres.operator_namespace'),
            [],
            (string) $region->kubeconfig,
            (string) config('bindings.postgres.chart_version'),
        );

        if (! $result->success) {
            throw new BindingException(__('api.bindings.cnpg_install_failed', ['error' => mb_substr(trim($result->error ?: $result->output), 0, 400)]));
        }

        $k8s = $this->cluster->client($region);
        if (! $this->cluster->wait(fn () => $k8s->hasApiVersion(BindingManifests::CNPG_API), 120)) {
            throw new BindingException(__('api.bindings.cnpg_install_failed', ['error' => BindingManifests::CNPG_API.' is not served']));
        }
    }

    public function uninstall(KnativeRegion $region, BindingRegionService $row): void
    {
        $k8s = $this->cluster->client($region);
        $ns = BindingsCluster::dataNamespace();

        $k8s->deleteResource(BindingManifests::CNPG_API, 'ScheduledBackup', BindingManifests::PG_SHARED.'-daily', $ns);
        $k8s->deleteResource(BindingManifests::CNPG_API, 'Cluster', BindingManifests::PG_SHARED, $ns);
        $k8s->deleteResource('v1', 'Secret', self::BACKUP_SECRET, $ns);

        app(HelmService::class)->uninstall('cnpg', (string) config('bindings.postgres.operator_namespace'), (string) $region->kubeconfig);
    }

    public function health(KnativeRegion $region, BindingRegionService $row): array
    {
        $status = $this->clusterStatus($region, BindingManifests::PG_SHARED);
        if (! $status) {
            return ['state' => 'error', 'message' => __('api.bindings.postgres_cluster_missing', ['cluster' => BindingManifests::PG_SHARED]), 'details' => []];
        }

        $ready = (int) ($status['readyInstances'] ?? 0);
        $want = (int) ($status['instances'] ?? $row->configValue('instances', 1));

        return [
            'state' => $ready >= $want && $ready > 0 ? 'ready' : ($ready > 0 ? 'degraded' : 'pending'),
            'message' => $status['phase'] ?? null,
            'details' => ['ready_instances' => $ready, 'instances' => $want],
        ];
    }

    public function describe(BindingRegionService $row): array
    {
        return [
            'instances' => $row->configValue('instances'),
            'storage' => $row->configValue('storage'),
            'backups' => (bool) $row->configValue('backups', false),
            'chart_version' => $row->configValue('chart_version'),
            'host' => BindingsCluster::serviceHost(BindingManifests::PG_SHARED.'-rw'),
        ];
    }

    /** status of a CNPG Cluster, null when it does not exist */
    public function clusterStatus(KnativeRegion $region, string $cluster): ?array
    {
        $resource = $this->cluster->client($region)->getResourceStatus(BindingManifests::CNPG_API, 'Cluster', $cluster, BindingsCluster::dataNamespace());

        return $resource ? ((array) ($resource['status'] ?? []) + ['instances' => $resource['spec']['instances'] ?? null]) : null;
    }

    public function waitClusterReady(KnativeRegion $region, string $cluster, int $instances, int $timeout): bool
    {
        return $this->cluster->wait(function () use ($region, $cluster, $instances) {
            $status = $this->clusterStatus($region, $cluster);

            return $status && (int) ($status['readyInstances'] ?? 0) >= $instances;
        }, $timeout);
    }

    /**
     * Backups bucket + key in the region object store and the Secret barman-cloud reads it from.
     *
     * @return array{secret: string, bucket: string, endpoint: string}
     */
    public function backupTarget(KnativeRegion $region, BindingRegionService $row): array
    {
        $objects = BindingRegionService::forRegion($region, 'object');
        if (! $objects?->isReady()) {
            throw new BindingException(__('api.bindings.backups_need_object_store'));
        }

        $target = $this->objects->backend($region, $objects)->systemBucket(
            (string) config('bindings.postgres.backup.bucket'),
            $row->secret('backup'),
        );

        $row->secrets = array_merge((array) $row->secrets, ['backup' => $target]);
        if ($row->exists) {
            $row->save();
        }

        $this->cluster->apply($region, [BindingManifests::backupSecret(BindingsCluster::dataNamespace(), self::BACKUP_SECRET, $target)]);

        return ['secret' => self::BACKUP_SECRET, 'bucket' => $target['bucket'], 'endpoint' => $target['endpoint']];
    }

    /**
     * SQL as the cluster's superuser (CNPG <cluster>-superuser Secret), statements of $sql run
     * one by one (psql, ON_ERROR_STOP). Prints RUNR_OK when every statement succeeded.
     */
    public function sql(KnativeRegion $region, string $cluster, string $purpose, string $sql, string $database = 'postgres'): OpsResult
    {
        $secret = $cluster.'-superuser';
        $env = [
            ['name' => 'PGHOST', 'value' => $cluster.'-rw'],
            ['name' => 'PGPORT', 'value' => (string) BindingManifests::PG_PORT],
            ['name' => 'PGDATABASE', 'value' => $database],
            ['name' => 'PGSSLMODE', 'value' => 'require'],
            ['name' => 'PGCONNECT_TIMEOUT', 'value' => '10'],
            ['name' => 'PGUSER', 'valueFrom' => ['secretKeyRef' => ['name' => $secret, 'key' => 'username']]],
            ['name' => 'PGPASSWORD', 'valueFrom' => ['secretKeyRef' => ['name' => $secret, 'key' => 'password']]],
        ];

        $script = "set -e\npsql -v ON_ERROR_STOP=1 -X -q -At -f /ops/script.sql\necho RUNR_OK\n";

        $result = $this->ops->run($region, new OpsTask($purpose, (string) config('bindings.postgres.image'), $script, ['script.sql' => $sql."\n"], $env));

        return new OpsResult($result->ok && $result->hasMarker('RUNR_OK'), $result->output, $result->phase);
    }
}
