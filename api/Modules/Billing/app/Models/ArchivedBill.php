<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A project or account bill of a deleted tenant (docs/billing.md#bills-of-deleted-tenants): copied
 * from the tenant's schema into the central schema before tenant deletion dropped it (BillArchive),
 * as it was then — a closed bill with its final line items, an open one with the estimate of that
 * moment. Read-only; central schema only (BillingContext::central()).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $tenant_slug
 * @property string|null $tenant_name
 * @property string $bill_id
 * @property string $kind
 * @property string $period
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string|null $project_id
 * @property string|null $project_name
 * @property string|null $owner_id
 * @property string|null $owner_name
 * @property string|null $owner_email
 * @property string $currency
 * @property string $status
 * @property string|null $billing_type
 * @property array<int, array<string, mixed>>|null $line_items
 * @property array<int, array<string, mixed>>|null $metered_lines
 * @property float|null $fixed_total
 * @property float|null $usage_total
 * @property float|null $total
 * @property float|null $metered_cost
 * @property string|null $payment_url
 * @property Carbon|null $payment_url_set_at
 * @property bool $paid
 * @property Carbon|null $paid_at
 * @property string|null $payment_reference
 * @property string $source
 * @property Carbon|null $closed_at
 * @property Carbon|null $bill_created_at
 * @property Carbon $archived_at
 */
class ArchivedBill extends Model
{
    use HasUlids;

    protected $table = 'billing_archived_bills';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'tenant_slug', 'tenant_name', 'bill_id', 'kind', 'period', 'period_start', 'period_end',
        'project_id', 'project_name', 'owner_id', 'owner_name', 'owner_email', 'currency', 'status', 'billing_type',
        'line_items', 'metered_lines', 'fixed_total', 'usage_total', 'total', 'metered_cost',
        'payment_url', 'payment_url_set_at', 'paid', 'paid_at', 'payment_reference', 'source', 'closed_at',
        'bill_created_at', 'archived_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'line_items' => 'array',
        'metered_lines' => 'array',
        'fixed_total' => 'float',
        'usage_total' => 'float',
        'total' => 'float',
        'metered_cost' => 'float',
        'payment_url_set_at' => 'datetime',
        'paid' => 'boolean',
        'paid_at' => 'datetime',
        'closed_at' => 'datetime',
        'bill_created_at' => 'datetime',
        'archived_at' => 'datetime',
    ];
}
