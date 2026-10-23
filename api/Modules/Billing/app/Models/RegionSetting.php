<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The billing type of a region (payg | fixed) and whether its queue consumers are charged
 * (docs/billing.md#queue-consumers), kept by the organization that owns the region: the platform
 * for platform regions (a shared region carries the platform's settings), a tenant for its own
 * regions. A region without a row is pay as you go and charges no queue consumer.
 *
 * @property string $region_id
 * @property string $billing_type
 * @property bool $charge_queue_consumers
 * @property string|null $changed_by
 * @property Carbon|null $updated_at
 */
class RegionSetting extends Model
{
    protected $table = 'billing_region_settings';

    protected $primaryKey = 'region_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['region_id', 'billing_type', 'charge_queue_consumers', 'changed_by'];

    protected $casts = ['charge_queue_consumers' => 'boolean'];
}
