<?php

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a tenant owes for a period, calculated by RunR (docs/billing.md). Invoicing and payment
 * happen in the operator's billing system, which reads closed charges through the billing API.
 *
 *   kind     capacity_deal  monthly fee of an active capacity deal (ChargeService, daily)
 *            plan           plan fee of a plan period (PlanService)
 *            usage          metered usage of a calendar month, rated with the cost configuration
 *            manual         one-off charge entered by a platform administrator or the billing API
 *   status   open → closed (the month of period_start has ended: line items, total, credit
 *            drawn and amount due are final) | void
 *
 * A charge belongs to the billing period (calendar month) its period_start falls in.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $capacity_deal_id
 * @property string $kind
 * @property string $amount
 * @property string $currency
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $status
 * @property string|null $notes
 * @property string|null $external_ref
 * @property array|null $line_items
 * @property string|null $total
 * @property string $credit_applied
 * @property string|null $amount_due
 * @property Carbon|null $closed_at
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CapacityDeal|null $capacityDeal
 */
class Charge extends Model
{
    use HasUlids;

    public const KIND_CAPACITY_DEAL = 'capacity_deal';

    public const KIND_PLAN = 'plan';

    public const KIND_USAGE = 'usage';

    public const KIND_MANUAL = 'manual';

    public const KINDS = [self::KIND_CAPACITY_DEAL, self::KIND_PLAN, self::KIND_USAGE, self::KIND_MANUAL];

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_VOID = 'void';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_CLOSED, self::STATUS_VOID];

    protected $connection = 'central';

    protected $table = 'charges';

    protected $fillable = [
        'tenant_id',
        'capacity_deal_id',
        'kind',
        'amount',
        'currency',
        'period_start',
        'period_end',
        'status',
        'notes',
        'external_ref',
        'line_items',
        'total',
        'credit_applied',
        'amount_due',
        'closed_at',
        'voided_at',
    ];

    protected $attributes = [
        'kind' => self::KIND_MANUAL,
        'status' => self::STATUS_OPEN,
        'credit_applied' => 0,
    ];

    protected $casts = [
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'amount' => 'decimal:2',
        'total' => 'decimal:2',
        'credit_applied' => 'decimal:2',
        'amount_due' => 'decimal:2',
        'line_items' => 'array',
        'closed_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function capacityDeal(): BelongsTo
    {
        return $this->belongsTo(CapacityDeal::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** Billing period (calendar month) of the charge: "YYYY-MM" of period_start. */
    public function billingPeriod(): string
    {
        return $this->period_start->format('Y-m');
    }

    public function description(): string
    {
        $notes = trim((string) $this->notes);
        if ($notes !== '') {
            return mb_substr($notes, 0, 255);
        }

        return match ($this->kind) {
            self::KIND_USAGE => 'Usage '.$this->billingPeriod(),
            self::KIND_CAPACITY_DEAL => 'Capacity deal '.$this->billingPeriod(),
            default => sprintf('Charge %s – %s', $this->period_start->toDateString(), $this->period_end->toDateString()),
        };
    }

    /**
     * API shape. $estimate: line items / total calculated now for an open charge (amounts provisional).
     *
     * @param  array{line_items: list<array>, total: float}|null  $estimate
     */
    public function toApiArray(?array $estimate = null): array
    {
        $open = $this->isOpen();
        $lines = $open ? ($estimate['line_items'] ?? null) : ($this->line_items ?? []);
        $total = $open ? ($estimate['total'] ?? null) : ($this->total === null ? null : (float) $this->total);

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'kind' => $this->kind,
            'description' => $this->description(),
            'period' => $this->billingPeriod(),
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'currency' => strtoupper((string) $this->currency),
            'status' => $this->status,
            'final' => $this->status === self::STATUS_CLOSED,
            'amount' => (float) $this->amount,
            'line_items' => $lines,
            'total' => $total,
            'credit_applied' => (float) $this->credit_applied,
            'amount_due' => $open ? $total : ($this->amount_due === null ? null : (float) $this->amount_due),
            'external_ref' => $this->external_ref,
            'capacity_deal_id' => $this->capacity_deal_id,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
