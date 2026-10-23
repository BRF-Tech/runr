<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A usage record or bill of another organization that an organization import brought along
 * (docs/org-export.md#run-history) — read-only history, listed by
 * GET /api/org-transfers/{id}/history. Never metered, priced or billed: the organization's own
 * usage_records and billing_bills are not touched by an import.
 *
 * @property string $id
 * @property string $org_transfer_id the import that brought it
 * @property string $kind usage | bill
 * @property string|null $source_table usage_records | binding_usage_records | workflow_usage | billing_bills
 * @property string|null $project_id the imported project it belongs to (null: no project, e.g. a binding's usage)
 * @property string|null $source_project_id
 * @property string|null $subject project, binding or workflow name
 * @property Carbon|null $period_date the record's date, a bill's period start
 * @property array<string, mixed> $data the row as the source had it
 * @property Carbon|null $created_at
 */
class ImportedHistoryRecord extends Model
{
    use HasUlids;

    public const KIND_USAGE = 'usage';

    public const KIND_BILL = 'bill';

    public const UPDATED_AT = null;

    protected $table = 'imported_history';

    protected $fillable = [
        'org_transfer_id', 'kind', 'source_table', 'project_id', 'source_project_id', 'subject', 'period_date', 'data',
    ];

    protected $casts = [
        'data' => 'array',
        'period_date' => 'date',
    ];

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'source_table' => $this->source_table,
            'project_id' => $this->project_id,
            'source_project_id' => $this->source_project_id,
            'subject' => $this->subject,
            'period_date' => $this->period_date?->toDateString(),
            'data' => $this->data,
        ];
    }
}
