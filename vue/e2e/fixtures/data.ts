// Default data of the in-memory fake RunR API (mock mode). Shapes follow the
// Laravel API resources the UI reads (see vue/src/api/*.js and the views).
// Every test gets a fresh deep copy, so specs may mutate it freely.

export type Json = Record<string, any>

const ISO = (d: string) => new Date(d).toISOString()

export interface MockDb {
  user: Json
  /** Accepted password for POST /auth/login (any e-mail of `users`). */
  password: string
  /** When set, login answers 202 { requires_2fa } until this code is sent. */
  twoFactorCode: string | null
  settings: Json
  regions: Json[]
  executorImages: Json[]
  projects: Json[]
  /** projectId → versions, newest first */
  versions: Record<string, Json[]>
  /** projectId → current code (xaas) */
  code: Record<string, string>
  /** projectId → current files (faas) */
  files: Record<string, Record<string, string>>
  /** versionId → files of that version */
  versionFiles: Record<string, Record<string, string>>
  /** projectId → deployments, newest first */
  deployments: Record<string, Json[]>
  /** projectId → custom domains */
  domains: Record<string, Json[]>
  tokens: Json[]
  /** GET /tokens available_abilities: [{ key, group, scope }] (the user's permissions) */
  abilities: Json[]
  users: Json[]
  // Roles & permissions (docs/permissions.md)
  /** GET /auth/permissions — the signed-in user's effective permissions */
  permissions: Json
  catalog: Json[]
  roles: Json[]
  defaultRole: string
  groups: Json[]
  /** projectId → project access grants */
  projectAccess: Record<string, Json[]>
  notifications: Json[]
  // Region wizard previews
  nodes: Json[]
  storageClasses: Json[]
  namespaceTaken: string[]
  // Modules (tenancy, billing, tickets)
  tenants: Json[]
  activeTenant: Json | null
  billing: Record<string, Json>
  tickets: Json[]
  seq: number
}

export const ADMIN: Json = {
  id: 1,
  name: 'Ada Admin',
  username: 'admin',
  email: 'admin@runr.test',
  is_admin: true,
  avatar_url: null,
  two_factor_confirmed_at: null,
  // A non-null value keeps the guided tours from auto-starting
  tutorial_completed_at: ISO('2026-01-01T00:00:00Z'),
  created_at: ISO('2026-01-01T00:00:00Z'),
}

export const READY_REGION: Json = {
  id: 'r-ready',
  name: 'eu-1',
  project_namespace: 'eu-1',
  server_url: 'https://10.0.0.10:6443',
  is_active: true,
  is_synced: true,
  is_demo: false,
  has_kubeconfig: true,
  has_serving: true,
  has_redis: true,
  region_status: 'active',
  region_mode: 'managed',
  cluster_status: 'online',
  knative_operator_status: 'ready',
  knative_serving_status: 'ready',
  knative_redis_status: 'ready',
  knative_serving_version: '1.17.0',
  knative_settings: { request_timeout: 300 },
  selected_workers: ['worker-1'],
  total_cpu_m: 8000,
  total_memory_mb: 16384,
  used_cpu_m: 700,
  used_memory_mb: 1024,
}

/** A region without Knative Serving: only schedule triggers work there. */
export const CRON_REGION: Json = {
  ...READY_REGION,
  id: 'r-cron',
  name: 'region-b',
  project_namespace: 'region-b',
  is_synced: false,
  has_serving: false,
  has_redis: false,
  knative_serving_status: 'not_installed',
  used_cpu_m: 0,
  used_memory_mb: 0,
}

export const EXECUTOR_IMAGES: Json[] = [
  { id: 1, runtime: 'python', version: '3.12', is_default: true, docker_image: 'ghcr.io/brf-tech/runr/python:3.12', eol_at: null },
  { id: 2, runtime: 'nodejs', version: '22', is_default: true, docker_image: 'ghcr.io/brf-tech/runr/nodejs:22', eol_at: null },
  { id: 3, runtime: 'go', version: '1.23', is_default: true, docker_image: 'ghcr.io/brf-tech/runr/go:1.23', eol_at: null },
  { id: 4, runtime: 'php', version: '8.3', is_default: true, docker_image: 'ghcr.io/brf-tech/runr/php:8.3', eol_at: null },
]

const baseConfig = {
  cpu: '250m',
  memory_mb: 256,
  timeout_seconds: 30,
  trigger_type: 'http',
  schedule: null,
  queue_stream: null,
  scale_to_zero: true,
  min_scale: 0,
  max_scale: 1,
  env_vars: { LOG_LEVEL: 'info' },
}

export const XAAS_PROJECT: Json = {
  id: 'p-xaas',
  name: 'Hello Snippet',
  slug: 'hello-snippet',
  description: 'Single-file greeting',
  service_type: 'xaas',
  runtime: 'python',
  runtime_version: '3.12',
  runtime_eol: null,
  knative_region_id: 'r-ready',
  endpoint_type: 'subdomain',
  endpoint_url: 'https://hello-snippet.eu-1.runr.test',
  config: { ...baseConfig, filename: 'main.py' },
  created_at: ISO('2026-03-01T10:00:00Z'),
  updated_at: ISO('2026-03-02T10:00:00Z'),
}

export const FAAS_PROJECT: Json = {
  id: 'p-faas',
  name: 'Orders API',
  slug: 'orders-api',
  description: 'Multi-file Node.js function',
  service_type: 'faas',
  runtime: 'nodejs',
  runtime_version: '22',
  runtime_eol: null,
  knative_region_id: 'r-ready',
  endpoint_type: 'subdomain',
  endpoint_url: 'https://orders-api.eu-1.runr.test',
  config: { ...baseConfig, handler: 'index.handler', env_vars: { DATABASE_URL: 'postgres://db/orders' } },
  created_at: ISO('2026-03-03T10:00:00Z'),
  updated_at: ISO('2026-03-04T10:00:00Z'),
}

export const CAAS_PROJECT: Json = {
  id: 'p-caas',
  name: 'Web Frontend',
  slug: 'web-frontend',
  description: 'nginx container',
  service_type: 'caas',
  runtime: null,
  runtime_version: null,
  runtime_eol: null,
  knative_region_id: 'r-ready',
  endpoint_type: 'subdomain',
  endpoint_url: 'https://web-frontend.eu-1.runr.test',
  config: {
    ...baseConfig,
    cpu: '500m',
    memory_mb: 512,
    image: 'nginx:1.27',
    ports: [80],
    command: null,
    image_pull_policy: 'IfNotPresent',
    env_vars: {},
  },
  created_at: ISO('2026-03-05T10:00:00Z'),
  updated_at: ISO('2026-03-05T10:00:00Z'),
}

const version = (id: string, label: string, message: string, at: string, extra: Json = {}) => ({
  id, label, commit_message: message, build_status: 'ready', created_at: ISO(at), ...extra,
})

export const PY_V1 = 'def handler(event, context):\n    return {"message": "hello"}\n'
export const PY_V2 = 'def handler(event, context):\n    return {"message": "hello, world"}\n'
export const JS_INDEX = 'exports.handler = async (event) => ({ statusCode: 200, body: "ok" })\n'

// ── Roles & permissions (docs/permissions.md) ──
// Every permission of api/config/permissions.php, in its order: [key, group, scope, implies, edition].
// Keep in sync with the PHP catalog; the mock serves the edition's subset like PermissionCatalog.
export type Edition = 'single' | 'multi'
type CatalogRow = [string, string, 'org' | 'project', string[], 'all' | Edition]
const CATALOG_ROWS: CatalogRow[] = [
  ['projects.view', 'projects', 'project', [], 'all'],
  ['projects.create', 'projects', 'org', [], 'all'],
  ['projects.update', 'projects', 'project', ['projects.view'], 'all'],
  ['projects.delete', 'projects', 'project', ['projects.view'], 'all'],
  ['projects.deploy', 'projects', 'project', ['projects.view'], 'all'],
  ['projects.manage_access', 'projects', 'project', ['projects.view'], 'all'],
  ['code.edit', 'projects', 'project', ['projects.view'], 'all'],
  ['git.pull', 'git', 'project', ['projects.view'], 'all'],
  ['git.push', 'git', 'project', ['git.pull'], 'all'],
  ['git.manage', 'git', 'project', ['git.pull'], 'all'],
  ['previews.manage', 'git', 'project', ['git.pull'], 'all'],
  ['git_providers.manage', 'git', 'org', [], 'single'],
  ['env.view', 'configuration', 'project', ['projects.view'], 'all'],
  ['env.edit', 'configuration', 'project', ['env.view'], 'all'],
  ['secrets.view_values', 'configuration', 'project', ['env.view'], 'all'],
  ['domains.manage', 'configuration', 'project', ['projects.view'], 'all'],
  ['connections.manage', 'configuration', 'project', ['projects.view'], 'all'],
  ['shell.exec', 'operations', 'project', ['projects.view'], 'all'],
  ['logs.view', 'operations', 'project', ['projects.view'], 'all'],
  ['metrics.view', 'operations', 'project', ['projects.view'], 'all'],
  ['traces.view', 'operations', 'project', ['projects.view'], 'all'],
  ['replay.manage', 'operations', 'project', ['projects.view'], 'all'],
  ['uptime.manage', 'operations', 'project', ['metrics.view'], 'all'],
  ['alerts.manage', 'operations', 'org', [], 'all'],
  ['status_page.manage', 'operations', 'org', [], 'all'],
  ['workflows.view', 'workflows', 'project', ['projects.view'], 'all'],
  ['workflows.run', 'workflows', 'project', ['workflows.view'], 'all'],
  ['workflows.manage', 'workflows', 'project', ['workflows.view'], 'all'],
  ['events.send', 'workflows', 'org', [], 'all'],
  ['sandboxes.view', 'sandboxes', 'org', [], 'all'],
  ['sandboxes.create', 'sandboxes', 'org', ['sandboxes.view'], 'all'],
  ['sandboxes.exec', 'sandboxes', 'org', ['sandboxes.view'], 'all'],
  ['sandboxes.manage', 'sandboxes', 'org', ['sandboxes.view'], 'all'],
  ['security.view', 'security', 'project', ['projects.view'], 'all'],
  ['security.override_policy', 'security', 'project', ['security.view', 'projects.deploy'], 'all'],
  ['regions.view', 'infrastructure', 'org', [], 'all'],
  ['regions.manage', 'infrastructure', 'org', ['regions.view'], 'all'],
  ['sandbox.manage', 'infrastructure', 'org', ['regions.view'], 'all'],
  ['registry.manage', 'infrastructure', 'org', ['regions.view'], 'all'],
  ['regions.observability.manage', 'infrastructure', 'org', ['regions.view'], 'all'],
  ['regions.data_services.manage', 'infrastructure', 'org', ['regions.view'], 'all'],
  ['regions.supply_chain.manage', 'infrastructure', 'org', ['regions.view'], 'all'],
  ['runtimes.manage', 'infrastructure', 'org', [], 'single'],
  ['runtimes.use_custom', 'infrastructure', 'org', [], 'all'],
  ['regions.gpu.manage', 'infrastructure', 'org', ['regions.view'], 'all'],
  ['images.view', 'images', 'org', [], 'all'],
  ['images.upload', 'images', 'org', ['images.view'], 'all'],
  ['images.delete', 'images', 'org', ['images.view'], 'all'],
  ['models.view', 'models', 'org', [], 'all'],
  ['models.deploy', 'models', 'org', ['models.view'], 'all'],
  ['models.manage', 'models', 'org', ['models.view'], 'all'],
  ['bindings.view', 'data', 'org', [], 'all'],
  ['bindings.manage', 'data', 'org', ['bindings.view'], 'all'],
  ['bindings.view_credentials', 'data', 'org', ['bindings.view'], 'all'],
  ['mcp.servers.manage', 'mcp', 'project', ['projects.view'], 'all'],
  ['mcp.servers.call', 'mcp', 'project', ['projects.view'], 'all'],
  ['members.view', 'organization', 'org', [], 'all'],
  ['members.invite', 'organization', 'org', ['members.view'], 'all'],
  ['members.update', 'organization', 'org', ['members.view'], 'all'],
  ['members.remove', 'organization', 'org', ['members.view'], 'all'],
  ['roles.manage', 'organization', 'org', ['members.view'], 'all'],
  ['groups.manage', 'organization', 'org', ['members.view'], 'all'],
  ['tokens.manage', 'organization', 'org', [], 'all'],
  ['settings.manage', 'organization', 'org', [], 'all'],
  ['branding.manage', 'organization', 'org', [], 'all'],
  ['signup.manage', 'organization', 'org', [], 'single'],
  ['sso.manage', 'organization', 'org', [], 'all'],
  ['audit.view', 'organization', 'org', [], 'all'],
  ['audit.export', 'organization', 'org', ['audit.view'], 'all'],
  ['tenants.residency.manage', 'organization', 'org', [], 'multi'],
  ['billing.view', 'billing', 'org', [], 'multi'],
  ['billing.manage', 'billing', 'org', ['billing.view'], 'multi'],
  ['tickets.view', 'support', 'org', [], 'all'],
  ['tickets.create', 'support', 'org', ['tickets.view'], 'all'],
  ['tickets.manage', 'support', 'org', ['tickets.view'], 'single'],
]

// Built-in roles of api/config/permissions.php: [key, name, description, edition, permissions ('*' = all)]
type RoleRow = [string, string, string, 'all' | Edition, string[]]
const ROLE_ROWS: RoleRow[] = [
  ['owner', 'Owner', 'Full control of the organization, including ownership.', 'all', ['*']],
  ['admin', 'Admin', 'Full control of the organization except granting ownership.', 'all', ['*']],
  ['developer', 'Developer', 'Builds, deploys and operates projects.', 'all', [
    'projects.view', 'projects.create', 'projects.update', 'projects.delete', 'projects.deploy',
    'code.edit', 'env.view', 'env.edit', 'domains.manage', 'connections.manage',
    'git.pull', 'git.push', 'git.manage', 'previews.manage',
    'shell.exec', 'logs.view', 'metrics.view',
    'traces.view', 'replay.manage', 'uptime.manage',
    'workflows.view', 'workflows.run', 'workflows.manage', 'events.send',
    'sandboxes.view', 'sandboxes.create', 'sandboxes.exec',
    'security.view',
    'regions.view', 'runtimes.use_custom',
    'images.view', 'images.upload', 'images.delete',
    'models.view', 'models.deploy',
    'bindings.view', 'bindings.manage',
    'mcp.servers.manage', 'mcp.servers.call',
    'members.view', 'tokens.manage',
    'tickets.view', 'tickets.create',
  ]],
  ['viewer', 'Viewer', 'Read-only access to projects, deployments, logs and metrics.', 'all', [
    'projects.view', 'git.pull', 'logs.view', 'metrics.view', 'traces.view', 'workflows.view', 'sandboxes.view', 'security.view',
    'regions.view', 'images.view', 'models.view', 'bindings.view', 'mcp.servers.call', 'members.view', 'tokens.manage',
    'tickets.view', 'tickets.create',
  ]],
  ['billing', 'Billing', 'Charges, credit, purchases and usage.', 'multi', [
    'billing.view', 'billing.manage',
    'regions.view', 'bindings.view', 'members.view', 'tokens.manage',
    'tickets.view', 'tickets.create',
  ]],
]

const inEdition = (edition: 'all' | Edition, current: Edition) => edition === 'all' || edition === current

/** GET /permissions `permissions` of an edition: [{ key, group, scope, implies }] */
export function permissionCatalog(edition: Edition = 'single'): Json[] {
  return CATALOG_ROWS
    .filter(([, , , , e]) => inEdition(e, edition))
    .map(([key, group, scope, implies]) => ({ key, group, scope, implies: [...implies] }))
}

/** PermissionCatalog::expand — '*' = every permission of the catalog, implied ones added, catalog order. */
export function expandPermissions(permissions: string[], catalog: Json[]): string[] {
  if (permissions.includes('*')) return catalog.map(c => c.key)
  const byKey = new Map(catalog.map(c => [c.key, c]))
  const out = new Set<string>()
  const queue = [...permissions]
  while (queue.length) {
    const p = queue.pop()!
    const entry = byKey.get(p)
    if (!entry || out.has(p)) continue
    out.add(p)
    queue.push(...entry.implies)
  }
  return catalog.map(c => c.key).filter(k => out.has(k))
}

/** GET /roles built-in entries of an edition (owner, admin, developer, viewer; billing in multi). */
export function builtinRoles(edition: Edition = 'single'): Json[] {
  const catalog = permissionCatalog(edition)
  return ROLE_ROWS
    .filter(([, , , e]) => inEdition(e, edition))
    .map(([key, name, description, , permissions]) => ({
      key, name, description, permissions: expandPermissions(permissions, catalog), builtin: true, grantable: true,
    }))
}

/** Catalog of the default (single-tenant) mock install. */
export const PERMISSION_CATALOG: Json[] = permissionCatalog('single')
/** Every permission of both editions: an owner with these sees every action in either mode. */
export const ALL_PERMISSIONS: string[] = CATALOG_ROWS.map(([key]) => key)
export const BUILTIN_ROLES: Json[] = builtinRoles('single')

/** GET /auth/permissions of a member with $permissions (and project access per project id). */
export function effectivePermissions(role: string, permissions: string[], projects: Record<string, string[]> = {}, platformAdmin = false, edition: Edition = 'single'): Json {
  return { edition, platform_admin: platformAdmin, organization: true, role, roles: [role], permissions, projects, token_scoped: false }
}

export const TENANTS: Json[] = [
  { id: 't-acme', name: 'Acme Corp', slug: 'acme', role: 'owner', status: 'active', created_at: ISO('2026-02-01T00:00:00Z') },
  { id: 't-globex', name: 'Globex', slug: 'globex', role: 'member', status: 'active', created_at: ISO('2026-02-02T00:00:00Z') },
]

/** Fresh mock data; edition 'multi' = the Tenancy module is on (mock-modules project). */
export function defaultDb(edition: Edition = 'single'): MockDb {
  const catalog = permissionCatalog(edition)
  const db: MockDb = {
    user: { ...ADMIN },
    password: 'secret-password',
    twoFactorCode: null,
    settings: {
      base_domain: 'runr.test',
      registration_enabled: false,
      gpu_demo_enabled: false,
      demo_status: 'idle',
      total_cpu_m: 16000,
      total_memory_mb: 32768,
      tenancy_enabled: edition === 'multi',
    },
    regions: [{ ...READY_REGION }, { ...CRON_REGION }],
    executorImages: structuredClone(EXECUTOR_IMAGES),
    projects: [structuredClone(XAAS_PROJECT), structuredClone(FAAS_PROJECT), structuredClone(CAAS_PROJECT)],
    versions: {
      'p-xaas': [
        version('v-xaas-2', 'v2', 'Say hello, world', '2026-03-02T10:00:00Z'),
        version('v-xaas-1', 'v1', 'Initial version', '2026-03-01T10:00:00Z'),
      ],
      'p-faas': [version('v-faas-1', 'v1', 'Initial version', '2026-03-03T10:00:00Z')],
      'p-caas': [version('v-caas-1', 'v1', 'nginx 1.27', '2026-03-05T10:00:00Z', {
        config_snapshot: { image: 'nginx:1.27', ports: [80], knative_region_id: 'r-ready' },
      })],
    },
    code: { 'p-xaas': PY_V2 },
    files: { 'p-faas': { 'index.js': JS_INDEX, 'package.json': '{ "name": "orders-api" }\n' } },
    versionFiles: {
      'v-xaas-1': { 'main.py': PY_V1 },
      'v-xaas-2': { 'main.py': PY_V2 },
      'v-faas-1': { 'index.js': JS_INDEX, 'package.json': '{ "name": "orders-api" }\n' },
    },
    deployments: {
      'p-xaas': [{
        id: 'd-xaas-1',
        status: 'active',
        version: { id: 'v-xaas-2', label: 'v2' },
        version_id: 'v-xaas-2',
        k8s_job_name: 'hello-snippet',
        deployed_by: 'Ada Admin',
        created_at: ISO('2026-03-02T11:00:00Z'),
      }],
      'p-faas': [],
      'p-caas': [],
    },
    domains: {
      'p-xaas': [],
      'p-faas': [],
      'p-caas': [],
    },
    tokens: [{
      id: 11,
      name: 'CI pipeline',
      abilities: ['*'],
      created_at: ISO('2026-02-10T00:00:00Z'),
      expires_at: null,
      last_used_at: null,
      created_by: { id: 1, name: 'Ada Admin' },
    }],
    abilities: catalog.map(({ key, group, scope }) => ({ key, group, scope })),
    users: [
      { ...ADMIN, role: 'owner' },
      {
        id: 2, name: 'Bob Builder', username: 'bob', email: 'bob@runr.test', is_admin: false, role: 'developer',
        two_factor_confirmed_at: ISO('2026-02-03T00:00:00Z'), created_at: ISO('2026-02-03T00:00:00Z'),
      },
    ],
    // The signed-in user (ADMIN) owns the install; specs narrow this to test a role
    permissions: effectivePermissions('owner', catalog.map(c => c.key), {}, true, edition),
    catalog,
    roles: builtinRoles(edition),
    defaultRole: 'developer',
    groups: [],
    projectAccess: {},
    notifications: [{
      id: 'n-1',
      type: 'build.completed',
      level: 'info',
      title: 'Build completed',
      data: { project_name: 'Orders API', version: 'v1' },
      read_at: null,
      created_at: ISO('2026-03-03T10:05:00Z'),
    }],
    nodes: [
      { name: 'worker-1', is_selected: true, cpu_m: 4000, memory_mb: 8192, roles: ['worker'] },
      { name: 'worker-2', is_selected: false, cpu_m: 4000, memory_mb: 8192, roles: ['worker'] },
    ],
    storageClasses: [
      { name: 'local-path', is_default: false },
      { name: 'longhorn', is_default: true },
    ],
    namespaceTaken: ['kube-system', 'default'],
    tenants: structuredClone(TENANTS),
    activeTenant: null,
    billing: {
      't-acme': {
        total_cost: 123.45, currency: 'USD', monthly_cost: 100, usage_cost: 23.45,
        cpu_m: 1500, cpu_quota_m: 4000, memory_mb: 2048, memory_quota_mb: 8192, active_deployments: 3,
      },
      't-globex': {
        total_cost: 9.5, currency: 'USD', monthly_cost: 0, usage_cost: 9.5,
        cpu_m: 250, cpu_quota_m: 1000, memory_mb: 256, memory_quota_mb: 1024, active_deployments: 1,
      },
    },
    tickets: [{
      id: 'tk-1',
      subject: 'Deployment stuck in pending',
      description: 'The new version never becomes ready.',
      status: 'open',
      priority: 'high',
      category: 'deployment',
      user: { id: 2, name: 'Bob Builder' },
      tenant_name: null,
      replies: [],
      created_at: ISO('2026-03-06T09:00:00Z'),
      updated_at: ISO('2026-03-06T09:00:00Z'),
    }],
    seq: 100,
  }
  return db
}
