<?php

namespace App\Models\Observability;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One load test of a project (docs/load-test.md): a k6 Job in the project's region that sends
 * $users concurrent users' requests to $method https://$host$path for $duration_seconds.
 *
 * results (when completed): requests, rps, error_rate (0..1), latency_ms {avg, min, p50, p95, p99,
 * max}, data_received / data_sent (bytes), pods_peak (+ its source), cold_starts and the window.
 *
 * @property string $id
 * @property string $project_id
 * @property string|null $deployment_id
 * @property string|null $region_id
 * @property string $status
 * @property string $method
 * @property string $host
 * @property string $path
 * @property string|null $body
 * @property string|null $content_type
 * @property int $users
 * @property int $duration_seconds
 * @property string|null $job_name
 * @property string|null $namespace
 * @property array<string, mixed>|null $results
 * @property string|null $error
 * @property string|null $started_by
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project|null $project
 * @property-read User|null $starter
 */
class LoadTest extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** A test that holds the project's slot: one at a time per project. */
    public const ACTIVE = [self::PENDING, self::RUNNING];

    public const METHODS = ['GET', 'POST'];

    protected $table = 'load_tests';

    protected $attributes = [
        'status' => self::PENDING,
        'method' => 'GET',
        'path' => '/',
    ];

    protected $fillable = [
        'project_id', 'deployment_id', 'region_id', 'status', 'method', 'host', 'path', 'body', 'content_type',
        'users', 'duration_seconds', 'job_name', 'namespace', 'results', 'error', 'started_by', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'results' => 'array',
        'users' => 'integer',
        'duration_seconds' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /**
     * The latest moment the test can still be running: its duration plus the time the Job has to
     * start, from when it was started (created, while pending). A test past it holds the project's
     * slot no longer (CheckLoadTestJob ends it; a lost check chain must not lock the project).
     */
    public function deadline(): Carbon
    {
        $from = $this->started_at ?? $this->created_at ?? now();

        return $from->copy()->addSeconds($this->duration_seconds + max(30, (int) config('observability.load_test.start_grace_seconds', 180)));
    }

    public function targetUrl(): string
    {
        $scheme = (string) config('observability.load_test.target_scheme', 'https') === 'http' ? 'http' : 'https';

        return "{$scheme}://{$this->host}{$this->path}";
    }

    /**
     * The API form. $withBody: the request body too (the API; never in broadcasts, which every
     * member of the organization receives).
     */
    public function toApi(bool $withBody = true): array
    {
        $out = [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'deployment_id' => $this->deployment_id,
            'status' => $this->status,
            'method' => $this->method,
            'host' => $this->host,
            'path' => $this->path,
            'url' => $this->targetUrl(),
            'has_body' => $this->body !== null && $this->body !== '',
            'content_type' => $this->content_type,
            'users' => $this->users,
            'duration_seconds' => $this->duration_seconds,
            'results' => $this->results,
            'error' => $this->error,
            'started_by' => $this->started_by !== null ? [
                'id' => $this->started_by,
                'name' => $this->relationLoaded('starter') ? $this->starter?->name : null,
            ] : null,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
        if ($withBody) {
            $out['body'] = $this->body;
        }

        return $out;
    }
}
