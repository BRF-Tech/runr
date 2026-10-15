<?php

namespace App\Services\Bindings\DataServices;

use App\Models\BindingRegionService;
use App\Models\KnativeRegion;
use App\Services\Bindings\BindingException;
use App\Services\Bindings\BindingManifests;
use App\Services\Bindings\BindingsCluster;
use App\Services\Bindings\Kv\RedisEndpoint;
use App\Services\Bindings\Kv\RedisScripts;
use App\Services\Bindings\Ops\BindingOpsRunner;
use Illuminate\Support\Str;

/**
 * KV data service of a region:
 *
 *  - managed — RunR's KV Redis (runr-kv, Redis 7) in the data namespace: admin password, ACL
 *              file + AOF on a volume, maxmemory with noeviction. Separate from the queue-trigger
 *              Redis, whose clients (queue consumers, region demo) may connect without a password.
 *  - region  — the region's own Redis (Region → Redis, managed / in-cluster / external). It
 *              needs a password; with ACL support each binding gets an ACL user, without it a
 *              logical database (1–15) of its own.
 */
class KvService implements DataService
{
    public const MODES = ['managed', 'region'];

    public function __construct(
        private BindingsCluster $cluster,
        private BindingOpsRunner $ops,
    ) {}

    public function install(KnativeRegion $region, BindingRegionService $row, array $options): void
    {
        $mode = $options['mode'] ?? 'managed';

        if ($mode === 'region') {
            $this->useRegionRedis($region, $row);

            return;
        }

        $maxMemory = (int) ($options['maxmemory_mb'] ?? $row->configValue('maxmemory_mb') ?? config('bindings.kv.maxmemory_mb'));
        $storage = (string) ($options['storage'] ?? $row->configValue('storage') ?? config('bindings.kv.storage'));
        $password = (string) ($row->secret('password') ?: Str::random(40));
        $ns = BindingsCluster::dataNamespace();
        $image = $region->registryImageRef((string) config('bindings.kv.image'));

        $this->cluster->ensureDataNamespace($region);
        $this->cluster->apply($region, [
            BindingManifests::kvSecret($ns, $password),
            BindingManifests::kvPvc($ns, $storage, (string) config('bindings.kv.storage_class')),
            BindingManifests::kvDeployment($ns, $image, $maxMemory),
            BindingManifests::kvService($ns),
        ]);

        $k8s = $this->cluster->client($region);
        $ready = $this->cluster->wait(fn () => BindingsCluster::podsReady($k8s->listResources('v1', 'Pod', $ns, ['app.kubernetes.io/name' => BindingManifests::KV_NAME])), 180);
        if (! $ready) {
            throw new BindingException(__('api.bindings.service_timeout', ['service' => 'KV Redis']));
        }

        $row->mode = 'managed';
        $row->config = ['maxmemory_mb' => $maxMemory, 'storage' => $storage, 'acl' => true];
        $row->secrets = ['password' => $password];

        $probe = $this->ops->run($region, RedisScripts::probe($this->endpoint($region, $row), (string) config('bindings.kv.image')));
        if (! $probe->ok) {
            throw new BindingException(__('api.bindings.kv_unreachable', ['error' => $probe->tail()]));
        }
    }

    private function useRegionRedis(KnativeRegion $region, BindingRegionService $row): void
    {
        if (! $region->has_redis || ! $region->redis_address) {
            throw new BindingException(__('api.bindings.kv_region_redis_missing'));
        }
        if (! $region->redis_password) {
            throw new BindingException(__('api.bindings.kv_region_password_required'));
        }

        $row->mode = 'region';
        $row->secrets = null;
        $row->config = ['acl' => true, 'db_allocations' => (array) $row->configValue('db_allocations', [])];

        $probe = $this->ops->run($region, RedisScripts::probe($this->endpoint($region, $row), (string) config('bindings.kv.image')));
        if (! $probe->ok) {
            throw new BindingException(__('api.bindings.kv_unreachable', ['error' => $probe->tail()]));
        }

        $row->config = array_merge($row->config, ['acl' => in_array('yes', $probe->marked('ACL_SUPPORTED'), true)]);
    }

    public function uninstall(KnativeRegion $region, BindingRegionService $row): void
    {
        if ($row->mode !== 'managed') {
            return;
        }

        $k8s = $this->cluster->client($region);
        $ns = BindingsCluster::dataNamespace();
        $k8s->deleteResource('apps/v1', 'Deployment', BindingManifests::KV_NAME, $ns);
        $k8s->deleteResource('v1', 'Service', BindingManifests::KV_NAME, $ns);
        $k8s->deleteResource('v1', 'Secret', BindingManifests::KV_NAME.'-auth', $ns);
        $k8s->deleteResource('v1', 'PersistentVolumeClaim', BindingManifests::KV_NAME.'-data', $ns);
    }

    public function health(KnativeRegion $region, BindingRegionService $row): array
    {
        if ($row->mode === 'region') {
            $ok = $region->has_redis && (bool) $region->redis_password;

            return ['state' => $ok ? 'ready' : 'error', 'message' => $ok ? null : __('api.bindings.kv_region_password_required'), 'details' => ['mode' => 'region']];
        }

        $pods = $this->cluster->client($region)->listResources('v1', 'Pod', BindingsCluster::dataNamespace(), ['app.kubernetes.io/name' => BindingManifests::KV_NAME]);

        return BindingsCluster::podsReady($pods)
            ? ['state' => 'ready', 'message' => null, 'details' => ['mode' => 'managed']]
            : ['state' => 'degraded', 'message' => __('api.bindings.service_pods_not_ready'), 'details' => ['mode' => 'managed']];
    }

    public function describe(BindingRegionService $row): array
    {
        return [
            'acl' => (bool) $row->configValue('acl', true),
            'maxmemory_mb' => $row->configValue('maxmemory_mb'),
            'storage' => $row->configValue('storage'),
            'host' => $row->mode === 'managed' ? BindingsCluster::serviceHost(BindingManifests::KV_NAME) : null,
        ];
    }

    /**
     * Where KV bindings of the region connect (and RunR administers them).
     */
    public function endpoint(KnativeRegion $region, ?BindingRegionService $row = null): RedisEndpoint
    {
        $row ??= BindingRegionService::forRegion($region, 'kv');
        if (! $row) {
            throw new BindingException(__('api.bindings.service_not_ready', ['service' => 'kv', 'region' => $region->name]));
        }

        if ($row->mode === 'region') {
            [$host, $port] = self::parseAddress((string) $region->redis_address);

            return new RedisEndpoint($host, $port, (string) $region->redis_password, (bool) $row->configValue('acl', true), false, 'region');
        }

        return new RedisEndpoint(
            BindingsCluster::serviceHost(BindingManifests::KV_NAME),
            BindingManifests::KV_PORT,
            (string) $row->secret('password'),
            true,
            true,
            'managed',
        );
    }

    /**
     * Free logical database of a region Redis without ACLs (0 stays with the queue triggers).
     */
    public function allocateDatabase(BindingRegionService $row, string $bindingId): int
    {
        $allocations = (array) $row->configValue('db_allocations', []);
        if (isset($allocations[$bindingId])) {
            return (int) $allocations[$bindingId];
        }

        $used = array_map('intval', array_values($allocations));
        for ($db = 1; $db <= 15; $db++) {
            if (! in_array($db, $used, true)) {
                $allocations[$bindingId] = $db;
                $row->config = array_merge((array) $row->config, ['db_allocations' => $allocations]);
                $row->save();

                return $db;
            }
        }

        throw new BindingException(__('api.bindings.kv_no_free_database'));
    }

    public function releaseDatabase(BindingRegionService $row, string $bindingId): void
    {
        $allocations = (array) $row->configValue('db_allocations', []);
        if (array_key_exists($bindingId, $allocations)) {
            unset($allocations[$bindingId]);
            $row->config = array_merge((array) $row->config, ['db_allocations' => $allocations]);
            $row->save();
        }
    }

    /** @return array{0: string, 1: int} */
    public static function parseAddress(string $address): array
    {
        $raw = preg_replace('#^rediss?://#', '', $address);
        $raw = preg_replace('#^[^@]*@#', '', (string) $raw);
        $raw = explode('/', (string) $raw)[0];
        $parts = explode(':', $raw);

        return [$parts[0] ?: 'localhost', (int) ($parts[1] ?? 6379)];
    }
}
