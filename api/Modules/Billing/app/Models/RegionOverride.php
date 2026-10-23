<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The organization's own choice for a region (docs/billing.md#queue-consumers), in the
 * organization's database: the install / platform centrally, a tenant in its schema — for its own
 * regions and the platform regions shared with it alike.
 *
 * @property string $region_id
 * @property bool|null $charge_queue_consumers null: as the region; false: the organization's
 *                                             projects there are not charged for their consumers
 * @property string|null $changed_by
 * @property Carbon|null $updated_at
 */
class RegionOverride extends Model
{
    protected $table = 'billing_region_overrides';

    protected $primaryKey = 'region_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['region_id', 'charge_queue_consumers', 'changed_by'];

    protected $casts = ['charge_queue_consumers' => 'boolean'];
}
