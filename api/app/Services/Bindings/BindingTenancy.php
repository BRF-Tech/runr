<?php

namespace App\Services\Bindings;

use Closure;

/**
 * What bindings need from an optional organization layer (the Tenancy module sets these hooks,
 * Modules\Tenancy\Services\Bindings\TenancyBindingsBridge). Without it the install is the one
 * organization.
 *
 *  - runner        fn (callable $cb): void — runs $cb once per organization context (scheduled
 *                  jobs: usage, purge, reconcile; the "in use" check of a region data service)
 *  - quotas        fn (): ?array — per-organization limit overrides (BindingQuotas::KEYS)
 *  - tenantKey     fn (): ?string — the current organization's id (audit)
 */
final class BindingTenancy
{
    private static ?Closure $runner = null;

    private static ?Closure $quotas = null;

    private static ?Closure $tenantKey = null;

    public static function setRunner(?Closure $runner): void
    {
        self::$runner = $runner;
    }

    public static function setQuotaResolver(?Closure $resolver): void
    {
        self::$quotas = $resolver;
    }

    public static function setTenantKeyResolver(?Closure $resolver): void
    {
        self::$tenantKey = $resolver;
    }

    /** Run $callback once per organization context (once when there is no organization layer). */
    public static function eachContext(callable $callback): void
    {
        if (self::$runner) {
            (self::$runner)($callback);

            return;
        }

        $callback();
    }

    /** @return array<string, int|null>|null */
    public static function quotaOverrides(): ?array
    {
        return self::$quotas ? (self::$quotas)() : null;
    }

    public static function tenantKey(): ?string
    {
        return self::$tenantKey ? (self::$tenantKey)() : null;
    }

    /** Test helper: drop every hook. */
    public static function reset(): void
    {
        self::$runner = self::$quotas = self::$tenantKey = null;
    }
}
