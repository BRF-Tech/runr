<?php

namespace App\Models\Git;

use App\Models\Project;
use App\Models\Version;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The preview environment of a pull/merge request: its own Knative Service
 * (<project slug>-pr-<number>), scaled to zero, built from the request's head commit.
 *
 * status: pending → building → deploying → ready | failed; skipped (limit, fork rule, quota,
 *         trigger type); deleting → deleted (closed, merged, expired or removed by hand).
 *
 * @property string $id
 * @property string $project_id
 * @property int $number
 * @property string $provider
 * @property string|null $git_repo_link_id
 * @property string|null $title
 * @property string|null $branch
 * @property string|null $base_branch
 * @property string|null $head_sha
 * @property string|null $author
 * @property string|null $pr_url
 * @property bool $is_fork
 * @property string $service_name
 * @property string|null $url
 * @property string $status
 * @property string|null $status_message
 * @property string|null $version_id
 * @property string|null $comment_id
 * @property string|null $provider_deployment_id
 * @property Carbon|null $last_deployed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $closed_at
 * @property-read Project $project
 */
class PreviewEnvironment extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const BUILDING = 'building';

    public const DEPLOYING = 'deploying';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const DELETING = 'deleting';

    public const DELETED = 'deleted';

    /** Statuses of a preview that holds (or is about to hold) cluster resources */
    public const LIVE = [self::PENDING, self::BUILDING, self::DEPLOYING, self::READY, self::FAILED];

    protected $table = 'preview_environments';

    protected $fillable = [
        'project_id', 'number', 'provider', 'git_repo_link_id', 'title', 'branch', 'base_branch',
        'head_sha', 'author', 'pr_url', 'is_fork', 'service_name', 'url', 'status', 'status_message',
        'version_id', 'comment_id', 'provider_deployment_id', 'last_deployed_at', 'expires_at', 'closed_at',
    ];

    protected $casts = [
        'number' => 'integer',
        'is_fork' => 'boolean',
        'last_deployed_at' => 'datetime',
        'expires_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class)->withoutGlobalScopes();
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
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
            'number' => $this->number,
            'provider' => $this->provider,
            'title' => $this->title,
            'branch' => $this->branch,
            'base_branch' => $this->base_branch,
            'head_sha' => $this->head_sha,
            'short_sha' => $this->head_sha ? substr($this->head_sha, 0, 7) : null,
            'author' => $this->author,
            'pr_url' => $this->pr_url,
            'is_fork' => $this->is_fork,
            'service_name' => $this->service_name,
            'url' => $this->url,
            'status' => $this->status,
            'status_message' => $this->status_message,
            'last_deployed_at' => $this->last_deployed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
