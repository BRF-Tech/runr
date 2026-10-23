<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The platform's choice for one tenant in one platform region (docs/billing.md#queue-consumers),
 * central only: the region's owner sets it per organization.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $region_id
 * @property bool|null $charge_queue_consumers null: as the region; false: the tenant is not
 *                                             charged for the region's queue consumers
 * @property string|null $changed_by
 * @property Carbon|null $updated_at
 */
class TenantRegionOverride extends Model
{
    use HasUlids;

    protected $table = 'billing_tenant_region_overrides';

    protected $fillable = ['tenant_id', 'region_id', 'charge_queue_consumers', 'changed_by'];

    protected $casts = ['charge_queue_consumers' => 'boolean'];
}
