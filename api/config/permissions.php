<?php

/*
|--------------------------------------------------------------------------
| Permission catalog and built-in roles (docs/permissions.md)
|--------------------------------------------------------------------------
|
| The one place that defines what can be granted. Every permission is also a Laravel Gate
| ability ($user->can('projects.deploy', $project), Gate::authorize('audit.view'),
| ->can('audit.export') on a route); every authenticated API route is mapped to one of them in
| config/route_permissions.php (RouteAuthorizationTest fails for a route that is not).
|
| scope    'org'      applies to the whole organization (a tenant; the install in single-tenant)
|          'project'  can also be granted on individual projects ("project access")
| edition  'all' (default) | 'multi' (multi-tenant only) | 'single' (single-tenant only)
| implies  permissions that always come with this one (added when a role is saved/resolved)
|
*/

return [

    'permissions' => [
        // ── Projects ──
        'projects.view' => ['group' => 'projects', 'scope' => 'project'],
        'projects.create' => ['group' => 'projects', 'scope' => 'org'],
        'projects.update' => ['group' => 'projects', 'scope' => 'project', 'implies' => ['projects.view']],
        'projects.delete' => ['group' => 'projects', 'scope' => 'project', 'implies' => ['projects.view']],
        'projects.deploy' => ['group' => 'projects', 'scope' => 'project', 'implies' => ['projects.view']],
        'projects.manage_access' => ['group' => 'projects', 'scope' => 'project', 'implies' => ['projects.view']],
        // Stop a project (workloads off, no deploys) and resume it, with a reason (docs/billing.md#project-stop-and-resume)
        'projects.stop' => ['group' => 'projects', 'scope' => 'org'],
        'code.edit' => ['group' => 'projects', 'scope' => 'project', 'implies' => ['projects.view']],
        // Environments (docs/environments.md): create / change / delete a project's environments;
        // request a promotion of an environment's version to the next one; approve (or reject)
        // promotions into an environment that requires approval — production by default
        'environments.manage' => ['group' => 'projects', 'scope' => 'project', 'implies' => ['projects.view']],
        'environments.promote' => ['group' => 'projects', 'scope' => 'project', 'implies' => ['projects.view']],
        'environments.approve' => ['group' => 'projects', 'scope' => 'project', 'implies' => ['projects.view']],

        // ── Git (docs/git-deploy.md) ──
        'git.pull' => ['group' => 'git', 'scope' => 'project', 'implies' => ['projects.view']],
        'git.push' => ['group' => 'git', 'scope' => 'project', 'implies' => ['git.pull']],
        'git.manage' => ['group' => 'git', 'scope' => 'project', 'implies' => ['git.pull']],
        'previews.manage' => ['group' => 'git', 'scope' => 'project', 'implies' => ['git.pull']],
        // GitHub App / GitLab / Forgejo instances are shared by every tenant: in multi-tenant
        // installs the platform administrators configure them
        'git_providers.manage' => ['group' => 'git', 'scope' => 'org', 'edition' => 'single'],

        // ── Configuration ──
        'env.view' => ['group' => 'configuration', 'scope' => 'project', 'implies' => ['projects.view']],
        'env.edit' => ['group' => 'configuration', 'scope' => 'project', 'implies' => ['env.view']],
        'secrets.view_values' => ['group' => 'configuration', 'scope' => 'project', 'implies' => ['env.view']],
        // The organization's secret stores (docs/secret-stores.md): seeing them (to refer to one from
        // a project's secret variables) and registering them with the trust they need
        'secret_stores.view' => ['group' => 'configuration', 'scope' => 'org'],
        'secret_stores.manage' => ['group' => 'configuration', 'scope' => 'org', 'implies' => ['secret_stores.view']],
        'domains.manage' => ['group' => 'configuration', 'scope' => 'project', 'implies' => ['projects.view']],
        // Access mode (public / IP allow list / internal only) and the internal callers a project
        // accepts, other organizations' included (docs/internal-network.md)
        'network.manage' => ['group' => 'configuration', 'scope' => 'project', 'implies' => ['projects.view']],
        'connections.manage' => ['group' => 'configuration', 'scope' => 'project', 'implies' => ['projects.view']],

        // ── Operations ──
        'shell.exec' => ['group' => 'operations', 'scope' => 'project', 'implies' => ['projects.view']],
        'logs.view' => ['group' => 'operations', 'scope' => 'project', 'implies' => ['projects.view']],
        'metrics.view' => ['group' => 'operations', 'scope' => 'project', 'implies' => ['projects.view']],
        // Observability (docs/observability.md): traces, request replay (captured request bodies),
        // uptime checks; the organization's alert channels / rules and public status page
        'traces.view' => ['group' => 'operations', 'scope' => 'project', 'implies' => ['projects.view']],
        'replay.manage' => ['group' => 'operations', 'scope' => 'project', 'implies' => ['projects.view']],
        'uptime.manage' => ['group' => 'operations', 'scope' => 'project', 'implies' => ['metrics.view']],
        'alerts.manage' => ['group' => 'operations', 'scope' => 'org'],
        'status_page.manage' => ['group' => 'operations', 'scope' => 'org'],

        // ── Workflows (durable workflows, docs/workflows.md) ──
        'workflows.view' => ['group' => 'workflows', 'scope' => 'project', 'implies' => ['projects.view']],
        'workflows.run' => ['group' => 'workflows', 'scope' => 'project', 'implies' => ['workflows.view']],
        'workflows.manage' => ['group' => 'workflows', 'scope' => 'project', 'implies' => ['workflows.view']],
        'events.send' => ['group' => 'workflows', 'scope' => 'org'],
        // Fan-out topics of the organization (docs/triggers.md#topics); publishing is events.send
        'topics.manage' => ['group' => 'workflows', 'scope' => 'org'],

        // ── Sandboxes (Sandbox API, docs/sandbox-api.md) ──
        'sandboxes.view' => ['group' => 'sandboxes', 'scope' => 'org'],
        'sandboxes.create' => ['group' => 'sandboxes', 'scope' => 'org', 'implies' => ['sandboxes.view']],
        'sandboxes.exec' => ['group' => 'sandboxes', 'scope' => 'org', 'implies' => ['sandboxes.view']],
        // Region sandbox settings (with the region's ownership) and warm pools; quotas of every
        // organization and the Prometheus metrics stay with the platform (SandboxAdminController)
        'sandboxes.manage' => ['group' => 'sandboxes', 'scope' => 'org', 'implies' => ['sandboxes.view']],

        // ── Security (software supply chain, docs/supply-chain.md) ──
        'security.view' => ['group' => 'security', 'scope' => 'project', 'implies' => ['projects.view']],
        'security.override_policy' => ['group' => 'security', 'scope' => 'project', 'implies' => ['security.view', 'projects.deploy']],

        // ── Infrastructure ──
        'regions.view' => ['group' => 'infrastructure', 'scope' => 'org'],
        'regions.manage' => ['group' => 'infrastructure', 'scope' => 'org', 'implies' => ['regions.view']],
        'sandbox.manage' => ['group' => 'infrastructure', 'scope' => 'org', 'implies' => ['regions.view']],
        'registry.manage' => ['group' => 'infrastructure', 'scope' => 'org', 'implies' => ['regions.view']],
        // Tracing backend of regions (OpenTelemetry Collector + Tempo, reuse, external export)
        'regions.observability.manage' => ['group' => 'infrastructure', 'scope' => 'org', 'implies' => ['regions.view']],
        // Region data services bindings run on: KV Redis, object store, CloudNativePG (docs/bindings.md)
        'regions.data_services.manage' => ['group' => 'infrastructure', 'scope' => 'org', 'implies' => ['regions.view']],
        // Region SBOM / scan / signing key / admission policy settings; an organization's policy gate
        'regions.supply_chain.manage' => ['group' => 'infrastructure', 'scope' => 'org', 'implies' => ['regions.view']],
        // Platform runtimes are shared by every tenant: in multi-tenant installs the platform
        // administrators manage them; organizations register custom runtimes (use_custom, while
        // the platform setting custom_runtimes_enabled is on — App\Providers\RuntimeServiceProvider)
        'runtimes.manage' => ['group' => 'infrastructure', 'scope' => 'org', 'edition' => 'single'],
        'runtimes.use_custom' => ['group' => 'infrastructure', 'scope' => 'org'],
        // GPU support and the model volume of regions (docs/ai.md); platform regions of a
        // multi-tenant install stay with the platform administrators (region.mutable)
        'regions.gpu.manage' => ['group' => 'infrastructure', 'scope' => 'org', 'implies' => ['regions.view']],

        // ── Images: container images and WebAssembly modules uploaded into region registries
        //    (docs/images.md); using one in a project needs nothing beyond creating / changing it ──
        'images.view' => ['group' => 'images', 'scope' => 'org'],
        'images.upload' => ['group' => 'images', 'scope' => 'org', 'implies' => ['images.view']],
        'images.delete' => ['group' => 'images', 'scope' => 'org', 'implies' => ['images.view']],

        // ── Models (Hugging Face models and model services, docs/huggingface.md) ──
        'models.view' => ['group' => 'models', 'scope' => 'org'],
        'models.deploy' => ['group' => 'models', 'scope' => 'org', 'implies' => ['models.view']],
        'models.manage' => ['group' => 'models', 'scope' => 'org', 'implies' => ['models.view']],

        // ── Data: bindings — managed KV / object storage / Postgres / external credentials that
        //    projects attach to (docs/bindings.md). Organization resources: attaching one to a
        //    project also needs env.edit on that project.
        'bindings.view' => ['group' => 'data', 'scope' => 'org'],
        'bindings.manage' => ['group' => 'data', 'scope' => 'org', 'implies' => ['bindings.view']],
        'bindings.view_credentials' => ['group' => 'data', 'scope' => 'org', 'implies' => ['bindings.view']],

        // ── AI agents (docs/mcp.md): projects served as MCP servers behind the RunR gateway. The
        //    RunR MCP server itself needs no permission of its own — its tools follow the ones they
        //    wrap, and connecting a client creates an API token (tokens.manage).
        'mcp.servers.manage' => ['group' => 'mcp', 'scope' => 'project', 'implies' => ['projects.view']],
        'mcp.servers.call' => ['group' => 'mcp', 'scope' => 'project', 'implies' => ['projects.view']],

        // ── API product (docs/api-product.md): consumers, keys, plans and usage of a gateway follow
        //    the gateway (its owner); the organization's consumers and plans — accepted on every
        //    gateway — and the usage of every gateway need this ──
        'api_products.manage' => ['group' => 'configuration', 'scope' => 'org'],

        // ── Organization ──
        'members.view' => ['group' => 'organization', 'scope' => 'org'],
        'members.invite' => ['group' => 'organization', 'scope' => 'org', 'implies' => ['members.view']],
        'members.update' => ['group' => 'organization', 'scope' => 'org', 'implies' => ['members.view']],
        'members.remove' => ['group' => 'organization', 'scope' => 'org', 'implies' => ['members.view']],
        'roles.manage' => ['group' => 'organization', 'scope' => 'org', 'implies' => ['members.view']],
        'groups.manage' => ['group' => 'organization', 'scope' => 'org', 'implies' => ['members.view']],
        'tokens.manage' => ['group' => 'organization', 'scope' => 'org'],
        'settings.manage' => ['group' => 'organization', 'scope' => 'org'],
        // White-label (docs/white-label.md): single-tenant the install's brand, multi-tenant the
        // tenant's own brand (the platform brand is the platform administrators' — BrandingController)
        'branding.manage' => ['group' => 'organization', 'scope' => 'org'],
        // Self-service signup settings of the install (multi-tenant: platform administrators)
        'signup.manage' => ['group' => 'organization', 'scope' => 'org', 'edition' => 'single'],
        'sso.manage' => ['group' => 'organization', 'scope' => 'org'],
        'audit.view' => ['group' => 'organization', 'scope' => 'org'],
        'audit.export' => ['group' => 'organization', 'scope' => 'org', 'implies' => ['audit.view']],
        // Data residency of the organization: allowed regions / data locations, default region
        'tenants.residency.manage' => ['group' => 'organization', 'scope' => 'org', 'edition' => 'multi'],
        // Export the organization into a package / import one (docs/org-export.md); the API also
        // requires the Owner role — the permission scopes API tokens and custom roles
        'org.transfer' => ['group' => 'organization', 'scope' => 'org'],

        // ── Billing (Billing module, docs/billing.md) ──
        // Bills, usage, prices, region billing types and fixed resource requests of the organization
        'billing.view' => ['group' => 'billing', 'scope' => 'org'],
        // Prices, region billing types, markup, region offers, payment links and paid status, webhook
        'billing.manage' => ['group' => 'billing', 'scope' => 'org', 'implies' => ['billing.view']],
        // Approve or reject fixed resource requests (the seller of the region)
        'billing.approve' => ['group' => 'billing', 'scope' => 'org', 'implies' => ['billing.view']],
        // A project's own bills and its fixed resource requests (the project owner has it by ownership)
        'projects.billing' => ['group' => 'billing', 'scope' => 'project', 'implies' => ['projects.view']],

        // ── Support ──
        'tickets.view' => ['group' => 'support', 'scope' => 'org'],
        'tickets.create' => ['group' => 'support', 'scope' => 'org', 'implies' => ['tickets.view']],
        // Answering every ticket of the install; in multi-tenant this is the platform operator's job
        'tickets.manage' => ['group' => 'support', 'scope' => 'org', 'edition' => 'single', 'implies' => ['tickets.view']],
    ],

    /*
    | Built-in roles: read-only, available in every organization. '*' = every permission of the
    | edition. Owner differs from Admin only in ownership: only owners (and platform admins)
    | grant or revoke the Owner role, and the last owner cannot be removed or demoted.
    */
    'roles' => [
        'owner' => [
            'name' => 'Owner',
            'description' => 'Full control of the organization, including ownership.',
            'permissions' => ['*'],
        ],
        'admin' => [
            'name' => 'Admin',
            'description' => 'Full control of the organization except granting ownership.',
            'permissions' => ['*'],
        ],
        'developer' => [
            'name' => 'Developer',
            'description' => 'Builds, deploys and operates projects.',
            'permissions' => [
                'projects.view', 'projects.create', 'projects.update', 'projects.delete', 'projects.deploy',
                'environments.manage', 'environments.promote',
                'code.edit', 'env.view', 'env.edit', 'secret_stores.view', 'domains.manage', 'network.manage', 'connections.manage',
                'git.pull', 'git.push', 'git.manage', 'previews.manage',
                'shell.exec', 'logs.view', 'metrics.view',
                'traces.view', 'replay.manage', 'uptime.manage',
                'workflows.view', 'workflows.run', 'workflows.manage', 'events.send', 'topics.manage',
                'sandboxes.view', 'sandboxes.create', 'sandboxes.exec',
                'security.view',
                'regions.view', 'runtimes.use_custom',
                'images.view', 'images.upload', 'images.delete',
                'models.view', 'models.deploy',
                'bindings.view', 'bindings.manage',
                'mcp.servers.manage', 'mcp.servers.call',
                'members.view', 'tokens.manage',
                'tickets.view', 'tickets.create',
            ],
        ],
        'viewer' => [
            'name' => 'Viewer',
            'description' => 'Read-only access to projects, deployments, logs and metrics.',
            'permissions' => [
                'projects.view', 'git.pull', 'logs.view', 'metrics.view', 'traces.view', 'workflows.view', 'sandboxes.view', 'security.view',
                'regions.view', 'images.view', 'models.view', 'bindings.view', 'mcp.servers.call', 'members.view', 'tokens.manage',
                'tickets.view', 'tickets.create',
            ],
        ],
        'billing' => [
            'name' => 'Billing',
            'description' => 'Bills, usage, prices, fixed resource approvals and stopping projects.',
            'permissions' => [
                'billing.view', 'billing.manage', 'billing.approve', 'projects.stop',
                'regions.view', 'bindings.view', 'members.view', 'tokens.manage',
                'tickets.view', 'tickets.create',
            ],
        ],
    ],

    /*
    | Role new members get when nothing else is chosen: users created from the Users page,
    | self-registration, invitations without a role and SSO just-in-time provisioning. The
    | organization setting (Roles page) overrides it. Never 'owner'.
    */
    'default_role' => env('RBAC_DEFAULT_ROLE', 'developer'),

    /*
    | Upgrade from 0.4.x (multi-tenant): tenant members ("member") keep exactly what they could
    | do before — the upgrade creates this custom role in every tenant that has members. A
    | permission a release adds to the built-in Developer role (a fresh tenant's default role) is
    | added here too, and a tenant migration adds it to the Member roles that upgrades created
    | before (0108 for 0.6.0); PermissionCatalogTest keeps the two lists in step.
    */
    'legacy_member_role' => [
        'key' => 'member',
        'name' => 'Member',
        'description' => 'Created by the upgrade: what members could do before roles existed. Move members to Developer to drop the settings and SSO permissions.',
        'permissions' => [
            'projects.view', 'projects.create', 'projects.update', 'projects.delete', 'projects.deploy',
            'environments.manage', 'environments.promote',
            'code.edit', 'env.view', 'env.edit', 'secret_stores.view', 'domains.manage', 'network.manage', 'connections.manage',
            'git.pull', 'git.push', 'git.manage', 'previews.manage',
            'shell.exec', 'logs.view', 'metrics.view',
            'traces.view', 'replay.manage', 'uptime.manage',
            'workflows.view', 'workflows.run', 'workflows.manage', 'events.send', 'topics.manage',
            'sandboxes.view', 'sandboxes.create', 'sandboxes.exec',
            'security.view',
            'regions.view', 'runtimes.use_custom', 'models.view',
            'images.view', 'images.upload', 'images.delete',
            'bindings.view', 'bindings.manage',
            'members.view', 'tokens.manage', 'settings.manage', 'sso.manage',
            'tickets.view', 'tickets.create',
        ],
    ],

    /*
    | API token scopes of 0.4.x, translated on upgrade (and when still sent by older clients).
    */
    'legacy_token_abilities' => [
        'projects:read' => ['projects.view', 'logs.view', 'metrics.view', 'git.pull'],
        'projects:write' => ['projects.view', 'projects.create', 'projects.update', 'projects.delete', 'code.edit', 'env.view', 'env.edit', 'domains.manage', 'git.pull', 'git.push'],
        'deployments:read' => ['projects.view', 'logs.view', 'metrics.view'],
        'deployments:write' => ['projects.view', 'projects.deploy'],
        'settings:read' => ['regions.view'],
        'settings:write' => ['regions.view', 'regions.manage', 'settings.manage'],
    ],
];
