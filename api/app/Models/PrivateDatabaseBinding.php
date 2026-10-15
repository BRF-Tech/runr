<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project's private connection to a Managed PostgreSQL database through Skupper.
 *
 * On the region, in the project namespace (one Skupper Site per namespace):
 *   - Link (or AccessToken, redeemed into a Link) named {resource_name} — the link to the
 *     Managed PostgreSQL Skupper site;
 *   - Listener {resource_name}: routing key → local Service {resource_name}:{port};
 *   - Secret {resource_name}-pgconn with DATABASE_URL / PG* — the only place credentials live.
 * The project's Knative Service / CronJob get {env_prefix}DATABASE_URL, {env_prefix}PGHOST, …
 * as secretKeyRef env vars.
 */
class PrivateDatabaseBinding extends Model
{
    use HasUlids;

    /** Keys of the credentials Secret = env var names (after the binding's env_prefix). */
    public const SECRET_KEYS = ['DATABASE_URL', 'PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD', 'PGSSLMODE'];

    public const SECRET_SUFFIX = '-pgconn';

    protected $table = 'private_db_bindings';

    protected $fillable = [
        'project_id',
        'name',
        'resource_name',
        'env_prefix',
        'routing_key',
        'port',
        'sslmode',
        'link_kind',
    ];

    protected $casts = [
        'port' => 'integer',
    ];

    protected $appends = ['host', 'secret_name', 'env_names'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Local host name functions connect to (the Listener's Service in the project namespace). */
    public function getHostAttribute(): string
    {
        return $this->resource_name;
    }

    public function getSecretNameAttribute(): string
    {
        return $this->resource_name.self::SECRET_SUFFIX;
    }

    public function getEnvNamesAttribute(): array
    {
        return array_map(fn ($k) => $this->env_prefix.$k, self::SECRET_KEYS);
    }

    /**
     * Container env entries (secretKeyRef). optional: a pod still starts while the Secret is
     * being replaced or after the binding is removed.
     */
    public function envVars(): array
    {
        return array_map(fn (string $key) => [
            'name' => $this->env_prefix.$key,
            'valueFrom' => ['secretKeyRef' => [
                'name' => $this->secret_name,
                'key' => $key,
                'optional' => true,
            ]],
        ], self::SECRET_KEYS);
    }

    public static function resourceNameFor(string $name, string $id): string
    {
        return $name.'-'.strtolower(substr($id, -6));
    }
}
