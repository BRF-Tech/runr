<?php

namespace App\Services\Observability;

use App\Services\Runtimes\RuntimeRegistry;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Models\Tenant;

/**
 * Where observability rows live: the central database (no Tenancy, or central-context projects)
 * and, with the Tenancy module on, every tenant schema. Background work (uptime runner, alert
 * evaluation, pruning) visits each; callbacks from the cluster (replay ingest, agent results)
 * enter the schema their key names.
 */
final class TenantScope
{
    public static function enabled(): bool
    {
        return (bool) config('tenancy.enabled') && class_exists(Tenant::class);
    }

    /** The tenant the current request / job runs for, null in central context. */
    public static function current(): ?string
    {
        return RuntimeRegistry::currentTenantId();
    }

    /**
     * Run $fn in the schema of $tenantId (null: central). Null when the tenant does not exist.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T|null
     */
    public static function run(?string $tenantId, callable $fn): mixed
    {
        if ($tenantId === null || ! self::enabled()) {
            return $tenantId === null ? $fn() : null;
        }

        if (self::current() === $tenantId) {
            return $fn();
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            return null;
        }

        return $tenant->run(fn () => $fn());
    }

    /**
     * $fn(null) in central context, then $fn(<tenant id>) inside every tenant schema. A failing
     * scope is logged and skipped.
     *
     * @param  callable(?string): void  $fn
     */
    public static function each(callable $fn): void
    {
        self::guarded(fn () => $fn(null), null);

        if (! self::enabled()) {
            return;
        }

        foreach (Tenant::query()->get() as $tenant) {
            // tenants being deleted are left alone
            if (($tenant->status ?? 'active') !== 'active') {
                continue;
            }
            $id = (string) $tenant->getTenantKey();
            self::guarded(fn () => $tenant->run(fn () => $fn($id)), $id);
        }
    }

    private static function guarded(callable $fn, ?string $tenantId): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('Observability: scope skipped', ['tenant' => $tenantId, 'error' => $e->getMessage()]);
        }
    }
}
