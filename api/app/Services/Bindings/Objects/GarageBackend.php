<?php

namespace App\Services\Bindings\Objects;

use App\Models\Binding;
use App\Models\BindingRegionService;
use App\Models\KnativeRegion;
use App\Services\Bindings\BindingException;
use App\Services\Bindings\BindingManifests;
use App\Services\Bindings\BindingsCluster;
use App\Services\Bindings\Ops\BindingOpsRunner;
use App\Services\Bindings\Ops\OpsTask;
use Illuminate\Support\Collection;

/**
 * Garage: the region's managed object store (runr-objects) or an external Garage cluster
 * reachable from the region (admin API endpoint + token). Each binding: a bucket (global alias =
 * the binding's resource name) with a size quota, and a key allowed read + write on it only.
 */
class GarageBackend implements ObjectBackend
{
    public function __construct(
        private KnativeRegion $region,
        private BindingRegionService $row,
        private GarageAdmin $admin,
        private BindingOpsRunner $ops,
    ) {}

    public function adminUrl(): string
    {
        return $this->row->mode === 'managed'
            ? 'http://'.BindingManifests::OBJECT_NAME.':'.BindingManifests::OBJECT_ADMIN_PORT
            : rtrim((string) $this->row->configValue('admin_endpoint'), '/');
    }

    public function s3Endpoint(): string
    {
        return $this->row->mode === 'managed'
            ? 'http://'.BindingsCluster::serviceHost(BindingManifests::OBJECT_NAME).':'.BindingManifests::OBJECT_S3_PORT
            : rtrim((string) $this->row->configValue('endpoint'), '/');
    }

    public function s3Region(): string
    {
        return (string) ($this->row->configValue('s3_region') ?: config('bindings.object.s3_region'));
    }

    public function pathStyle(): bool
    {
        return true;
    }

    public function scopedKeys(): bool
    {
        return true;
    }

    public function createBucket(Binding $binding): array
    {
        $res = $this->call('object-provision', [
            GarageAdmin::createBucket('bucket', $binding->resource_name),
            GarageAdmin::createKey('key', $binding->resource_name),
            GarageAdmin::allow('{{bucket.id}}', '{{key.accessKeyId}}'),
            GarageAdmin::updateQuota('{{bucket.id}}', self::quotaBytes($binding)),
        ]);

        return [
            'bucket' => $binding->resource_name,
            'bucket_id' => (string) ($res['bucket']['id'] ?? ''),
            'access_key_id' => (string) ($res['key']['accessKeyId'] ?? ''),
            'secret_access_key' => (string) ($res['key']['secretAccessKey'] ?? ''),
        ];
    }

    public function newKey(Binding $binding): array
    {
        $bucketId = $this->bucketId($binding);
        $res = $this->call('object-rotate', [
            GarageAdmin::createKey('key', $binding->resource_name.'-'.now()->format('YmdHis')),
            GarageAdmin::allow($bucketId, '{{key.accessKeyId}}'),
        ]);

        return array_merge((array) $binding->credentials, [
            'bucket_id' => $bucketId,
            'access_key_id' => (string) ($res['key']['accessKeyId'] ?? ''),
            'secret_access_key' => (string) ($res['key']['secretAccessKey'] ?? ''),
        ]);
    }

    public function revokeKey(Binding $binding, array $credentials): void
    {
        $key = (string) ($credentials['access_key_id'] ?? '');
        if ($key === '') {
            return;
        }

        $this->call('object-revoke', [
            GarageAdmin::deny($this->bucketId($binding), $key),
            GarageAdmin::deleteKey($key),
        ]);
    }

    public function suspend(Binding $binding): void
    {
        $this->call('object-suspend', [GarageAdmin::deny($this->bucketId($binding), (string) $binding->credential('access_key_id'))]);
    }

    public function resume(Binding $binding): void
    {
        $this->call('object-resume', [GarageAdmin::allow($this->bucketId($binding), (string) $binding->credential('access_key_id'))]);
    }

    public function purge(Binding $binding): void
    {
        $bucketId = $this->bucketId($binding, false);
        if ($bucketId === '') {
            return; // never created
        }

        $key = (string) $binding->credential('access_key_id');
        if ($key !== '') {
            // The key empties the bucket: allowed again for the purge
            $this->call('object-purge-allow', [GarageAdmin::allow($bucketId, $key)]);

            $env = Rclone::envFile($this->s3Endpoint(), $this->s3Region(), $key, (string) $binding->credential('secret_access_key'));
            $bucket = OpsTask::quote('t:'.$binding->resource_name);
            $result = $this->ops->run($this->region, Rclone::task('object-purge', $env, [
                "delete --rmdirs {$bucket}",
                "cleanup {$bucket} || true",
            ]));
            if (! $result->ok) {
                throw new BindingException(__('api.bindings.purge_failed', ['error' => $result->tail()]));
            }
        }

        $calls = [GarageAdmin::deleteBucket($bucketId)];
        if ($key !== '') {
            $calls[] = GarageAdmin::deleteKey($key);
        }
        $this->call('object-purge', $calls);
    }

    public function setQuota(Binding $binding): void
    {
        $this->call('object-quota', [GarageAdmin::updateQuota($this->bucketId($binding), self::quotaBytes($binding))]);
    }

    public function sizes(Collection $bindings): array
    {
        $calls = [];
        $ids = [];
        foreach ($bindings->values() as $i => $binding) {
            $bucketId = (string) $binding->credential('bucket_id');
            if ($bucketId === '') {
                continue;
            }
            $calls[] = GarageAdmin::bucketInfo("b{$i}", $bucketId, true);
            $ids["b{$i}"] = $binding->id;
        }

        if (! $calls) {
            return [];
        }

        $res = $this->call('object-usage', $calls);
        $sizes = [];
        foreach ($ids as $name => $bindingId) {
            if (is_array($res[$name] ?? null) && isset($res[$name]['bytes'])) {
                $sizes[$bindingId] = (int) $res[$name]['bytes'];
            }
        }

        return $sizes;
    }

    public function health(Binding $binding): array
    {
        $res = $this->call('object-health', [GarageAdmin::bucketInfo('info', $this->bucketId($binding))]);
        $info = (array) ($res['info'] ?? []);

        return ['state' => 'ready', 'message' => null, 'details' => [
            'objects' => (int) ($info['objects'] ?? 0),
            'bytes' => (int) ($info['bytes'] ?? 0),
            'quota_bytes' => $info['quotas']['maxSize'] ?? null,
        ]];
    }

    public function systemBucket(string $name, ?array $existing = null): array
    {
        if (! empty($existing['access_key_id']) && ($existing['bucket'] ?? null) === $name) {
            return $existing;
        }

        $res = $this->call('object-system-bucket', [
            GarageAdmin::createBucket('bucket', $name),
            GarageAdmin::createKey('key', $name),
            GarageAdmin::allow('{{bucket.id}}', '{{key.accessKeyId}}', true, true, true),
        ]);

        return [
            'bucket' => $name,
            'bucket_id' => (string) ($res['bucket']['id'] ?? ''),
            'access_key_id' => (string) ($res['key']['accessKeyId'] ?? ''),
            'secret_access_key' => (string) ($res['key']['secretAccessKey'] ?? ''),
            'endpoint' => $this->s3Endpoint(),
            'region' => $this->s3Region(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function call(string $purpose, array $calls): array
    {
        return $this->admin->run($this->region, $this->adminUrl(), (string) $this->row->secret('admin_token'), $calls, $purpose);
    }

    private function bucketId(Binding $binding, bool $required = true): string
    {
        $id = (string) $binding->credential('bucket_id');
        if ($id !== '') {
            return $id;
        }

        $res = $this->call('object-lookup', [GarageAdmin::bucketInfoByAlias('info', $binding->resource_name)]);
        $id = (string) ($res['info']['id'] ?? '');

        if ($id === '' && $required) {
            throw new BindingException(__('api.bindings.object_bucket_missing', ['bucket' => $binding->resource_name]));
        }

        return $id;
    }

    public static function quotaBytes(Binding $binding): int
    {
        return (int) $binding->setting('quota_gb', config('bindings.object.default_quota_gb')) * 1024 ** 3;
    }
}
