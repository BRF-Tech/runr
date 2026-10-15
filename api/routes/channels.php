<?php

use App\Support\Broadcasting\ChannelAccess;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Private broadcast channels (docs/security.md, "Real-time channels")
|--------------------------------------------------------------------------
| Authorized by POST /api/broadcasting/auth (bootstrap/app.php: api + auth:sanctum, so the
| tenant of X-Tenant-ID is initialized). Who may subscribe: App\Support\Broadcasting\ChannelAccess.
| Names without the "private-" prefix; tenant channels carry "tenant.{tenantId}.".
*/

Broadcast::channel('platform', fn ($user) => ChannelAccess::organization($user));
Broadcast::channel('notifications', fn ($user) => ChannelAccess::organization($user));
Broadcast::channel('notifications.{userId}', fn ($user, string $userId) => ChannelAccess::ownNotifications($user, $userId));
Broadcast::channel('deployment.{projectId}', fn ($user, string $projectId) => ChannelAccess::deployment($user, $projectId));
Broadcast::channel('support-tickets', fn ($user) => ChannelAccess::ticketList($user));
Broadcast::channel('support-ticket.{ticketId}', fn ($user, string $ticketId) => ChannelAccess::ticket($user, $ticketId));
Broadcast::channel('support-ticket.{ticketId}.internal', fn ($user, string $ticketId) => ChannelAccess::ticketInternal($user));

Broadcast::channel('tenant.{tenantId}.platform', fn ($user, string $tenantId) => ChannelAccess::organization($user, $tenantId));
Broadcast::channel('tenant.{tenantId}.notifications', fn ($user, string $tenantId) => ChannelAccess::organization($user, $tenantId));
Broadcast::channel('tenant.{tenantId}.notifications.{userId}', fn ($user, string $tenantId, string $userId) => ChannelAccess::ownNotifications($user, $userId, $tenantId));
Broadcast::channel('tenant.{tenantId}.deployment.{projectId}', fn ($user, string $tenantId, string $projectId) => ChannelAccess::deployment($user, $projectId, $tenantId));
Broadcast::channel('tenant.{tenantId}.support-tickets', fn ($user, string $tenantId) => ChannelAccess::ticketList($user, $tenantId));
Broadcast::channel('tenant.{tenantId}.support-ticket.{ticketId}', fn ($user, string $tenantId, string $ticketId) => ChannelAccess::ticket($user, $ticketId, $tenantId));
Broadcast::channel('tenant.{tenantId}.support-ticket.{ticketId}.internal', fn ($user, string $tenantId, string $ticketId) => ChannelAccess::ticketInternal($user, $tenantId));
