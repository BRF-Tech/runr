<?php

namespace App\Models\Observability;

use App\Casts\Encrypted;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A failed request (5xx / timeout) of a project with replay capture on. Headers and bodies are
 * encrypted at rest; credentials and the configured headers were removed before storage (in the
 * pod and again on ingest, ReplayService). Expires after the project's retention.
 *
 * @property string $id
 * @property string $project_id
 * @property Carbon $captured_at
 * @property Carbon $expires_at
 * @property string $trigger
 * @property string|null $request_id
 * @property string|null $trace_id
 * @property string|null $revision
 * @property string $method
 * @property string $path
 * @property string|null $query
 * @property array|null $headers
 * @property array|null $redacted_headers
 * @property string|null $body
 * @property bool $body_base64
 * @property int $body_size
 * @property bool $body_truncated
 * @property int $status
 * @property bool $timed_out
 * @property float|null $duration_ms
 * @property array|null $response_headers
 * @property string|null $response_body
 * @property bool $response_body_base64
 * @property bool $response_truncated
 * @property int $replay_count
 * @property array|null $last_replay
 * @property Carbon|null $last_replayed_at
 */
class ReplayCapture extends Model
{
    use HasUlids;

    protected $table = 'replay_captures';

    protected $fillable = [
        'project_id', 'captured_at', 'expires_at', 'trigger', 'request_id', 'trace_id', 'revision',
        'method', 'path', 'query', 'headers', 'redacted_headers', 'body', 'body_base64', 'body_size',
        'body_truncated', 'status', 'timed_out', 'duration_ms', 'response_headers', 'response_body',
        'response_body_base64', 'response_truncated', 'replay_count', 'last_replay', 'last_replayed_at',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_replayed_at' => 'datetime',
        'headers' => Encrypted::class.':array',
        'redacted_headers' => 'array',
        'body' => Encrypted::class,
        'response_headers' => Encrypted::class.':array',
        'response_body' => Encrypted::class,
        'last_replay' => Encrypted::class.':array',
        'body_base64' => 'boolean',
        'body_truncated' => 'boolean',
        'timed_out' => 'boolean',
        'response_body_base64' => 'boolean',
        'response_truncated' => 'boolean',
        'status' => 'integer',
        'body_size' => 'integer',
        'replay_count' => 'integer',
        'duration_ms' => 'float',
    ];

    /** List entry: no headers or bodies. */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'captured_at' => $this->captured_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'trigger' => $this->trigger,
            'method' => $this->method,
            'path' => $this->path,
            'query' => $this->query,
            'status' => $this->status,
            'timed_out' => $this->timed_out,
            'duration_ms' => $this->duration_ms,
            'trace_id' => $this->trace_id,
            'request_id' => $this->request_id,
            'revision' => $this->revision,
            'body_size' => $this->body_size,
            'replay_count' => $this->replay_count,
            'last_replayed_at' => $this->last_replayed_at?->toIso8601String(),
            'last_replay_status' => $this->last_replay['status'] ?? null,
        ];
    }

    /** Detail: the stored (already redacted) request and response. */
    public function toDetail(): array
    {
        return $this->toSummary() + [
            'headers' => $this->headers ?? [],
            'redacted_headers' => $this->redacted_headers ?? [],
            'body' => $this->body,
            'body_base64' => $this->body_base64,
            'body_truncated' => $this->body_truncated,
            'response_headers' => $this->response_headers ?? [],
            'response_body' => $this->response_body,
            'response_body_base64' => $this->response_body_base64,
            'response_truncated' => $this->response_truncated,
            'last_replay' => $this->last_replay,
        ];
    }
}
