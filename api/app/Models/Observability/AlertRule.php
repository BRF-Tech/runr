<?php

namespace App\Models\Observability;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An alert rule: what fires, how often at most (cooldown) and where it goes (channel_ids).
 *
 * Types and params:
 *  - resource_threshold: region CPU / memory reservation (the built-in 60 % / 80 % check of
 *    ResourceThresholdService); params {resource: cpu|memory|any, min_level: warning|critical},
 *    region_id optional
 *  - uptime_down:        an uptime incident opens / resolves; project_id optional (all projects)
 *  - error_rate:         share of 5xx / failed invocations over a window, params {threshold (%),
 *                        window_minutes, min_requests}
 *  - p95_latency:        95th percentile duration, params {threshold_ms, window_minutes, min_requests}
 *  - cold_start_rate:    share of cold starts, params {threshold (%), window_minutes, min_requests}
 * The three metric types read the span metrics of the region (traced projects).
 *
 * @property string $id
 * @property string $name
 * @property string $type
 * @property string|null $project_id
 * @property string|null $region_id
 * @property array|null $params
 * @property string $severity
 * @property int $cooldown_minutes
 * @property array|null $channel_ids
 * @property bool $is_enabled
 * @property string $state
 * @property float|null $last_value
 * @property Carbon|null $last_evaluated_at
 * @property Carbon|null $last_fired_at
 */
class AlertRule extends Model
{
    use HasUlids;

    public const TYPES = ['resource_threshold', 'uptime_down', 'error_rate', 'p95_latency', 'cold_start_rate'];

    public const METRIC_TYPES = ['error_rate', 'p95_latency', 'cold_start_rate'];

    public const SEVERITIES = ['info', 'warning', 'critical'];

    public const STATE_OK = 'ok';

    public const STATE_FIRING = 'firing';

    protected $table = 'alert_rules';

    protected $fillable = [
        'name', 'type', 'project_id', 'region_id', 'params', 'severity', 'cooldown_minutes',
        'channel_ids', 'is_enabled', 'state', 'last_value', 'last_evaluated_at', 'last_fired_at',
    ];

    protected $casts = [
        'params' => 'array',
        'channel_ids' => 'array',
        'is_enabled' => 'boolean',
        'cooldown_minutes' => 'integer',
        'last_value' => 'float',
        'last_evaluated_at' => 'datetime',
        'last_fired_at' => 'datetime',
    ];

    public function param(string $key, mixed $default = null): mixed
    {
        return ($this->params ?? [])[$key] ?? $default;
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'project_id' => $this->project_id,
            'region_id' => $this->region_id,
            'params' => $this->params ?? (object) [],
            'severity' => $this->severity,
            'cooldown_minutes' => $this->cooldown_minutes,
            'channel_ids' => array_values($this->channel_ids ?? []),
            'is_enabled' => $this->is_enabled,
            'state' => $this->state,
            'last_value' => $this->last_value,
            'last_evaluated_at' => $this->last_evaluated_at?->toIso8601String(),
            'last_fired_at' => $this->last_fired_at?->toIso8601String(),
        ];
    }
}
