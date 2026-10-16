import type { Page, Request, Route } from '@playwright/test'
import { defaultDb, expandPermissions, type Json, type MockDb } from './data'

// In-memory fake of the RunR REST API for mock mode. Installed with page.route
// on every same-origin /api/* request (channel auth: POST /api/broadcasting/auth), so no
// backend is needed. Specs can:
//   - change data before navigating:        mockApi.db.projects.push(...)
//   - override one endpoint (wins):         mockApi.on('POST', '/projects', () => ({ status: 422, body }))
//   - assert what the UI sent:              const req = mockApi.waitFor('POST', '/projects')
//                                           ...click...; expect((await req).body).toMatchObject(...)
//                                           mockApi.calls('PUT', '/projects/:id')

export interface MockRequest {
  method: string
  /** Path without the /api prefix and query string, e.g. /projects/p-xaas */
  path: string
  params: Record<string, string>
  query: URLSearchParams
  body: any
  headers: Record<string, string>
}

export interface MockReply {
  status?: number
  body?: unknown
  headers?: Record<string, string>
}

export type MockHandler = (req: MockRequest, db: MockDb) => MockReply | Promise<MockReply>

interface RouteDef {
  method: string
  pattern: RegExp
  keys: string[]
  handler: MockHandler
}

interface Waiter {
  method: string
  pattern: RegExp
  resolve: (r: MockRequest) => void
}

const ok = (body: unknown, status = 200): MockReply => ({ status, body })
const notFound = (): MockReply => ({ status: 404, body: { message: 'Not found.' } })
const now = () => new Date().toISOString()

function compile(path: string | RegExp): { pattern: RegExp; keys: string[] } {
  if (path instanceof RegExp) return { pattern: path, keys: [] }
  const keys: string[] = []
  const source = path.replace(/:(\w+)/g, (_, k) => { keys.push(k); return '([^/]+)' })
  return { pattern: new RegExp(`^${source}$`), keys }
}

function parseMultipart(raw: string, contentType: string): Json {
  const boundary = /boundary=([^;]+)/.exec(contentType)?.[1]
  if (!boundary) return {}
  const out: Json = {}
  for (const part of raw.split(`--${boundary}`)) {
    const name = /name="([^"]+)"/.exec(part)?.[1]
    if (!name) continue
    const filename = /filename="([^"]*)"/.exec(part)?.[1]
    const value = part.split(/\r\n\r\n/).slice(1).join('\r\n\r\n').replace(/\r\n$/, '')
    const entry = filename !== undefined ? { filename } : value
    if (name.endsWith('[]')) (out[name] ??= []).push(entry)
    else out[name] = entry
  }
  return out
}

function parseBody(request: Request): any {
  const raw = request.postData()
  if (raw == null) return undefined
  const type = request.headers()['content-type'] ?? ''
  if (type.includes('multipart/form-data')) return parseMultipart(raw, type)
  try { return JSON.parse(raw) } catch { return raw }
}

export class MockApi {
  readonly db: MockDb
  readonly requests: MockRequest[] = []
  /** GET/POST/... requests that no handler knew (answered with a harmless default) */
  readonly unhandled: string[] = []
  private readonly overrides: RouteDef[] = []
  private readonly defaults: RouteDef[] = []
  private waiters: Waiter[] = []

  constructor(options: { modules?: boolean } = {}) {
    this.db = defaultDb(options.modules ? 'multi' : 'single')
    registerDefaults(this)
  }

  /** Override an endpoint for this test; checked before the built-in handlers. */
  on(method: string, path: string | RegExp, handler: MockHandler): this {
    this.overrides.unshift({ method: method.toUpperCase(), ...compile(path), handler })
    return this
  }

  /** Built-in handler (used by registerDefaults). */
  define(method: string, path: string | RegExp, handler: MockHandler): this {
    this.defaults.push({ method: method.toUpperCase(), ...compile(path), handler })
    return this
  }

  /** Recorded requests matching method + path pattern ('/projects/:id' or a RegExp). */
  calls(method: string, path: string | RegExp): MockRequest[] {
    const { pattern } = compile(path)
    return this.requests.filter(r => r.method === method.toUpperCase() && pattern.test(r.path))
  }

  /** Resolves with the next request matching method + path (also one already recorded after this call). */
  waitFor(method: string, path: string | RegExp): Promise<MockRequest> {
    const { pattern } = compile(path)
    return new Promise(resolve => this.waiters.push({ method: method.toUpperCase(), pattern, resolve }))
  }

  nextId(prefix: string): string {
    this.db.seq += 1
    return `${prefix}-${this.db.seq}`
  }

  async install(page: Page): Promise<void> {
    await page.route(url => url.pathname.startsWith('/api/'), route => this.handle(route))
    await page.route(url => url.pathname.startsWith('/sentry-tunnel'), route => route.fulfill({ status: 204 }))
  }

  private async handle(route: Route): Promise<void> {
    const request = route.request()
    const url = new URL(request.url())
    const path = url.pathname.replace(/^\/api/, '') || '/'
    const req: MockRequest = {
      method: request.method(),
      path,
      params: {},
      query: url.searchParams,
      body: parseBody(request),
      headers: request.headers(),
    }
    this.requests.push(req)
    this.waiters = this.waiters.filter(w => {
      if (w.method === req.method && w.pattern.test(path)) { w.resolve(req); return false }
      return true
    })

    let reply: MockReply | undefined
    for (const def of [...this.overrides, ...this.defaults]) {
      if (def.method !== req.method) continue
      const m = def.pattern.exec(path)
      if (!m) continue
      req.params = Object.fromEntries(def.keys.map((k, i) => [k, decodeURIComponent(m[i + 1])]))
      reply = await def.handler(req, this.db)
      break
    }
    if (!reply) {
      this.unhandled.push(`${req.method} ${path}`)
      reply = req.method === 'GET' ? ok({ data: [] }) : ok({})
    }
    await route.fulfill({
      status: reply.status ?? 200,
      headers: reply.headers,
      contentType: 'application/json',
      body: reply.body === undefined ? '' : JSON.stringify(reply.body),
    })
  }
}

// ── Built-in endpoints ────────────────────────────────────────────────────────

function project(db: MockDb, id: string): Json | undefined {
  return db.projects.find(p => p.id === id)
}

function addVersion(api: MockApi, db: MockDb, projectId: string, message: string | undefined, files: Record<string, string>): Json {
  const list = (db.versions[projectId] ??= [])
  const v = {
    id: api.nextId('v'),
    label: `v${list.length + 1}`,
    commit_message: message ?? null,
    build_status: 'ready',
    created_at: now(),
  }
  list.unshift(v)
  db.versionFiles[v.id] = { ...files }
  return v
}

function settingsPayload(db: MockDb): Json {
  // a region carries both capacity budgets (KnativeRegionResource `capacity`), shown on the projects page
  const regions = db.regions.map((r) => {
    const { cpu_m, memory_mb } = regionCapacity(r)
    return { capacity: { cpu_m, memory_mb }, ...r }
  })
  return { ...db.settings, regions, executor_images: db.executorImages }
}

/** GET /branding with nothing customised (BrandingService::publicPayload): RunR by BRF Teknoloji. */
function defaultBranding(multiTenant: boolean): Json {
  return {
    product_name: 'RunR',
    company_name: 'BRF Teknoloji',
    logo_light_url: null,
    logo_dark_url: null,
    logo_mark_url: null,
    favicon_url: null,
    email_logo_url: null,
    primary_color: '#f97316',
    primary_scale: null,
    accent_color: '#e63946',
    email_from_name: null,
    login_headline: '',
    login_text: '',
    footer_links: [],
    support_email: '',
    support_url: '',
    powered_by: true,
    palette: { 300: '253 186 116', 400: '251 146 60', 500: '249 115 22', 600: '234 88 12', 700: '194 65 12', 800: '154 52 18', 900: '124 45 18' },
    accent_rgb: '230 57 70',
    is_default_brand: true,
    tenant: null,
    features: { multi_tenant: multiTenant, plans: false, tenant_branding: false },
  }
}

/** Manifest fields of the built-in runtimes (runtime/<id>/runtime.yaml). */
const BUILTIN_RUNTIMES: Record<string, Json> = {
  python: { name: 'Python', language: 'python', extensions: ['py'], pattern: 'def\\s+handler\\s*\\(', dependency_file: 'requirements.txt' },
  nodejs: { name: 'Node.js', language: 'javascript', extensions: ['js'], pattern: '(?:exports\\.handler|module\\.exports\\.handler|function\\s+handler|const\\s+handler)', dependency_file: 'package.json' },
  go:     { name: 'Go', language: 'go', extensions: ['go'], pattern: 'func\\s+Handler\\s*\\(', dependency_file: null },
  php:    { name: 'PHP', language: 'php', extensions: ['php'], pattern: 'function\\s+handler\\s*\\(', dependency_file: 'composer.json' },
}

/** GET /runtimes entries (RuntimeResource) of the built-in runtimes that have executor images. */
function runtimeCatalog(db: MockDb): Json[] {
  const ids = [...new Set(db.executorImages.map(i => i.runtime))].filter(id => BUILTIN_RUNTIMES[id])
  return ids.map(id => {
    const m = BUILTIN_RUNTIMES[id]
    return {
      id, name: m.name, description: null, icon: null, language: m.language, extensions: m.extensions, category: 'general',
      source: 'builtin', run_mode: 'http', compiled: false, triggers: ['http', 'schedule', 'queue'], sandbox: ['runc', 'gvisor', 'kata'],
      runtime_class: null, wasm: null, gpu: null,
      handler: { file: 'main', function: 'handler', filename: `main.${m.extensions[0]}`, pattern: m.pattern },
      dependency_file: m.dependency_file,
      versions: db.executorImages.filter(i => i.runtime === id).map(i => ({
        version: i.version, is_default: !!i.is_default, eol_at: i.eol_at ?? null, gpu_required: false,
        image: i.docker_image ?? null, upload: null, built: false,
      })),
      regions: null,
      is_enabled: true,
    }
  })
}

/**
 * GET /knative-regions/{id}/capacity (RegionCapacityController): both budgets per resource, with
 * the region's used figures counted as scale-to-zero (FaaS) use and nothing always-on.
 */
function regionCapacity(region: Json): Json {
  const budget = (capacity: number, factor: number, used: number) => {
    const faasBudget = Math.floor(capacity * factor)
    return {
      capacity, always_on_used: 0, always_on_available: capacity, faas_overcommit: factor,
      faas_budget: faasBudget, faas_used: used, faas_available: Math.max(0, faasBudget - used),
      utilization: faasBudget > 0 ? Math.round((used / faasBudget) * 1000) / 10 : null,
    }
  }
  const cpu = region.faas_cpu_overcommit ?? 3
  const memory = region.faas_memory_overcommit ?? 1.5
  return {
    region_id: region.id,
    faas_cpu_overcommit: cpu,
    faas_memory_overcommit: memory,
    cpu_m: budget(region.total_cpu_m ?? 0, cpu, region.used_cpu_m ?? 0),
    memory_mb: budget(region.total_memory_mb ?? 0, memory, region.used_memory_mb ?? 0),
  }
}

/** GET /knative-regions/{id}/gpu of a region without GPU nodes or GPU support (GpuController::status). */
function noGpu(): Json {
  return {
    mode: 'none',
    status: 'not_installed',
    status_message: null,
    settings: {
      time_slicing_replicas: null, runtime_class: null, runtime_class_choice: 'auto', gvisor_nvproxy: false,
      operator_driver: true, operator_toolkit: true, restrict_to_gpu_nodes: true, gpu_nodes: null,
    },
    summary: { gpu_nodes: 0, total: 0, allocatable: 0, allocated: 0, available: 0, products: [], hardware_without_plugin: [] },
    nodes: [],
    allocation: { total: 0, by_node: {} },
    sandbox: {
      xaas: { runtime_class: null, gpu: 'ok', message: null },
      faas: { runtime_class: null, gpu: 'ok', message: null },
      caas: { runtime_class: null, gpu: 'ok', message: null },
    },
    detected_at: null,
  }
}

/**
 * GET /knative-regions/{id}/kata-shims (SandboxController::kataShims) of a region whose worker
 * nodes have been checked and have /dev/kvm: every hypervisor RunR installs is available,
 * Firecracker after RunR sets up containerd's devmapper snapshotter.
 */
function kataShims(db: MockDb): Json {
  const shim = (label: string, runtime: string, extra: Json = {}): Json => ({
    label, runtime, category: 'general', arches: ['amd64', 'arm64'], available: true, reason: null, recommended: false, ...extra,
  })
  const nodes = db.nodes.map(n => n.name as string)
  return {
    shims: {
      clh: shim('Cloud Hypervisor', 'go', { recommended: true }),
      qemu: shim('QEMU', 'go', { arches: ['amd64', 'arm64', 's390x', 'ppc64le'] }),
      dragonball: shim('Dragonball', 'rs'),
      fc: shim('Firecracker', 'go', { setup: 'devmapper' }),
    },
    kvm: {
      state: 'yes',
      nodes: Object.fromEntries(nodes.map(n => [n, true])),
      kvm_nodes: nodes,
      checked: Object.fromEntries(nodes.map(n => [n, 'kvm=yes dev_kvm=yes cpu=vmx module=kvm_intel at=2026-10-11T08:00:00Z'])),
      probing: false,
    },
    warning: 'Installing Kata Containers restarts the container runtime on every target node '
      + '(containerd, or the k3s / RKE2 service that runs it). Running pods keep running; on a single-node '
      + 'k3s or RKE2 cluster the Kubernetes API is unavailable for about 10–20 seconds.',
    fc_warning: 'Firecracker (fc) also sets up containerd\'s devmapper snapshotter on those nodes: a thin-pool '
      + 'on sparse files under /var/lib/runr/devmapper (disk is used only as images and containers fill it) and a '
      + 'systemd unit that activates it at boot. It is loaded by the same container runtime restart.',
    devmapper: { default_pool_gb: 20, min_pool_gb: 10, max_pool_gb: 1024 },
  }
}

/** Storage quota of the uploaded images (ImageQuota::summary; RUNR_IMAGES_QUOTA_GB defaults to 50). */
function imageQuota(): Json {
  return { limit_bytes: 50 * 1024 ** 3, used_bytes: 0, images: 0 }
}

function registerDefaults(api: MockApi): void {
  // ── Platform ──
  api.define('GET', '/version', () => ok({ version: '0.0.0-e2e' }))
  api.define('GET', '/auth/registration-status', () => ok({ enabled: false }))
  api.define('GET', '/auth-providers/discover', () => ok([]))
  api.define('POST', '/tutorial/complete', () => ok({}))
  api.define('GET', '/settings', (_, db) => ok(settingsPayload(db)))
  api.define('PATCH', '/settings', (req, db) => {
    Object.assign(db.settings, req.body ?? {})
    return ok(settingsPayload(db))
  })
  api.define('GET', '/branding', (_, db) => ok(defaultBranding(!!db.settings.tenancy_enabled)))
  // Private broadcast channels are authorized here (the fake socket accepts any signature)
  api.define('POST', '/broadcasting/auth', (req) => ok({ auth: `runr-local-key:e2e-${req.body?.channel_name ?? ''}` }))
  api.define('GET', '/runtimes', (_, db) => {
    const can = (p: string) => !!db.permissions.platform_admin || (db.permissions.permissions ?? []).includes(p)
    return ok({
      data: runtimeCatalog(db),
      abilities: { manage: can('runtimes.manage'), use_custom: can('runtimes.use_custom') },
      custom_enabled: db.settings.custom_runtimes_enabled === true,
    })
  })
  // Data residency of the organization: unrestricted
  api.define('GET', '/residency', (_, db) => ok({ data: {
    tenant_id: null, restricted: false, residency_enforced: false, allowed_region_ids: null, allowed_locations: [],
    default_region_id: null, regions: db.regions.map(r => ({ id: r.id, name: r.name, data_location: null, allowed: true })),
  } }))

  // ── Auth ──
  api.define('POST', '/auth/login', (req, db) => {
    const { login, password, two_factor_code: code } = req.body ?? {}
    const user = db.users.find(u => u.email === login || u.username === login) ?? (login === db.user.email ? db.user : null)
    if (!user || password !== db.password) {
      return ok({ message: 'These credentials do not match our records.' }, 422)
    }
    if (db.twoFactorCode) {
      if (!code) return ok({ requires_2fa: true }, 202)
      if (code !== db.twoFactorCode) return ok({ message: 'The two-factor code is invalid.' }, 422)
    }
    const body: Json = { token: `1|e2e-token-${user.id}`, user: user.id === db.user.id ? db.user : user }
    if (db.settings.tenancy_enabled) {
      body.tenants = db.tenants
      body.active_tenant = db.activeTenant
    }
    return ok(body)
  })
  api.define('POST', '/auth/logout', () => ok({}))
  api.define('GET', '/auth/me', (_, db) => ok(db.user))

  // ── Notifications ──
  api.define('GET', '/notifications/unread-count', (_, db) => ok({ count: db.notifications.filter(n => !n.read_at).length }))
  api.define('GET', '/notifications', (_, db) => ok({ data: db.notifications }))
  api.define('PATCH', '/notifications/:id/read', (req, db) => {
    const n = db.notifications.find(x => x.id === req.params.id)
    if (n) n.read_at = now()
    return ok({})
  })
  api.define('POST', '/notifications/read-all', (_, db) => { db.notifications.forEach(n => { n.read_at ??= now() }); return ok({}) })
  api.define('DELETE', '/notifications/:id', (req, db) => { db.notifications = db.notifications.filter(n => n.id !== req.params.id); return ok({}) })
  api.define('DELETE', '/notifications', (_, db) => { db.notifications = []; return ok({}) })

  // ── Projects ──
  api.define('GET', '/projects', (_, db) => ok({ data: db.projects }))
  api.define('POST', '/projects', (req, db) => {
    const b = req.body ?? {}
    const id = api.nextId('p')
    const slug = String(b.name ?? id).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
    const region = db.regions.find(r => r.id === b.knative_region_id)
    const p: Json = {
      id,
      name: b.name,
      slug,
      description: b.description ?? null,
      service_type: b.service_type,
      runtime: b.runtime ?? null,
      runtime_version: b.runtime_version ?? null,
      runtime_eol: null,
      knative_region_id: b.knative_region_id ?? null,
      endpoint_type: b.endpoint_type ?? 'subdomain',
      endpoint_url: b.config?.trigger_type === 'http' && region ? `https://${slug}.${region.name}.runr.test` : null,
      config: { ...(b.config ?? {}) },
      created_at: now(),
      updated_at: now(),
    }
    db.projects.unshift(p)
    db.versions[id] = []
    db.deployments[id] = []
    db.domains[id] = []
    if (p.service_type === 'xaas') db.code[id] = 'def handler(event, context):\n    return {"ok": True}\n'
    if (p.service_type === 'faas') db.files[id] = { 'main.py': 'def handler(event, context):\n    return {"ok": True}\n' }
    return ok({ data: p }, 201)
  })
  api.define('GET', '/projects/:id', (req, db) => {
    const p = project(db, req.params.id)
    return p ? ok({ data: p }) : notFound()
  })
  api.define('PUT', '/projects/:id', (req, db) => {
    const p = project(db, req.params.id)
    if (!p) return notFound()
    const { config, config_message, ...rest } = req.body ?? {}
    Object.assign(p, rest, { updated_at: now() })
    if (config) {
      p.config = { ...p.config, ...config }
      if (p.service_type === 'caas') {
        addVersion(api, db, p.id, config_message, {})
        db.versions[p.id][0].config_snapshot = { ...p.config, knative_region_id: p.knative_region_id }
      }
    }
    return ok({ data: p })
  })
  api.define('DELETE', '/projects/:id', (req, db) => {
    db.projects = db.projects.filter(p => p.id !== req.params.id)
    return ok({}, 204)
  })

  // Code (xaas) and files (faas)
  api.define('GET', '/projects/:id/code', (req, db) => ok({ code: db.code[req.params.id] ?? '' }))
  api.define('POST', '/projects/:id/code', (req, db) => {
    const p = project(db, req.params.id)
    if (!p) return notFound()
    db.code[p.id] = req.body?.code ?? ''
    const v = addVersion(api, db, p.id, req.body?.message, { [p.config?.filename ?? 'main']: db.code[p.id] })
    return ok({ data: v }, 201)
  })
  api.define('GET', '/projects/:id/files', (req, db) => ok({ files: db.files[req.params.id] ?? {} }))
  api.define('POST', '/projects/:id/files', (req, db) => {
    const p = project(db, req.params.id)
    if (!p) return notFound()
    const files = Object.fromEntries((req.body?.files ?? []).map((f: Json) => [f.name, f.content]))
    db.files[p.id] = files
    const v = addVersion(api, db, p.id, req.body?.message, files)
    return ok({ data: v }, 201)
  })

  // Versions
  api.define('GET', '/projects/:id/versions', (req, db) => ok({ data: db.versions[req.params.id] ?? [] }))
  api.define('GET', '/projects/:id/versions/:vid/files', (req, db) => ok({ files: db.versionFiles[req.params.vid] ?? {} }))

  // Deployments (deploy = the chosen version becomes the active deployment)
  api.define('GET', '/projects/:id/deployments', (req, db) => ok({ data: db.deployments[req.params.id] ?? [] }))
  api.define('POST', '/projects/:id/deploy', (req, db) => {
    const p = project(db, req.params.id)
    if (!p) return notFound()
    const v = (db.versions[p.id] ?? []).find(x => x.id === req.body?.version_id)
    if (!v) return ok({ message: 'The selected version is invalid.', errors: { version_id: ['The selected version is invalid.'] } }, 422)
    const list = (db.deployments[p.id] ??= [])
    list.forEach(d => { if (d.status === 'active') d.status = 'shut_down' })
    const d = {
      id: api.nextId('d'),
      status: 'active',
      version: { id: v.id, label: v.label },
      version_id: v.id,
      k8s_job_name: p.slug,
      deployed_by: db.user.name,
      created_at: now(),
    }
    list.unshift(d)
    return ok({ data: d }, 201)
  })
  api.define('GET', '/projects/:id/deployments/:did/logs', () => ok({ logs: '' }))
  api.define('GET', '/projects/:id/deployments/:did/metrics', () => ok({ rps: 0, cpu_m: 0, memory_mb: 0, pods: 1 }))
  api.define('PATCH', '/projects/:id/deployments/:did/status', (req, db) => {
    const d = (db.deployments[req.params.id] ?? []).find(x => x.id === req.params.did)
    if (d) d.status = req.body?.status
    return ok({ data: d ?? null })
  })
  api.define('DELETE', '/projects/:id/deployments/:did', (req, db) => {
    db.deployments[req.params.id] = (db.deployments[req.params.id] ?? []).filter(d => d.id !== req.params.did)
    return ok({}, 204)
  })
  api.define('GET', '/projects/:id/metrics/history', () => ok({ data: [] }))
  api.define('GET', '/projects/:id/metrics/summary', () => ok({ data: {} }))
  api.define('GET', '/projects/:id/private-connections', () => ok({ data: [] }))

  // Custom domains
  api.define('GET', '/projects/:id/domains', (req, db) => {
    const p = project(db, req.params.id)
    return ok({
      data: db.domains[req.params.id] ?? [],
      meta: { eligible: true, default_host: p?.endpoint_url?.replace(/^https?:\/\//, '') ?? null },
    })
  })
  api.define('POST', '/projects/:id/domains', (req, db) => {
    const host = String(req.body?.domain ?? '').trim().toLowerCase()
    if (!/^[a-z0-9.-]+\.[a-z]{2,}$/.test(host)) {
      return ok({ message: 'The domain field must be a valid host name.', errors: { domain: ['The domain field must be a valid host name.'] } }, 422)
    }
    const d = {
      id: api.nextId('dom'),
      domain: host,
      status: 'pending',
      verification: { type: 'TXT', name: `_runr-challenge.${host}`, value: 'runr-verify=e2e' },
      dns_targets: [{ type: 'CNAME', name: host, value: 'ingress.eu-1.runr.test' }],
      tls_mode: 'auto',
      tls_expires_at: null,
      created_at: now(),
    }
    ;(db.domains[req.params.id] ??= []).push(d)
    return ok({ data: d }, 201)
  })
  api.define('POST', '/projects/:id/domains/:did/verify', (req, db) => {
    const d = (db.domains[req.params.id] ?? []).find(x => x.id === req.params.did)
    if (!d) return notFound()
    d.status = 'active'
    return ok({ data: d, message: 'Domain verified and routed.' })
  })
  api.define('DELETE', '/projects/:id/domains/:did', (req, db) => {
    db.domains[req.params.id] = (db.domains[req.params.id] ?? []).filter(d => d.id !== req.params.did)
    return ok({}, 204)
  })

  // ── API tokens ──
  api.define('GET', '/tokens', (_, db) => ok({ tokens: db.tokens, available_abilities: db.abilities }))
  api.define('POST', '/tokens', (req, db) => {
    const token = {
      id: Number(api.nextId('t').split('-')[1]),
      name: req.body?.name,
      abilities: req.body?.abilities ?? ['*'],
      expires_at: req.body?.expires_at ?? null,
      created_at: now(),
      last_used_at: null,
      created_by: { id: db.user.id, name: db.user.name },
    }
    db.tokens.unshift(token)
    return ok({ token, plain_token: `${token.id}|e2e-plain-token-value` }, 201)
  })
  api.define('DELETE', '/tokens/:id', (req, db) => {
    db.tokens = db.tokens.filter(t => String(t.id) !== req.params.id)
    return ok({}, 204)
  })

  // ── Roles & permissions (docs/permissions.md) ──
  api.define('GET', '/auth/permissions', (_, db) => ok(db.permissions))
  api.define('GET', '/permissions', (_, db) => ok({
    edition: db.settings.tenancy_enabled ? 'multi' : 'single', permissions: db.catalog, groups: [...new Set(db.catalog.map(p => p.group))],
  }))
  api.define('GET', '/roles', (_, db) => ok({ roles: db.roles, default_role: db.defaultRole }))
  api.define('POST', '/roles', (req, db) => {
    const b = req.body ?? {}
    const key = String(b.name ?? '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
    if (!key || db.roles.some(r => r.key === key)) {
      return ok({ message: 'A role with this name already exists.', errors: { name: ['A role with this name already exists.'] } }, 422)
    }
    const role = { key, name: b.name, description: b.description ?? '', permissions: expandPermissions(b.permissions ?? [], db.catalog), builtin: false, grantable: true, usage: { members: 0, groups: 0, projects: 0 } }
    db.roles.push(role)
    return ok({ role }, 201)
  })
  api.define('PUT', '/roles/default', (req, db) => {
    db.defaultRole = req.body?.role ?? db.defaultRole
    return ok({ default_role: db.defaultRole })
  })
  api.define('PUT', '/roles/:key', (req, db) => {
    const role = db.roles.find(r => r.key === req.params.key)
    if (!role) return notFound()
    if (role.builtin) return ok({ message: 'Built-in roles cannot be changed.' }, 422)
    Object.assign(role, req.body ?? {})
    return ok({ role })
  })
  api.define('DELETE', '/roles/:key', (req, db) => {
    db.roles = db.roles.filter(r => r.key !== req.params.key || r.builtin)
    return ok({ message: 'Role deleted.' })
  })
  api.define('GET', '/members', (_, db) => ok({
    members: db.users.map(u => ({
      id: u.id, name: u.name, email: u.email, username: u.username, role: u.role ?? db.defaultRole,
      groups: db.groups.filter(g => (g.members ?? []).some((m: Json) => m.id === u.id)).map(g => ({ id: g.id, name: g.name, source: 'manual' })),
    })),
  }))
  api.define('PUT', '/members/:id/role', (req, db) => {
    const user = db.users.find(u => String(u.id) === req.params.id)
    if (!user) return notFound()
    user.role = req.body?.role
    return ok({ message: 'Role updated.', role: user.role })
  })
  const groupDetail = (g: Json) => ({ ...g, members: g.members ?? [], projects: g.projects ?? [] })
  api.define('GET', '/groups', (_, db) => ok({
    groups: db.groups.map(g => ({ id: g.id, name: g.name, description: g.description ?? null, role: g.role ?? null, member_count: (g.members ?? []).length, project_grants: 0 })),
  }))
  api.define('POST', '/groups', (req, db) => {
    const group = { id: api.nextId('g'), name: req.body?.name, description: req.body?.description ?? null, role: req.body?.role ?? null, members: [] }
    db.groups.push(group)
    return ok({ group: groupDetail(group) }, 201)
  })
  api.define('GET', '/groups/:id', (req, db) => {
    const group = db.groups.find(g => g.id === req.params.id)
    return group ? ok({ group: groupDetail(group) }) : notFound()
  })
  api.define('PUT', '/groups/:id', (req, db) => {
    const group = db.groups.find(g => g.id === req.params.id)
    if (!group) return notFound()
    Object.assign(group, req.body ?? {})
    return ok({ group: groupDetail(group) })
  })
  api.define('DELETE', '/groups/:id', (req, db) => {
    db.groups = db.groups.filter(g => g.id !== req.params.id)
    return ok({ message: 'Group deleted.' })
  })
  api.define('POST', '/groups/:id/members', (req, db) => {
    const group = db.groups.find(g => g.id === req.params.id)
    if (!group) return notFound()
    for (const id of req.body?.user_ids ?? []) {
      const user = db.users.find(u => String(u.id) === String(id))
      if (user && !(group.members ?? []).some((m: Json) => m.id === user.id)) {
        (group.members ??= []).push({ id: user.id, name: user.name, email: user.email, source: 'manual' })
      }
    }
    return ok({ group: groupDetail(group) })
  })
  api.define('DELETE', '/groups/:id/members/:uid', (req, db) => {
    const group = db.groups.find(g => g.id === req.params.id)
    if (!group) return notFound()
    group.members = (group.members ?? []).filter((m: Json) => String(m.id) !== req.params.uid)
    return ok({ group: groupDetail(group) })
  })
  api.define('GET', '/projects/:id/access', (req, db) => ok({
    grants: db.projectAccess[req.params.id] ?? [],
    can_manage: db.permissions.platform_admin || db.permissions.permissions.includes('projects.manage_access'),
  }))
  api.define('POST', '/projects/:id/access', (req, db) => {
    const b = req.body ?? {}
    const list = (db.projectAccess[req.params.id] ??= [])
    const subject = b.subject_type === 'group'
      ? db.groups.find(g => String(g.id) === String(b.subject_id))
      : db.users.find(u => String(u.id) === String(b.subject_id))
    if (!subject) return ok({ message: 'This user or group does not exist in the organization.', errors: { subject_id: ['unknown'] } }, 422)
    const existing = list.find(g => g.subject_type === b.subject_type && String(g.subject_id) === String(b.subject_id))
    const grant = existing ?? { id: api.nextId('ra'), subject_type: b.subject_type, subject_id: String(b.subject_id), subject_name: subject.name, subject_email: subject.email ?? null, grantable: true }
    grant.role = b.role
    if (!existing) list.push(grant)
    return ok({ grant }, 201)
  })
  api.define('DELETE', '/projects/:id/access/:gid', (req, db) => {
    db.projectAccess[req.params.id] = (db.projectAccess[req.params.id] ?? []).filter(g => g.id !== req.params.gid)
    return ok({ message: 'Access removed.' })
  })

  // ── Users ──
  api.define('GET', '/users', (req, db) => {
    const q = req.query.get('search')?.toLowerCase()
    const users = q ? db.users.filter(u => `${u.name} ${u.email}`.toLowerCase().includes(q)) : db.users
    return ok({ users, data: users })
  })
  api.define('POST', '/users', (req, db) => {
    const b = req.body ?? {}
    if (!b.name || !b.username || !b.email || !b.password) {
      return ok({ message: 'The given data was invalid.', errors: { name: ['All fields are required.'] } }, 422)
    }
    const user = { id: Number(api.nextId('u').split('-')[1]), name: b.name, username: b.username, email: b.email, is_admin: false, role: b.role ?? db.defaultRole, two_factor_confirmed_at: null, created_at: now() }
    db.users.push(user)
    return ok({ data: user }, 201)
  })
  api.define('PATCH', '/users/:id', (req, db) => {
    const user = db.users.find(u => String(u.id) === req.params.id)
    if (!user) return notFound()
    Object.assign(user, req.body ?? {})
    return ok({ data: user })
  })
  api.define('DELETE', '/users/:id', (req, db) => {
    db.users = db.users.filter(u => String(u.id) !== req.params.id)
    return ok({}, 204)
  })
  api.define('POST', '/users/:id/reset-2fa', (req, db) => {
    const user = db.users.find(u => String(u.id) === req.params.id)
    if (user) user.two_factor_confirmed_at = null
    return ok({})
  })

  // ── Regions (wizard previews and setup) ──
  api.define('GET', '/knative-regions', (_, db) => ok({ data: db.regions }))
  api.define('POST', '/knative-regions/validate-kubeconfig', (req) => {
    const kc = String(req.body?.kubeconfig ?? '')
    if (!kc.includes('apiVersion')) return ok({ message: 'The kubeconfig is not valid YAML.' }, 422)
    return ok({ valid: true, kubeconfig: kc.trim(), server: 'https://10.0.0.20:6443' })
  })
  api.define('POST', '/knative-regions/check-namespace-preview', (req, db) => ok({ exists: db.namespaceTaken.includes(req.body?.name) }))
  api.define('POST', '/knative-regions/preview-nodes', (_, db) => ok({ nodes: db.nodes }))
  api.define('POST', '/knative-regions/preview-storage-classes', (_, db) => ok({ storageClasses: db.storageClasses }))
  api.define('POST', '/registry-setup/start', (req, db) => {
    const id = api.nextId('r')
    db.regions.push({
      ...db.regions[0],
      id,
      name: req.body?.name,
      project_namespace: req.body?.name,
      is_synced: false,
      has_serving: false,
      has_redis: false,
      knative_operator_status: 'not_installed',
      knative_serving_status: 'not_installed',
      knative_redis_status: null,
      selected_workers: req.body?.nodes ?? [],
      used_cpu_m: 0,
      used_memory_mb: 0,
    })
    return ok({ setup_token: `setup-${id}`, setup_id: `sid-${id}`, region_id: id }, 202)
  })
  api.define('POST', '/knative-regions/:id/detect-components', (req, db) => {
    const region = db.regions.find(r => r.id === req.params.id)
    return ok({ operator: { adopted: false }, serving: { adopted: false }, region: { data: region } })
  })
  api.define('GET', '/knative-regions/:id/component-status', (req, db) => ok({ data: db.regions.find(r => r.id === req.params.id) ?? {} }))
  api.define('GET', '/knative-regions/:id/compatible-versions', () => ok({ versions: ['1.17.0', '1.16.2'], default: '1.17.0' }))
  api.define('GET', '/knative-regions/:id/check-namespace', (req, db) => ok({ exists: db.namespaceTaken.includes(req.query.get('name') ?? '') }))
  api.define('GET', '/knative-regions/:id/redis-status', () => ok({ status: null }))
  api.define('GET', '/knative-regions/:id/gpu', () => ok(noGpu()))
  // Capacity budgets (RegionCapacityController): the region's used figures as scale-to-zero use
  api.define('GET', '/knative-regions/:id/capacity', (req, db) => {
    const region = db.regions.find(r => r.id === req.params.id)
    return region ? ok({ data: regionCapacity(region) }) : notFound()
  })
  api.define('PUT', '/knative-regions/:id/capacity', (req, db) => {
    const region = db.regions.find(r => r.id === req.params.id)
    if (!region) return notFound()
    for (const field of ['faas_cpu_overcommit', 'faas_memory_overcommit']) {
      const value = req.body?.[field]
      if (value === undefined) continue
      if (typeof value !== 'number' || value < 1 || value > 10) {
        const message = `The ${field.replaceAll('_', ' ')} field must be between 1 and 10.`
        return ok({ message, errors: { [field]: [message] } }, 422)
      }
      region[field] = value
    }
    return ok({ message: 'Capacity settings updated.', data: regionCapacity(region) })
  })
  api.define('GET', '/knative-regions/:id/kata-shims', (req, db) => {
    const region = db.regions.find(r => r.id === req.params.id)
    if (!region) return notFound()
    return region.has_kubeconfig === false ? ok({ message: 'Kubeconfig not configured.' }, 422) : ok(kataShims(db))
  })
  // WebAssembly support of a region (docs/wasm.md): the region's state, the shims RunR installs
  // and, unless ?cluster=0, the worker nodes (all eligible)
  api.define('GET', '/knative-regions/:id/wasm', (req, db) => {
    const r = db.regions.find(x => x.id === req.params.id)
    if (!r) return notFound()
    const withCluster = req.query.get('cluster') !== '0' && !r.is_demo
    return ok({
      status: r.sandbox_wasm_status ?? 'not_installed',
      message: r.sandbox_wasm_status_message ?? null,
      shims: r.sandbox_wasm_shims ?? [],
      nodes: r.sandbox_wasm_nodes ?? null,
      available_shims: [
        { id: 'spin', name: 'Spin', version: 'v0.26.0', runtime_class: 'wasmtime-spin-v2', handler: 'spin-runr', description: 'Spin apps' },
        { id: 'wasmtime', name: 'Wasmtime', version: 'v0.6.1', runtime_class: 'wasmtime-v1', handler: 'wasmtime-runr', description: 'WASI command modules' },
      ],
      spin_runtime: '4.1.0',
      warning: 'Installing WebAssembly support restarts the container runtime once on every selected node where its configuration changes.',
      can_manage: !!db.permissions.platform_admin || (db.permissions.permissions ?? []).includes('sandbox.manage'),
      cluster: withCluster
        ? {
            nodes: db.nodes.map(n => ({ name: n.name, arch: 'amd64', flavor: 'k3s', runtime_version: 'containerd://2.0.5-k3s1', eligible: true, reason: null, target: false, shims: [] })),
            runtime_classes: { 'wasmtime-spin-v2': null, 'wasmtime-v1': null },
            knative_features_to_enable: ['kubernetes.podspec-nodeselector'],
            installer: null,
          }
        : null,
      cluster_error: null,
    })
  })
  // Supply chain of a region (docs/supply-chain.md): the defaults, no key, no admission policy
  api.define('GET', '/knative-regions/:id/supply-chain', (req, db) => {
    const r = db.regions.find(x => x.id === req.params.id)
    if (!r) return notFound()
    return ok({ data: {
      knative_region_id: r.id, sbom_enabled: true, scan_enabled: true, scan_external_images: false, rescan_interval_hours: 24,
      scanner_db_mode: 'online', scanner_db_source: null, scanner_db_status: 'none', gate_mode: 'off', gate_max_critical: 0,
      gate_max_high: null, gate_only_fixable: false, gate_require_scan: false, signing_enabled: false, verify_on_deploy: true,
      attest_enabled: true, signing_status: 'none', signing_public_key: null, signing_key_fingerprint: null, signing_retired_keys: [],
      admission_mode: 'off', admission_status: 'not_installed', admission_require_sbom: false, admission_exempt_images: null,
      caas_policy: 'any', caas_allowed_registries: null, caas_trusted_keys: null, data_location: null,
      region: { id: r.id, name: r.name, has_registry: true, registry_mode: 'internal', project_namespace: r.project_namespace ?? r.name },
      scanner_db_repository: null, scanner_db_default_source: 'mirror.gcr.io/aquasec/trivy-db:2',
      supply_chain_namespace: 'runr-supply-chain',
      can_manage: !!db.permissions.platform_admin || (db.permissions.permissions ?? []).includes('regions.supply_chain.manage'),
    } })
  })

  // ── Tenancy module ──
  api.define('GET', '/tenant/current', (_, db) => ok({ tenants: db.tenants, tenant: db.activeTenant, role: db.activeTenant?.role ?? null }))
  api.define('GET', '/tenant/check/:slug', (req, db) => {
    const t = db.tenants.find(x => x.slug === req.params.slug)
    return t ? ok({ id: t.id, name: t.name, slug: t.slug }) : notFound()
  })
  api.define('GET', '/tenants', (_, db) => ok({ data: db.tenants }))
  api.define('GET', '/tenants/:id', (req, db) => {
    const t = db.tenants.find(x => x.id === req.params.id)
    return t ? ok({ data: t }) : notFound()
  })
  api.define('GET', '/tenants/:id/billing/summary', (req, db) => ok(db.billing[req.params.id] ?? { total_cost: 0, currency: 'USD' }))
  api.define('GET', '/tenants/:id/billing/usage', () => ok({ data: [] }))
  api.define('GET', '/tenants/:id/capacity-deals', () => ok({ data: [] }))
  api.define('GET', '/cost-configs', () => ok({ data: [] }))
  api.define('GET', '/resource-usage', () => ok({ data: [] }))

  // ── Billing API (docs/billing.md): RunR calculates charges; nothing charged yet ──
  api.define('GET', '/billing/tenants', (_, db) => ok({
    plans_enabled: false,
    tenants: db.tenants.map(t => ({ tenant: { id: t.id, slug: t.slug, name: t.name, status: 'active' }, subscription: null })),
  }))
  api.define('GET', '/billing/tenants/:id', (req, db) => {
    const t = db.tenants.find(x => x.id === req.params.id || x.slug === req.params.id)
    return t ? ok({
      tenant: { id: t.id, slug: t.slug, name: t.name, status: 'active' }, plans_enabled: false, subscription: null,
      limits: null, addon_limits: {}, metered_usage: true, currency: 'USD', credit_balances: {}, open_charges: [],
    }) : notFound()
  })
  api.define('GET', '/billing/charges', () => ok({ data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } }))
  api.define('GET', '/billing/charges/:id', () => notFound())
  api.define('GET', '/billing/purchases', () => ok({ data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } }))
  api.define('GET', '/billing/events', () => ok({ data: [], meta: { next_after: null, next_before: null, webhook_configured: false } }))
  api.define('GET', '/tenant/billing', () => ok({ charges: [], credit_balances: {}, purchases: [], store_url: null }))
  api.define('GET', '/admin/billing-settings', () => ok({
    plans_enabled: false, webhook: { url: null, url_source: 'config', secret_set: false },
    usage_thresholds: [], store_url: null, default_currency: 'USD',
  }))
  api.define('GET', '/admin/plans', () => ok([]))

  // ── Plans and onboarding (white-label, multi-tenant): plans off, the organization set up ──
  api.define('GET', '/tenant/subscription', (_, db) => ok({
    plans_enabled: false,
    subscription: null,
    limits: null,
    addon_limits: {},
    plans: [],
    usage: { projects: db.projects.length, gpu: 0, invocations_this_month: 0, storage_mb: 0 },
    store_url: null,
  }))
  api.define('GET', '/onboarding', (_, db) => ok({
    status: 'completed', can_complete: false,
    tenant: db.activeTenant ? { id: db.activeTenant.id, name: db.activeTenant.name, slug: db.activeTenant.slug } : null,
    regions: [], region_assigned: true, default_quota: null, plan: null,
  }))

  // ── Git deploy of a project (docs/git-deploy.md): nothing pushed, no repository connected ──
  api.define('GET', '/projects/:id/git', (req, db) => {
    const p = project(db, req.params.id)
    if (!p) return notFound()
    const can = (perm: string) => !!db.permissions.platform_admin || (db.permissions.permissions ?? []).includes(perm)
    return ok({ data: {
      remote_url: `http://127.0.0.1/git/${db.activeTenant?.slug ?? 'runr'}/${p.slug}.git`,
      remote_ready: false,
      remote_head: null,
      read_only: false,
      settings: {
        deploy_branch: 'main', root_dir: null, auto_deploy: true, allow_force_push: false, previews_enabled: false,
        preview_limit: 3, preview_ttl_hours: 72, preview_env: {}, fork_previews: false, fork_secrets: false,
      },
      runr_yaml: null,
      connection: null,
      providers: [],
      limits: { preview_limit_max: 20, preview_ttl_max: 720, max_push_mb: 50 },
      abilities: { pull: can('git.pull'), push: can('git.push'), manage: can('git.manage'), manage_previews: can('previews.manage') },
      username_hint: db.user.username ?? 'runr',
    } })
  })

  // ── Hosted MCP server of a project (docs/mcp.md): none until a spec sets one up ──
  api.define('GET', '/projects/:id/mcp-server', () => ({ status: 404, body: { message: 'This project is not served as an MCP server.' } }))

  // ── Secret environment variables (write-only; docs/security.md): the names and who set them,
  // never a value (ProjectSecretController) ──
  const secrets: Record<string, Json[]> = {}
  api.define('GET', '/projects/:id/secrets', (req, db) => ok({
    data: secrets[req.params.id] ?? [],
    can_view_values: !!db.permissions.platform_admin || (db.permissions.permissions ?? []).includes('secrets.view_values'),
  }))
  api.define('PUT', '/projects/:id/secrets/:name', (req, db) => {
    if (!project(db, req.params.id)) return notFound()
    const value = req.body?.value
    if (typeof value !== 'string' || value === '') {
      return ok({ message: 'The value field is required.', errors: { value: ['The value field is required.'] } }, 422)
    }
    const list = (secrets[req.params.id] ??= [])
    const row = { name: req.params.name, updated_at: now(), updated_by: { id: db.user.id, name: db.user.name } }
    const i = list.findIndex(x => x.name === req.params.name)
    if (i >= 0) list[i] = row
    else list.push(row)
    return ok({ data: row, requires_deploy: true }, i >= 0 ? 200 : 201)
  })

  // ── Uploaded images (docs/images.md): none uploaded yet; the list carries the quota (ImageController) ──
  api.define('GET', '/images', () => ok({ data: [], quota: imageQuota() }))
  api.define('GET', '/images/quota', () => ok(imageQuota()))

  // ── Auth-provider module: the administrator's list is a plain array (none configured) ──
  api.define('GET', '/auth-providers', () => ok([]))

  // ── Ticket module ──
  const ticketStats = (db: MockDb) => {
    const count = (s: string) => db.tickets.filter(t => t.status === s).length
    return { open: count('open'), in_progress: count('in_progress'), resolved: count('resolved'), total: db.tickets.length }
  }
  const listTickets = (req: MockRequest, db: MockDb) => {
    const status = req.query.get('status')
    const q = req.query.get('search')?.toLowerCase()
    let data = db.tickets
    if (status) data = data.filter(t => t.status === status)
    if (q) data = data.filter(t => t.subject.toLowerCase().includes(q))
    return ok({ data, current_page: 1, last_page: 1, total: data.length })
  }
  api.define('GET', '/support-tickets/stats', (_, db) => ok(ticketStats(db)))
  api.define('GET', '/support-tickets/suggestions', () => ok([]))
  api.define('GET', '/support-tickets', listTickets)
  api.define('POST', '/support-tickets', (req, db) => {
    const b = req.body ?? {}
    const ticket = {
      id: api.nextId('tk'),
      subject: b.subject,
      description: b.description,
      category: b.category,
      priority: b.priority,
      status: 'open',
      user: { id: db.user.id, name: db.user.name },
      tenant_name: null,
      replies: [],
      created_at: now(),
      updated_at: now(),
    }
    db.tickets.unshift(ticket)
    return ok({ data: ticket }, 201)
  })
  api.define('GET', '/support-tickets/:id', (req, db) => {
    const t = db.tickets.find(x => x.id === req.params.id)
    return t ? ok({ data: t }) : notFound()
  })
  api.define('GET', '/admin/support-tickets/stats', (_, db) => ok(ticketStats(db)))
  api.define('GET', '/admin/support-tickets', listTickets)
}
