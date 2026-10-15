<?php

namespace App\Workflows\Models;

use App\Models\Project;
use App\Workflows\Engine\Duration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A workflow definition of a project.
 *
 * config (normalized by App\Workflows\Engine\Definition):
 *  - retries: int                       attempts per step = retries + 1
 *  - timeout: string|null               run timeout ("30d")
 *  - concurrency: list<{limit:int, key:string|null}>
 *  - idempotency: string|null           expression of the trigger event, e.g. "event.data.order_id"
 *  - cancel_on: list<{event, match?, if?}>
 *
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property string|null $description
 * @property array|null $triggers
 * @property array|null $config
 * @property bool $is_active
 * @property string $source
 * @property string|null $definition_hash
 * @property Carbon|null $synced_at
 * @property Carbon|null $archived_at
 * @property-read Project|null $project
 */
class Workflow extends Model
{
    use HasUlids;

    protected $table = 'workflows';

    protected $fillable = [
        'project_id',
        'name',
        'description',
        'triggers',
        'config',
        'is_active',
        'source',
        'definition_hash',
        'synced_at',
        'archived_at',
    ];

    protected $casts = [
        'triggers' => 'array',
        'config' => 'array',
        'is_active' => 'boolean',
        'synced_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    /** Definitions that can start runs: active and still declared by the project. */
    public function scopeStartable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('archived_at');
    }

    public function canStart(): bool
    {
        return $this->is_active && $this->archived_at === null;
    }

    public function retries(): int
    {
        $retries = $this->config['retries'] ?? config('workflows.defaults.retries', 3);

        return max(0, min((int) $retries, (int) config('workflows.limits.max_retries', 20)));
    }

    public function maxAttempts(): int
    {
        return $this->retries() + 1;
    }

    public function runTimeoutSeconds(): int
    {
        $timeout = $this->config['timeout'] ?? config('workflows.defaults.run_timeout', '30d');
        $max = Duration::seconds(config('workflows.limits.max_run_timeout', '365d'));

        return min(Duration::seconds($timeout), $max);
    }

    /** @return list<array{limit:int, key:string|null}> */
    public function concurrencyLimits(): array
    {
        return array_values(array_filter(
            $this->config['concurrency'] ?? [],
            fn ($c) => is_array($c) && (int) ($c['limit'] ?? 0) > 0,
        ));
    }

    /** The expression whose value keys the keyed concurrency limit (one per workflow). */
    public function concurrencyKeyExpression(): ?string
    {
        foreach ($this->concurrencyLimits() as $limit) {
            if (! empty($limit['key'])) {
                return $limit['key'];
            }
        }

        return null;
    }

    public function idempotencyExpression(): ?string
    {
        $expr = $this->config['idempotency'] ?? null;

        return is_string($expr) && $expr !== '' ? $expr : null;
    }
}
