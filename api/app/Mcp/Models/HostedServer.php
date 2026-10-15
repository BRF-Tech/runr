<?php

namespace App\Mcp\Models;

use App\Casts\Encrypted;
use App\Mcp\Hosting\HostedServerIndex;
use App\Mcp\Models\Concerns\UsesCentralConnection;
use App\Models\Project;
use App\Support\TenantAccess;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A project served as an MCP server behind the RunR gateway (docs/mcp.md, "Hosting MCP servers"):
 * <console>/mcp/servers/{handle} forwards to the project's function URL + path with the caller's
 * identity, signed with the gateway key the workload holds as RUNR_MCP_GATEWAY_KEY.
 *
 *   access_mode  public (no authentication) | token (a RunR API token with mcp.servers.call on
 *                the project) | oauth (the same, and the gateway advertises the RunR OAuth server)
 *
 * Central schema with the project's tenant_id: the gateway finds the server before it knows the
 * tenant. The keys are encrypted at rest (App\Casts\Encrypted, SecretColumns).
 *
 * @property string $id
 * @property string $handle
 * @property string|null $tenant_id
 * @property string $project_id
 * @property string $access_mode
 * @property string $path
 * @property bool $audit_calls
 * @property string $signing_key
 * @property string|null $signing_key_previous
 * @property Carbon|null $previous_key_expires_at
 * @property Carbon|null $key_rotated_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class HostedServer extends Model
{
    use HasUlids, UsesCentralConnection;

    public const PUBLIC = 'public';

    public const TOKEN = 'token';

    public const OAUTH = 'oauth';

    public const ACCESS_MODES = [self::PUBLIC, self::TOKEN, self::OAUTH];

    public const KEY_PREFIX = 'rmg_';

    /** How long the previous key keeps signing after a rotation (days). */
    public const ROTATION_GRACE_DAYS = 7;

    protected $table = 'mcp_servers';

    protected $fillable = [
        'handle', 'tenant_id', 'project_id', 'access_mode', 'path', 'audit_calls', 'signing_key',
        'signing_key_previous', 'previous_key_expires_at', 'key_rotated_at', 'created_by',
    ];

    protected $hidden = ['signing_key', 'signing_key_previous'];

    protected $casts = [
        'audit_calls' => 'boolean',
        'signing_key' => Encrypted::class,
        'signing_key_previous' => Encrypted::class,
        'previous_key_expires_at' => 'datetime',
        'key_rotated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // ProjectResource reads servers through a per-request index
        $forget = fn () => app()->resolved(HostedServerIndex::class) ? app(HostedServerIndex::class)->forget() : null;
        static::saved($forget);
        static::deleted($forget);
    }

    public static function forProject(Project|string $project): ?self
    {
        $id = $project instanceof Project ? $project->id : $project;

        return static::query()->where('project_id', $id)->first();
    }

    public static function forHandle(string $handle): ?self
    {
        return static::query()->where('handle', $handle)->first();
    }

    /** A new server for $project with a fresh key and a free handle (the project slug). */
    public static function newFor(Project $project, array $attributes): self
    {
        $handle = $project->slug;
        while (static::query()->where('handle', $handle)->exists()) {
            $handle = mb_substr($project->slug, 0, 70).'-'.strtolower(bin2hex(random_bytes(2)));
        }

        return new self(array_merge([
            'handle' => $handle,
            'tenant_id' => TenantAccess::currentId(),
            'project_id' => $project->id,
            'access_mode' => self::TOKEN,
            'path' => (string) config('mcp.gateway.default_path', '/mcp'),
            'audit_calls' => false,
            'signing_key' => self::generateKey(),
        ], $attributes));
    }

    public static function generateKey(): string
    {
        return self::KEY_PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** New key; the previous one keeps signing (a second v1) until the next deploy has had time to roll out. */
    public function rotateKey(): void
    {
        $this->signing_key_previous = $this->signing_key;
        $this->previous_key_expires_at = now()->addDays(self::ROTATION_GRACE_DAYS);
        $this->signing_key = self::generateKey();
        $this->key_rotated_at = now();
        $this->save();
    }

    /** @return list<string> keys the gateway signs with (current first) */
    public function signingKeys(): array
    {
        $keys = [(string) $this->signing_key];
        if ($this->signing_key_previous && (! $this->previous_key_expires_at || $this->previous_key_expires_at->isFuture())) {
            $keys[] = (string) $this->signing_key_previous;
        }

        return $keys;
    }

    public function requiresToken(): bool
    {
        return $this->access_mode !== self::PUBLIC;
    }

    public function project(): ?Project
    {
        return Project::query()->find($this->project_id);
    }
}
