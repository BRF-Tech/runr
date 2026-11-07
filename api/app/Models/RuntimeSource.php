<?php

namespace App\Models;

use App\Casts\Encrypted;
use App\Models\Concerns\UsesCentralConnection;
use App\Support\Secrets\SecretRedactor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A source of runtimes (docs/runtimes.md#runtime-sources): a signed OCI catalog of manifests
 * (kind oci-catalog) or a Git repository with <path>/<id>/runtime.yaml (kind git), public or
 * private, read periodically by RuntimeSourceSync. Platform sources (scope platform, connected by
 * holders of runtimes.manage) give every organization their runtimes; organization sources (scope
 * organization, tenant_id) only their organization, while the platform allows custom runtimes. The
 * row with is_default is RunR's own catalog: its runtimes are the built-in ones and its reference,
 * key and region come from config/runtime_sources.php.
 *
 * credentials (encrypted at rest, never in an API answer — toApi() reports what is set):
 *   git       {token, username} (HTTPS) or {ssh_key, known_hosts} (SSH deploy key)
 *   registry  {username, password} for registry_host: a private catalog / patch feed and the
 *             runtime images (version discovery, image pull secrets, Kaniko base pulls)
 *
 * @property string $id
 * @property string $name
 * @property string $kind
 * @property string $scope
 * @property string|null $tenant_id
 * @property string $url
 * @property string|null $ref
 * @property string|null $path
 * @property string|null $public_key
 * @property string|null $patch_feed
 * @property string|null $registry_host
 * @property array|null $credentials
 * @property bool $is_default
 * @property bool $is_enabled
 * @property int $interval_minutes
 * @property string $status
 * @property string|null $error
 * @property array|null $problems
 * @property string|null $revision
 * @property string|null $rejected_revision
 * @property bool $signature_verified
 * @property int $runtimes_count
 * @property Carbon|null $checked_at
 * @property Carbon|null $synced_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|RuntimeSource query()
 */
class RuntimeSource extends Model
{
    use HasUlids, UsesCentralConnection;

    public const KIND_OCI = 'oci-catalog';

    public const KIND_GIT = 'git';

    public const KINDS = [self::KIND_OCI, self::KIND_GIT];

    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_ORGANIZATION = 'organization';

    // Sync status
    public const NEVER = 'never';

    public const OK = 'ok';

    /** read, but some manifests were refused (problems) */
    public const PARTIAL = 'partial';

    public const UNREACHABLE = 'unreachable';

    /**
     * The registry or Git host has no such catalog / repository for this read: it answered 404,
     * or refused (401 / 403) a read without credentials — a package that is missing or not public
     * looks the same to an anonymous reader (ghcr.io answers both with 401 / 403).
     */
    public const NOT_FOUND = 'not_found';

    /** The source's own credentials were refused (only a source that has some) */
    public const AUTH_FAILED = 'auth_failed';

    public const INVALID_SIGNATURE = 'invalid_signature';

    public const INVALID_CATALOG = 'invalid_catalog';

    public const DISABLED = 'disabled';

    /** Statuses of a read that left the runtimes as they were (the last good read stays) */
    public const FAILED = [self::UNREACHABLE, self::NOT_FOUND, self::AUTH_FAILED, self::INVALID_SIGNATURE, self::INVALID_CATALOG];

    /** The API document's shape of toApi() (the controllers' #[Response] attributes) */
    public const OPENAPI_TYPE = 'array{id: string, name: string, kind: "oci-catalog"|"git", scope: "platform"|"organization", tenant_id: string|null, is_default: bool, url: string, ref: string|null, path: string|null, public_key: string|null, patch_feed: string|null, registry_host: string|null, credentials: array{git: "token"|"ssh"|null, git_username: string|null, git_token_hint: string|null, ssh_key_set: bool, known_hosts_set: bool, registry_username: string|null, registry_password_set: bool}, is_enabled: bool, interval_minutes: int, status: "never"|"ok"|"partial"|"unreachable"|"not_found"|"auth_failed"|"invalid_signature"|"invalid_catalog"|"disabled", error: string|null, problems: list<array{file: string|null, runtime: string|null, path: string, message: string}>, revision: string|null, signature_verified: bool, runtimes_count: int, checked_at: string|null, synced_at: string|null, next_check_at: string|null, created_by: string|null, created_at: string|null, updated_at: string|null}';

    protected $fillable = [
        'name', 'kind', 'scope', 'tenant_id', 'url', 'ref', 'path', 'public_key', 'patch_feed',
        'registry_host', 'credentials', 'is_default', 'is_enabled', 'interval_minutes', 'status',
        'error', 'problems', 'revision', 'rejected_revision', 'signature_verified', 'runtimes_count',
        'checked_at', 'synced_at', 'created_by',
    ];

    protected $hidden = ['credentials'];

    protected $attributes = [
        'status' => self::NEVER,
        'is_enabled' => true,
        'is_default' => false,
        'interval_minutes' => 60,
        'signature_verified' => false,
        'runtimes_count' => 0,
    ];

    protected $casts = [
        'credentials' => Encrypted::class.':array',
        'problems' => 'array',
        'is_default' => 'boolean',
        'is_enabled' => 'boolean',
        'signature_verified' => 'boolean',
        'interval_minutes' => 'integer',
        'runtimes_count' => 'integer',
        'checked_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    /**
     * RunR's default catalog (created on first use). Its reference, key and region are the
     * config's (config/runtime_sources.php), read at every sync, never stored as editable fields.
     */
    public static function default(): self
    {
        $source = static::query()->where('is_default', true)->first();
        if ($source) {
            return $source;
        }

        return static::query()->create([
            'name' => 'RunR runtimes',
            'kind' => self::KIND_OCI,
            'scope' => self::SCOPE_PLATFORM,
            'url' => (string) config('runtime_sources.default.reference'),
            'is_default' => true,
            'interval_minutes' => max(1, (int) config('runtime_sources.default.interval_minutes', 15)),
        ]);
    }

    /** The default source when it exists already (no row is created). */
    public static function existingDefault(): ?self
    {
        try {
            return static::query()->where('is_default', true)->first();
        } catch (\Throwable) {
            return null;     // not migrated yet
        }
    }

    public function runtimes(): HasMany
    {
        return $this->hasMany(Runtime::class, 'source_id');
    }

    public function isOrganization(): bool
    {
        return $this->scope === self::SCOPE_ORGANIZATION;
    }

    /** The source's OCI reference (catalog) — the config's for the default source. */
    public function reference(): string
    {
        return $this->is_default ? trim((string) config('runtime_sources.default.reference')) : trim($this->url);
    }

    /** The cosign key the catalog / patch feed must verify with (the config's for the default source). */
    public function verificationKey(): ?string
    {
        $key = $this->is_default ? (string) config('runtime_sources.default.public_key') : (string) $this->public_key;

        return trim($key) === '' ? null : $key;
    }

    /** Whether the default source can be read at all (a reference is configured). */
    public function configured(): bool
    {
        return $this->reference() !== '';
    }

    /** The "Runtime" source column its runtimes get: builtin, platform or custom. */
    public function runtimeSource(): string
    {
        return match (true) {
            $this->is_default => Runtime::SOURCE_BUILTIN,
            $this->isOrganization() => Runtime::SOURCE_CUSTOM,
            default => Runtime::SOURCE_PLATFORM,
        };
    }

    public function credential(string $group, string $key): ?string
    {
        $value = $this->credentials[$group][$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Whether the source has registry credentials (a private catalog, private runtime images). */
    public function hasRegistryCredentials(): bool
    {
        return $this->credential('registry', 'username') !== null && $this->credential('registry', 'password') !== null;
    }

    /** Every secret value of the source (masked out of errors, logs and problems). */
    public function secretValues(): array
    {
        $values = [];
        foreach ((array) ($this->credentials ?? []) as $group) {
            foreach ((array) $group as $key => $value) {
                if (is_string($value) && $value !== '' && ! in_array($key, ['username', 'known_hosts'], true)) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    /** When the scheduler reads the source next (null: it is off). */
    public function nextCheckAt(): ?Carbon
    {
        if (! $this->is_enabled || ($this->is_default && ! $this->configured())) {
            return null;
        }

        return ($this->checked_at ?? now()->subYear())->copy()->addMinutes(max(1, $this->interval_minutes));
    }

    public function due(): bool
    {
        $next = $this->nextCheckAt();

        return $next !== null && $next->lessThanOrEqualTo(now());
    }

    /** API shape: never a secret; which credentials are set, the last four characters of a token. */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'scope' => $this->scope,
            'tenant_id' => $this->tenant_id,
            'is_default' => $this->is_default,
            'url' => $this->is_default ? $this->reference() : $this->url,
            'ref' => $this->ref,
            'path' => $this->path,
            'public_key' => $this->is_default ? $this->verificationKey() : $this->public_key,
            'patch_feed' => $this->patch_feed,
            'registry_host' => $this->registry_host,
            'credentials' => [
                'git' => $this->credential('git', 'token') !== null ? 'token' : ($this->credential('git', 'ssh_key') !== null ? 'ssh' : null),
                'git_username' => $this->credential('git', 'username'),
                'git_token_hint' => SecretRedactor::hint($this->credential('git', 'token')),
                'ssh_key_set' => $this->credential('git', 'ssh_key') !== null,
                'known_hosts_set' => $this->credential('git', 'known_hosts') !== null,
                'registry_username' => $this->credential('registry', 'username'),
                'registry_password_set' => $this->credential('registry', 'password') !== null,
            ],
            'is_enabled' => $this->is_enabled,
            'interval_minutes' => $this->interval_minutes,
            'status' => $this->is_default && ! $this->configured() ? self::DISABLED : $this->status,
            'error' => $this->error,
            'problems' => array_values((array) ($this->problems ?? [])),
            'revision' => $this->revision,
            'signature_verified' => $this->signature_verified,
            'runtimes_count' => $this->runtimes_count,
            'checked_at' => $this->checked_at?->toIso8601String(),
            'synced_at' => $this->synced_at?->toIso8601String(),
            'next_check_at' => $this->nextCheckAt()?->toIso8601String(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
