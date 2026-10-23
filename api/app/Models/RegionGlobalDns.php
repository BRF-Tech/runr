<?php

namespace App\Models;

use App\Casts\Encrypted;
use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The PowerDNS server RunR runs in a region for the global zone (docs/global-projects.md,
 * App\Services\GlobalDns). Platform-wide (central schema): the servers belong to the platform's
 * regions, whose global zone g.<base domain> the operator delegates to them; a region without a
 * row has no server and cannot take part in global projects.
 *
 * The API key of the server's HTTP API is a secret column (App\Casts\Encrypted,
 * App\Support\Secrets\SecretColumns), hidden from serialization; RunR reaches the API through the
 * Kubernetes API server's service proxy only — the API is never exposed outside the cluster.
 *
 * @property string $knative_region_id
 * @property string $status installing | ready | error | uninstalling
 * @property string|null $status_message
 * @property string|null $address the LoadBalancer address of the DNS Service (public :53)
 * @property string|null $api_key
 * @property string|null $image
 * @property bool|null $reachable answers the zone's SOA authoritatively on :53 (null: not checked)
 * @property string|null $sync_status in_sync | drift | error
 * @property string|null $sync_message
 * @property Carbon|null $synced_at
 * @property string|null $tls_status none | pending | ready | error
 * @property string|null $tls_message
 * @property Carbon|null $tls_expires_at
 * @property string|null $tls_spec_hash hash of the certificate objects last applied in the region
 * @property string|null $geoip_source default | url | file | none (null: default)
 * @property string|null $geoip_url the operator's URL of the GeoIP database (source url)
 * @property string|null $geoip_status loaded | missing | off | unknown
 * @property string|null $geoip_message
 * @property Carbon|null $geoip_checked_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read KnativeRegion|null $knativeRegion
 */
class RegionGlobalDns extends Model
{
    use UsesCentralConnection;

    public const STATUS_INSTALLING = 'installing';

    public const STATUS_READY = 'ready';

    public const STATUS_ERROR = 'error';

    public const STATUS_UNINSTALLING = 'uninstalling';

    protected $table = 'region_global_dns';

    protected $primaryKey = 'knative_region_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'status' => self::STATUS_INSTALLING,
    ];

    protected $fillable = [
        'knative_region_id',
        'status',
        'status_message',
        'address',
        'api_key',
        'image',
        'reachable',
        'sync_status',
        'sync_message',
        'synced_at',
        'tls_status',
        'tls_message',
        'tls_expires_at',
        'tls_spec_hash',
        'created_by',
        'geoip_source',
        'geoip_url',
        'geoip_status',
        'geoip_message',
        'geoip_checked_at',
    ];

    protected $hidden = ['api_key'];

    protected $casts = [
        'api_key' => Encrypted::class,
        'reachable' => 'boolean',
        'synced_at' => 'datetime',
        'tls_expires_at' => 'datetime',
        'geoip_checked_at' => 'datetime',
    ];

    public function knativeRegion(): BelongsTo
    {
        return $this->belongsTo(KnativeRegion::class, 'knative_region_id');
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY && $this->address !== null && $this->address !== '';
    }

    /** The server of a region, or null when the region runs none. */
    public static function forRegion(KnativeRegion|string $region): ?self
    {
        $id = $region instanceof KnativeRegion ? $region->getKey() : $region;

        try {
            return static::query()->find($id);
        } catch (\Throwable) {
            // a schema without the table (before the migration ran)
            return null;
        }
    }

    /**
     * Ids of the regions whose server is ready.
     *
     * @return list<string>
     */
    public static function readyRegionIds(): array
    {
        try {
            return static::query()
                ->where('status', self::STATUS_READY)
                ->whereNotNull('address')
                ->pluck('knative_region_id')
                ->map(fn ($id) => (string) $id)
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
