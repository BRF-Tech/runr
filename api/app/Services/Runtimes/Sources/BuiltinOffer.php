<?php

namespace App\Services\Runtimes\Sources;

use App\Models\Setting;
use App\Support\PlatformConnection;

/**
 * The platform setting "Built-in runtimes" (Build & Run → Runtimes, docs/runtimes.md#built-in-runtimes):
 * which runtimes of RunR's catalog the platform offers.
 *
 *   {"mode": "all"}                          every runtime of the catalog, also one it gains later
 *   {"mode": "selected", "ids": ["python"]}  only these
 *
 * Until a platform administrator saves it, EXECUTOR_RUNTIMES (config executor.runtimes) is its
 * value: empty = all. A built-in left out is not offered for new projects; its projects keep
 * deploying.
 */
final class BuiltinOffer
{
    public const KEY = 'builtin_runtimes';

    /** The offered ids, null for the whole catalog. */
    public static function ids(): ?array
    {
        $stored = self::stored();
        if ($stored !== null) {
            return $stored['mode'] === 'all' ? null : $stored['ids'];
        }

        $configured = array_values(array_filter((array) config('executor.runtimes', []), fn ($id) => is_string($id) && $id !== ''));

        return $configured === [] ? null : $configured;
    }

    /** Whether the value comes from the saved setting (false: EXECUTOR_RUNTIMES). */
    public static function saved(): bool
    {
        return self::stored() !== null;
    }

    /** @param  list<string>|null  $ids  null: every runtime of the catalog */
    public static function save(?array $ids): void
    {
        $value = $ids === null
            ? ['mode' => 'all']
            : ['mode' => 'selected', 'ids' => array_values(array_unique(array_map('strval', $ids)))];

        $connection = PlatformConnection::name();
        ($connection ? Setting::on($connection) : Setting::query())
            ->updateOrCreate(['key' => self::KEY], ['value' => json_encode($value, JSON_UNESCAPED_SLASHES)]);
    }

    /** @return array{mode: string, ids: list<string>}|null */
    private static function stored(): ?array
    {
        try {
            $raw = Setting::platform(self::KEY);
        } catch (\Throwable) {
            return null;
        }
        $value = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($value) || ! in_array($value['mode'] ?? null, ['all', 'selected'], true)) {
            return null;
        }

        return ['mode' => $value['mode'], 'ids' => array_values(array_filter((array) ($value['ids'] ?? []), 'is_string'))];
    }
}
