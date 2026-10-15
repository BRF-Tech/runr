<?php

namespace App\Models\Observability;

use App\Casts\Encrypted;
use App\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Synthetic HTTP check of a project: its endpoint + path (or an absolute URL), run from the RunR
 * control plane or from region agents at an interval. status / location_state are maintained by
 * the incident state machine (UptimeService::record).
 *
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property string|null $url
 * @property string $path
 * @property string $method
 * @property array|null $headers
 * @property int $interval_seconds
 * @property int $timeout_seconds
 * @property string $expected_status
 * @property string|null $body_regex
 * @property string $locations
 * @property array|null $region_ids
 * @property int $failure_threshold
 * @property int $recovery_threshold
 * @property bool $is_enabled
 * @property bool $show_on_status_page
 * @property string|null $status_page_name
 * @property int $status_page_order
 * @property string $status
 * @property array|null $location_state
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $next_check_at
 * @property int|null $last_response_ms
 * @property int|null $last_status_code
 * @property string|null $last_error
 * @property-read Project|null $project
 */
class UptimeCheck extends Model
{
    use HasUlids;

    public const LOCATION_CONTROL_PLANE = 'control_plane';

    public const LOCATION_REGIONS = 'regions';

    public const STATUS_UP = 'up';

    public const STATUS_DOWN = 'down';

    public const STATUS_UNKNOWN = 'unknown';

    public const CONTROL_PLANE = 'control-plane';

    protected $table = 'uptime_checks';

    /** The column default, also on a model that was just created (not read back from the database). */
    protected $attributes = [
        'status' => self::STATUS_UNKNOWN,
    ];

    protected $fillable = [
        'project_id', 'name', 'url', 'path', 'method', 'headers', 'interval_seconds', 'timeout_seconds',
        'expected_status', 'body_regex', 'locations', 'region_ids', 'failure_threshold', 'recovery_threshold',
        'is_enabled', 'show_on_status_page', 'status_page_name', 'status_page_order',
        'status', 'location_state', 'last_checked_at', 'next_check_at', 'last_response_ms', 'last_status_code', 'last_error',
    ];

    /** Check headers may carry credentials (e.g. a health token): never returned, only their names. */
    protected $hidden = ['headers'];

    protected $casts = [
        'headers' => Encrypted::class.':array',
        'region_ids' => 'array',
        'location_state' => 'array',
        'is_enabled' => 'boolean',
        'show_on_status_page' => 'boolean',
        'interval_seconds' => 'integer',
        'timeout_seconds' => 'integer',
        'failure_threshold' => 'integer',
        'recovery_threshold' => 'integer',
        'status_page_order' => 'integer',
        'last_response_ms' => 'integer',
        'last_status_code' => 'integer',
        'last_checked_at' => 'datetime',
        'next_check_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(UptimeResult::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(UptimeIncident::class)->orderByDesc('started_at');
    }

    /** Name on the public status page. */
    public function publicName(): string
    {
        return $this->status_page_name ?: $this->name;
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'url' => $this->url,
            'path' => $this->path,
            'method' => $this->method,
            'header_names' => array_keys($this->headers ?? []),
            'interval_seconds' => $this->interval_seconds,
            'timeout_seconds' => $this->timeout_seconds,
            'expected_status' => $this->expected_status,
            'body_regex' => $this->body_regex,
            'locations' => $this->locations,
            'region_ids' => $this->region_ids,
            'failure_threshold' => $this->failure_threshold,
            'recovery_threshold' => $this->recovery_threshold,
            'is_enabled' => $this->is_enabled,
            'show_on_status_page' => $this->show_on_status_page,
            'status_page_name' => $this->status_page_name,
            'status_page_order' => $this->status_page_order,
            'status' => $this->status,
            'location_state' => $this->location_state ?? (object) [],
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'next_check_at' => $this->next_check_at?->toIso8601String(),
            'last_response_ms' => $this->last_response_ms,
            'last_status_code' => $this->last_status_code,
            'last_error' => $this->last_error,
        ];
    }
}
