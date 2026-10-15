<?php

namespace App\Models\Git;

use App\Casts\Encrypted;
use App\Models\Git\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A project connected to a provider repository. Central: webhooks resolve the organization and
 * the project from it before a tenant context exists. The id is the webhook path segment of
 * GitLab and Forgejo repositories (/api/git/webhooks/{type}/{id}).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $project_id
 * @property string $git_provider_id
 * @property string $provider_type
 * @property string $repo_id
 * @property string $repo_full_name
 * @property string|null $repo_url
 * @property string|null $default_branch
 * @property string|null $installation_id
 * @property string $auth_mode
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property string|null $webhook_secret
 * @property string|null $external_hook_id
 * @property string|null $created_by
 * @property Carbon|null $last_event_at
 * @property-read GitProvider|null $provider
 */
class GitRepoLink extends Model
{
    use HasUlids, UsesCentralConnection;

    protected $table = 'git_repo_links';

    protected $fillable = [
        'tenant_id', 'project_id', 'git_provider_id', 'provider_type', 'repo_id', 'repo_full_name',
        'repo_url', 'default_branch', 'installation_id', 'auth_mode', 'access_token', 'refresh_token',
        'token_expires_at', 'webhook_secret', 'external_hook_id', 'created_by', 'last_event_at',
    ];

    protected $hidden = ['access_token', 'refresh_token', 'webhook_secret'];

    protected $casts = [
        'access_token' => Encrypted::class,
        'refresh_token' => Encrypted::class,
        'webhook_secret' => Encrypted::class,
        'token_expires_at' => 'datetime',
        'last_event_at' => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(GitProvider::class, 'git_provider_id');
    }

    /** The link of a project in an organization (null: single-tenant). */
    public static function forProject(string $projectId, ?string $tenantId): ?self
    {
        return static::query()
            ->where('project_id', $projectId)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId), fn ($q) => $q->whereNull('tenant_id'))
            ->first();
    }

    /** API shape (no secrets). */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'provider_id' => $this->git_provider_id,
            'provider_type' => $this->provider_type,
            'provider_name' => $this->provider?->name,
            'repo_id' => $this->repo_id,
            'repo_full_name' => $this->repo_full_name,
            'repo_url' => $this->repo_url,
            'default_branch' => $this->default_branch,
            'auth_mode' => $this->auth_mode,
            'installation_id' => $this->installation_id,
            'webhook_registered' => $this->provider_type === GitProvider::GITHUB || $this->external_hook_id !== null,
            'last_event_at' => $this->last_event_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
