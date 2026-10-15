<?php

namespace App\Support\Broadcasting;

use App\Models\Project;
use App\Support\Rbac\Rbac;
use App\Support\TenantAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Who may subscribe to which private broadcast channel (routes/channels.php, docs/security.md).
 * Every RunR channel is private: the console authorizes each subscription through
 * POST /api/broadcasting/auth (Bearer token, X-Tenant-ID), and these checks decide with the
 * RBAC permissions of the signed-in user. Payloads stay masked as a second line of defence.
 *
 *   platform / notifications            the organization's members (multi-tenant, outside a
 *                                      tenant: platform admins only)
 *   notifications.{userId}              that user only
 *   deployment.{projectId}              projects.view on the project
 *   support-tickets                     tickets.manage (the list of every ticket)
 *   support-ticket.{ticketId}           the ticket's author, or tickets.manage
 *   support-ticket.{ticketId}.internal  tickets.manage (internal notes)
 *
 * In a tenant every name carries the prefix tenant.{tenantId}. — the subscription must come
 * from that tenant's context (X-Tenant-ID) and the user must belong to the tenant.
 */
final class ChannelAccess
{
    /** Organization-wide channels: platform, notifications. */
    public static function organization(?Authenticatable $user, ?string $tenantId = null): bool
    {
        if (! $user) {
            return false;
        }
        if ($tenantId !== null) {
            return self::inTenant($user, $tenantId);
        }

        // Without a tenant: single-tenant, the install's members; multi-tenant, platform channels
        return config('tenancy.enabled') ? self::rbac()->isPlatformAdmin($user) : true;
    }

    public static function ownNotifications(?Authenticatable $user, string $userId, ?string $tenantId = null): bool
    {
        if (! $user || (string) $user->getAuthIdentifier() !== $userId) {
            return false;
        }

        return $tenantId === null || self::inTenant($user, $tenantId);
    }

    public static function deployment(?Authenticatable $user, string $projectId, ?string $tenantId = null): bool
    {
        if (! $user || ($tenantId !== null && ! self::inTenant($user, $tenantId))) {
            return false;
        }

        $project = Project::query()->find($projectId);

        return $project !== null && Gate::forUser($user)->allows('projects.view', $project);
    }

    public static function ticketList(?Authenticatable $user, ?string $tenantId = null): bool
    {
        if (! $user || ($tenantId !== null && ! self::inTenant($user, $tenantId))) {
            return false;
        }

        return self::managesTickets($user);
    }

    public static function ticket(?Authenticatable $user, string $ticketId, ?string $tenantId = null): bool
    {
        if (! $user || ($tenantId !== null && ! self::inTenant($user, $tenantId))) {
            return false;
        }
        if (self::managesTickets($user)) {
            return true;
        }

        $class = 'Modules\\Ticket\\Models\\SupportTicket';
        if (! class_exists($class)) {
            return false;
        }
        try {
            $author = $class::query()->whereKey($ticketId)->value('user_id');
        } catch (\Throwable) {
            return false; // Ticket module not installed (no table)
        }

        return $author !== null && (string) $author === (string) $user->getAuthIdentifier();
    }

    public static function ticketInternal(?Authenticatable $user, ?string $tenantId = null): bool
    {
        return self::ticketList($user, $tenantId);
    }

    /** A member of $tenantId (or a platform admin), subscribing from that tenant's context. */
    private static function inTenant(Authenticatable $user, string $tenantId): bool
    {
        if (TenantAccess::currentId() !== $tenantId) {
            return false;
        }

        return self::rbac()->isPlatformAdmin($user) || TenantAccess::roleOf($user, $tenantId) !== null;
    }

    /** Gate: platform admins pass (RbacServiceProvider's Gate::before) */
    private static function managesTickets(Authenticatable $user): bool
    {
        return Gate::forUser($user)->allows('tickets.manage');
    }

    private static function rbac(): Rbac
    {
        return app(Rbac::class);
    }
}
