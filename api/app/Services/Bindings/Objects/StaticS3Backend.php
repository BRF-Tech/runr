<?php

namespace App\Services\Bindings\Objects;

use App\Models\Binding;
use App\Models\BindingRegionService;
use App\Models\KnativeRegion;
use App\Services\Bindings\BindingException;
use App\Services\Bindings\Ops\BindingOpsRunner;
use App\Services\Bindings\Ops\OpsTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Any S3 endpoint the admin configured (AWS S3, Ceph RGW, MinIO, Wasabi, …) without an admin API
 * RunR can drive: each binding gets its own bucket (bucket_prefix + resource name); functions use
 * the configured access key — the region page shows these keys as shared, not bucket-scoped.
 */
class StaticS3Backend implements ObjectBackend
{
    public function __construct(
        private KnativeRegion $region,
        private BindingRegionService $row,
        private BindingOpsRunner $ops,
    ) {}

    public function s3Endpoint(): string
    {
        return rtrim((string) $this->row->configValue('endpoint'), '/');
    }

    public function s3Region(): string
    {
        return (string) ($this->row->configValue('s3_region') ?: 'us-east-1');
    }

    public function pathStyle(): bool
    {
        return (bool) $this->row->configValue('path_style', true);
    }

    public function scopedKeys(): bool
    {
        return false;
    }

    public function bucketFor(Binding $binding): string
    {
        return (string) ($binding->credential('bucket') ?: ($this->row->configValue('bucket_prefix', '').$binding->resource_name));
    }

    public function createBucket(Binding $binding): array
    {
        $bucket = $this->bucketFor($binding);
        $this->rclone('object-provision', ['mkdir '.OpsTask::quote('t:'.$bucket)]);

        return $this->credentials($bucket);
    }

    public function newKey(Binding $binding): array
    {
        // The configured key is the only one: rotation re-reads it (an admin-changed key rolls out)
        return $this->credentials($this->bucketFor($binding));
    }

    public function revokeKey(Binding $binding, array $credentials): void {}

    public function suspend(Binding $binding): void {}

    public function resume(Binding $binding): void {}

    public function purge(Binding $binding): void
    {
        $this->rclone('object-purge', ['purge '.OpsTask::quote('t:'.$this->bucketFor($binding))]);
    }

    public function setQuota(Binding $binding): void {}

    public function sizes(Collection $bindings): array
    {
        $buckets = [];
        foreach ($bindings as $binding) {
            $buckets[$binding->id] = $this->bucketFor($binding);
        }
        if (! $buckets) {
            return [];
        }

        $result = $this->ops->run($this->region, Rclone::sizes($this->envFile(), $buckets));
        $sizes = [];
        foreach ($result->marked('USAGE') as $line) {
            [$id, $bytes] = array_pad(explode(' ', $line), 2, '0');
            $sizes[$id] = (int) $bytes;
        }

        return $sizes;
    }

    public function health(Binding $binding): array
    {
        $sizes = $this->sizes(collect([$binding]));

        return isset($sizes[$binding->id])
            ? ['state' => 'ready', 'message' => null, 'details' => ['bytes' => $sizes[$binding->id], 'shared_key' => true]]
            : ['state' => 'error', 'message' => __('api.bindings.object_unreachable'), 'details' => ['shared_key' => true]];
    }

    public function systemBucket(string $name, ?array $existing = null): array
    {
        $bucket = $this->row->configValue('bucket_prefix', '').$name;
        if (($existing['bucket'] ?? null) !== $bucket) {
            $this->rclone('object-system-bucket', ['mkdir '.OpsTask::quote('t:'.$bucket)]);
        }

        return $this->credentials($bucket) + ['endpoint' => $this->s3Endpoint(), 'region' => $this->s3Region()];
    }

    /** Probe the endpoint + key: create and remove a bucket. */
    public function probe(): void
    {
        $bucket = OpsTask::quote('t:'.$this->row->configValue('bucket_prefix', '').'runr-probe-'.strtolower(Str::random(6)));
        $this->rclone('object-probe', ["mkdir {$bucket}", "rmdir {$bucket}"]);
    }

    private function credentials(string $bucket): array
    {
        return [
            'bucket' => $bucket,
            'access_key_id' => (string) $this->row->secret('access_key_id'),
            'secret_access_key' => (string) $this->row->secret('secret_access_key'),
        ];
    }

    private function envFile(): string
    {
        return Rclone::envFile(
            $this->s3Endpoint(),
            $this->s3Region(),
            (string) $this->row->secret('access_key_id'),
            (string) $this->row->secret('secret_access_key'),
            (string) ($this->row->configValue('vendor') ?: 'Other'),
            $this->pathStyle(),
        );
    }

    private function rclone(string $purpose, array $commands): void
    {
        $result = $this->ops->run($this->region, Rclone::task($purpose, $this->envFile(), $commands));
        if (! $result->ok) {
            throw new BindingException(__('api.bindings.object_operation_failed', ['error' => $result->tail()]));
        }
    }
}
