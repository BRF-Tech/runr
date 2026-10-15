<?php

namespace App\Models\Git;

use App\Models\Git\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A GitHub App installation linked to an organization (tenant_id null: the single organization
 * of a single-tenant install). Created by the installation callback only after GitHub confirmed
 * that the signed-in GitHub user can access the installation.
 *
 * @property string $id
 * @property string $git_provider_id
 * @property string $installation_id
 * @property string|null $account_login
 * @property string|null $account_type
 * @property string|null $tenant_id
 * @property string|null $created_by
 */
class GitInstallation extends Model
{
    use HasUlids, UsesCentralConnection;

    protected $table = 'git_installations';

    protected $fillable = [
        'git_provider_id', 'installation_id', 'account_login', 'account_type', 'tenant_id', 'created_by',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(GitProvider::class, 'git_provider_id');
    }

    /** Installations of a provider the organization may use. */
    public static function forTenant(string $providerId, ?string $tenantId)
    {
        return static::query()
            ->where('git_provider_id', $providerId)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId), fn ($q) => $q->whereNull('tenant_id'));
    }
}
