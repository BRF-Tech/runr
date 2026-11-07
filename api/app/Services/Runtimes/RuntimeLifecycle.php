<?php

namespace App\Services\Runtimes;

use App\Models\ExecutorImage;
use App\Models\Runtime;
use Illuminate\Support\Carbon;

/**
 * The lifecycle of runtimes and their versions (docs/runtimes.md#lifecycle), the same for every
 * source — built-in, platform, organization, synced from a source:
 *
 *  eol         the version's end of life (endoflife.date for an `eol:` product, else the manifest)
 *  deprecated  the manifest's version or runtime `deprecated` (a date, or true: from the first
 *              sync that saw it): a badge and a notice, the version stays usable
 *  sunset      the manifest's version or runtime `sunset`, or — for a runtime that left its
 *              source (orphaned) — the end of the grace period (runtime_sources.orphan_grace_days)
 *
 * executor_images carries the dates (eol_at, deprecated_at, sunset_at). After the earlier of
 * eol_at and sunset_at (ExecutorImage::endsAt()) a version leaves the picker and its projects can
 * no longer be saved or deployed; the reminders count down to it (RuntimeEolRemindersJob).
 * Running deployments are never touched.
 */
class RuntimeLifecycle
{
    public const ACTIVE = 'active';

    public const DEPRECATED = 'deprecated';

    public const EOL = 'eol';

    public const SUNSET = 'sunset';

    public const ORPHANED = 'orphaned';

    /** The API document's shape of runtime() */
    public const RUNTIME_OPENAPI_TYPE = 'array{state: "active"|"deprecated"|"sunset"|"orphaned", deprecated_at: string|null, sunset_at: string|null, orphaned_at: string|null, replacement: string|null, notice: string|null}';

    /** The API document's shape of version() */
    public const VERSION_OPENAPI_TYPE = 'array{state: "active"|"deprecated"|"eol"|"sunset", eol_at: string|null, deprecated_at: string|null, sunset_at: string|null, ends_at: string|null, ends_by: "eol"|"sunset"|null}';

    /**
     * Write the lifecycle of the manifest (versions, runtime) and of an orphaned runtime onto the
     * runtime's executor_images rows. A discovered version takes its eol from the manifest's
     * versionDiscovery.lifecycle when that names one.
     */
    public function apply(Runtime $runtime, RuntimeManifest $manifest): void
    {
        $today = now()->toDateString();
        $runtimeSunset = $manifest->sunset();
        $orphanSunset = $runtime->orphaned_at
            ? $runtime->orphaned_at->copy()->addDays(max(0, (int) config('runtime_sources.orphan_grace_days', 90)))->toDateString()
            : null;

        foreach (ExecutorImage::query()->where('runtime', $runtime->id)->get() as $image) {
            $version = $manifest->versionLifecycle((string) $image->version);
            $current = $image->deprecated_at?->toDateString();

            $deprecated = self::earliest([
                self::deprecationDate($version['deprecated'], $current, $today),
                self::deprecationDate($manifest->deprecated(), $current, $today),
                $runtime->orphaned_at?->toDateString(),
            ]);
            $sunset = self::earliest([$version['sunset'], $runtimeSunset, $orphanSunset]);

            $image->forceFill(['deprecated_at' => $deprecated, 'sunset_at' => $sunset]);
            if ($manifest->version((string) $image->version) === null && $version['eol'] !== null) {
                $image->forceFill(['eol_at' => $version['eol']]);
            }
            if ($image->isDirty()) {
                $image->save();
            }
        }
    }

    /** "true" (deprecated now) keeps the date a sync first saw it; a date is the date. */
    private static function deprecationDate(string|bool|null $value, ?string $current, string $today): ?string
    {
        return match (true) {
            $value === true => $current ?? $today,
            is_string($value) => $value,
            default => null,
        };
    }

    /** @param  list<string|null>  $dates */
    private static function earliest(array $dates): ?string
    {
        $dates = array_values(array_filter($dates, fn ($d) => is_string($d) && $d !== ''));
        sort($dates);

        return $dates[0] ?? null;
    }

    /**
     * The runtime's lifecycle for the API: orphaned (it left its source), sunset (the runtime's
     * sunset passed), deprecated, active.
     *
     * @return array{state: "active"|"deprecated"|"sunset"|"orphaned", deprecated_at: string|null, sunset_at: string|null, orphaned_at: string|null, replacement: string|null, notice: string|null}
     */
    public static function runtime(Runtime $runtime, ?RuntimeManifest $manifest): array
    {
        $sunset = $manifest?->sunset();
        if ($runtime->orphaned_at) {
            $grace = $runtime->orphaned_at->copy()->addDays(max(0, (int) config('runtime_sources.orphan_grace_days', 90)))->toDateString();
            $sunset = $sunset === null || $grace < $sunset ? $grace : $sunset;
        }
        $deprecated = $manifest?->deprecated();
        $deprecatedAt = $deprecated === true ? ($runtime->orphaned_at?->toDateString() ?? self::firstDeprecated($runtime)) : (is_string($deprecated) ? $deprecated : null);
        if ($runtime->orphaned_at && ($deprecatedAt === null || $runtime->orphaned_at->toDateString() < $deprecatedAt)) {
            $deprecatedAt = $runtime->orphaned_at->toDateString();
        }

        $today = now()->toDateString();
        $state = match (true) {
            $runtime->orphaned_at !== null => self::ORPHANED,
            $sunset !== null && $sunset <= $today => self::SUNSET,
            $deprecatedAt !== null && $deprecatedAt <= $today => self::DEPRECATED,
            default => self::ACTIVE,
        };

        return [
            'state' => $state,
            'deprecated_at' => $deprecatedAt,
            'sunset_at' => $sunset,
            'orphaned_at' => $runtime->orphaned_at?->toIso8601String(),
            'replacement' => $manifest?->replacement(),
            'notice' => $manifest?->notice(),
        ];
    }

    private static function firstDeprecated(Runtime $runtime): string
    {
        return ExecutorImage::query()->where('runtime', $runtime->id)->whereNotNull('deprecated_at')->min('deprecated_at') ?: now()->toDateString();
    }

    /**
     * A version's lifecycle for the API.
     *
     * @return array{state: "active"|"deprecated"|"eol"|"sunset", eol_at: string|null, deprecated_at: string|null, sunset_at: string|null, ends_at: string|null, ends_by: "eol"|"sunset"|null}
     */
    public static function version(ExecutorImage $image): array
    {
        $ends = $image->endsAt();
        $state = match (true) {
            $image->hasEnded() => (string) $image->endKind(),
            $image->isDeprecated() => self::DEPRECATED,
            default => self::ACTIVE,
        };

        return [
            'state' => $state,
            'eol_at' => $image->eol_at?->toDateString(),
            'deprecated_at' => $image->deprecated_at?->toDateString(),
            'sunset_at' => $image->sunset_at?->toDateString(),
            'ends_at' => $ends?->toDateString(),
            'ends_by' => $image->endKind(),
        ];
    }

    /** Whether the date (YYYY-MM-DD) has come. */
    public static function reached(?string $date): bool
    {
        return $date !== null && Carbon::parse($date)->startOfDay()->lessThanOrEqualTo(now());
    }
}
