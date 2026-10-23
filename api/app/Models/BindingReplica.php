<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where a binding attached to a global project is reached from (docs/global-projects.md,
 * "Data"), one row per region of the project:
 *
 *   home     the region that holds the binding's data (bindings.knative_region_id): its data link
 *            server (a TLS route of the region's Traefik that admits the link's client
 *            certificate only)
 *   replica  a dedicated Postgres binding's CloudNativePG replica cluster in the region — reads
 *            stay in the region (DATABASE_URL_RO), writes go through the data link to the home;
 *            a failover or a switchover promotes it
 *   link     the data link to the home: a forwarder in the region's data namespace that the
 *            region's copies connect to (KV, object storage, shared Postgres)
 *
 * @property string $id
 * @property string $binding_id
 * @property string|null $project_id
 * @property string $knative_region_id
 * @property string $kind home | replica | link
 * @property string $status pending | provisioning | ready | promoting | recloning | failed | removing
 * @property string|null $status_message
 * @property string|null $topology the region's name in the CloudNativePG topology
 * @property int|null $lag_seconds
 * @property Carbon|null $checked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Binding|null $binding
 * @property-read KnativeRegion|null $knativeRegion
 */
class BindingReplica extends Model
{
    use HasUlids;

    public const KIND_HOME = 'home';

    public const KIND_REPLICA = 'replica';

    public const KIND_LINK = 'link';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_READY = 'ready';

    public const STATUS_PROMOTING = 'promoting';

    public const STATUS_RECLONING = 'recloning';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REMOVING = 'removing';

    protected $fillable = [
        'binding_id',
        'project_id',
        'knative_region_id',
        'kind',
        'status',
        'status_message',
        'topology',
        'lag_seconds',
        'checked_at',
    ];

    protected $casts = [
        'lag_seconds' => 'integer',
        'checked_at' => 'datetime',
    ];

    public function binding(): BelongsTo
    {
        return $this->belongsTo(Binding::class);
    }

    public function knativeRegion(): BelongsTo
    {
        return $this->belongsTo(KnativeRegion::class, 'knative_region_id');
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }
}
