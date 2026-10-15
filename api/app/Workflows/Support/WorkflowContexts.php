<?php

namespace App\Workflows\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Tenancy\Models\Tenant;
use Throwable;

/**
 * The databases holding workflow tables: the central database and — with the Tenancy module
 * enabled — every active tenant schema. Scheduled engine work (ticks, retention) runs once per
 * context; a job dispatched inside a tenant context carries the tenant (stancl's queue
 * bootstrapper) and runs there again.
 */
final class WorkflowContexts
{
    public const CENTRAL = 'central';

    /** Run $fn in every context; one context's failure does not stop the others. */
    public static function each(callable $fn): void
    {
        try {
            if (Schema::hasTable('workflow_runs')) {
                $fn(self::CENTRAL);
            }
        } catch (Throwable $e) {
            Log::error('Workflow engine: central context failed', ['error' => $e->getMessage()]);
            report($e);
        }

        if (! self::tenancyEnabled()) {
            return;
        }

        foreach (Tenant::all() as $tenant) {
            if (method_exists($tenant, 'isActive') && ! $tenant->isActive()) {
                continue;
            }

            try {
                $tenant->run(function () use ($fn, $tenant) {
                    if (Schema::hasTable('workflow_runs')) {
                        $fn((string) $tenant->getTenantKey());
                    }
                });
            } catch (Throwable $e) {
                Log::error('Workflow engine: tenant context failed', ['tenant' => $tenant->getTenantKey(), 'error' => $e->getMessage()]);
                report($e);
            }
        }
    }

    /** Key of the current context: the tenant id, or "central". */
    public static function current(): string
    {
        if (self::tenancyEnabled() && function_exists('tenancy') && tenancy()->initialized && tenancy()->tenant) {
            return (string) tenancy()->tenant->getTenantKey();
        }

        return self::CENTRAL;
    }

    /**
     * Run $fn in the context with the given key (signed hooks arrive without a user, so the
     * context comes from the URL). Returns null when the tenant does not exist.
     */
    public static function runIn(string $key, callable $fn): mixed
    {
        if ($key === self::CENTRAL) {
            return $fn();
        }

        if (! self::tenancyEnabled()) {
            return null;
        }

        $tenant = Tenant::find($key);
        if (! $tenant) {
            return null;
        }

        return $tenant->run($fn);
    }

    public static function tenancyEnabled(): bool
    {
        return (bool) config('tenancy.enabled') && class_exists(Tenant::class);
    }
}
