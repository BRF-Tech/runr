<?php

namespace App\Models;

use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A runtime plugin (runtime.yaml). source: builtin (manifest in the release catalog, the row holds
 * enabled / regions), platform (registered by a platform admin) or custom (registered by an
 * organization when the platform allows custom runtimes; tenant_id scopes it in tenant context).
 *
 * @property string $id
 * @property string $name
 * @property string $source
 * @property string|null $tenant_id
 * @property string|null $created_by
 * @property array|null $manifest
 * @property string|null $manifest_source
 * @property array|null $files
 * @property string|null $digest
 * @property bool $is_enabled
 * @property array|null $regions
 *
 * @method static Builder<static>|Runtime query()
 *
 * @mixin Eloquent
 */
class Runtime extends Model
{
    public const SOURCE_BUILTIN = 'builtin';

    public const SOURCE_PLATFORM = 'platform';

    public const SOURCE_CUSTOM = 'custom';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'name', 'source', 'tenant_id', 'created_by', 'manifest', 'manifest_source', 'files',
        'digest', 'is_enabled', 'regions',
    ];

    protected $casts = [
        'manifest' => 'array',
        'files' => 'array',
        'regions' => 'array',
        'is_enabled' => 'boolean',
    ];

    public function isBuiltin(): bool
    {
        return $this->source === self::SOURCE_BUILTIN;
    }

    /** Whether the runtime is offered in the region (regions null = every region). */
    public function availableIn(?string $regionId): bool
    {
        return $this->regions === null || $regionId === null || in_array($regionId, $this->regions, true);
    }

    public function builds(): HasMany
    {
        return $this->hasMany(RuntimeBuild::class, 'runtime_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'runtime');
    }
}
