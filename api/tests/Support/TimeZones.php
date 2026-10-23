<?php

namespace Tests\Support;

use DateTimeZone;

/**
 * Time-zone names as the PHP running the tests knows them. The API takes the zones of its PHP,
 * backward-compatible names included (Europe/Kiev for Europe/Kyiv; Chrome's zone list still
 * offers some). PHP's own zone database (the API image, the official PHP images) has them; a PHP
 * built against the system's tzdata does not where the distribution moved them out (Ubuntu 24.04
 * without tzdata-legacy: WSL, PPA builds). Tests ask here instead of naming such a zone.
 */
final class TimeZones
{
    /** Backward-compatible names to prefer, in this order. */
    private const PREFERRED = ['Europe/Kiev', 'Asia/Calcutta', 'US/Eastern'];

    public static function knows(string $zone): bool
    {
        return in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }

    /** A backward-compatible zone name this PHP knows (Europe/Kiev where it can), null if it knows none. */
    public static function backward(): ?string
    {
        $canonical = array_flip(DateTimeZone::listIdentifiers());
        $backward = array_values(array_filter(
            DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),
            fn (string $zone) => ! isset($canonical[$zone]),
        ));

        foreach (self::PREFERRED as $zone) {
            if (in_array($zone, $backward, true)) {
                return $zone;
            }
        }

        return $backward[0] ?? null;
    }
}
