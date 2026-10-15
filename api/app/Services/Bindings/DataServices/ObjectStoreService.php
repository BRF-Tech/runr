<?php

namespace App\Services\Bindings\DataServices;

use App\Models\BindingRegionService;
use App\Models\KnativeRegion;
use App\Services\Bindings\BindingException;
use App\Services\Bindings\BindingManifests;
use App\Services\Bindings\BindingsCluster;
use App\Services\Bindings\Objects\GarageAdmin;
use App\Services\Bindings\Objects\GarageBackend;
use App\Services\Bindings\Objects\ObjectBackend;
use App\Services\Bindings\Objects\StaticS3Backend;
use App\Services\Bindings\Ops\BindingOpsRunner;
use App\Services\Helpers\KubeQuantity;
use Illuminate\Support\Str;

/**
 * Object storage data service of a region:
 *
 *  - managed  — Garage (single node, replication factor 1, LMDB metadata) as the StatefulSet
 *               runr-objects in the data namespace. Chosen over SeaweedFS and MinIO: one small
 *               Rust binary (tens of MB of RAM), runs on one node, and its admin API creates
 *               bucket-scoped keys and bucket quotas natively. Used unmodified as a separate
 *               service (AGPL-3.0); RunR only talks to its S3 and admin APIs.
 *  - external — provider "garage" (an external Garage: S3 endpoint + admin endpoint + token,
 *               bucket-scoped keys) or "s3" (any S3 endpoint: a bucket per binding with the
 *               configured key).
 */
class ObjectStoreService implements DataService
{
    public function __construct(
        private BindingsCluster $cluster,
        private BindingOpsRunner $ops,
        private GarageAdmin $admin,
    ) {}

    public function install(KnativeRegion $region, BindingRegionService $row, array $options): void
    {
        if (($options['mode'] ?? 'managed') === 'external') {
            $this->configureExternal($region, $row, $options);

            return;
        }

        $storage = (string) ($options['storage'] ?? $row->configValue('storage') ?? config('bindings.object.storage'));
        $metaSize = (string) config('bindings.object.meta_storage');
        $rpcSecret = (string) ($row->secret('rpc_secret') ?: bin2hex(random_bytes(32)));
        $adminToken = (string) ($row->secret('admin_token') ?: Str::random(48));
        $s3Region = (string) config('bindings.object.s3_region');
        $ns = BindingsCluster::dataNamespace();

        $this->cluster->ensureDataNamespace($region);
        $this->cluster->apply($region, [
            BindingManifests::garageConfigSecret($ns, BindingManifests::garageToml($rpcSecret, $adminToken, $s3Region)),
            BindingManifests::garageStatefulSet($ns, $region->registryImageRef((string) config('bindings.object.image')), $storage, $metaSize, (string) config('bindings.object.storage_class')),
            BindingManifests::garageService($ns),
        ]);

        $k8s = $this->cluster->client($region);
        $ready = $this->cluster->wait(fn () => BindingsCluster::podsReady($k8s->listResources('v1', 'Pod', $ns, ['app.kubernetes.io/name' => BindingManifests::OBJECT_NAME])), 240);
        if (! $ready) {
            throw new BindingException(__('api.bindings.service_timeout', ['service' => 'Garage']));
        }

        $row->mode = 'managed';
        $row->config = ['provider' => 'garage', 'storage' => $storage, 's3_region' => $s3Region];
        $row->secrets = ['rpc_secret' => $rpcSecret, 'admin_token' => $adminToken];

        $this->ensureLayout($region, $row, (int) KubeQuantity::bytes($storage));
    }

    /**
     * A fresh Garage node has no role: assign it the whole volume (zone dc1) and apply the layout.
     */
    private function ensureLayout(KnativeRegion $region, BindingRegionService $row, int $capacity): void
    {
        $backend = new GarageBackend($region, $row, $this->admin, $this->ops);
        $state = $backend->call('object-layout', [GarageAdmin::clusterStatus(), GarageAdmin::clusterLayout()]);

        $nodeId = (string) ($state['status']['nodes'][0]['id'] ?? '');
        if ($nodeId === '') {
            throw new BindingException(__('api.bindings.object_admin_failed', ['error' => 'no Garage node in the cluster status']));
        }

        $roles = array_column((array) ($state['layout']['roles'] ?? []), 'id');
        if (in_array($nodeId, $roles, true)) {
            return;
        }

        $backend->call('object-layout-apply', [
            GarageAdmin::updateLayout($nodeId, max(1, $capacity)),
            GarageAdmin::applyLayout((int) ($state['layout']['version'] ?? 0) + 1),
        ]);
    }

    private function configureExternal(KnativeRegion $region, BindingRegionService $row, array $options): void
    {
        $provider = $options['provider'] ?? 's3';

        $row->mode = 'external';
        $row->config = array_filter([
            'provider' => $provider,
            'endpoint' => rtrim((string) $options['endpoint'], '/'),
            's3_region' => (string) ($options['region'] ?? ($provider === 'garage' ? 'garage' : 'us-east-1')),
            'path_style' => (bool) ($options['path_style'] ?? true),
            'vendor' => $provider === 's3' ? (string) ($options['vendor'] ?? 'Other') : null,
            'bucket_prefix' => (string) ($options['bucket_prefix'] ?? ''),
            'admin_endpoint' => $provider === 'garage' ? rtrim((string) ($options['admin_endpoint'] ?? ''), '/') : null,
        ], fn ($v) => $v !== null);

        $row->secrets = $provider === 'garage'
            ? ['admin_token' => (string) ($options['admin_token'] ?? $row->secret('admin_token'))]
            : [
                'access_key_id' => (string) ($options['access_key_id'] ?? $row->secret('access_key_id')),
                'secret_access_key' => (string) ($options['secret_access_key'] ?? $row->secret('secret_access_key')),
            ];

        $backend = $this->backend($region, $row);
        if ($backend instanceof GarageBackend) {
            $backend->call('object-probe', [GarageAdmin::clusterStatus()]);
        } elseif ($backend instanceof StaticS3Backend) {
            $backend->probe();
        }
    }

    public function uninstall(KnativeRegion $region, BindingRegionService $row): void
    {
        if ($row->mode !== 'managed') {
            return;
        }

        $k8s = $this->cluster->client($region);
        $ns = BindingsCluster::dataNamespace();
        $k8s->deleteResource('apps/v1', 'StatefulSet', BindingManifests::OBJECT_NAME, $ns);
        $k8s->deleteResource('v1', 'Service', BindingManifests::OBJECT_NAME, $ns);
        $k8s->deleteResource('v1', 'Secret', BindingManifests::OBJECT_NAME.'-config', $ns);
        foreach (['meta', 'data'] as $volume) {
            $k8s->deleteResource('v1', 'PersistentVolumeClaim', $volume.'-'.BindingManifests::OBJECT_NAME.'-0', $ns);
        }
    }

    public function health(KnativeRegion $region, BindingRegionService $row): array
    {
        if ($row->mode !== 'managed') {
            return ['state' => 'ready', 'message' => null, 'details' => ['mode' => 'external', 'provider' => $row->configValue('provider')]];
        }

        $pods = $this->cluster->client($region)->listResources('v1', 'Pod', BindingsCluster::dataNamespace(), ['app.kubernetes.io/name' => BindingManifests::OBJECT_NAME]);

        return BindingsCluster::podsReady($pods)
            ? ['state' => 'ready', 'message' => null, 'details' => ['mode' => 'managed']]
            : ['state' => 'degraded', 'message' => __('api.bindings.service_pods_not_ready'), 'details' => ['mode' => 'managed']];
    }

    public function describe(BindingRegionService $row): array
    {
        $backendScoped = $row->mode === 'managed' || $row->configValue('provider') === 'garage';

        return [
            'provider' => $row->configValue('provider', 'garage'),
            'endpoint' => $row->mode === 'managed'
                ? 'http://'.BindingsCluster::serviceHost(BindingManifests::OBJECT_NAME).':'.BindingManifests::OBJECT_S3_PORT
                : $row->configValue('endpoint'),
            's3_region' => $row->configValue('s3_region'),
            'storage' => $row->configValue('storage'),
            'bucket_prefix' => $row->configValue('bucket_prefix'),
            'scoped_keys' => $backendScoped,
        ];
    }

    /** The store object bindings of a region use. */
    public function backend(KnativeRegion $region, ?BindingRegionService $row = null): ObjectBackend
    {
        $row ??= BindingRegionService::forRegion($region, 'object');
        if (! $row) {
            throw new BindingException(__('api.bindings.service_not_ready', ['service' => 'object', 'region' => $region->name]));
        }

        if ($row->mode === 'managed' || $row->configValue('provider') === 'garage') {
            return new GarageBackend($region, $row, $this->admin, $this->ops);
        }

        return new StaticS3Backend($region, $row, $this->ops);
    }
}
