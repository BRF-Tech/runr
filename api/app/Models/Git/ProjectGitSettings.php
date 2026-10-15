<?php

namespace App\Models\Git;

use App\Casts\Encrypted;
use App\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Git settings of a project (Git tab): the branch that deploys to production, the directory of
 * the project in a monorepo, auto-deploy, force pushes to the RunR remote and the preview
 * environments of pull/merge requests.
 *
 * @property string $id
 * @property string $project_id
 * @property string $deploy_branch
 * @property string|null $root_dir
 * @property bool $auto_deploy
 * @property bool $allow_force_push
 * @property bool $previews_enabled
 * @property int $preview_limit
 * @property int $preview_ttl_hours
 * @property array|null $preview_env
 * @property bool $fork_previews
 * @property bool $fork_secrets
 * @property array|null $runr_yaml
 * @property-read Project $project
 */
class ProjectGitSettings extends Model
{
    use HasUlids;

    protected $table = 'project_git_settings';

    protected $fillable = [
        'project_id', 'deploy_branch', 'root_dir', 'auto_deploy', 'allow_force_push', 'previews_enabled',
        'preview_limit', 'preview_ttl_hours', 'preview_env', 'fork_previews', 'fork_secrets', 'runr_yaml',
    ];

    protected $casts = [
        'auto_deploy' => 'boolean',
        'allow_force_push' => 'boolean',
        'previews_enabled' => 'boolean',
        'fork_previews' => 'boolean',
        'fork_secrets' => 'boolean',
        'preview_limit' => 'integer',
        'preview_ttl_hours' => 'integer',
        'preview_env' => Encrypted::class.':array',
        'runr_yaml' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The settings of a project, created with the defaults on first use. */
    public static function for(Project $project): self
    {
        return static::firstOrCreate(['project_id' => $project->id], [
            'deploy_branch' => 'main',
            'auto_deploy' => true,
            'allow_force_push' => false,
            'previews_enabled' => false,
            'preview_limit' => (int) config('git-deploy.previews.limit', 3),
            'preview_ttl_hours' => (int) config('git-deploy.previews.ttl_hours', 72),
            'fork_previews' => false,
            'fork_secrets' => false,
        ]);
    }

    /** Root directory without leading/trailing slashes ('' = repository root). */
    public function rootDir(): string
    {
        return trim((string) $this->root_dir, '/');
    }

    public function deployRef(): string
    {
        return 'refs/heads/'.$this->deploy_branch;
    }
}
