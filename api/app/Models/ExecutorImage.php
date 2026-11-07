<?php

namespace App\Models;

use Carbon\CarbonInterface;
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
        // Lifecycle (docs/runtimes.md#lifecycle): deprecated from / sunset after (the manifest's
        // version or runtime fields, an orphaned runtime's grace period); eol_at is the end of life
        'deprecated_at',
        'sunset_at',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'eol_at' => 'date',
        'deprecated_at' => 'date',
        'sunset_at' => 'date',
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
                if ($record->hasEnded()) {
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
     * The date after which the version can no longer be saved or deployed: the earlier of its end
     * of life and its sunset (docs/runtimes.md#lifecycle).
     */
    public function endsAt(): ?CarbonInterface
    {
        $dates = array_values(array_filter([$this->eol_at, $this->sunset_at]));
        if ($dates === []) {
            return null;
        }
        usort($dates, fn (CarbonInterface $a, CarbonInterface $b) => $a <=> $b);

        return $dates[0];
    }

    /** What ends the version at endsAt(): eol or sunset (the earlier date; eol on a tie). */
    public function endKind(): ?string
    {
        $ends = $this->endsAt();
        if ($ends === null) {
            return null;
        }

        return $this->eol_at && $this->eol_at->equalTo($ends) ? 'eol' : 'sunset';
    }

    public function hasEnded(): bool
    {
        return (bool) $this->endsAt()?->isPast();
    }

    public function isDeprecated(): bool
    {
        return $this->deprecated_at !== null && ! $this->deprecated_at->isFuture();
    }

    /**
     * Map of "runtime:version" → the date the version ends (endsAt(): end of life or sunset) for
     * all images that have one.
     */
    public static function eolMap(): array
    {
        return static::query()
            ->where(fn ($q) => $q->whereNotNull('eol_at')->orWhereNotNull('sunset_at'))
            ->get(['runtime', 'version', 'eol_at', 'sunset_at'])
            ->mapWithKeys(fn (self $row) => [$row->runtime.':'.$row->version => $row->endsAt()?->toDateString()])
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
