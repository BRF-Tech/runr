<?php

namespace App\Models\Git;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\Version;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One git-triggered deploy: a push to the deploy branch (production) or a pull/merge request
 * commit (preview). The Git tab's deploy history.
 *
 * status: queued → importing → building → deploying → active | failed; also
 *         unchanged (the tree equals the current version, nothing to deploy),
 *         imported (auto-deploy off: the version waits for a manual deploy),
 *         skipped (a rule kept it from deploying: preview limit, fork, quota).
 *
 * @property string $id
 * @property string $project_id
 * @property string|null $version_id
 * @property string|null $deployment_id
 * @property string|null $preview_environment_id
 * @property string $kind
 * @property string $trigger
 * @property string $provider
 * @property string $sha
 * @property string|null $branch
 * @property string|null $author_name
 * @property string|null $message
 * @property string|null $url
 * @property int|null $pr_number
 * @property string $status
 * @property string|null $status_message
 * @property array|null $details
 * @property string|null $provider_deployment_id
 * @property string|null $reported_state
 * @property-read Project $project
 */
class GitDeploy extends Model
{
    use HasUlids;

    public const KIND_PRODUCTION = 'production';

    public const KIND_PREVIEW = 'preview';

    public const QUEUED = 'queued';

    public const IMPORTING = 'importing';

    public const BUILDING = 'building';

    public const DEPLOYING = 'deploying';

    public const ACTIVE = 'active';

    public const FAILED = 'failed';

    public const UNCHANGED = 'unchanged';

    public const IMPORTED = 'imported';

    public const SKIPPED = 'skipped';

    /** Statuses that end a deploy */
    public const FINAL = [self::ACTIVE, self::FAILED, self::UNCHANGED, self::IMPORTED, self::SKIPPED];

    protected $table = 'git_deploys';

    protected $fillable = [
        'project_id', 'version_id', 'deployment_id', 'preview_environment_id', 'kind', 'trigger',
        'provider', 'sha', 'branch', 'author_name', 'message', 'url', 'pr_number', 'status',
        'status_message', 'details', 'provider_deployment_id', 'reported_state',
    ];

    protected $casts = [
        'pr_number' => 'integer',
        'details' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class)->withoutGlobalScopes();
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function preview(): BelongsTo
    {
        return $this->belongsTo(PreviewEnvironment::class, 'preview_environment_id');
    }

    public function mark(string $status, ?string $message = null): self
    {
        $this->update([
            'status' => $status,
            'status_message' => $message !== null ? mb_substr($message, 0, 2000) : null,
        ]);

        return $this;
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'trigger' => $this->trigger,
            'provider' => $this->provider,
            'sha' => $this->sha,
            'short_sha' => substr($this->sha, 0, 7),
            'branch' => $this->branch,
            'author_name' => $this->author_name,
            'message' => $this->message,
            'url' => $this->url,
            'pr_number' => $this->pr_number,
            'status' => $this->status,
            'status_message' => $this->status_message,
            'warnings' => $this->details['warnings'] ?? [],
            'skipped' => $this->details['skipped'] ?? [],
            'applied' => $this->details['applied'] ?? (object) [],
            'version' => $this->version_id && $this->relationLoaded('version') && $this->version
                ? ['id' => $this->version->id, 'number' => $this->version->number, 'label' => $this->version->label]
                : null,
            'deployment_id' => $this->deployment_id,
            'preview_id' => $this->preview_environment_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
