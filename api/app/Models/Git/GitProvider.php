<?php

namespace App\Models\Git;

use App\Casts\Encrypted;
use App\Models\Git\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A git provider configured by a platform admin (Settings → Git providers):
 *  - github  — a GitHub App (github.com or GitHub Enterprise Server): app id, slug, private key,
 *              webhook secret, client id/secret (verifies who installed the app);
 *  - gitlab  — a GitLab instance (gitlab.com or self-managed); an OAuth application is optional,
 *              projects can always connect with an access token;
 *  - forgejo — a Forgejo or Gitea instance; projects connect with an access token.
 *
 * Secrets are encrypted at rest (App\Casts\Encrypted, registered in App\Support\Secrets\SecretColumns)
 * and hidden from serialization.
 *
 * @property string $id
 * @property string $type
 * @property string $name
 * @property string|null $base_url
 * @property string|null $api_url
 * @property string|null $app_id
 * @property string|null $app_slug
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property string|null $private_key
 * @property string|null $webhook_secret
 * @property bool $is_enabled
 * @property array|null $settings
 * @property string|null $created_by
 */
class GitProvider extends Model
{
    use HasUlids, UsesCentralConnection;

    public const GITHUB = 'github';

    public const GITLAB = 'gitlab';

    public const FORGEJO = 'forgejo';

    public const TYPES = [self::GITHUB, self::GITLAB, self::FORGEJO];

    /** Secret attributes: write-only through the API */
    public const SECRETS = ['client_secret', 'private_key', 'webhook_secret'];

    protected $table = 'git_providers';

    protected $fillable = [
        'type', 'name', 'base_url', 'api_url', 'app_id', 'app_slug', 'client_id',
        'client_secret', 'private_key', 'webhook_secret', 'is_enabled', 'settings', 'created_by',
    ];

    protected $hidden = self::SECRETS;

    protected $casts = [
        'client_secret' => Encrypted::class,
        'private_key' => Encrypted::class,
        'webhook_secret' => Encrypted::class,
        'settings' => 'array',
        'is_enabled' => 'boolean',
    ];

    public function links(): HasMany
    {
        return $this->hasMany(GitRepoLink::class, 'git_provider_id');
    }

    public function installations(): HasMany
    {
        return $this->hasMany(GitInstallation::class, 'git_provider_id');
    }

    /** Web URL without a trailing slash (defaults per type). */
    public function webUrl(): string
    {
        $default = match ($this->type) {
            self::GITHUB => 'https://github.com',
            self::GITLAB => 'https://gitlab.com',
            default => '',
        };

        return rtrim($this->base_url ?: $default, '/');
    }

    /** REST API base URL without a trailing slash. */
    public function apiUrl(): string
    {
        if ($this->api_url) {
            return rtrim($this->api_url, '/');
        }

        $web = $this->webUrl();

        return match ($this->type) {
            self::GITHUB => $web === 'https://github.com' ? 'https://api.github.com' : "{$web}/api/v3",
            self::GITLAB => "{$web}/api/v4",
            self::FORGEJO => "{$web}/api/v1",
            default => $web,
        };
    }

    /** How projects connect a repository of this provider. */
    public function connectModes(): array
    {
        return match ($this->type) {
            self::GITHUB => ['app'],
            self::GITLAB => $this->client_id && $this->client_secret ? ['oauth', 'pat'] : ['pat'],
            default => ['pat'],
        };
    }

    /** Whether every setting the type needs is present. */
    public function isConfigured(): bool
    {
        return match ($this->type) {
            self::GITHUB => $this->app_id && $this->app_slug && $this->private_key && $this->webhook_secret
                && $this->client_id && $this->client_secret,
            self::GITLAB => true,
            self::FORGEJO => (bool) $this->base_url,
            default => false,
        };
    }
}
