<?php

namespace App\Services\Bindings\Objects;

use App\Models\Binding;
use Illuminate\Support\Collection;

/**
 * S3 store object bindings live on: Garage (managed in the region, or an external Garage with
 * its admin API — bucket-scoped keys) or any other S3 endpoint (bucket per binding, the
 * configured key).
 */
interface ObjectBackend
{
    /** S3 endpoint functions use */
    public function s3Endpoint(): string;

    public function s3Region(): string;

    public function pathStyle(): bool;

    /** Keys are scoped to their bucket */
    public function scopedKeys(): bool;

    /** Bucket + key of a new binding → credentials {bucket, bucket_id?, access_key_id, secret_access_key, key_id?} */
    public function createBucket(Binding $binding): array;

    /** A new key for the binding's bucket (rotation) → credentials */
    public function newKey(Binding $binding): array;

    /** Revoke a key (previous credentials of a rotation) */
    public function revokeKey(Binding $binding, array $credentials): void;

    public function suspend(Binding $binding): void;

    public function resume(Binding $binding): void;

    /** Delete every object, the bucket and its key */
    public function purge(Binding $binding): void;

    /** Bucket quota = the binding's quota_gb */
    public function setQuota(Binding $binding): void;

    /**
     * @param  Collection<int, Binding>  $bindings
     * @return array<string, int> binding id → bytes
     */
    public function sizes(Collection $bindings): array;

    /** @return array{state: string, message: ?string, details: array} */
    public function health(Binding $binding): array;

    /**
     * A bucket for RunR itself (Postgres backups) with its own key, created once.
     *
     * @return array{bucket: string, access_key_id: string, secret_access_key: string, endpoint: string, region: string}
     */
    public function systemBucket(string $name, ?array $existing = null): array;
}
