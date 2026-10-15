<?php

namespace App\Models\Observability;

use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Per-project tracing and request replay switches (both off by default). Applied to the
 * function's pod spec at deploy time (ObservabilityPodSpec): a change takes effect with the next
 * deployment.
 *
 * @property string $project_id
 * @property bool $tracing_enabled
 * @property float $tracing_sample_ratio
 * @property bool $replay_enabled
 * @property int $replay_max_body_bytes
 * @property int $replay_retention_days
 * @property array|null $replay_redact_headers
 * @property array|null $replay_redact_query
 * @property string|null $replay_salt
 */
class ProjectObservabilitySetting extends Model
{
    protected $table = 'project_observability_settings';

    protected $primaryKey = 'project_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'project_id', 'tracing_enabled', 'tracing_sample_ratio',
        'replay_enabled', 'replay_max_body_bytes', 'replay_retention_days',
        'replay_redact_headers', 'replay_redact_query', 'replay_salt',
    ];

    /** The salt of the replay ingest token never leaves the API. */
    protected $hidden = ['replay_salt'];

    protected $casts = [
        'tracing_enabled' => 'boolean',
        'tracing_sample_ratio' => 'float',
        'replay_enabled' => 'boolean',
        'replay_max_body_bytes' => 'integer',
        'replay_retention_days' => 'integer',
        'replay_redact_headers' => 'array',
        'replay_redact_query' => 'array',
    ];

    /** The project's settings; unsaved defaults (everything off) when it has none. */
    public static function forProject(Project|string $project): self
    {
        $id = $project instanceof Project ? $project->getKey() : $project;

        try {
            $found = self::query()->find($id);
        } catch (QueryException) {
            // a tenant schema not migrated to TenantMigrations/0050 yet: everything off
            $found = null;
        }

        return $found ?? new self([
            'project_id' => $id,
            'tracing_enabled' => false,
            'tracing_sample_ratio' => (float) config('observability.tracing.default_sample_ratio', 1.0),
            'replay_enabled' => false,
            'replay_max_body_bytes' => (int) config('observability.replay.max_body_bytes', 65536),
            'replay_retention_days' => (int) config('observability.replay.retention_days', 7),
            'replay_redact_headers' => [],
            'replay_redact_query' => [],
        ]);
    }

    /** A new salt: every ingest token derived from the old one stops working. */
    public function rotateReplaySalt(): void
    {
        $this->replay_salt = Str::random(40);
    }

    /** Header names never stored for this project: platform list + the project's own. */
    public function redactedHeaders(): array
    {
        return self::normalizeNames(array_merge(
            (array) config('observability.replay.redact_headers', []),
            (array) ($this->replay_redact_headers ?? []),
        ));
    }

    /** Query parameters whose values are replaced: platform list + the project's own. */
    public function redactedQuery(): array
    {
        return self::normalizeNames(array_merge(
            (array) config('observability.replay.redact_query', []),
            (array) ($this->replay_redact_query ?? []),
        ));
    }

    /** @param list<mixed> $names */
    public static function normalizeNames(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $name = strtolower(trim((string) $name));
            if ($name !== '' && ! in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    public function toApi(): array
    {
        return [
            'project_id' => $this->project_id,
            'tracing_enabled' => (bool) $this->tracing_enabled,
            'tracing_sample_ratio' => (float) $this->tracing_sample_ratio,
            'replay_enabled' => (bool) $this->replay_enabled,
            'replay_max_body_bytes' => (int) $this->replay_max_body_bytes,
            'replay_retention_days' => (int) $this->replay_retention_days,
            'replay_redact_headers' => array_values((array) ($this->replay_redact_headers ?? [])),
            'replay_redact_query' => array_values((array) ($this->replay_redact_query ?? [])),
            'platform_redact_headers' => array_values((array) config('observability.replay.redact_headers', [])),
            'platform_redact_query' => array_values((array) config('observability.replay.redact_query', [])),
        ];
    }
}
