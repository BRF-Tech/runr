<?php

namespace App\Support;

use App\Models\Setting;
use DateTimeZone;

/**
 * The organization's time zone (setting `timezone`, PATCH /api/settings): the zone the crons of the
 * timed warmer run in (ScaleSchedule) and the console explains them in. In a tenant it is the
 * organization's own setting, else the platform's (Setting::get() falls back to the central
 * settings), else Europe/Istanbul.
 */
final class OrgTimezone
{
    public const SETTING = 'timezone';

    public const DEFAULT = 'Europe/Istanbul';

    /** @var array<string, true>|null */
    private static ?array $known = null;

    public static function get(): string
    {
        $zone = Setting::get(self::SETTING);

        return self::isValid($zone) ? (string) $zone : self::DEFAULT;
    }

    /** An IANA zone PHP knows (backward-compatible names like Europe/Kiev included). */
    public static function isValid(mixed $zone): bool
    {
        if (! is_string($zone) || $zone === '') {
            return false;
        }

        self::$known ??= array_fill_keys(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);

        return isset(self::$known[$zone]);
    }
}
