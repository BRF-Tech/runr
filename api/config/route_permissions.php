<?php

/*
|--------------------------------------------------------------------------
| What every authenticated API route requires (docs/permissions.md)
|--------------------------------------------------------------------------
|
| Enforced for every route behind `auth:sanctum` by App\Http\Middleware\AuthorizeApiRoute; a
| route that is missing here answers 403 and fails Tests\Feature\Rbac\RouteAuthorizationTest.
| Keys are "METHOD uri" (uri without the "api/" prefix, as `php artisan route:list` prints it).
| A route with several methods is found under any of them: the PUT|PATCH update route of
| Route::apiResource (projects/{project}, knative-regions/{knative_region}) has one "PUT …" rule.
|
| Values
|   'projects.deploy'                one permission; on a route with {project} it is checked
|                                    for that project (org-wide role or project access)
|   'regions.manage|registry.manage' any of these
|   ['a', 'b']                       all of these
|   'projects.view:any'              org-wide, or on at least one project (lists filter rows)
|   '@authenticated'                 every signed-in user (own account, own notifications …)
|   '@platform'                      platform administrators only (users.is_admin)
|   '@platform:billing.view'         platform administrators; an API token of one must carry
|                                    the permission (narrowly scoped tokens for the operator's
|                                    systems, e.g. the billing API)
|   '@policy'                        the controller authorizes through a Policy
|
| A route may instead carry Laravel's `can:` middleware (->can('audit.view')); it then needs
| no entry here.
|
*/

return [

    // ── Auth / own account ──
    'POST auth/logout' => '@authenticated',
    'GET auth/me' => '@authenticated',
    'GET auth/permissions' => '@authenticated',
    'POST tutorial/complete' => '@authenticated',
    'POST contact' => '@authenticated',
    'GET account' => '@authenticated',
    'PATCH account/profile' => '@authenticated',
    'POST account/avatar' => '@authenticated',
    'DELETE account/avatar' => '@authenticated',
    'POST account/password' => '@authenticated',
    'POST account/disable' => '@authenticated',
    'DELETE account' => '@authenticated',
    'POST account/2fa/setup' => '@authenticated',
    'POST account/2fa/confirm' => '@authenticated',
    'DELETE account/2fa' => '@authenticated',
    'POST account/2fa/recovery-codes' => '@authenticated',
    'GET account/sessions' => '@authenticated',
    'DELETE account/sessions/{tokenId}' => '@authenticated',
    'POST account/sessions/revoke-all' => '@authenticated',
    'GET account/notification-preferences' => '@authenticated',
    'PUT account/notification-preferences' => '@authenticated',
    'GET notifications' => '@authenticated',
    'GET notifications/unread-count' => '@authenticated',
    'PATCH notifications/{id}/read' => '@authenticated',
    'POST notifications/read-all' => '@authenticated',
    'DELETE notifications/{id}' => '@authenticated',
    'DELETE notifications' => '@authenticated',

    // ── Projects ──
    'GET projects' => 'projects.view:any',
    'POST projects' => 'projects.create',
    'GET projects/{project}' => 'projects.view',
    // PATCH carrying environment variables needs env.edit, other fields projects.update (UpdateProjectRequest)
    'PUT projects/{project}' => 'projects.update|env.edit',
    'DELETE projects/{project}' => 'projects.delete',
    'GET projects/{project}/code' => 'projects.view',
    'POST projects/{project}/code' => 'code.edit',
    'GET projects/{project}/files' => 'projects.view',
    'POST projects/{project}/files' => 'code.edit',
    'GET projects/{project}/versions' => 'projects.view',
    'GET projects/{project}/versions/{version}/files' => 'projects.view',
    'GET projects/{project}/deployments' => 'projects.view',
    'POST projects/{project}/deploy' => 'projects.deploy',
    'GET projects/{project}/deployments/{deployment}' => 'projects.view',
    'GET projects/{project}/deployments/{deployment}/logs' => 'logs.view',
    'GET projects/{project}/deployments/{deployment}/metrics' => 'metrics.view',
    'DELETE projects/{project}/deployments/{deployment}' => 'projects.deploy',
    'PATCH projects/{project}/deployments/{deployment}/status' => 'projects.deploy',
    'POST projects/{project}/deployments/{deployment}/exec' => 'shell.exec',
    'GET projects/{project}/metrics/history' => 'metrics.view',
    'GET projects/{project}/metrics/summary' => 'metrics.view',
    'GET projects/{project}/private-connections' => 'projects.view',
    'POST projects/{project}/private-connections' => 'connections.manage',
    'GET projects/{project}/private-connections/{privateConnection}/status' => 'projects.view',
    'DELETE projects/{project}/private-connections/{privateConnection}' => 'connections.manage',
    'GET projects/{project}/domains' => 'projects.view',
    'POST projects/{project}/domains' => 'domains.manage',
    'POST projects/{project}/domains/{domain}/verify' => 'domains.manage',
    'PUT projects/{project}/domains/{domain}/tls' => 'domains.manage',
    'DELETE projects/{project}/domains/{domain}' => 'domains.manage',
    'GET projects/{project}/access' => 'projects.view',
    'POST projects/{project}/access' => 'projects.manage_access',
    'DELETE projects/{project}/access/{assignment}' => 'projects.manage_access',

    // ── Git deploy (docs/git-deploy.md; GitProjectController also needs previews.manage for
    //    preview settings; the /git remote and provider webhooks authenticate on their own) ──
    'GET projects/{project}/git' => 'git.pull',
    'PUT projects/{project}/git' => 'git.manage',
    'GET projects/{project}/git/deploys' => 'git.pull',
    'GET projects/{project}/git/versions' => 'git.pull',
    'POST projects/{project}/git/redeploy' => 'git.push',
    'POST projects/{project}/git/repos' => 'git.manage',
    'POST projects/{project}/git/connect' => 'git.manage',
    'DELETE projects/{project}/git/connect' => 'git.manage',
    'GET projects/{project}/git/installations' => 'git.pull',
    'POST projects/{project}/git/github/install' => 'git.manage',
    'POST projects/{project}/git/gitlab/authorize' => 'git.manage',
    'GET projects/{project}/previews' => 'git.pull',
    'POST projects/{project}/previews/{preview}/redeploy' => 'previews.manage',
    'DELETE projects/{project}/previews/{preview}' => 'previews.manage',
    // Single-tenant: the organization's git_providers.manage; multi-tenant: platform admins
    'GET git-providers' => 'git_providers.manage',
    'POST git-providers' => 'git_providers.manage',
    'PUT git-providers/{provider}' => 'git_providers.manage',
    'DELETE git-providers/{provider}' => 'git_providers.manage',

    // ── Bindings (docs/bindings.md; routes/bindings.php) — organization resources: a project
    //    route needs the project permission here, BindingController / ProjectBindingController
    //    add bindings.view / bindings.manage organization-wide ──
    'GET bindings/types' => 'bindings.view',
    'GET bindings/quota' => 'bindings.view',
    'GET bindings' => 'bindings.view',
    'POST bindings' => 'bindings.manage',
    'GET bindings/{binding}' => 'bindings.view',
    'PATCH bindings/{binding}' => 'bindings.manage',
    'DELETE bindings/{binding}' => 'bindings.manage',
    'POST bindings/{binding}/restore' => 'bindings.manage',
    'POST bindings/{binding}/rotate' => 'bindings.manage',
    'GET bindings/{binding}/status' => 'bindings.view',
    'GET bindings/{binding}/credentials' => 'bindings.view_credentials',
    'GET bindings/{binding}/usage' => 'bindings.view',
    'GET projects/{project}/bindings' => 'projects.view',
    'POST projects/{project}/bindings' => 'env.edit',
    'PATCH projects/{project}/bindings/{attachment}' => 'env.edit',
    'DELETE projects/{project}/bindings/{attachment}' => 'env.edit',
    'GET knative-regions/{knativeRegion}/data-services' => 'regions.view|bindings.view',
    'POST knative-regions/{knativeRegion}/data-services/{service}' => 'regions.data_services.manage',
    'DELETE knative-regions/{knativeRegion}/data-services/{service}' => 'regions.data_services.manage',

    // ── Durable workflows (routes/workflows.php, docs/workflows.md) ──
    'GET projects/{project}/workflows' => 'workflows.view',
    'PUT projects/{project}/workflows' => 'workflows.manage',
    'POST projects/{project}/workflows/sync' => 'workflows.manage',
    'GET projects/{project}/workflows/app' => 'workflows.view',
    'PATCH projects/{project}/workflows/app' => 'workflows.manage',
    'POST projects/{project}/workflows/app/rotate-key' => 'workflows.manage',
    'GET projects/{project}/workflows/app/signing-key' => 'workflows.manage',
    'GET projects/{project}/workflows/{workflow}' => 'workflows.view',
    'PATCH projects/{project}/workflows/{workflow}' => 'workflows.manage',
    'GET projects/{project}/workflows/{workflow}/runs' => 'workflows.view',
    'POST projects/{project}/workflows/{workflow}/runs' => 'workflows.run',
    'GET projects/{project}/workflow-runs' => 'workflows.view',
    'GET projects/{project}/workflow-runs/{run}' => 'workflows.view',
    'POST projects/{project}/workflow-runs/{run}/cancel' => 'workflows.run',
    'POST projects/{project}/workflow-runs/{run}/retry' => 'workflows.run',
    'POST projects/{project}/workflow-runs/{run}/replay' => 'workflows.run',
    'GET projects/{project}/workflow-usage' => 'workflows.view',
    // Tenant level: events and usage of every project (organization-wide permission)
    'GET workflow-usage' => 'workflows.view',
    'POST events' => 'events.send',
    'GET events' => 'workflows.view',
    'GET events/{event}' => 'workflows.view',

    // ── Software supply chain + data residency (routes/supply-chain.php, docs/supply-chain.md) ──
    'GET projects/{project}/security' => 'security.view',
    'GET projects/{project}/versions/{version}/security' => 'security.view',
    'POST projects/{project}/versions/{version}/security/scan' => 'security.view',
    'GET projects/{project}/versions/{version}/sbom' => 'security.view',
    'GET projects/{project}/deployments/{deployment}/security' => 'security.view',
    'GET knative-regions/{knativeRegion}/supply-chain' => 'regions.view',
    'GET knative-regions/{knativeRegion}/supply-chain/public-key' => 'regions.view',
    'GET knative-regions/{knativeRegion}/supply-chain/runtime-images' => 'regions.view',
    'GET knative-regions/{knativeRegion}/supply-chain/runtime-images/{report}/sbom' => 'regions.view',
    'GET knative-regions/{knativeRegion}/supply-chain/admission/preview' => 'regions.supply_chain.manage',
    'PUT knative-regions/{knativeRegion}/supply-chain' => 'regions.supply_chain.manage',
    'POST knative-regions/{knativeRegion}/supply-chain/signing-key' => 'regions.supply_chain.manage',
    'DELETE knative-regions/{knativeRegion}/supply-chain/signing-key/retired' => 'regions.supply_chain.manage',
    'POST knative-regions/{knativeRegion}/supply-chain/scanner-db/refresh' => 'regions.supply_chain.manage',
    'POST knative-regions/{knativeRegion}/supply-chain/admission' => 'regions.supply_chain.manage',
    'DELETE knative-regions/{knativeRegion}/supply-chain/admission' => 'regions.supply_chain.manage',
    'GET model-services/{service}/security' => 'models.view',
    'GET model-services/{service}/sbom' => 'models.view',
    'GET residency' => '@authenticated', // own organization's residency (region pickers)
    // Residency fields need tenants.residency.manage, gate fields regions.supply_chain.manage (ResidencyController)
    'GET tenants/{tenant}/security-policy' => 'tenants.residency.manage|regions.supply_chain.manage',
    'PUT tenants/{tenant}/security-policy' => 'tenants.residency.manage|regions.supply_chain.manage',

    // ── AI agents (docs/mcp.md; routes/mcp.php, routes/mcp-api.php) ──
    // The RunR MCP server (/mcp, outside /api): every tool decides with the permissions it wraps
    // (ToolRegistry hides tools the token does not cover; the API routes they call authorize
    // again). The same keys cover GET /api/mcp (connection settings).
    'POST mcp' => '@authenticated',
    'GET mcp' => '@authenticated',
    'DELETE mcp' => '@authenticated',
    'GET mcp/tools' => '@authenticated',
    // OAuth: connected clients are the caller's own tokens; approving a consent creates a token
    'GET mcp/clients' => 'tokens.manage',
    'DELETE mcp/clients/{client}' => 'tokens.manage',
    'GET oauth2/requests/{id}' => 'tokens.manage',
    'POST oauth2/requests/{id}/approve' => 'tokens.manage',
    'POST oauth2/requests/{id}/deny' => '@authenticated',
    // Hosted MCP servers
    'GET mcp-servers/templates' => '@authenticated',
    'GET mcp-servers' => 'projects.view:any',
    'GET projects/{project}/mcp-server' => 'projects.view',
    'PUT projects/{project}/mcp-server' => 'mcp.servers.manage',
    'DELETE projects/{project}/mcp-server' => 'mcp.servers.manage',
    'POST projects/{project}/mcp-server/rotate-key' => 'mcp.servers.manage',
    'GET projects/{project}/mcp-server/calls' => 'logs.view',
    'POST projects/{project}/mcp-server/inspector' => 'mcp.servers.call',

    // ── Gateways (per user; GatewayPolicy) ──
    'GET gateways' => '@authenticated',
    'GET gateways/{gateway}' => '@policy',
    'PATCH gateways/{gateway}' => '@policy',
    'GET gateways/{gateway}/routes' => '@policy',
    'POST gateways/{gateway}/routes' => '@policy',
    'PATCH gateways/{gateway}/routes/{route}' => '@policy',
    'DELETE gateways/{gateway}/routes/{route}' => '@policy',

    // ── Private broadcast channels: each channel decides (routes/channels.php, ChannelAccess) ──
    'GET broadcasting/auth' => '@authenticated',
    'POST broadcasting/auth' => '@authenticated',

    // ── Secret env vars (docs/security.md): names for env.view, values write-only ──
    'GET projects/{project}/secrets' => 'env.view',
    'PUT projects/{project}/secrets/{name}' => 'env.edit',
    'DELETE projects/{project}/secrets/{name}' => 'env.edit',
    'GET projects/{project}/secrets/{name}/value' => 'secrets.view_values',

    // ── Audit log (docs/security.md) ──
    'GET audit-events' => 'audit.view',
    'GET audit-events/export' => 'audit.export',
    'GET audit-events/{id}' => 'audit.view',
    'GET audit-settings' => 'audit.view',
    // Retention is install-wide: the organization's settings.manage in single-tenant; with
    // tenancy AuditEventController also requires a platform admin outside any tenant
    'PUT audit-settings' => 'settings.manage',

    // ── Organization settings ──
    'GET settings' => '@authenticated',
    'PATCH settings' => 'settings.manage',
    'POST demo' => 'settings.manage',
    'DELETE demo' => 'settings.manage',

    // ── Regions ──
    'POST knative-regions/validate-kubeconfig' => 'regions.manage',
    'POST knative-regions/preview-nodes' => 'regions.manage',
    'POST knative-regions/check-namespace-preview' => 'regions.manage',
    'POST knative-regions/preview-storage-classes' => 'regions.manage',
    'GET knative-regions' => 'regions.view',
    'GET knative-regions/{knative_region}' => 'regions.view',
    'POST knative-regions' => 'regions.manage',
    'PUT knative-regions/{knative_region}' => 'regions.manage',
    'DELETE knative-regions/{knative_region}' => 'regions.manage',
    'GET knative-regions/{knativeRegion}/kubeconfig' => 'regions.manage',
    'PUT knative-regions/{knativeRegion}/settings' => 'regions.manage',
    'GET knative-regions/{knativeRegion}/capacity' => 'regions.view',
    'PUT knative-regions/{knativeRegion}/capacity' => 'regions.manage',
    'PUT knative-regions/{knativeRegion}/function-tls' => 'regions.manage',
    'GET knative-regions/{knativeRegion}/skupper' => 'regions.view',
    'POST knative-regions/{knativeRegion}/skupper/site' => 'regions.manage',
    'GET knative-regions/{knativeRegion}/knative-status' => 'regions.view',
    'POST knative-regions/{knativeRegion}/install-knative' => 'regions.manage',
    'POST knative-regions/{knativeRegion}/uninstall-knative' => 'regions.manage',
    'POST knative-regions/{knativeRegion}/install-operator' => 'regions.manage',
    'POST knative-regions/{knativeRegion}/install-serving' => 'regions.manage',
    'POST knative-regions/{knativeRegion}/uninstall-operator' => 'regions.manage',
    'POST knative-regions/{knativeRegion}/uninstall-serving' => 'regions.manage',
    'POST knative-regions/{knativeRegion}/detect-components' => 'regions.manage',
    'GET knative-regions/{knativeRegion}/check-namespace' => 'regions.view',
    'GET knative-regions/{knativeRegion}/compatible-versions' => 'regions.view',
    'GET knative-regions/{knativeRegion}/component-status' => 'regions.view',
    'GET knative-regions/{knativeRegion}/nodes' => 'regions.view',
    'POST knative-regions/{knativeRegion}/workers' => 'regions.manage',
    'POST knative-regions/{knativeRegion}/test-redis' => 'regions.manage',
    'POST knative-regions/{knativeRegion}/redis' => 'regions.manage',
    'DELETE knative-regions/{knativeRegion}/redis' => 'regions.manage',
    'GET knative-regions/{knativeRegion}/redis-status' => 'regions.view',
    'GET knative-regions/{knativeRegion}/kata-shims' => 'regions.view',
    'POST knative-regions/{knativeRegion}/install-sandbox' => 'sandbox.manage',
    'POST knative-regions/{knativeRegion}/uninstall-sandbox' => 'sandbox.manage',
    'GET knative-regions/{knativeRegion}/sandbox-status' => 'regions.view',
    'PUT knative-regions/{knativeRegion}/sandbox-mapping' => 'sandbox.manage',
    // WebAssembly support (runwasi shims on the region's nodes — a sandbox runtime like gVisor / Kata)
    'GET knative-regions/{knativeRegion}/wasm' => 'regions.view',
    'POST knative-regions/{knativeRegion}/wasm' => 'sandbox.manage',
    'DELETE knative-regions/{knativeRegion}/wasm' => 'sandbox.manage',
    'POST knative-regions/{knativeRegion}/migrate' => 'regions.manage',
    'GET knative-regions/{knativeRegion}/migration-status' => 'regions.view',
    'POST knative-regions/{knativeRegion}/complete-migration' => 'regions.manage',
    'POST knative-regions/{knativeRegion}/rollback-migration' => 'regions.manage',
    'GET knative-regions/{knativeRegion}/registry-status' => 'regions.view',
    'DELETE knative-regions/{knativeRegion}/registry' => 'registry.manage',
    'POST knative-regions/{knativeRegion}/demo-projects' => 'regions.manage',
    'DELETE knative-regions/{knativeRegion}/demo-projects' => 'regions.manage',

    // ── GPU support of regions (routes/ai.php, docs/ai.md) ──
    'GET knative-regions/{knativeRegion}/gpu' => 'regions.view',
    'POST knative-regions/{knativeRegion}/gpu/install' => 'regions.gpu.manage',
    'POST knative-regions/{knativeRegion}/gpu/uninstall' => 'regions.gpu.manage',
    'PUT knative-regions/{knativeRegion}/gpu/settings' => 'regions.gpu.manage',
    'GET knative-regions/{knativeRegion}/model-storage' => 'regions.view',
    'PUT knative-regions/{knativeRegion}/model-storage' => 'regions.gpu.manage',

    // ── Uploaded images (routes/images.php, docs/images.md) ──
    'GET images' => 'images.view',
    'GET images/quota' => 'images.view',
    'GET images/{image}' => 'images.view',
    'DELETE images/{image}' => 'images.delete',
    'GET images/uploads' => 'images.upload',
    'POST images/uploads' => 'images.upload',
    'GET images/uploads/{upload}' => 'images.upload',
    'PUT images/uploads/{upload}/chunk' => 'images.upload',
    'POST images/uploads/{upload}/complete' => 'images.upload',
    'DELETE images/uploads/{upload}' => 'images.upload',

    // ── Models (routes/ai.php, docs/huggingface.md); the controllers refine POST models and
    //    POST models/{model}/snapshots: imports (model volume, OCI artifact) need models.manage ──
    'GET models/engines' => 'models.view',
    'GET models/hub/search' => 'models.view',
    'GET models/hub/info' => 'models.view',
    'GET models/hub-token' => 'models.view',
    'PUT models/hub-token' => 'models.manage',
    'DELETE models/hub-token' => 'models.manage',
    'GET models/gpu-quota' => 'models.view',
    'GET models' => 'models.view',
    'POST models' => 'models.deploy|models.manage',
    'GET models/{model}' => 'models.view',
    'PATCH models/{model}' => 'models.deploy',
    'DELETE models/{model}' => 'models.manage',
    'GET models/{model}/snapshots' => 'models.view',
    'POST models/{model}/snapshots' => 'models.deploy|models.manage',
    'DELETE models/{model}/snapshots/{snapshot}' => 'models.manage',
    'GET models/{model}/snapshots/{snapshot}/logs' => 'models.view',
    'POST models/{model}/uploads' => 'models.deploy',
    'GET models/{model}/uploads/{upload}' => 'models.view',
    'PUT models/{model}/uploads/{upload}/chunk' => 'models.deploy',
    'POST models/{model}/uploads/{upload}/complete' => 'models.deploy',
    'DELETE models/{model}/uploads/{upload}' => 'models.deploy',
    'GET model-services' => 'models.view',
    'POST model-services' => 'models.deploy',
    'GET model-services/{service}' => 'models.view',
    'PATCH model-services/{service}' => 'models.deploy',
    'DELETE model-services/{service}' => 'models.deploy',
    'POST model-services/{service}/deploy' => 'models.deploy',
    'POST model-services/{service}/stop' => 'models.deploy',
    'GET model-services/{service}/logs' => 'models.view',
    'POST model-services/{service}/invoke' => 'models.view',
    'GET model-services/{service}/bindings' => 'models.view',
    // Models of a project: checked for that project here; ProjectModelBindingController also
    // requires models.view (read) / models.deploy (changes) organization-wide
    'GET projects/{project}/models' => 'projects.view',
    'POST projects/{project}/models' => 'projects.update',
    'PATCH projects/{project}/models/{modelBinding}' => 'projects.update',
    'DELETE projects/{project}/models/{modelBinding}' => 'projects.update',

    // ── Runtimes (RuntimeController refines: platform runtimes need runtimes.manage, the
    //    organization's custom runtimes runtimes.use_custom while custom runtimes are enabled) ──
    'GET runtimes' => '@authenticated', // the catalog project forms pick from
    'GET runtimes/{runtime}' => '@authenticated',
    'GET runtimes/{runtime}/template' => '@authenticated',
    'POST runtimes/validate' => 'runtimes.manage|runtimes.use_custom',
    'POST runtimes' => 'runtimes.manage|runtimes.use_custom',
    'PUT runtimes/{runtime}' => 'runtimes.manage|runtimes.use_custom',
    'DELETE runtimes/{runtime}' => 'runtimes.manage|runtimes.use_custom',
    'GET runtimes/{runtime}/builds' => 'runtimes.manage|runtimes.use_custom',
    'POST runtimes/{runtime}/builds' => 'runtimes.manage|runtimes.use_custom',
    'POST runtimes/{runtime}/test-deploy' => 'runtimes.manage|runtimes.use_custom',

    // ── Registry setup (creates a region with its registry) ──
    'POST registry-setup/start' => ['regions.manage', 'registry.manage'],
    'GET registry-setup/{token}/status' => 'regions.view',
    'POST registry-setup/{token}/retry' => ['regions.manage', 'registry.manage'],
    'POST registry-setup/test-pvc' => 'registry.manage',
    'POST registry-setup/test-s3' => 'registry.manage',
    'POST registry-setup/test-external' => 'registry.manage',

    // ── Members (single-tenant user management; multi-tenant: the active tenant's members) ──
    'GET users' => 'members.view',
    'POST users' => 'members.invite',
    'PATCH users/{user}' => 'members.update',
    'DELETE users/{user}' => 'members.remove',
    'POST users/{user}/reset-2fa' => 'members.update',
    'GET members' => 'members.view',
    'PUT members/{user}/role' => 'roles.manage',

    // ── Roles, groups ──
    'GET permissions' => '@authenticated',
    'GET roles' => '@authenticated',
    'POST roles' => 'roles.manage',
    'PUT roles/default' => 'roles.manage',
    'GET roles/{role}' => '@authenticated',
    'PUT roles/{role}' => 'roles.manage',
    'DELETE roles/{role}' => 'roles.manage',
    'GET groups' => 'members.view',
    'POST groups' => 'groups.manage',
    'GET groups/{group}' => 'members.view',
    'PUT groups/{group}' => 'groups.manage',
    'DELETE groups/{group}' => 'groups.manage',
    'POST groups/{group}/members' => 'groups.manage',
    'DELETE groups/{group}/members/{user}' => 'groups.manage',

    // ── API tokens (own tokens; a token never exceeds its owner's permissions) ──
    'GET tokens' => 'tokens.manage',
    'POST tokens' => 'tokens.manage',
    'DELETE tokens/{token}' => 'tokens.manage',

    // ── Single sign-on (AuthProvider module) ──
    'GET auth-providers' => 'sso.manage',
    'POST auth-providers' => 'sso.manage',
    'PUT auth-providers/{authProvider}' => 'sso.manage',
    'DELETE auth-providers/{authProvider}' => 'sso.manage',
    'PATCH auth-providers/{authProvider}/toggle' => 'sso.manage',
    'GET sso/group-mappings' => 'sso.manage',
    'POST sso/group-mappings' => 'sso.manage',
    'PUT sso/group-mappings/{mapping}' => 'sso.manage',
    'DELETE sso/group-mappings/{mapping}' => 'sso.manage',
    'GET social-accounts' => '@authenticated',
    'DELETE social-accounts/{socialAccount}' => '@authenticated',
    'GET oauth/{provider}/link' => '@authenticated',

    // ── Support tickets (Ticket module) ──
    'GET support-tickets' => 'tickets.view',
    'POST support-tickets' => 'tickets.create',
    'GET support-tickets/stats' => 'tickets.view',
    'GET support-tickets/suggestions' => 'tickets.view',
    'GET support-tickets/{supportTicket}' => 'tickets.view',
    'POST support-tickets/{supportTicket}/replies' => 'tickets.create',
    'GET support-tickets/{supportTicket}/attachments/{filename}' => 'tickets.view',
    'POST support-tickets/{supportTicket}/read' => 'tickets.view',
    'GET admin/support-tickets' => 'tickets.manage',
    'GET admin/support-tickets/stats' => 'tickets.manage',
    'GET admin/support-tickets/{id}' => 'tickets.manage',
    'PATCH admin/support-tickets/{id}/status' => 'tickets.manage',
    'PATCH admin/support-tickets/{id}/assign' => 'tickets.manage',
    'PATCH admin/support-tickets/{id}/tags' => 'tickets.manage',
    'POST admin/support-tickets/{id}/replies' => 'tickets.manage',
    'GET admin/support-tickets/{id}/attachments/{filename}' => 'tickets.manage',
    'POST admin/support-tickets/{id}/read' => 'tickets.manage',

    // ── Tenancy module (multi-tenant) ──
    'GET tenant/current' => '@authenticated',
    'POST tenant/switch' => '@authenticated',
    'GET tenants/{tenant}/users' => 'members.view',
    'POST tenants/{tenant}/users' => 'members.invite',
    'POST tenants/{tenant}/users/search' => 'members.invite',
    'PATCH tenants/{tenant}/users/{user}' => 'roles.manage',
    'DELETE tenants/{tenant}/users/{user}' => 'members.remove',
    'POST tenants/{tenant}/users/{user}/reset-2fa' => 'members.update',
    'POST tenants/{tenant}/invitations/{invitation}/resend' => 'members.invite',
    'DELETE tenants/{tenant}/invitations/{invitation}' => 'members.invite',
    'GET tenants' => '@platform',
    'POST tenants' => '@platform',
    'GET tenants/{tenant}' => '@platform',
    'PATCH tenants/{tenant}' => '@platform',
    'DELETE tenants/{tenant}' => '@platform',
    'GET tenants/{tenant}/regions' => '@platform',
    'POST tenants/{tenant}/regions' => '@platform',
    'PATCH tenants/{tenant}/regions/{region}' => '@platform',
    'DELETE tenants/{tenant}/regions/{region}' => '@platform',
    'PUT tenants/{tenant}/regions/{region}/gpu-quota' => '@platform',
    'POST users/{user}/toggle-admin' => '@platform',
    'GET resource-usage' => '@platform',
    'POST impersonate/stop' => '@platform',
    'POST impersonate/{tenant}' => '@platform',
    'GET cost-configs' => '@platform',
    'POST cost-configs' => '@platform',
    'PATCH cost-configs/{id}' => '@platform',
    'DELETE cost-configs/{id}' => '@platform',
    'GET tenants/{tenant}/billing/summary' => '@platform',
    'GET tenants/{tenant}/billing/usage' => '@platform',
    'GET tenants/{tenant}/capacity-deals' => '@platform',
    'POST tenants/{tenant}/capacity-deals' => '@platform',
    'PATCH tenants/{tenant}/capacity-deals/{deal}' => '@platform',
    'GET regions/{region}/profit-loss' => '@platform',
    'GET tenants/{tenant}/binding-quotas' => '@platform',
    'PUT tenants/{tenant}/binding-quotas' => '@platform',

    // ── Observability (routes/observability.php, docs/observability.md) ──
    'GET observability/regions/{knativeRegion}/tracing' => 'regions.view',
    'PUT observability/regions/{knativeRegion}/tracing' => 'regions.observability.manage',
    'POST observability/regions/{knativeRegion}/tracing/install' => 'regions.observability.manage',
    'DELETE observability/regions/{knativeRegion}/tracing' => 'regions.observability.manage',
    'GET projects/{project}/observability' => 'projects.view',
    'PUT projects/{project}/observability/tracing' => 'projects.update',
    'PUT projects/{project}/observability/replay' => 'replay.manage',
    'GET projects/{project}/traces' => 'traces.view',
    'GET projects/{project}/traces/{traceId}' => 'traces.view',
    'GET projects/{project}/traces/{traceId}/logs' => ['traces.view', 'logs.view'],
    'GET projects/{project}/replays' => 'replay.manage',
    'DELETE projects/{project}/replays' => 'replay.manage',
    'GET projects/{project}/replays/{capture}' => 'replay.manage',
    'POST projects/{project}/replays/{capture}/replay' => 'replay.manage',
    'DELETE projects/{project}/replays/{capture}' => 'replay.manage',
    'GET projects/{project}/uptime-checks' => 'metrics.view',
    'POST projects/{project}/uptime-checks' => 'uptime.manage',
    'PUT projects/{project}/uptime-checks/{check}' => 'uptime.manage',
    'DELETE projects/{project}/uptime-checks/{check}' => 'uptime.manage',
    'GET projects/{project}/uptime-checks/{check}/results' => 'metrics.view',
    'POST projects/{project}/uptime-checks/{check}/run' => 'uptime.manage',
    'GET projects/{project}/uptime-incidents' => 'metrics.view',
    'PATCH projects/{project}/uptime-incidents/{incident}' => 'uptime.manage',
    'GET observability/status-page' => 'status_page.manage',
    'PUT observability/status-page' => 'status_page.manage',
    'GET observability/alert-channels' => 'alerts.manage',
    'POST observability/alert-channels' => 'alerts.manage',
    'PUT observability/alert-channels/{channel}' => 'alerts.manage',
    'DELETE observability/alert-channels/{channel}' => 'alerts.manage',
    'POST observability/alert-channels/{channel}/test' => 'alerts.manage',
    'GET observability/alert-rules' => 'alerts.manage',
    'POST observability/alert-rules' => 'alerts.manage',
    'PUT observability/alert-rules/{rule}' => 'alerts.manage',
    'DELETE observability/alert-rules/{rule}' => 'alerts.manage',
    'GET observability/alert-events' => 'alerts.manage',
    'GET observability/alert-deliveries' => 'alerts.manage',
    'POST observability/alert-deliveries/{delivery}/retry' => 'alerts.manage',
    // ── White-label (docs/white-label.md; public: branding, brand images, signup verification
    //    and the public plan list are outside auth:sanctum) ──
    // The install's brand / signup: single-tenant by permission; multi-tenant platform
    // administrators (BrandingController refines branding.manage; signup.manage is single-tenant only)
    'GET admin/branding' => 'branding.manage',
    'PUT admin/branding' => 'branding.manage',
    'POST admin/branding/assets/{kind}' => 'branding.manage',
    'DELETE admin/branding/assets/{kind}' => 'branding.manage',
    'GET admin/signup-settings' => 'signup.manage',
    'PUT admin/signup-settings' => 'signup.manage',
    // Multi-tenant: the tenant's own brand, onboarding wizard, plan, charges
    'GET tenant/branding' => 'branding.manage',
    'PUT tenant/branding' => 'branding.manage',
    'POST tenant/branding/assets/{kind}' => 'branding.manage',
    'DELETE tenant/branding/assets/{kind}' => 'branding.manage',
    'GET onboarding' => '@authenticated',
    'POST onboarding' => 'settings.manage',
    'GET tenant/subscription' => '@authenticated', // the plan page of every member
    'GET tenant/billing' => 'billing.view',
    // Plans and billing settings: platform administrators
    'GET admin/plans' => '@platform',
    'POST admin/plans' => '@platform',
    'PATCH admin/plans/{plan}' => '@platform',
    'DELETE admin/plans/{plan}' => '@platform',
    'GET admin/billing-settings' => '@platform',
    'PUT admin/billing-settings' => '@platform',

    // ── Billing API (Tenancy routes/billing.php, docs/billing.md): the operator's billing
    //    system — platform administrators, tokens with billing.view (reads) / billing.manage (writes) ──
    'GET billing/plans' => '@platform:billing.view',
    'GET billing/tenants' => '@platform:billing.view',
    'GET billing/tenants/{tenant}' => '@platform:billing.view',
    'GET billing/tenants/{tenant}/usage' => '@platform:billing.view',
    'GET billing/tenants/{tenant}/subscription' => '@platform:billing.view',
    'PUT billing/tenants/{tenant}/subscription' => '@platform:billing.manage',
    'POST billing/tenants/{tenant}/charges' => '@platform:billing.manage',
    'POST billing/tenants/{tenant}/purchases' => '@platform:billing.manage',
    'GET billing/charges' => '@platform:billing.view',
    'GET billing/charges/{charge}' => '@platform:billing.view',
    'PATCH billing/charges/{charge}' => '@platform:billing.manage',
    'POST billing/charges/{charge}/void' => '@platform:billing.manage',
    'GET billing/purchases' => '@platform:billing.view',
    'GET billing/purchases/{purchase}' => '@platform:billing.view',
    'POST billing/purchases/{purchase}/void' => '@platform:billing.manage',
    'GET billing/events' => '@platform:billing.view',
    'POST billing/events/test' => '@platform:billing.manage',
    'POST billing/events/{event}/redeliver' => '@platform:billing.manage',

    // ── Sandbox API (routes/sandboxes.php, docs/sandbox-api.md) ──
    // Lifecycle
    'GET sandboxes' => 'sandboxes.view',
    'POST sandboxes' => 'sandboxes.create',
    'GET sandboxes/{sandbox}' => 'sandboxes.view',
    'DELETE sandboxes/{sandbox}' => 'sandboxes.create',
    'POST sandboxes/{sandbox}/timeout' => 'sandboxes.create',
    'POST sandboxes/{sandbox}/keepalive' => 'sandboxes.exec',
    'POST sandboxes/{sandbox}/pause' => 'sandboxes.exec',
    'POST sandboxes/{sandbox}/resume' => 'sandboxes.exec',
    'PUT sandboxes/{sandbox}/network' => 'sandboxes.create',
    'GET sandboxes/{sandbox}/logs' => 'sandboxes.view',
    // Exec as JSON / Server-Sent Events, the token of the exec WebSocket (/ws/sandbox-exec),
    // the code interpreter, the terminal session of the web shell (/ws/shell)
    'POST sandboxes/{sandbox}/exec' => 'sandboxes.exec',
    'POST sandboxes/{sandbox}/exec/ws' => 'sandboxes.exec',
    'POST sandboxes/{sandbox}/run' => 'sandboxes.exec',
    'POST sandboxes/{sandbox}/terminal' => 'sandboxes.exec',
    // Background processes (logs?follow=1 is SSE)
    'GET sandboxes/{sandbox}/processes' => 'sandboxes.exec',
    'POST sandboxes/{sandbox}/processes' => 'sandboxes.exec',
    'GET sandboxes/{sandbox}/processes/{process}' => 'sandboxes.exec',
    'GET sandboxes/{sandbox}/processes/{process}/logs' => 'sandboxes.exec',
    'DELETE sandboxes/{sandbox}/processes/{process}' => 'sandboxes.exec',
    // Files
    'GET sandboxes/{sandbox}/files' => 'sandboxes.exec',
    'DELETE sandboxes/{sandbox}/files' => 'sandboxes.exec',
    'GET sandboxes/{sandbox}/files/content' => 'sandboxes.exec',
    'PUT sandboxes/{sandbox}/files/content' => 'sandboxes.exec',
    'POST sandboxes/{sandbox}/files/mkdir' => 'sandboxes.exec',
    'GET sandboxes/{sandbox}/files/archive' => 'sandboxes.exec',
    'PUT sandboxes/{sandbox}/files/archive' => 'sandboxes.exec',
    // Region settings (+ region.mutable), warm pools, quotas, metrics, usage. Quotas of every
    // organization and the Prometheus metrics are platform-level: SandboxAdminController
    // additionally requires a platform administrator when tenancy is on
    'GET sandbox-regions' => 'sandboxes.view',
    'PUT sandbox-regions/{knativeRegion}' => 'sandboxes.manage',
    'GET sandbox-pools' => 'sandboxes.view',
    'POST sandbox-pools' => 'sandboxes.manage',
    'PUT sandbox-pools/{pool}' => 'sandboxes.manage',
    'DELETE sandbox-pools/{pool}' => 'sandboxes.manage',
    'GET sandbox-quotas' => 'sandboxes.view',
    'GET sandbox-quotas/tenants' => 'sandboxes.manage',
    'PUT sandbox-quotas/{tenant}' => 'sandboxes.manage',
    'DELETE sandbox-quotas/{tenant}' => 'sandboxes.manage',
    'GET sandbox-metrics' => 'sandboxes.view',
    'GET sandbox-metrics/prometheus' => 'sandboxes.manage',
    'GET sandbox-usage' => 'sandboxes.view',
];
