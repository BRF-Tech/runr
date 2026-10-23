<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The current rightsizing recommendation of a project (App\Services\Rightsizing\RightsizingService):
 * the size it has and the size its last days call for, why (reasons, evidence), what the change
 * frees and — with the Billing module — what it saves (or costs) per month, and what the members
 * decided. One row per project, replaced by every daily run while it is open.
 *
 *   status  open | snoozed (hidden until snoozed_until) | dismissed (hidden while the
 *           recommended size stays the same) | applied (the config carries the recommended size)
 *
 * @property string $id
 * @property string $project_id
 * @property string|null $region_id
 * @property string $status
 * @property string $direction down | up | mixed
 * @property int $current_cpu_m
 * @property int $current_memory_mb
 * @property int $recommended_cpu_m
 * @property int $recommended_memory_mb
 * @property list<string> $reasons
 * @property array<string, mixed> $evidence
 * @property array<string, mixed>|null $savings
 * @property numeric|null $monthly_savings
 * @property string|null $currency
 * @property array{code: string, message: string}|null $blocked
 * @property Carbon|null $computed_at
 * @property Carbon|null $snoozed_until
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $applied_version_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
class RightsizingRecommendation extends Model
{
    use HasUlids;

    public const OPEN = 'open';

    public const SNOOZED = 'snoozed';

    public const DISMISSED = 'dismissed';

    public const APPLIED = 'applied';

    protected $fillable = [
        'project_id',
        'region_id',
        'status',
        'direction',
        'current_cpu_m',
        'current_memory_mb',
        'recommended_cpu_m',
        'recommended_memory_mb',
        'reasons',
        'evidence',
        'savings',
        'monthly_savings',
        'currency',
        'blocked',
        'computed_at',
        'snoozed_until',
        'decided_by',
        'decided_at',
        'applied_version_id',
    ];

    protected $casts = [
        'current_cpu_m' => 'integer',
        'current_memory_mb' => 'integer',
        'recommended_cpu_m' => 'integer',
        'recommended_memory_mb' => 'integer',
        'reasons' => 'array',
        'evidence' => 'array',
        'savings' => 'array',
        'monthly_savings' => 'float',
        'blocked' => 'array',
        'computed_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Shown to the members: open, or snoozed until a day that has passed. */
    public function isVisible(): bool
    {
        return $this->status === self::OPEN
            || ($this->status === self::SNOOZED && ($this->snoozed_until === null || $this->snoozed_until->isPast()));
    }
}
