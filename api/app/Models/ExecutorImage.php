<?php

namespace App\Models;

use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static Builder<static>|ExecutorImage newModelQuery()
 * @method static Builder<static>|ExecutorImage newQuery()
 * @method static Builder<static>|ExecutorImage query()
 *
 * @mixin Eloquent
 */
class ExecutorImage extends Model
{
    use HasUlids;

    protected $fillable = [
        'runtime',
        'version',
        'docker_image',
        'is_default',
        'is_active',
        'eol_at',
        'sort_order',
        'category',
        'gpu_required',
        // Digest of the version's newest runtime patch (the patch feed, docs/runtimes.md#runtime-patches)
        'digest',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'eol_at' => 'date',
        'sort_order' => 'integer',
        'gpu_required' => 'boolean',
    ];

    /**
     * Resolve the docker image tag for a runtime + version.
     * Falls back to the default version if $version is null.
     */
    public static function resolveImage(string $runtime, ?string $version = null): ?string
    {
        return static::resolve($runtime, $version)?->docker_image;
    }

    /**
     * The executor image record a runtime + version runs on (resolveImage() semantics): the
     * active record of that version — null when it is past its EOL — else the runtime's default.
     */
    public static function resolve(string $runtime, ?string $version = null): ?self
    {
        if ($version) {
            $record = static::where('runtime', $runtime)
                ->where('version', $version)
                ->where('is_active', true)
                ->first();

            if ($record) {
                if ($record->eol_at && $record->eol_at->isPast()) {
                    return null;
                }

                return $record;
            }
        }

        // Fallback to default (only when version is null)
        return static::where('runtime', $runtime)
            ->where('is_default', true)
            ->first();
    }

    /**
     * Map of "runtime:version" → eol_at date string for all images with eol_at set.
     */
    public static function eolMap(): array
    {
        return static::whereNotNull('eol_at')
            ->select(['runtime', 'version', 'eol_at'])
            ->get()
            ->mapWithKeys(fn ($row) => [$row->runtime.':'.$row->version => $row->eol_at->toDateString()])
            ->toArray();
    }

    /**
     * Get the default version string for a runtime.
     */
    public static function defaultVersion(string $runtime): ?string
    {
        return static::where('runtime', $runtime)
            ->where('is_default', true)
            ->value('version');
    }
}
