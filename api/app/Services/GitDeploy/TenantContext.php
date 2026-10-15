<?php

namespace App\Services\GitDeploy;

use App\Models\User;
use Closure;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;

/**
 * The organization a git operation belongs to. Multi-tenant installs (Tenancy module, schema per
 * tenant) run project work inside the tenant's context; a single-organization install has no
 * tenant (id null) and runs everything in place.
 */
class TenantContext
{
    public static function enabled(): bool
    {
        return (bool) config('tenancy.enabled') && class_exists(Tenant::class);
    }

    /** Id of the tenant whose context is active, null outside of one. */
    public static function currentId(): ?string
    {
        if (! self::enabled() || ! function_exists('tenant')) {
            return null;
        }

        $tenant = tenant();

        return $tenant ? (string) $tenant->getTenantKey() : null;
    }

    /** Slug of the active tenant (bare repository directory, remote URL owner). */
    public static function currentSlug(): ?string
    {
        if (! self::enabled() || ! function_exists('tenant')) {
            return null;
        }

        $tenant = tenant();

        return $tenant ? (string) $tenant->slug : null;
    }

    /** @return Tenant|null */
    public static function find(?string $id)
    {
        if (! $id || ! self::enabled()) {
            return null;
        }

        return Tenant::find($id);
    }

    /** @return Tenant|null */
    public static function findBySlug(string $slug)
    {
        if (! self::enabled()) {
            return null;
        }

        return Tenant::where('slug', $slug)->first();
    }

    /**
     * Run $callback in the tenant's context (null tenant: as is). Inside the same tenant it runs
     * directly; another tenant's context is entered and left again.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(?string $tenantId, Closure $callback): mixed
    {
        if (! $tenantId || ! self::enabled()) {
            return $callback();
        }

        if (self::currentId() === $tenantId) {
            return $callback();
        }

        $tenant = self::find($tenantId);
        if (! $tenant) {
            throw new \RuntimeException("Organization {$tenantId} not found");
        }

        return $tenant->run($callback);
    }

    /**
     * Run $callback once per organization context: every tenant (multi-tenant), else once.
     *
     * @param  Closure(?string $tenantId): void  $callback
     */
    public static function each(Closure $callback): void
    {
        if (! self::enabled()) {
            $callback(null);

            return;
        }

        foreach (Tenant::query()->cursor() as $tenant) {
            try {
                $tenant->run(fn () => $callback((string) $tenant->getTenantKey()));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** The user's role in the tenant (owner | admin | member | viewer), null when not a member. */
    public static function role(User $user, string $tenantId): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        return TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $user->getKey())
            ->value('role');
    }

    public static function isPlatformAdmin(User $user): bool
    {
        return (bool) ($user->is_admin ?? false);
    }
}
