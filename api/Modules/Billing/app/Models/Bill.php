<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Billing\Support\Period;

/**
 * A monthly bill (docs/billing.md#bills):
 *
 *   project  one per project and month; the payer is the project's owner (owner_id)
 *   account  one per user and month for what belongs to no project — bindings, sandboxes, model
 *            services — billed to the user who created them (owner_id null: the creator is unknown)
 *   tenant   platform → tenant: one per tenant and month, central schema only (converted 0.5.7
 *            charges: closed bills of their own, subject tenant-legacy:<tenant>)
 *
 * A bill covers period_start … period_end: its month, or the part of it billing was on — a month in
 * which billing was turned off and on again has one bill per run of billed days
 * (BillingActivity). An open bill is calculated on request (an estimate); the daily close job gives
 * a bill whose days have ended its final line items and totals (status closed) and never touches
 * it again. payment_url, paid / paid_at and payment_reference are set by the external invoicing
 * system.
 *
 * @property string $id
 * @property string $kind
 * @property string $subject_key
 * @property string|null $project_id
 * @property string|null $project_name
 * @property string|null $owner_id
 * @property string|null $owner_name
 * @property string|null $owner_email
 * @property string|null $tenant_id
 * @property string $period
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $currency
 * @property string $status
 * @property array<int, array<string, mixed>>|null $line_items
 * @property array<int, array<string, mixed>>|null $metered_lines
 * @property float|null $fixed_total
 * @property float|null $usage_total
 * @property float|null $total
 * @property float|null $metered_cost
 * @property string|null $billing_type
 * @property string|null $payment_url
 * @property Carbon|null $payment_url_set_at
 * @property bool $paid
 * @property Carbon|null $paid_at
 * @property string|null $payment_reference
 * @property string $source
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Bill extends Model
{
    use HasUlids;

    public const PROJECT = 'project';

    public const ACCOUNT = 'account';

    public const TENANT = 'tenant';

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const SOURCE_RUNR = 'runr';

    public const SOURCE_LEGACY = 'legacy';

    protected $table = 'billing_bills';

    protected $fillable = [
        'kind', 'subject_key', 'project_id', 'project_name', 'owner_id', 'owner_name', 'owner_email', 'tenant_id',
        'period', 'period_start', 'period_end', 'currency', 'status', 'line_items', 'metered_lines',
        'fixed_total', 'usage_total', 'total', 'metered_cost', 'billing_type', 'payment_url', 'payment_url_set_at',
        'paid', 'paid_at', 'payment_reference', 'source', 'closed_at',
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
    ];

    public static function projectKey(string $projectId): string
    {
        return 'project:'.$projectId;
    }

    public static function accountKey(?string $userId): string
    {
        return 'account:'.($userId ?? 'none');
    }

    public static function tenantKey(string $tenantId): string
    {
        return 'tenant:'.$tenantId;
    }

    /** Subject of the closed bills converted from a tenant's 0.5.7 charges (never a 0.5.8 bill's). */
    public static function legacyTenantKey(string $tenantId): string
    {
        return 'tenant-legacy:'.$tenantId;
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function periodObject(): Period
    {
        return Period::fromKey($this->period);
    }
}
