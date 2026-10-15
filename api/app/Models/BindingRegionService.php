<?php

namespace App\Models;

use App\Casts\Encrypted;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A region data service bindings run on (docs/bindings.md "Region setup"):
 *
 *  - kv       — mode "managed" (RunR's KV Redis runr-kv in the data namespace) or "region" (the
 *               region's own Redis, Region → Redis)
 *  - object   — mode "managed" (Garage) or "external" (an S3 endpoint the admin configured)
 *  - postgres — mode "managed" (CloudNativePG operator + the shared cluster runr-pg-shared)
 *
 * Platform regions keep their row in the central database (tenants share the service); a
 * tenant-owned region keeps it in the tenant's database — connectionFor() picks the one.
 *
 * @property string $id
 * @property string $knative_region_id
 * @property string $service
 * @property string $mode
 * @property string $status
 * @property string|null $status_message
 * @property array|null $config
 * @property array|null $secrets
 */
class BindingRegionService extends Model
{
    use HasUlids;

    public const SERVICES = ['kv', 'object', 'postgres'];

    public const STATUS_NOT_INSTALLED = 'not_installed';

    public const STATUS_INSTALLING = 'installing';

    public const STATUS_READY = 'ready';

    public const STATUS_ERROR = 'error';

    public const STATUS_UNINSTALLING = 'uninstalling';

    protected $fillable = ['knative_region_id', 'service', 'mode', 'status', 'status_message', 'config', 'secrets'];

    protected $casts = [
        'config' => 'array',
        // Secret column (App\Support\Secrets\SecretColumns, docs/security.md)
        'secrets' => Encrypted::class.':array',
    ];

    protected $hidden = ['secrets'];

    protected $attributes = [
        'mode' => 'managed',
        'status' => self::STATUS_NOT_INSTALLED,
    ];

    /**
     * Connection of a region's data service rows: central for a synced copy of a platform
     * region (tenancy on), the current one otherwise.
     */
    public static function connectionFor(KnativeRegion $region): ?string
    {
        $synced = (bool) ($region->getAttributes()['is_synced'] ?? false);

        return $synced && config('tenancy.enabled') && config('database.connections.central') ? 'central' : null;
    }

    public static function forRegion(KnativeRegion $region, string $service): ?self
    {
        return static::on(self::connectionFor($region))
            ->where('knative_region_id', $region->getKey())
            ->where('service', $service)
            ->first();
    }

    /** The row of a service, a new (unsaved) one when the region has none yet. */
    public static function firstOrNewFor(KnativeRegion $region, string $service): self
    {
        $row = self::forRegion($region, $service);
        if ($row) {
            return $row;
        }

        $row = new static(['knative_region_id' => $region->getKey(), 'service' => $service]);
        $row->setConnection(self::connectionFor($region));

        return $row;
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function configValue(string $key, mixed $default = null): mixed
    {
        return ($this->config ?? [])[$key] ?? $default;
    }

    public function secret(string $key, mixed $default = null): mixed
    {
        return ($this->secrets ?? [])[$key] ?? $default;
    }
}
