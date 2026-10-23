import type { Page, Request, Route } from '@playwright/test'
import { defaultDb, expandPermissions, type Json, type MockDb } from './data'
import { DEFAULT_TIMEZONE, knownTimezone, warmerPreview } from './warmer'

// In-memory fake of the RunR REST API for mock mode. Installed with page.route
// on every same-origin /api/* request (channel auth: POST /api/broadcasting/auth), so no
// backend is needed. Specs can:
//   - change data before navigating:        mockApi.db.projects.push(...)
//   - override one endpoint (wins):         mockApi.on('POST', '/projects', () => ({ status: 422, body }))
//   - assert what the UI sent:              const req = mockApi.waitFor('POST', '/projects')
//                                           ...click...; expect((await req).body).toMatchObject(...)
//                                           mockApi.calls('PUT', '/projects/:id')
//   - order answers like a slow network:    const files = mockApi.hold('POST', '/projects/:id/files')
//                                           ...click...; await files.arrived
//                                           await realtime.platform('project.created', ...)  // lands first
//                                           files.release()
//                                           mockApi.delay('GET', '/projects/:id/versions', 1500)
//     (the e2e `network` fixture holds answers the same way in stack mode)

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

/** Answers held back until release() (MockApi.hold, the e2e `network` fixture). */
export interface Gate {
  /** Resolves with the first held request once it has arrived. */
  readonly arrived: Promise<MockRequest>
  /** Held requests so far. */
  readonly requests: MockRequest[]
  /** Lets every held answer (and the ones still to come) go out. */
  release(): void
}

interface Hold {
  method: string
  pattern: RegExp
  /** requests still to hold */
  remaining: number
  delayMs: number
  gate: Promise<void> | null
  arrive: (r: MockRequest) => void
  requests: MockRequest[]
}

const ok = (body: unknown, status = 200): MockReply => ({ status, body })
const notFound = (): MockReply => ({ status: 404, body: { message: 'Not found.' } })
const now = () => new Date().toISOString()

export function compile(path: string | RegExp): { pattern: RegExp; keys: string[] } {
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

export function parseBody(request: Request): any {
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
  private readonly holds: Hold[] = []
  private readonly releases: Array<() => void> = []

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

  /**
   * Holds the answers to the next `times` requests matching method + path until release(). The
   * fake handles the request at once (its data changes, like a server that did the work) and
   * the answer reaches the page only at release(): a spec puts a websocket event, a second
   * click or a navigation between a request and its answer.
   */
  hold(method: string, path: string | RegExp, { times = 1 }: { times?: number } = {}): Gate {
    let release!: () => void
    let arrive!: (r: MockRequest) => void
    const gate = new Promise<void>(resolve => { release = resolve })
    const arrived = new Promise<MockRequest>(resolve => { arrive = resolve })
    const hold: Hold = { method: method.toUpperCase(), ...compile(path), remaining: times, delayMs: 0, gate, arrive, requests: [] }
    this.holds.push(hold)
    this.releases.push(release)
    return {
      arrived,
      requests: hold.requests,
      release: () => { hold.remaining = 0; release() },
    }
  }

  /** Lets every held answer go out (the fixture does it after each test). */
  releaseAll(): void {
    for (const hold of this.holds) hold.remaining = 0
    for (const release of this.releases) release()
  }

  /** Answers the next `times` (default: every) requests matching method + path `ms` later. */
  delay(method: string, path: string | RegExp, ms: number, { times = Infinity }: { times?: number } = {}): this {
    this.holds.push({ method: method.toUpperCase(), ...compile(path), remaining: times, delayMs: ms, gate: null, arrive: () => {}, requests: [] })
    return this
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
    // Held / delayed answers (hold(), delay()): the newest rule for the request wins
    const hold = [...this.holds].reverse().find(h => h.remaining > 0 && h.method === req.method && h.pattern.test(path))
    if (hold) {
      hold.remaining -= 1
      hold.requests.push(req)
      hold.arrive(req)
      if (hold.gate) await hold.gate
      if (hold.delayMs) await new Promise(resolve => setTimeout(resolve, hold.delayMs))
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

// cert-manager of a region (CertManagerService): its fields on the region rows of the mock
// (cert_manager_status, cert_manager_issuer, …), the reasons the API gives custom domains
const CERT_MANAGER_REASONS: Record<string, string> = {
  not_installed: 'cert-manager is not installed in this region. An administrator installs it under the region\'s General tab (cert-manager); `php artisan region:reconcile` does it on every upgrade.',
  installing:    'cert-manager is being installed in this region; TLS mode cert-manager is offered as soon as it runs (usually a few minutes). Until then HTTPS uses the region\'s default certificate.',
  uninstalling:  'cert-manager is being removed from this region.',
  error:         'cert-manager could not be set up in this region (ERROR: image pull failed). An administrator retries it under the region\'s General tab (cert-manager → Install again).',
}
const NOT_PUBLIC = 'the region\'s external address 10.0.0.20 is not reachable from the internet (a private or reserved address)'

function issuerKind(issuer: string | null): string | null {
  if (!issuer) return null
  return issuer === 'runr-acme' ? 'acme' : issuer === 'runr-ca' ? 'ca' : 'own'
}

function certManagerForDomains(r: Json | undefined): Json {
  const status = r?.cert_manager_status ?? 'not_installed'
  const issuer = r?.cert_manager_issuer ?? null
  const available = status === 'ready' && !!issuer
  return {
    available, status, issuer, kind: issuerKind(issuer),
    reason: available ? null : (CERT_MANAGER_REASONS[status] ?? CERT_MANAGER_REASONS.not_installed),
    ca: available && issuerKind(issuer) === 'ca',
    ca_download: available && issuerKind(issuer) === 'ca',
    default_mode: available ? 'cert-manager' : 'none',
  }
}

function certManagerState(r: Json): Json {
  const status = r.cert_manager_status ?? 'not_installed'
  const issuer = r.cert_manager_issuer ?? null
  const settings = r.knative_settings ?? {}
  return {
    status,
    message: r.cert_manager_status_message ?? null,
    managed: r.cert_manager_managed ?? null,
    version: r.cert_manager_version ?? null,
    namespace: status === 'not_installed' ? null : 'cert-manager',
    chart_version: 'v1.21.2',
    install_enabled: true,
    issuer: { name: issuer, kind: issuerKind(issuer), source: issuer ? (issuerKind(issuer) === 'own' ? 'region' : 'runr') : null },
    issuer_mode: settings.cert_manager_issuer_mode ?? 'auto',
    plan: { kind: 'ca', reason: NOT_PUBLIC },
    acme: {
      email: settings.cert_manager_acme_email ?? null, email_source: settings.cert_manager_acme_email ? 'region' : null,
      server: 'https://acme-v02.api.letsencrypt.org/directory', public: false, public_reason: NOT_PUBLIC,
    },
    domains: certManagerForDomains(r),
    can_manage: true,
    cluster: status === 'ready'
      ? { present: true, runr: r.cert_manager_managed !== false, namespace: 'cert-manager', version: r.cert_manager_version ?? null, available: true, drift: [], issuer_exists: true, issuer_ready: true, issuer_message: null, ca_available: issuerKind(issuer) === 'ca', domain_certificates: 0 }
      : null,
    cluster_error: null,
  }
}

function caDownload(regionName: string): MockReply {
  return {
    status: 200,
    body: '-----BEGIN CERTIFICATE-----\nMIIBszCCAVmgAwIBAgIRAJ3e2e\n-----END CERTIFICATE-----\n',
    headers: { 'content-disposition': `attachment; filename="${regionName}-runr-ca.crt"` },
  }
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

/** Default options of the deploy strategies (api DeployStrategy::DEFAULTS). */
export const DEPLOY_STRATEGY_DEFAULTS: Json = {
  strategy: 'direct',
  blue_green: { auto_promote: false, health_path: null, health_checks: 3, keep_previous_minutes: 15 },
  canary: { steps: [10, 50, 100], interval_minutes: 5, error_rate_threshold: 5, p95_threshold_ms: null },
}

/** GET /projects/:id/deploy-strategy of a project without stored options. */
function deployStrategyOf(p: Json): Json {
  const trigger = p.config?.trigger_type ?? 'http'
  return {
    ...structuredClone(DEPLOY_STRATEGY_DEFAULTS),
    strategy: p.deploy_strategy ?? 'direct',
    eligible: ['http', 'workflow'].includes(trigger),
    trigger_type: trigger,
    tracing: false,
    defaults: structuredClone(DEPLOY_STRATEGY_DEFAULTS),
  }
}

function settingsPayload(db: MockDb): Json {
  // a region carries both capacity budgets (KnativeRegionResource `capacity`), shown on the projects page
  const regions = db.regions.map((r) => {
    const { cpu_m, memory_mb } = regionCapacity(r)
    return { capacity: { cpu_m, memory_mb }, ...r }
  })
  return { ...db.settings, regions, executor_images: db.executorImages }
}

/** RunR's seeded default prices (DefaultPrices of the Billing module). */
const BILLING_DEFAULT_PRICES: Json = {
  cpu_vcpu_hour: 0.04, memory_gb_hour: 0.005, gpu_hour: 1.2, cpu_vcpu_month: 25, memory_gb_month: 3, gpu_month: 700,
  kv_gb_hour: 0.0002, object_gb_hour: 0.00004, postgres_gb_hour: 0.0002, postgres_instance_hour: 0.05,
  multiplier_http: 1, multiplier_queue: 1, multiplier_schedule: 1,
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
    // billing: the modules server builds the Billing module in (VITE_BILLING_ENABLED)
    features: { multi_tenant: multiTenant, billing: multiTenant, tickets: true, tenant_branding: false },
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

/**
 * The `global` object of a global project (GlobalProjectResource, docs/global-projects.md): every
 * region ready, healthy and in DNS, serving the project's latest version.
 */
export function globalObject(db: MockDb, project: Json, mode: string, regionIds: string[]): Json {
  const primary = regionIds[0]
  const version = (db.versions[project.id] ?? []).length || null
  return {
    mode,
    failover: 'automatic',
    failover_after_seconds: 180,
    moving_to: null,
    hostname: db.globalDns.zone ? `${project.slug}.${db.globalDns.zone}` : null,
    primary_region_id: primary,
    regions: regionIds.map((id, position) => {
      const region = db.regions.find(r => r.id === id)
      return {
        region_id: id,
        region_name: region?.name ?? id,
        role: id === primary ? 'primary' : 'secondary',
        position,
        status: 'ready',
        status_message: null,
        version,
        target_version: null,
        healthy: true,
        health_checked_at: now(),
        address: region?.external_ip ?? `203.0.113.${10 + position}`,
        in_dns: true,
        // the primary runs the triggers; no regional data unless a spec adds bindings
        triggers_active: id === primary,
        data_status: null,
        data_message: null,
        unhealthy_since: null,
        fenced_at: null,
      }
    }),
    data: [],
    warnings: [],
  }
}

/** GET /knative-regions/{id}/global-dns of a region without a server yet. */
function noGlobalDnsServer(region: Json): Json {
  return {
    region_id: region.id, region_name: region.name, status: 'disabled', status_message: null, address: null,
    ns_name: null, reachable: null, sync_status: null, sync_message: null, synced_at: null,
    tls_status: null, tls_message: null, tls_expires_at: null, eligible: true, reason: null, geoip: null,
  }
}

function registerDefaults(api: MockApi): void {
  // ── Platform ──
  api.define('GET', '/version', () => ok({ version: '0.0.0-e2e' }))
  api.define('GET', '/auth/registration-status', () => ok({ enabled: false }))
  api.define('GET', '/auth-providers/discover', () => ok([]))
  api.define('POST', '/tutorial/complete', () => ok({}))
  api.define('GET', '/settings', (_, db) => ok(settingsPayload(db)))
  api.define('PATCH', '/settings', (req, db) => {
    const body = { ...(req.body ?? {}) }
    // The organization's time zone: "" / null goes back to the default, an unknown name is refused
    if ('timezone' in body) {
      if (body.timezone === null || body.timezone === '') body.timezone = DEFAULT_TIMEZONE
      else if (!knownTimezone(body.timezone)) {
        return { status: 422, body: { message: 'The timezone must be a valid zone.', errors: { timezone: ['The timezone must be a valid zone.'] } } }
      }
    }
    Object.assign(db.settings, body)
    return ok(settingsPayload(db))
  })
  // Timed warmer: the week the rows keep warm and the month's warm hours (e2e/fixtures/warmer.ts)
  api.define('POST', '/scale-schedule/preview', (req, db) => warmerPreview(req.body, String(db.settings.timezone ?? DEFAULT_TIMEZONE)))
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
    // The user name or the e-mail address in any letter case; with "@" the e-mail address first
    // (App\Support\Auth\LoginIdentifier)
    const id = String(login ?? '').trim().toLowerCase()
    const accounts = [db.user, ...db.users.filter(u => u.id !== db.user.id)]
    const byEmail = id.includes('@') ? accounts.find(u => String(u.email ?? '').toLowerCase() === id) : undefined
    const user = byEmail ?? accounts.find(u => String(u.username ?? '').toLowerCase() === id) ?? null
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

  // ── Runtime patches (docs/runtimes.md#runtime-patches): nothing pending unless a spec says so ──
  api.define('GET', '/runtime-patches/banners', () => ok({ data: [] }))
  api.define('GET', '/runtime-patches', () => ok({ data: [], feed: { enabled: false, status: 'disabled', fetched_at: null }, keep: 5 }))

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
      placement: 'regional',
      global: null,
    }
    // Global project: its regions (the primary first) and the global host name as the endpoint
    if (b.placement === 'global') {
      p.placement = 'global'
      p.global = globalObject(db, p, b.global_mode ?? 'ha', b.region_ids ?? [])
      db.globalProjects[id] = p.global
      p.endpoint_url = p.global.hostname ? `https://${p.global.hostname}` : p.endpoint_url
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
  // Deploy strategy (the project's deploy_strategy, default options) and rollouts (none);
  // e2e/deploy-strategies.spec.ts installs a stateful fake over these
  api.define('GET', '/projects/:id/deploy-strategy', (req, db) => {
    const p = project(db, req.params.id)
    return p ? ok({ data: deployStrategyOf(p) }) : notFound()
  })
  api.define('GET', '/projects/:id/rollout', () => ok({ data: null }))
  api.define('GET', '/projects/:id/deployments/:did/rollout', () => notFound())
  // Environments (docs/environments.md): none besides production unless a spec adds them
  // (e2e/environments.spec.ts); the approvals inbox is empty
  api.define('GET', '/projects/:id/environments', () => ok({ data: [], abilities: { manage: true, promote: true, approve: true } }))
  api.define('GET', '/projects/:id/promotions', () => ok({ data: [] }))
  api.define('GET', '/environment-promotions', () => ok({ data: [] }))
  api.define('GET', '/projects/:id/metrics/history', () => ok({ data: [] }))
  api.define('GET', '/projects/:id/metrics/summary', () => ok({ data: {} }))
  // Rightsizing (docs/monitoring.md#rightsizing): no recommendation unless a spec overrides these
  api.define('GET', '/rightsizing', () => ok({ data: [], summary: { count: 0, monthly_savings: null, currency: null, freed_memory_mb: 0, freed_cpu_m: 0 } }))
  api.define('GET', '/projects/:id/rightsizing', () => ok({
    status: 'collecting', reason: null, data: null, pending_deploy: false,
    collecting: { days: 0, required_days: 3, sessions: 0, required_sessions: 20, window_days: 14 },
  }))
  api.define('GET', '/projects/:id/private-connections', () => ok({ data: [] }))

  // Custom domains
  api.define('GET', '/projects/:id/domains', (req, db) => {
    const p = project(db, req.params.id)
    const cm = certManagerForDomains(db.regions.find(r => r.id === p?.knative_region_id))
    return ok({
      data: db.domains[req.params.id] ?? [],
      meta: {
        eligible: true,
        default_host: p?.endpoint_url?.replace(/^https?:\/\//, '') ?? null,
        cert_manager_issuer: cm.available ? cm.issuer : null,
        cert_manager: cm,
      },
    })
  })
  api.define('GET', '/projects/:id/domains/ca-certificate', (req, db) => {
    const p = project(db, req.params.id)
    const cm = certManagerForDomains(db.regions.find(r => r.id === p?.knative_region_id))
    return cm.kind === 'ca' ? caDownload('eu-1') : ok({ message: 'This region has no region CA.' }, 404)
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
      // cert-manager where the project's region issues certificates (CertManagerService::defaultTlsMode)
      tls_mode: certManagerForDomains(db.regions.find(r => r.id === project(db, req.params.id)?.knative_region_id)).default_mode,
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

  // Network: access mode and internal callers (docs/internal-network.md)
  const network = (db: MockDb, p: Json) => {
    return (db.network[p.id] ??= {
      access_mode: 'public', allowed_cidrs: [], callers: [],
    })
  }
  const networkData = (db: MockDb, p: Json) => {
    const n = network(db, p)
    const name = `${p.slug}.platform`
    return {
      ...n,
      internal_name: name, internal_host: `${name}.runr.internal`, internal_url: `http://${name}.runr.internal`,
      organization: 'platform', token_header: 'X-Runr-Internal-Token', token_env: 'RUNR_INTERNAL_TOKEN',
      cross_tenant_grants: db.settings.internal_cross_tenant_grants !== false, enforced: true,
    }
  }
  api.define('GET', '/projects/:id/network', (req, db) => {
    const p = project(db, req.params.id)
    return p ? ok({ data: networkData(db, p) }) : notFound()
  })
  api.define('PUT', '/projects/:id/network', (req, db) => {
    const p = project(db, req.params.id)
    if (!p) return notFound()
    const mode = req.body?.access_mode
    const cidrs: string[] = req.body?.allowed_cidrs ?? []
    if (mode === 'allowlist' && !cidrs.length) {
      return ok({ message: 'The allowed cidrs field is required when access mode is allowlist.', errors: { allowed_cidrs: ['The allowed cidrs field is required when access mode is allowlist.'] } }, 422)
    }
    Object.assign(network(db, p), { access_mode: mode, allowed_cidrs: mode === 'allowlist' ? cidrs : [] })
    if (mode === 'internal') p.endpoint_url = null
    return ok({ message: 'Network settings saved.', data: networkData(db, p) })
  })
  api.define('POST', '/projects/:id/network/callers', (req, db) => {
    const p = project(db, req.params.id)
    if (!p) return notFound()
    let caller = String(req.body?.caller ?? '').trim().toLowerCase()
    if (!caller.includes('.')) caller = `${caller}.platform`
    const org = caller.slice(caller.indexOf('.') + 1)
    if (org !== 'platform' && db.settings.internal_cross_tenant_grants === false) {
      const message = 'The platform does not allow grants to projects of other organizations.'
      return ok({ message, errors: { caller: [message] } }, 422)
    }
    const row = { caller, organization: org, cross_tenant: org !== 'platform', created_at: now() }
    const n = network(db, p)
    n.callers = [...n.callers.filter((c: Json) => c.caller !== caller), row].sort((a: Json, b: Json) => a.caller.localeCompare(b.caller))
    return ok({ data: row }, 201)
  })
  api.define('DELETE', '/projects/:id/network/callers/:caller', (req, db) => {
    const p = project(db, req.params.id)
    if (!p) return notFound()
    const n = network(db, p)
    const before = n.callers.length
    n.callers = n.callers.filter((c: Json) => c.caller !== decodeURIComponent(req.params.caller))
    return n.callers.length < before ? ok({}, 204) : notFound()
  })

  // Global projects (docs/global-projects.md): regions, primary and DNS mode of a global project
  api.define('GET', '/projects/:id/global', (req, db) => {
    const g = db.globalProjects[req.params.id]
    return g ? ok({ data: g }) : notFound()
  })
  api.define('PUT', '/projects/:id/global', (req, db) => {
    const p = project(db, req.params.id)
    const g = db.globalProjects[req.params.id]
    if (!p || !g) return notFound()
    const b = req.body ?? {}
    const primary = b.primary_region_id ?? g.primary_region_id
    const ids: string[] = b.region_ids ?? g.regions.map((r: Json) => r.region_id)
    if (!ids.includes(primary)) {
      return ok({ message: 'The primary region must be one of the regions.', errors: { primary_region_id: ['The primary region must be one of the regions.'] } }, 422)
    }
    if (ids.length < 2) {
      return ok({ message: 'A global project runs in at least 2 regions.', errors: { region_ids: ['A global project runs in at least 2 regions.'] } }, 422)
    }
    const ordered = [primary, ...ids.filter(id => id !== primary)]
    const next = globalObject(db, p, b.mode ?? g.mode, ordered)
    next.failover = b.failover ?? g.failover ?? 'automatic'
    next.data = g.data ?? []
    // a removed region stays listed as removing until its copy is gone; an added one rolls out
    for (const old of g.regions) {
      if (!ordered.includes(old.region_id)) next.regions.push({ ...old, status: 'removing', in_dns: false, role: 'secondary' })
    }
    for (const r of next.regions) {
      if (!g.regions.some((old: Json) => old.region_id === r.region_id)) Object.assign(r, { status: 'deploying', version: null, in_dns: false, healthy: null })
    }
    db.globalProjects[p.id] = next
    p.global = next
    p.knative_region_id = primary
    return ok({ data: next })
  })
  api.define('POST', '/projects/:id/global/sync', (req, db) => (db.globalProjects[req.params.id] ? ok({ queued: true }, 202) : notFound()))
  // Failover: the target becomes the primary at once, the old primary is fenced until it rejoins
  api.define('POST', '/projects/:id/global/failover', (req, db) => {
    const p = project(db, req.params.id)
    const g = db.globalProjects[req.params.id]
    if (!p || !g) return notFound()
    const to = req.body?.region_id ?? g.regions.find((r: Json) => r.role !== 'primary' && r.status === 'ready')?.region_id
    const target = g.regions.find((r: Json) => r.region_id === to)
    if (!target || target.role === 'primary') {
      return ok({ message: 'No other region serves the active version and answers.', errors: { region_id: ['No other region serves the active version and answers.'] } }, 422)
    }
    const old = g.primary_region_id
    const ordered = [to, ...g.regions.map((r: Json) => r.region_id).filter((id: string) => id !== to)]
    const next = globalObject(db, p, g.mode, ordered)
    next.failover = g.failover
    next.data = g.data ?? []
    const fenced = next.regions.find((r: Json) => r.region_id === old)
    const toName = db.regions.find(r => r.id === to)?.name ?? to
    Object.assign(fenced, {
      status: 'fenced', in_dns: false, healthy: false, fenced_at: now(), unhealthy_since: now(),
      status_message: `Failed over to ${toName}: this region is out of the DNS answers until it answers again.`,
    })
    db.globalProjects[p.id] = next
    p.global = next
    p.knative_region_id = to
    return ok(next, 202)
  })

  // Global DNS: the zone, its delegation and each region's server
  api.define('GET', '/global-dns', (_, db) => ok({ ...db.globalDns, servers: Object.values(db.globalDnsServers) }))
  api.define('POST', '/global-dns/sync', () => ok({ queued: true }, 202))
  api.define('GET', '/knative-regions/:id/global-dns', (req, db) => {
    const region = db.regions.find(r => r.id === req.params.id)
    if (!region) return notFound()
    return ok(db.globalDnsServers[region.id] ?? noGlobalDnsServer(region))
  })
  api.define('POST', '/knative-regions/:id/global-dns', (req, db) => {
    const region = db.regions.find(r => r.id === req.params.id)
    if (!region) return notFound()
    const server = { ...noGlobalDnsServer(region), status: 'installing', status_message: 'Waiting for the LoadBalancer address' }
    db.globalDnsServers[region.id] = server
    return ok(server, 202)
  })
  // The GeoIP source of a region's server and its reload
  api.define('PUT', '/knative-regions/:id/global-dns/geoip', (req, db) => {
    const server = db.globalDnsServers[req.params.id]
    if (!server) return notFound()
    const source = req.body?.source ?? 'default'
    const url = source === 'url' ? req.body?.url ?? null : null
    server.geoip = {
      source, url,
      effective_url: source === 'url' ? url : source === 'default' ? 'https://download.db-ip.com/free/dbip-city-lite-{yyyy}-{mm}.mmdb.gz' : null,
      status: 'unknown', message: null, checked_at: null,
    }
    return ok(server)
  })
  api.define('POST', '/knative-regions/:id/global-dns/geoip/reload', (req, db) => {
    const server = db.globalDnsServers[req.params.id]
    if (!server) return notFound()
    server.geoip = { ...(server.geoip ?? { source: 'default', url: null, effective_url: null }), status: 'unknown', message: 'The server reads its GeoIP source again.' }
    return ok(server, 202)
  })
  api.define('DELETE', '/knative-regions/:id/global-dns', (req, db) => {
    const region = db.regions.find(r => r.id === req.params.id)
    if (!region) return notFound()
    const users = db.projects.filter(p => (db.globalProjects[p.id]?.regions ?? []).some((r: Json) => r.region_id === region.id))
    if (users.length) {
      return ok({ message: `Global projects still use ${region.name}: ${users.map(p => p.name).join(', ')}.` }, 422)
    }
    const server = { ...(db.globalDnsServers[region.id] ?? noGlobalDnsServer(region)), status: 'uninstalling' }
    db.globalDnsServers[region.id] = server
    return ok(server, 202)
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
  // cert-manager of a region and the ClusterIssuer of custom domains (docs/regions.md#cert-manager)
  api.define('GET', '/knative-regions/:id/cert-manager', (req, db) => {
    const r = db.regions.find(x => x.id === req.params.id)
    return r ? ok(certManagerState(r)) : notFound()
  })
  api.define('POST', '/knative-regions/:id/cert-manager', (req, db) => {
    const r = db.regions.find(x => x.id === req.params.id)
    if (!r) return notFound()
    Object.assign(r, { cert_manager_status: 'installing', cert_manager_status_message: '[10:00:00] Installation queued...\n' })
    return ok({ message: 'cert-manager installation queued.', region: r }, 202)
  })
  api.define('DELETE', '/knative-regions/:id/cert-manager', (req, db) => {
    const r = db.regions.find(x => x.id === req.params.id)
    if (!r) return notFound()
    Object.assign(r, { cert_manager_status: 'uninstalling' })
    return ok({ message: 'cert-manager removal queued.', region: r }, 202)
  })
  api.define('GET', '/knative-regions/:id/cert-manager/ca-certificate', (req, db) => {
    const r = db.regions.find(x => x.id === req.params.id)
    return r && issuerKind(r.cert_manager_issuer ?? null) === 'ca' ? caDownload(r.name) : ok({ message: 'This region has no region CA.' }, 404)
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
  // TenantController::current: the organization of the request's X-Tenant-ID (none: the platform),
  // and the account's tenants with the membership flag (false = opened as administrator)
  api.define('GET', '/tenant/current', (req, db) => {
    const tenant = db.tenants.find(t => t.id === req.headers['x-tenant-id']) ?? null
    return ok({
      tenant, role: tenant?.role ?? null, mode: tenant ? 'tenant' : 'central', impersonating: false,
      tenants: db.tenants.map(t => ({ member: true, ...t })),
    })
  })
  api.define('GET', '/tenant/check/:slug', (req, db) => {
    const t = db.tenants.find(x => x.slug === req.params.slug)
    return t ? ok({ id: t.id, name: t.name, slug: t.slug }) : notFound()
  })
  api.define('GET', '/tenants', (_, db) => ok({ data: db.tenants }))
  api.define('GET', '/tenants/:id', (req, db) => {
    const t = db.tenants.find(x => x.id === req.params.id)
    return t ? ok({ data: t }) : notFound()
  })
  api.define('GET', '/resource-usage', () => ok({ data: [] }))

  // ── Billing module (docs/billing.md; the modules server builds it in): nothing billed yet ──
  const emptyList = () => ok({ data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } })
  const period = new Date().toISOString().slice(0, 7)
  api.define('GET', '/billing/settings', (_, db) => ok({
    enabled: true, currency: 'USD', context: db.activeTenant ? 'tenant' : (db.settings.tenancy_enabled ? 'platform' : 'install'),
    webhook: { url: null, url_source: null, secret_set: false },
  }))
  api.define('GET', '/billing/bills', emptyList)
  api.define('GET', '/billing/me', () => ok({ period, currency: 'USD', total: 0, data: [] }))
  api.define('GET', '/billing/rollup', () => ok({ period, currency: 'USD', data: [] }))
  api.define('GET', '/billing/fixed-requests', emptyList)
  api.define('GET', '/billing/regions', (_, db) => ok({
    data: db.regions.map(r => ({
      id: r.id, name: r.name, own: true, billing_type: 'payg', type_locked: false, active_allocations: 0, pending_requests: 0,
      prices: { ...BILLING_DEFAULT_PRICES, source: 'default', effective_from: '2000-01-01', price_id: null }, scheduled_prices: [],
    })),
  }))
  api.define('GET', '/billing/prices', () => ok({
    currency: 'USD',
    fields: Object.keys(BILLING_DEFAULT_PRICES),
    defaults: { current: { id: 'price-default', region_id: null, effective_from: '2000-01-01', ...BILLING_DEFAULT_PRICES, source: 'platform_default' }, scheduled: [], history: [] },
    inherited_defaults: null,
  }))
  api.define('GET', '/billing/events', () => ok({ data: [], meta: { next_after: null, next_before: null, webhook_configured: false } }))
  api.define('GET', '/billing/region-offers', () => ok({ default_markup_percent: 0, data: [] }))
  api.define('GET', '/billing/markups', () => ok({ default: { current: null, scheduled: [], history: [] }, regions: [] }))
  api.define('GET', '/billing/platform-bills', emptyList)
  api.define('GET', '/billing/tenant-bills', emptyList)
  // A pay-as-you-go project's monthly cost limit: none set, nothing used this month
  const today = new Date()
  const costLimitStatus = (over: Json = {}): Json => ({
    available: true, currency: 'USD', period, limit: null, month_to_date: 0, forecast: 0,
    days_elapsed: today.getDate(), days_in_month: new Date(today.getFullYear(), today.getMonth() + 1, 0).getDate(),
    percent: null, warning_percent: 80, state: 'none', warned_at: null, reached_at: null,
    stopped: false, can_manage: true, can_resume: false, checked_at: null,
    ...over,
  })
  api.define('GET', '/projects/:id/billing/cost-limit', () => ok({ data: costLimitStatus() }))
  api.define('PUT', '/projects/:id/billing/cost-limit', req => ok({ data: costLimitStatus({
    limit: { amount: Number(req.body?.amount), action: req.body?.action, updated_by: null, updated_at: now() }, percent: 0, state: 'ok',
  }) }))
  api.define('DELETE', '/projects/:id/billing/cost-limit', () => ok({ data: costLimitStatus() }))
  api.define('POST', '/projects/:id/billing/cost-limit/resume', () => ({ status: 409, body: { message: 'The cost limit did not stop this project.', code: 'cost_limit_not_stopped' } }))
  api.define('GET', '/projects/:id/billing', (req, db) => {
    const p = project(db, req.params.id)
    if (!p) return notFound()
    const region = db.regions.find(r => r.id === p.knative_region_id) ?? null
    return ok({
      currency: 'USD', billing_type: 'payg', region: region ? { id: region.id, name: region.name } : null,
      can_request: false, can_view_costs: true, allocation: null, pending_request: null,
      current: {
        id: `bill-${p.id}`, kind: 'project', period, period_start: `${period}-01`, period_end: `${period}-28`, status: 'open', estimate: true,
        currency: 'USD', source: 'runr', project: { id: p.id, name: p.name }, owner: null, tenant: null, billing_type: 'payg',
        line_items: [], fixed_total: 0, usage_total: 0, total: 0, metered_cost: 0, difference: 0,
        payment_url: null, payment_url_set_at: null, paid: false, paid_at: null, payment_reference: null, closed_at: null,
      },
      bills: [],
      cost_limit: costLimitStatus(),
    })
  })

  // ── Onboarding (white-label, multi-tenant): the organization set up ──
  api.define('GET', '/onboarding', (_, db) => ok({
    status: 'completed', can_complete: false,
    tenant: db.activeTenant ? { id: db.activeTenant.id, name: db.activeTenant.name, slug: db.activeTenant.slug } : null,
    regions: [], region_assigned: true, default_quota: null,
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

  // ── Hosted MCP servers (docs/mcp.md): the starters of "New MCP server" and a project's server
  // (HostedServerController); a project is no MCP server until PUT …/mcp-server makes it one ──
  const mcpServers = new Map<string, Json>()
  api.define('GET', '/mcp-servers/templates', () => ok({ data: [
    { id: 'python', name: 'Python (FastMCP)', description: 'Official MCP Python SDK', service_type: 'faas', runtime: 'mcp-python', runtime_registered: true, runtime_available: true, can_register_runtime: false, runtime_manifest: null, files: { 'server.py': 'from mcp.server.fastmcp import FastMCP\n', 'requirements.txt': 'mcp\n' }, path: '/mcp' },
    { id: 'image', name: 'Container image', description: 'An MCP server you already have as a container image that serves Streamable HTTP.', service_type: 'caas', runtime: null, runtime_registered: true, runtime_available: true, can_register_runtime: false, runtime_manifest: null, files: null, path: '/mcp', port: 8080 },
  ] }))
  api.define('GET', '/projects/:id/mcp-server', (req) => {
    const server = mcpServers.get(req.params.id)
    return server ? ok({ data: server }) : { status: 404, body: { message: 'This project is not served as an MCP server.' } }
  })
  api.define('PUT', '/projects/:id/mcp-server', (req, db) => {
    const p = project(db, req.params.id)
    if (!p) return notFound()
    const prev = mcpServers.get(p.id)
    const server: Json = {
      id: prev?.id ?? api.nextId('mcp'), project_id: p.id, handle: p.slug, url: `http://127.0.0.1/mcp/servers/${p.slug}`,
      access_mode: req.body?.access_mode ?? prev?.access_mode ?? 'token', path: req.body?.path ?? prev?.path ?? '/mcp',
      audit_calls: req.body?.audit_calls ?? prev?.audit_calls ?? false, function_url: p.endpoint_url ?? null,
      key_rotated_at: prev?.key_rotated_at ?? now(), needs_redeploy: false, created_at: prev?.created_at ?? now(), updated_at: now(),
    }
    mcpServers.set(p.id, server)
    p.mcp_server = { handle: server.handle, url: server.url, access_mode: server.access_mode }
    return ok({ data: server, created: !prev }, prev ? 200 : 201)
  })
  api.define('GET', '/projects/:id/mcp-server/calls', () => ok({ data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 50 } }))

  // ── Secret environment variables (docs/security.md, docs/secret-stores.md): a value written into
  // the region (write-only: the names, version, fingerprint and who set them, never a value) or a
  // reference to a secret store with its sync status (ProjectSecretController) ──
  const secrets: Record<string, Json[]> = {}
  const secretList = (req: MockRequest, db: MockDb) => ok({
    data: secrets[req.params.id] ?? [],
    can_view_values: !!db.permissions.platform_admin || (db.permissions.permissions ?? []).includes('secrets.view_values'),
    storage: 'region',
  })
  api.define('GET', '/projects/:id/secrets', secretList)
  api.define('POST', '/projects/:id/secrets/refresh', secretList)
  api.define('PUT', '/projects/:id/secrets/:name', (req, db) => {
    if (!project(db, req.params.id)) return notFound()
    const list = (secrets[req.params.id] ??= [])
    const i = list.findIndex(x => x.name === req.params.name)
    const version = i >= 0 ? (list[i].version ?? 1) + 1 : 1
    const base = { name: req.params.name, version, updated_at: now(), updated_by: { id: db.user.id, name: db.user.name } }
    let row: Json
    const reference = req.body?.reference
    if (reference) {
      const store = db.secretStores.find(s => s.id === reference.store_id)
      if (!store) return ok({ message: 'The given data was invalid.', errors: { 'reference.store_id': ['No secret store of this organization has this ID.'] } }, 422)
      if (!reference.path) return ok({ message: 'The given data was invalid.', errors: { 'reference.path': ['The reference.path field is required.'] } }, 422)
      row = {
        ...base, source: 'external',
        reference: { store_id: store.id, store_name: store.name, provider: store.provider, path: reference.path, key: reference.key ?? null },
        sync: { status: 'pending', message: 'Not synced yet: deploy the project to apply the reference.', synced_at: null },
      }
    } else {
      const value = req.body?.value
      if (typeof value !== 'string' || value === '') {
        return ok({ message: 'The value field is required.', errors: { value: ['The value field is required.'] } }, 422)
      }
      // A stand-in for the keyed fingerprint: 12 hex digits that change with the value
      let h = 0
      for (const c of value) h = (h * 31 + c.charCodeAt(0)) >>> 0
      row = { ...base, source: 'region', stored_in: 'region', fingerprint: h.toString(16).padStart(12, '0').slice(-12) }
    }
    if (i >= 0) list[i] = row
    else list.push(row)
    return ok({ data: row, requires_deploy: true }, i >= 0 ? 200 : 201)
  })
  api.define('DELETE', '/projects/:id/secrets/:name', req => {
    const list = secrets[req.params.id] ?? []
    const i = list.findIndex(x => x.name === req.params.name)
    if (i === -1) return notFound()
    list.splice(i, 1)
    return ok({ requires_deploy: true })
  })

  // ── Secret stores of the organization (docs/secret-stores.md): settings only, never a credential ──
  const STORE_PROVIDERS = [
    { provider: 'vault', auth: ['kubernetes', 'jwt'], settings: { server: true, mount: false, kv_version: false, vault_namespace: false, ca_bundle: false, auth_mount: false, role: true, audience: false } },
    { provider: 'aws', auth: ['workload_identity'], settings: { region: true, role_arn: true, audience: false } },
    { provider: 'gcp', auth: ['workload_identity'], settings: { project_id: true, audience: true, service_account_email: false } },
    { provider: 'azure', auth: ['workload_identity'], settings: { vault_url: true, tenant_id: true, client_id: true } },
  ]
  const storeRefs = (id: string) => Object.values(secrets).flat().filter(s => s.reference?.store_id === id).length
  const storeOut = (s: Json) => ({ ...s, kubernetes_name: `runr-ss-${String(s.id).toLowerCase()}`, references: storeRefs(s.id) })
  api.define('GET', '/secret-stores', (_, db) => ok({ data: db.secretStores.map(storeOut), providers: STORE_PROVIDERS }))
  api.define('GET', '/secret-stores/:id', (req, db) => {
    const s = db.secretStores.find(x => x.id === req.params.id)
    return s ? ok({ data: storeOut(s) }) : notFound()
  })
  api.define('POST', '/secret-stores', (req, db) => {
    const b = req.body ?? {}
    const known = STORE_PROVIDERS.find(p => p.provider === b.provider)
    if (!known) return ok({ message: 'The given data was invalid.', errors: { provider: ['The selected provider is invalid.'] } }, 422)
    const unknown = Object.keys(b.settings ?? {}).filter(k => !(k in known.settings))
    if (unknown.length) {
      return ok({ message: 'The given data was invalid.', errors: Object.fromEntries(unknown.map(k => [`settings.${k}`, [`"${k}" is not a setting of a ${b.provider} store.`]])) }, 422)
    }
    const store = {
      id: api.nextId('ss'), name: b.name, provider: b.provider, auth: b.auth ?? known.auth[0],
      settings: b.settings ?? {}, refresh_interval: b.refresh_interval ?? 3600, regions: {},
      audience: b.provider === 'aws' ? 'sts.amazonaws.com' : b.auth === 'jwt' ? 'vault' : null,
      created_at: now(), updated_at: now(),
    }
    db.secretStores.push(store)
    return ok({ data: storeOut(store) }, 201)
  })
  api.define('PUT', '/secret-stores/:id', (req, db) => {
    const s = db.secretStores.find(x => x.id === req.params.id)
    if (!s) return notFound()
    Object.assign(s, { name: req.body?.name ?? s.name, auth: req.body?.auth ?? s.auth, settings: { ...s.settings, ...(req.body?.settings ?? {}) }, updated_at: now() })
    return ok({ data: storeOut(s) })
  })
  api.define('DELETE', '/secret-stores/:id', (req, db) => {
    const i = db.secretStores.findIndex(x => x.id === req.params.id)
    if (i === -1) return notFound()
    if (storeRefs(req.params.id) > 0) return ok({ message: 'Secret variables of a project refer to this store: change them first.' }, 409)
    db.secretStores.splice(i, 1)
    return { status: 204 }
  })
  api.define('GET', '/secret-stores/:id/trust', (req, db) => {
    const s = db.secretStores.find(x => x.id === req.params.id)
    const region = db.regions.find(r => r.id === req.query.get('region_id'))
    if (!s || !region) return notFound()
    const namespace = region.project_namespace ?? region.name
    const sa = `runr-ss-${String(s.id).toLowerCase()}`
    return ok({ data: {
      region: { id: region.id, name: region.name },
      issuer: 'https://kubernetes.default.svc.cluster.local',
      jwks_uri: 'https://203.0.113.10:6443/openid/v1/jwks',
      jwks: { keys: [{ kty: 'RSA', kid: 'e2e-key', use: 'sig', alg: 'RS256', n: 'e2e-public-modulus', e: 'AQAB' }] },
      pem_keys: ['-----BEGIN PUBLIC KEY-----\nE2E-PUBLIC-KEY\n-----END PUBLIC KEY-----'],
      publicly_reachable: false,
      namespace, service_account: sa, subject: `system:serviceaccount:${namespace}:${sa}`,
      audience: s.audience ?? null,
      eso_status: 'ready',
    } })
  })
  api.define('POST', '/secret-stores/:id/verify', (req, db) => {
    const s = db.secretStores.find(x => x.id === req.params.id)
    if (!s) return notFound()
    const regionId = req.body?.region_id
    s.regions = { ...(s.regions ?? {}), [regionId]: { status: 'ready', message: null, checked_at: now() } }
    return ok({ data: { status: 'ready', message: null, region_id: regionId } })
  })

  // ── External Secrets Operator of a region (docs/secret-stores.md) ──
  api.define('GET', '/knative-regions/:id/external-secrets', (req, db) => {
    const r = db.regions.find(x => x.id === req.params.id)
    if (!r) return notFound()
    return ok({ data: {
      status: r.eso_status ?? 'not_installed', message: null, chart_version: r.eso_status === 'ready' ? '0.19.2' : null,
      release_chart_version: '0.19.2', installed_at: null, references: 0, can_manage: true,
    } })
  })
  api.define('POST', '/knative-regions/:id/external-secrets', (req, db) => {
    const r = db.regions.find(x => x.id === req.params.id)
    if (!r) return notFound()
    r.eso_status = 'ready'
    return ok({ message: 'External Secrets Operator is being installed.', data: { status: 'installing', release_chart_version: '0.19.2', references: 0, can_manage: true } }, 202)
  })
  api.define('DELETE', '/knative-regions/:id/external-secrets', (req, db) => {
    const r = db.regions.find(x => x.id === req.params.id)
    if (!r) return notFound()
    r.eso_status = 'not_installed'
    return ok({ message: 'External Secrets Operator is being removed.', data: { status: 'uninstalling', release_chart_version: '0.19.2', references: 0, can_manage: true } }, 202)
  })

  // ── The region's own OpenBao (docs/secret-stores.md, OpenBaoController): needs the operator;
  // the install answers "installing", the next status read "ready" ──
  const openBao = (r: Json, extra: Json = {}) => ({
    status: r.openbao_status ?? 'not_installed', message: null,
    version: r.openbao_status === 'ready' ? '2.4.1' : null, release_version: '2.4.1', installed_at: null,
    default_for_values: r.openbao_default ?? true, values: r.openbao_values ?? 0,
    eso_status: r.eso_status ?? 'not_installed', can_manage: true, ...extra,
  })
  api.define('GET', '/knative-regions/:id/openbao', (req, db) => {
    const r = db.regions.find(x => x.id === req.params.id)
    if (!r) return notFound()
    return ok({ data: openBao(r) })
  })
  api.define('POST', '/knative-regions/:id/openbao', (req, db) => {
    const r = db.regions.find(x => x.id === req.params.id)
    if (!r) return notFound()
    if (r.eso_status !== 'ready') {
      return ok({ message: `OpenBao in region "${r.name}" needs External Secrets Operator there: install it first on the region's Secrets tab.` }, 422)
    }
    r.openbao_status = 'ready'
    return ok({ message: 'OpenBao is being installed.', data: openBao(r, { status: 'installing', version: null }) }, 202)
  })
  api.define('PATCH', '/knative-regions/:id/openbao', (req, db) => {
    const r = db.regions.find(x => x.id === req.params.id)
    if (!r) return notFound()
    r.openbao_default = !!req.body?.default_for_values
    return ok({ data: openBao(r) })
  })
  api.define('DELETE', '/knative-regions/:id/openbao', (req, db) => {
    const r = db.regions.find(x => x.id === req.params.id)
    if (!r) return notFound()
    if ((r.openbao_values ?? 0) > 0) {
      return ok({ message: `Secret values or dynamic credentials of projects in region "${r.name}" live in its OpenBao: move them before removing it.` }, 409)
    }
    r.openbao_status = 'not_installed'
    return ok({ message: 'OpenBao is being removed.', data: openBao(r, { status: 'uninstalling' }) }, 202)
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
