<?php

namespace App\Models;

use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
 * @property string|null $source_id the runtime source it was synced from (RuntimeSource)
 * @property string|null $source_path its manifest in the source (runtimes/<id>/runtime.yaml)
 * @property-read RuntimeSource|null $runtimeSource
 * @property Carbon|null $orphaned_at it left its source: projects keep running, a sunset follows
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
        'digest', 'is_enabled', 'regions', 'source_id', 'source_path', 'orphaned_at',
    ];

    protected $casts = [
        'manifest' => 'array',
        'files' => 'array',
        'regions' => 'array',
        'is_enabled' => 'boolean',
        'orphaned_at' => 'datetime',
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

    /** A runtime a source manages: its manifest changes with the source, not through the API. */
    public function isSourceManaged(): bool
    {
        return $this->source_id !== null && ! $this->isBuiltin();
    }

    public function isOrphaned(): bool
    {
        return $this->orphaned_at !== null;
    }

    /** @return BelongsTo<RuntimeSource, $this> */
    public function runtimeSource(): BelongsTo
    {
        return $this->belongsTo(RuntimeSource::class, 'source_id');
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
