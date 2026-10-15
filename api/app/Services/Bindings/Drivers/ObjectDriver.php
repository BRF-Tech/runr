<?php

namespace App\Services\Bindings\Drivers;

use App\Models\Binding;
use App\Models\BindingRegionService;
use App\Models\KnativeRegion;
use App\Services\Bindings\BindingException;
use App\Services\Bindings\BindingManifests;
use App\Services\Bindings\DataServices\ObjectStoreService;
use App\Services\Bindings\Objects\ObjectBackend;
use Illuminate\Support\Collection;

/**
 * Object binding: a bucket with a size quota and a key allowed on that bucket only (Garage), or
 * a bucket with the configured key (other S3 endpoints). Rotation creates a second key; the
 * first one is deleted after the grace period, once the workloads rolled to the new Secret.
 */
class ObjectDriver implements BindingDriver
{
    public function __construct(private ObjectStoreService $objects) {}

    public function provision(Binding $binding): void
    {
        $backend = $this->backend($binding);
        $binding->credentials = $backend->createBucket($binding) + ['scoped' => $backend->scopedKeys()];
    }

    public function rotate(Binding $binding): void
    {
        $new = $this->backend($binding)->newKey($binding);

        $binding->previous_credentials = ($new['access_key_id'] ?? null) !== $binding->credential('access_key_id') ? $binding->credentials : null;
        $binding->credentials = $new + ['scoped' => (bool) $binding->credential('scoped', true)];
    }

    public function finishRotation(Binding $binding): void
    {
        if (! empty($binding->previous_credentials)) {
            $this->backend($binding)->revokeKey($binding, (array) $binding->previous_credentials);
        }

        $binding->previous_credentials = null;
    }

    public function suspend(Binding $binding): void
    {
        $this->backend($binding)->suspend($binding);
    }

    public function resume(Binding $binding): void
    {
        $this->backend($binding)->resume($binding);
    }

    public function purge(Binding $binding): void
    {
        if (! BindingRegionService::forRegion($this->region($binding), 'object')) {
            return;
        }

        $backend = $this->backend($binding);
        if (! empty($binding->previous_credentials)) {
            $backend->revokeKey($binding, (array) $binding->previous_credentials);
        }
        $backend->purge($binding);
    }

    public function applySettings(Binding $binding, array $old): void
    {
        if (($old['quota_gb'] ?? null) !== $binding->setting('quota_gb')) {
            $this->backend($binding)->setQuota($binding);
        }
    }

    public function env(Binding $binding): array
    {
        // Connection details only: valid while the service is being reconfigured too
        $backend = $this->objects->backend($this->region($binding));

        return [
            'S3_ENDPOINT' => $backend->s3Endpoint(),
            'S3_BUCKET' => (string) $binding->credential('bucket'),
            'S3_ACCESS_KEY_ID' => (string) $binding->credential('access_key_id'),
            'S3_SECRET_ACCESS_KEY' => (string) $binding->credential('secret_access_key'),
            'S3_REGION' => $backend->s3Region(),
            'S3_FORCE_PATH_STYLE' => $backend->pathStyle() ? 'true' : 'false',
        ];
    }

    public function health(Binding $binding): array
    {
        try {
            $health = $this->backend($binding)->health($binding);
        } catch (BindingException $e) {
            return ['state' => 'error', 'message' => $e->getMessage(), 'details' => []];
        }

        $health['details']['quota_gb'] = (int) $binding->setting('quota_gb', config('bindings.object.default_quota_gb'));

        return $health;
    }

    public function measure(KnativeRegion $region, Collection $bindings): array
    {
        $sizes = $this->objects->backend($region)->sizes($bindings->filter(fn (Binding $b) => ! empty($b->credentials)));

        return array_map(fn (int $bytes) => ['bytes' => $bytes, 'instances' => 0], $sizes);
    }

    public function enforce(Binding $binding, int $bytes): void
    {
        // Garage enforces the bucket quota itself
    }

    public function accessTarget(Binding $binding): ?array
    {
        $row = BindingRegionService::forRegion($this->region($binding), 'object');

        return $row?->mode === 'managed'
            ? ['labels' => ['app.kubernetes.io/name' => BindingManifests::OBJECT_NAME], 'port' => BindingManifests::OBJECT_S3_PORT]
            : null;
    }

    private function region(Binding $binding): KnativeRegion
    {
        return $binding->region ?? throw new BindingException(__('api.bindings.region_missing'));
    }

    private function backend(Binding $binding): ObjectBackend
    {
        $region = $this->region($binding);
        $row = BindingRegionService::forRegion($region, 'object');
        if (! $row?->isReady()) {
            throw new BindingException(__('api.bindings.service_not_ready', ['service' => 'object', 'region' => $region->name]));
        }

        return $this->objects->backend($region, $row);
    }
}
