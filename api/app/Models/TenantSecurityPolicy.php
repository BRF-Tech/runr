<?php

namespace App\Models;

use App\Services\SupplyChain\SupplyChainDb;
use Illuminate\Database\Eloquent\Model;

/**
 * Data residency and policy gate of one tenant (organization), platform-wide (central schema).
 *
 * Residency: with residency_enforced, the tenant's projects, deployments, previews, sandboxes
 * and bindings may only use a region listed in allowed_region_ids or one whose data location
 * (region_supply_chain_settings.data_location) is listed in allowed_locations — checked by
 * App\Services\Residency\RegionPolicy. default_region_id preselects the region of new projects.
 *
 * Gate: the tenant's own (stricter) policy gate on top of each region's; null fields inherit.
 *
 * @property string $tenant_id
 * @property bool $residency_enforced
 * @property array|null $allowed_region_ids
 * @property array|null $allowed_locations
 * @property string|null $default_region_id
 * @property string|null $gate_mode
 * @property int|null $gate_max_critical
 * @property int|null $gate_max_high
 * @property bool|null $gate_require_scan
 * @property string|null $updated_by
 */
class TenantSecurityPolicy extends Model
{
    protected $table = 'tenant_security_policies';

    protected $primaryKey = 'tenant_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'residency_enforced' => true,
    ];

    protected $fillable = [
        'tenant_id', 'residency_enforced', 'allowed_region_ids', 'allowed_locations', 'default_region_id',
        'gate_mode', 'gate_max_critical', 'gate_max_high', 'gate_require_scan', 'updated_by',
    ];

    protected $casts = [
        'residency_enforced' => 'boolean',
        'allowed_region_ids' => 'array',
        'allowed_locations' => 'array',
        'gate_max_critical' => 'integer',
        'gate_max_high' => 'integer',
        'gate_require_scan' => 'boolean',
    ];

    public function getConnectionName()
    {
        return SupplyChainDb::central() ?? parent::getConnectionName();
    }

    /** Whether the policy restricts regions at all (enforced and at least one region or location listed). */
    public function restrictsRegions(): bool
    {
        return $this->residency_enforced && (! empty($this->allowed_region_ids) || ! empty($this->allowed_locations));
    }
}
