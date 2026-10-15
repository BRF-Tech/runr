# WebSocket events

Every `PlatformEvent` topic: where the backend broadcasts it and where the frontend handles it.

**Channel**: `platform` (Echo/Reverb, private channel — subscriptions are authorized by `POST /api/broadcasting/auth`, see [security.md](security.md#real-time-channels)). In tenant context (Tenancy module) the name is prefixed: `tenant.{tenantId}.platform`.
**Event name**: `.platform.event`
**Payload shape**: `{ topic: string, payload: object }`

Topics broadcast through `BroadcastsJobCompletion` also create a `Notification` for the user who started the job (pushed on the `notifications` channel as `NotificationCreated`). Failure topics carry an extra `error` field (first 500 characters of the exception message).

---

## 1. Project CRUD

| Topic | Backend | Payload | Frontend handler |
|-------|---------|---------|------------------|
| `project.created` | `ProjectController::store()` | `{ project: ProjectResource }` | `usePlatformSync` — unshift into the projects list |
| `project.updated` | `ProjectController::update()` | `{ project: ProjectResource }` | `usePlatformSync` — update in the list / single project |
| `project.deleted` | `ProjectController::destroy()` | `{ project_id }` | `usePlatformSync` — remove from the list |

**Views**: `ProjectsView` (list), `ProjectDetailView` (single project)

---

## 2. Version

| Topic | Backend | Payload | Frontend handler |
|-------|---------|---------|------------------|
| `version.created` | `CodeController::saveXaaSCode()` | `{ project_id, version: VersionResource }` | `usePlatformSync` — unshift into the versions list |
| `version.created` | `CodeController::saveFaaSFiles()` | `{ project_id, version: VersionResource }` | (same handler) |

**View**: `ProjectDetailView` (filtered by `project_id`)

---

## 3. Deployment

| Topic | Backend | Payload | Frontend handler |
|-------|---------|---------|------------------|
| `deployment.created` | `DeploymentController::deploy()` | `{ project_id, deployment: DeploymentResource }` | `usePlatformSync` — unshift into the deployments list |
| `deployment.deleted` | `DeploymentController::destroy()` | `{ project_id, deployment_id }` | `usePlatformSync` — remove from the list |
| `deployment.ready` | `CheckDeploymentReadyJob` | `{ project_id, project_name, deployment_id, status: 'active' }` | `usePlatformSync` (`deployment.*` wildcard) — update status |
| `deployment.failed` | `CheckDeploymentReadyJob` | `{ project_id, project_name, deployment_id, status: 'failed', error }` | (same wildcard handler) |

**View**: `ProjectDetailView` (filtered by `project_id`)

---

## 4. Build

| Topic | Backend | Payload | Frontend handler |
|-------|---------|---------|------------------|
| `build.completed` | `CheckBuildReadyJob` | `{ project_id, project_name, version }` | `usePlatformSync` — sets `build_status = 'ready'` on the version with that number |
| `build.failed` | `BuildVersionJob`, `CheckBuildReadyJob` | `{ project_id, project_name, version, error }` | `usePlatformSync` — sets `build_status = 'failed'` and `build_error` |

`version` is the version **number**, not its ID.

---

## 5. Settings and sessions

| Topic | Backend | Payload | Frontend handler |
|-------|---------|---------|------------------|
| `settings.updated` | `SettingsController::update()` | `{ base_domain, registration_enabled, … }` (fields from `buildSettingsData()`) | `useRealtimeNotifications`, `usePlatformSync` — `settingsStore.update(payload.settings)` when `payload.settings` is present |
| `session.revoked` | `SessionController::destroy()`, `SessionController::destroyAll()` | `{ token_ids: int[] }` | `useRealtimeNotifications` — force logout when the current token ID is in the list |

---

## 6. Region

| Topic | Backend | Payload | Frontend handler |
|-------|---------|---------|------------------|
| `region.updated` | `CheckKnativeStatusJob`, `RegionMigrateService`, `KnativeInstallController::knativeStatus()` | `{ region: KnativeRegionResource }` | `useRealtimeNotifications` — replace the region in `settingsStore.data.regions` |
| `region.created` | `InstallRegistryAndCreateRegionJob` | `{ region_id, region_name }` | `useRegistrySetup` — `setupStatus = 'ready'`, sets `setupRegionId` |
| `region.create_failed` | `InstallRegistryAndCreateRegionJob` | `{ token, error }` | `useRegistrySetup` — `setupStatus = 'error'` |
| `region.migrated` | `MigrateRegionJob` | `{ region_id, region_name }` | notification only |
| `region.migrate_failed` | `MigrateRegionJob` | `{ region_id, region_name, error }` | notification only |
| `region.migration_completed` | `CheckMigrationDnsJob` | `{ region_id, region_name }` | notification only |
| `region.detached` | `DetachRegionCleanupJob` (Tenancy) | `{ tenant_id, region_id }` | notification only |
| `region.detach_failed` | `DetachRegionCleanupJob` (Tenancy) | `{ tenant_id, region_id, error }` | notification only |

---

## 7. Registry setup progress

| Topic | Backend | Payload | Frontend handler |
|-------|---------|---------|------------------|
| `registry.progress` | `InstallRegistryAndCreateRegionJob::updateCache()` | `{ token, status, phase, message }` | `useRegistrySetup` — update `setupStatus` / `setupPhase` / `setupMessage` |

**`phase` values**: `pvc_test`, `s3_test`, `registry_test`, `zot_install`, `region_create`
**`status` values**: `testing`, `installing`

**Component**: region creation wizard in `RegionDetailModal` (`useRegionDetail` → `useRegistrySetup`)

---

## 8. Install progress (step log)

| Topic | Backend | Payload | Frontend handler |
|-------|---------|---------|------------------|
| `install.progress` | `KnativeLogTrait` (`component`: `knative`, `operator`, `serving`), `KnativeRedisService` (`redis`), `SandboxInstallService` (`kata`, `gvisor`) | `{ region_id, component, message }` | `useInstallPopup` (`operator`, `serving`), `useSandboxTab` (`kata`, `gvisor`) — show the latest log text |

`message` is the component's full step log so far.

---

## 9. Knative components

`{component}` is `all`, `operator` or `serving`.

| Topic | Backend | Payload | Frontend handler |
|-------|---------|---------|------------------|
| `knative.{component}.installed` | `InstallKnativeJob` | `{ region_id, region_name, component }` | `useInstallPopup` (`knative.*`) — fetch component status |
| `knative.{component}.install_failed` | `InstallKnativeJob` | `{ region_id, region_name, component, error }` | `useInstallPopup` — status `'error'` |
| `knative.{component}.uninstalled` | `UninstallKnativeJob` | `{ region_id, region_name, component }` | `useInstallPopup` — fetch component status |
| `knative.{component}.uninstall_failed` | `UninstallKnativeJob` | `{ region_id, region_name, component, error }` | `useInstallPopup` — status `'error'` |

**Filter**: handlers ignore events where `payload.region_id !== regionId.value`.
**Component**: `RegionDetailModal` (`useRegionDetail` → `useInstallPopup`), Knative install panel

---

## 10. Redis

| Topic | Backend | Payload | Frontend handler |
|-------|---------|---------|------------------|
| `redis.installed` | `InstallRedisJob` | `{ region_id, region_name }` | `useInstallPopup` (`redis.*`) — fetch `redisStatus` |
| `redis.install_failed` | `InstallRedisJob` | `{ region_id, region_name, error }` | (same handler) |
| `redis.removed` | `RemoveRedisJob` | `{ region_id, region_name }` | (same handler) |
| `redis.remove_failed` | `RemoveRedisJob` | `{ region_id, region_name, error }` | (same handler) |

**Component**: `RegionDetailModal` (Redis section)

---

## 11. Sandbox (Kata / gVisor)

`{runtime}` is `kata` or `gvisor`.

| Topic | Backend | Payload | Frontend handler |
|-------|---------|---------|------------------|
| `sandbox.{runtime}.installed` | `InstallSandboxJob` | `{ region_id, region_name, runtime }` | `useSandboxTab` (`sandbox.*`) — fetch sandbox status; runtime is taken from the topic |
| `sandbox.kata.installed` (partial) | `InstallSandboxJob` | `{ region_id, region_name, runtime: 'kata', warning }` | (same handler) — sent when Kata is installed but some requested hypervisors/changes were not added |
| `sandbox.{runtime}.install_failed` | `InstallSandboxJob` | `{ region_id, region_name, runtime, error }` | `useSandboxTab` — status `'error'`, then fetch status |
| `sandbox.{runtime}.uninstalled` | `UninstallSandboxJob` | `{ region_id, region_name, runtime }` | `useSandboxTab` — fetch status |
| `sandbox.{runtime}.uninstall_failed` | `UninstallSandboxJob` | `{ region_id, region_name, runtime, error }` | `useSandboxTab` — status `'error'` |
| `sandbox.kvm.probed` | `ProbeKvmJob` | `{ region_id, kvm }` | `useSandboxTab` — reload the hypervisor list (`/dev/kvm` check finished) |

**Component**: `RegionDetailModal` > `SandboxTab`

---

## 12. Demo data

| Topic | Backend | Payload | Frontend handler |
|-------|---------|---------|------------------|
| `demo.seeded` | `SeedDemoJob`, `SeedTenantDemoJob` (Tenancy) | `{ demo_status: 'loaded' }` or `{ tenant_id }` | `DemoManager` — `demoStatus = 'loaded'`; `useRealtimeNotifications` — `settingsStore.fetch()`; `usePlatformSync` — refetch (`ProjectsView`) |
| `demo.seed_failed` | `SeedDemoJob`, `SeedRegionDemoJob`, `SeedTenantDemoJob` | `{ demo_status: 'error' }`, `{ region_id, region_name }` or `{ tenant_id }` (+ `error`) | `DemoManager` (no `region_id`), `RegionDemoSection` (filtered by `region_id`) |
| `demo.deployed` | `DeployRegionDemoJob` | `{ region_id, region_name, region_demo_status: 'ready' }` | `RegionDemoSection` — `demoStatus = 'ready'` |
| `demo.deploy_failed` | `DeployRegionDemoJob` | `{ region_id, region_name, region_demo_status: 'error', error }` | `RegionDemoSection` — `demoStatus = 'error'` |
| `demo.removed` | `RemoveDemoJob`, `RemoveRegionDemoJob`, `RemoveTenantDemoJob` | `{ demo_status: 'idle' }`, `{ region_id, region_name, region_demo_status: null }` or `{ tenant_id }` | `DemoManager` (no `region_id`), `RegionDemoSection` (filtered by `region_id`), `useRealtimeNotifications`, `usePlatformSync` (refetch) |
| `demo.remove_failed` | `RemoveDemoJob`, `RemoveRegionDemoJob`, `RemoveTenantDemoJob` | `{ demo_status: 'error' }`, `{ region_id, region_name, region_demo_status: 'error' }` or `{ tenant_id }` (+ `error`) | (same handlers) |

**Components**: `DemoManager` (global demo, Settings), `RegionDemoSection` (per-region demo)

---

## 13. Tenants (Tenancy module)

| Topic | Backend | Payload | Frontend handler |
|-------|---------|---------|------------------|
| `tenant.deleted` | `DeleteTenantJob` | `{ tenant_id, slug }` | notification only (`TenantSettings` polls every 5s while a tenant is `deleting`) |
| `tenant.delete_failed` | `DeleteTenantJob` | `{ tenant_id, slug, error }` | notification only |

---

## Architecture

```
Backend (Laravel)                          Frontend (Vue 3)
===================================       ===================================

Controller/Job/Service                     Echo/Reverb WebSocket
       |                                         |
  event(new PlatformEvent(                  private('platform')
    topic, payload                            .listen('.platform.event')
  ))                                              |
       |                                   useRealtimeNotifications
  Reverb WS Server  ───────────────>         handlePlatformEvent()
  (channel: platform)                             |
                                            dispatchPlatformEvent()
                                                  |
                                      ┌───────────┴───────────────┐
                                      |                           |
                                 Exact match              Wildcard match
                                 handlers.get(topic)      pattern.endsWith('.*')
                                      |                           |
                              ┌───────┴────────┐         ┌───────┴────────┐
                              |                |         |                |
                        usePlatformSync   useRegistrySetup  useInstallPopup  useSandboxTab
                        DemoManager       RegionDemoSection
```

`PlatformEvent` implements `ShouldBroadcastNow` (sent synchronously, not queued). Its channel comes from `BroadcastChannelResolver::resolve('platform')` — a `PrivateChannel`; the Tenancy module registers a resolver that returns `tenant.{id}.platform` in tenant context. On the frontend, the Tenancy module calls `setBroadcastChannelPrefix('tenant.{id}')` so `getChannel()` subscribes to the same name.

### Traits

| Trait | Location | Used by | Methods |
|-------|----------|---------|---------|
| `BroadcastsModelEvents` | `app/Services/Concerns/` | Controllers | `broadcastModel(topic, payload)` |
| `BroadcastsJobCompletion` | `app/Jobs/Concerns/` | Queue jobs | `broadcastSuccess(topic, payload, title)`, `broadcastFailure(topic, payload, e, title)` |

### Wildcard matching

`useJobEvents` supports patterns ending in `.*`:
- `knative.*` → `knative.operator.installed`, `knative.serving.install_failed`, …
- `redis.*` → `redis.installed`, `redis.remove_failed`, …
- `sandbox.*` → `sandbox.kata.installed`, `sandbox.gvisor.uninstalled`, `sandbox.kvm.probed`, …
- `deployment.*` → `deployment.ready`, `deployment.failed`, …

Handlers registered through `useJobEvents` are removed when the component unmounts.

### Other channels

| Channel | Event | Source |
|---------|-----------------------|--------|
| `notifications` | `NotificationCreated` | `NotificationCreated` without a user — notification popup + unread badge (`useRealtimeNotifications`) |
| `notifications.{user_id}` | `NotificationCreated` | `NotificationCreated` of one user (only that user may subscribe) |
| `deployment.{project_id}` | `DeploymentStatusChanged` | `DeploymentController`, `ProcessDeployment` listener |
| `support-tickets` | `.SupportTicketCreated`, `.SupportTicketStatusChanged` | Ticket module (`useTicketListRealtime`) |
| `support-ticket.{id}` | `.SupportTicketReplied`, `.SupportTicketRead`, `.SupportTicketStatusChanged` | Ticket module (`useTicketDetailRealtime`) |
| `support-ticket.{id}.internal` | `.SupportTicketInternalNote` | Ticket module (`useTicketDetailRealtime`, ticket managers only) |

All channels are private; who may subscribe is decided in `api/routes/channels.php` (`App\Support\Broadcasting\ChannelAccess`). All channel names go through the same tenant prefixing, except internal ticket notes.

### Fallback polling

When the WebSocket connection drops, only these views run a slow fallback poll (`useFallbackPolling`):

| View/Component | Interval | Function |
|----------------|----------|----------|
| `ProjectsView` | 90s | `silentRefresh()` — project list + settings |
| `ProjectDetailView` | 90s | `silentRefresh()` — project + versions + deployments |
| `AppHeader` | 120s | `notificationStore.fetchUnreadCount()` |

While the WebSocket is connected these timers skip their call (`wsConnected` ref from `useWsConnection`).

### Polling that stays in place

These use polling for live data regardless of the WebSocket state:
- `useDeploymentLogs` — logs every 1s, quick metrics every 2s, 1h averages every 60s
- `LogMetricsBar` — 1s clock tick for elapsed time
- `useInstallPopup` — 5s component status poll while a Knative/Redis install or uninstall is running
- `TenantSettings` (Tenancy module) — 5s tenant list poll while a tenant is being deleted
- `LoginView` — slide rotation (UI animation)

See also: [development.md](development.md) (local Reverb/WSS setup), [web-shell.md](web-shell.md) (raw WebSocket shell on the same Reverb server).
