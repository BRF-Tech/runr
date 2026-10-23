<?php

namespace App\Workflows\Models;

use App\Models\Project;
use App\Workflows\Support\WorkflowEnvironments;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The workflow side of a project in one environment (docs/workflows.md#environments): the HMAC
 * signing key the engine signs every request to the environment's function with (injected into its
 * Knative Service as RUNR_WORKFLOW_SIGNING_KEY), an optional endpoint override and the result of
 * the last definition sync. Production's app has environment_id null; every other environment has
 * an app — and a key — of its own, so an environment's code never holds production's key.
 *
 * @property string $id
 * @property string $project_id
 * @property string|null $environment_id null: production
 * @property string $signing_key
 * @property string|null $signing_key_previous
 * @property Carbon|null $previous_key_expires_at
 * @property string|null $endpoint_url
 * @property string $sync_status
 * @property string|null $sync_error
 * @property array|null $sdk
 * @property Carbon|null $last_synced_at
 * @property-read Project $project
 */
class WorkflowApp extends Model
{
    use HasUlids;

    public const KEY_PREFIX = 'rwk_';

    protected $table = 'workflow_apps';

    protected $fillable = [
        'project_id',
        'environment_id',
        'signing_key',
        'signing_key_previous',
        'previous_key_expires_at',
        'endpoint_url',
        'sync_status',
        'sync_error',
        'sdk',
        'last_synced_at',
    ];

    protected $hidden = ['signing_key', 'signing_key_previous'];

    protected $casts = [
        'signing_key' => 'encrypted',
        'signing_key_previous' => 'encrypted',
        'previous_key_expires_at' => 'datetime',
        'sdk' => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The app of a project in the environment $project stands for (an EnvironmentProject view, or
     * the project itself: production), created (with a fresh signing key) on first use.
     */
    public static function forProject(Project $project): self
    {
        return self::forEnvironment((string) $project->getKey(), WorkflowEnvironments::idOf($project));
    }

    /** The app of a project in one environment (null: production), created on first use. */
    public static function forEnvironment(string $projectId, ?string $environmentId): self
    {
        $key = ['project_id' => $projectId];
        if (WorkflowEnvironments::ready()) {
            $key['environment_id'] = $environmentId;
        }

        return static::firstOrCreate($key, ['signing_key' => self::generateKey(), 'sync_status' => 'never']);
    }

    /** The existing app of a project in one environment (null: production). */
    public static function findFor(string $projectId, ?string $environmentId): ?self
    {
        return WorkflowEnvironments::constrain(static::query()->where('project_id', $projectId), $environmentId)->first();
    }

    public static function generateKey(): string
    {
        return self::KEY_PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * New signing key; the previous one keeps signing (second v1 signature) for the rotation
     * grace period so a function still deployed with the old key keeps accepting requests.
     */
    public function rotateKey(): void
    {
        $this->forceFill([
            'signing_key_previous' => $this->signing_key,
            'previous_key_expires_at' => now()->addSeconds((int) config('workflows.key_rotation_grace', 86400)),
            'signing_key' => self::generateKey(),
        ])->save();
    }

    /** @return list<string> keys requests are signed with: current first, previous while in grace */
    public function signingKeys(): array
    {
        $keys = [$this->signing_key];

        if ($this->signing_key_previous && $this->previous_key_expires_at?->isFuture()) {
            $keys[] = $this->signing_key_previous;
        }

        return $keys;
    }

    /** Non-secret identifier of the current key (UI, logs). */
    public function keyFingerprint(): string
    {
        return substr(hash('sha256', (string) $this->signing_key), 0, 12);
    }

    /**
     * Secret path token of the queue bridge sink URL (derived from the signing key; an
     * environment's names the environment too).
     */
    public function queueHookToken(): string
    {
        $scope = 'queue-bridge:'.$this->project_id.($this->environment_id ? ':'.$this->environment_id : '');

        return substr(hash_hmac('sha256', $scope, (string) $this->signing_key), 0, 40);
    }
}
