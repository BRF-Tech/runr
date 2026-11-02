import { expect, type Page } from '@playwright/test'
import type { Json } from '../e2e/fixtures/data'
import { DEPLOY_STRATEGY_DEFAULTS, globalObject, type MockApi } from '../e2e/fixtures/mock-api'
import { ago, DAY, deployment, DOMAIN, makeProject, NOW, PEOPLE, version } from './db'

// The scenes of the showcase screenshots (shots/shots.spec.ts renders each in both themes).
// A scene seeds the mocked API (over shots/db.ts) and brings the console to the state of the
// shot; the fakes follow the handlers of the e2e specs of the same features.

export interface Scene {
  /** File name: landing/shots/<name>-<theme>.png */
  name: string
  title: string
  /** The console route of the shot, for the manifest */
  page: string
  /** Data of this scene on top of the Northwind dataset */
  seed?: (api: MockApi) => void
  /** Navigates and brings the page to the state of the shot */
  prepare: (page: Page, api: MockApi) => Promise<void>
}

const OW = '/projects/p-order-webhooks'
const CA = '/projects/p-checkout-api'
const notFound = { status: 404, body: { message: 'Not found.' } }

// ── Environments of order-webhooks (docs/environments.md) ─────────────────────────────────

function seedEnvironments(api: MockApi): void {
  const region = { id: 'r-eu', name: 'eu-1' }
  const staging = deployment('d-ow-st-8', 'v-ow-8', 'v8', 'active', 'order-webhooks-staging', 'Priya Patel', 35, { environment: 'staging' })
  const dev = deployment('d-ow-dev-8', 'v-ow-8', 'v8', 'active', 'order-webhooks-dev', 'Jonas Weber', 3 * 60, { environment: 'dev' })
  const production = api.db.deployments['p-order-webhooks'][0]
  const envs: Json[] = [
    { id: 'e-dev', name: 'dev', is_primary: false, requires_approval: false, workload_name: 'order-webhooks-dev', git_branch: 'feature/*', region, url: `https://order-webhooks-dev.eu-1.${DOMAIN}`, overrides: { min_scale: 0, env_vars: { LOG_LEVEL: 'debug' } }, deploy_strategy: 'direct', deployment: dev },
    { id: 'e-staging', name: 'staging', is_primary: false, requires_approval: false, workload_name: 'order-webhooks-staging', git_branch: 'develop', region, url: `https://order-webhooks-staging.eu-1.${DOMAIN}`, overrides: { min_scale: 0 }, deploy_strategy: 'direct', deployment: staging },
    { id: 'e-prod', name: 'production', is_primary: true, requires_approval: true, workload_name: 'order-webhooks', git_branch: 'main', region, url: `https://order-webhooks.eu-1.${DOMAIN}`, overrides: {}, deploy_strategy: 'blue_green', deployment: production },
  ]
  api
    .on('GET', `${OW}/environments`, () => ({ body: { data: envs, abilities: { manage: true, promote: true, approve: true } } }))
    .on('GET', `${OW}/deployments`, (req, db) => {
      const env = req.query.get('environment') ?? 'production'
      const rows = env === 'staging' ? [staging] : env === 'dev' ? [dev] : db.deployments['p-order-webhooks']
      return { body: { data: rows } }
    })
    .on('GET', `${OW}/environments/staging/promote`, () => ({ body: { data: {
      source: { environment: 'staging' }, target: { environment: 'production' },
      code: { from: { label: 'v7' }, to: { label: 'v8', image: '10.96.100.50:5000/runr-builds/order-webhooks:v8' }, changes: true },
      config: [
        { field: 'env_vars', added: ['REFUND_TOPIC'], removed: [], changed: ['LEDGER_URL'] },
        { field: 'min_scale', from: 0, to: 1 },
      ],
      environment_differences: [], requires_approval: true, can_promote: true, problem: null, can_approve: true,
    } } }))
    .on('GET', `${OW}/promotions`, () => ({ body: { data: [
      { id: 'pr-7', project_id: 'p-order-webhooks', project: { id: 'p-order-webhooks', name: 'order-webhooks' }, source: 'staging', target: 'production', version: { id: 'v-ow-8', label: 'v8' }, status: 'pending_approval', message: 'Release v8: refund fan-out to the ledger topic', requested_by: 'Priya Patel', created_at: ago(28), can_approve: true, can_cancel: true },
      { id: 'pr-6', project_id: 'p-order-webhooks', project: { id: 'p-order-webhooks', name: 'order-webhooks' }, source: 'staging', target: 'production', version: { id: 'v-ow-7', label: 'v7' }, status: 'deployed', message: 'Release v7: Stripe and Shopify signature presets', requested_by: 'Priya Patel', decided_by: 'Alex Chen', decision_comment: 'Verified on staging with the replay set', created_at: ago(2 * DAY + 40), decided_at: ago(2 * DAY + 22), can_approve: false, can_cancel: false },
    ] } }))
}

// ── Canary rollout of checkout-api (docs/deploy-strategies.md) ────────────────────────────

const CANARY = { steps: [10, 50, 100], interval_minutes: 10, error_rate_threshold: 2, p95_threshold_ms: 500 }

function seedCanaryRollout(api: MockApi): void {
  const db = api.db
  const stable = { ...db.deployments['p-checkout-api'][0], rollout: null, rollout_role: 'stable' }
  const candidate: Json = deployment('d-ca-12', 'v-ca-12', 'v12', 'running', 'checkout-api', 'Jonas Weber', 14, { rollout_role: 'candidate' })
  candidate.rollout = {
    id: '01JRLLOUTCHECKOUT0000000001', project_id: 'p-checkout-api', deployment_id: candidate.id, stable_deployment_id: stable.id,
    strategy: 'canary', status: 'progressing', open: true, traffic: { candidate: 50, stable: 50 },
    revision: 'checkout-api-00012', stable_revision: 'checkout-api-00011', tag: 'canary',
    test_url: `https://canary-checkout-api.eu-1.${DOMAIN}`, previous_url: null,
    options: { ...CANARY },
    step: { index: 1, count: 3, percent: 50, next_percent: 100, started_at: ago(4), ends_at: ago(-6) },
    health: null,
    metrics: { tracing: true, error_rate: 0.3, p95_ms: 142.7, volume: 1840, window_minutes: 5, measured_at: ago(0), error_rate_threshold: 2, p95_threshold_ms: 500 },
    keep_until: null, promoted_at: null, finished_at: null, released_at: null, next_check_at: ago(-1), reason: null,
    events: [
      { at: ago(14), type: 'created', message: 'Rollout started by jonas', traffic: 0, actor: 'jonas' },
      { at: ago(13), type: 'ready', message: 'New revision ready', traffic: 10, actor: 'system' },
      { at: ago(13), type: 'step', message: 'Step 1 of 3: 10 % of the traffic', traffic: 10, actor: 'system' },
      { at: ago(4), type: 'health', message: 'Step 1 healthy: error rate 0.2 %, p95 138 ms', traffic: 10, actor: 'system' },
      { at: ago(4), type: 'step', message: 'Step 2 of 3: 50 % of the traffic', traffic: 50, actor: 'system' },
    ],
    can: { promote: true, abort: true, rollback: false },
    created_by: 'jonas', created_at: ago(14), updated_at: ago(4),
  }
  db.deployments['p-checkout-api'] = [candidate, stable]
  api
    .on('GET', `${CA}/rollout`, () => ({ body: { data: candidate.rollout } }))
    .on('GET', `${CA}/deployments/:did/rollout`, (req) => (req.params.did === candidate.id ? { body: { data: candidate.rollout } } : notFound))
    .on('GET', `${CA}/deploy-strategy`, () => ({ body: { data: {
      strategy: 'canary', blue_green: { ...DEPLOY_STRATEGY_DEFAULTS.blue_green }, canary: { ...CANARY },
      eligible: true, trigger_type: 'http', tracing: true, defaults: structuredClone(DEPLOY_STRATEGY_DEFAULTS),
    } } }))
}

// ── Logs of the order-webhooks deployment ─────────────────────────────────────────────────

const PODS = [
  { name: 'order-webhooks-00007-deployment-6d9f7c8b4-x7k2p', status: 'Running' },
  { name: 'order-webhooks-00007-deployment-6d9f7c8b4-m4q8z', status: 'Running' },
]

/** Deterministic pseudo-random numbers (the same logs every run). */
function rng(seed: number): () => number {
  let s = seed >>> 0
  return () => { s = (s * 1664525 + 1013904223) >>> 0; return s / 4294967296 }
}

function logLines(): string {
  const r = rng(20261102)
  const orders = () => `NW-${48100 + Math.floor(r() * 400)}`
  const templates: Array<() => string> = [
    () => `INFO  POST /hooks/shop 202 ${18 + Math.floor(r() * 60)}ms order=${orders()} source=shopify signature=ok`,
    () => `INFO  POST /hooks/shop 202 ${22 + Math.floor(r() * 70)}ms order=${orders()} source=stripe signature=ok`,
    () => `INFO  published order.created topic=order.events order=${orders()} partitions=3`,
    () => `INFO  GET /healthz 200 1ms`,
    () => `DEBUG idempotency key already seen order=${orders()}, skipping fan-out`,
    () => `INFO  published order.paid topic=order.events order=${orders()} amount=${(20 + r() * 380).toFixed(2)} EUR`,
    () => `WARN  ledger responded in ${1100 + Math.floor(r() * 900)}ms (threshold 1000ms) order=${orders()}`,
    () => `INFO  POST /hooks/shop 401 3ms source=unknown signature=invalid`,
  ]
  const burst = [
    `ERROR publish failed topic=order.events: ECONNRESET from redis-master.runr-system:6379, retry in 2s (attempt 1/5)`,
    `WARN  publish retried topic=order.events attempt=2 backoff=2s`,
    `INFO  publish recovered topic=order.events attempt=2 queued=14`,
  ]
  const lines: Array<[number, string, number]> = []
  const start = NOW - 24 * 60_000
  // steady traffic with a busier window around minute 15 and the retry burst at minute 17
  for (let t = start; t < NOW - 20_000; t += 9_000 + r() * 26_000) {
    const minute = (t - start) / 60_000
    const extra = minute > 13 && minute < 19 ? 2 : 0
    for (let i = 0; i < 1 + extra * (r() > 0.4 ? 1 : 0); i++) {
      lines.push([Math.round(t + i * 400), templates[Math.floor(r() * templates.length)](), r() > 0.5 ? 0 : 1])
    }
    if (Math.abs(minute - 17) < 0.15 && !lines.some(l => l[1].startsWith('ERROR'))) {
      burst.forEach((text, i) => lines.push([Math.round(t + 2000 + i * 2100), text, 0]))
    }
  }
  lines.push([NOW - 9_000, `INFO  POST /hooks/shop 202 31ms order=${orders()} source=shopify signature=ok`, 1])
  lines.push([NOW - 4_000, `INFO  published order.created topic=order.events order=${orders()} partitions=3`, 1])
  lines.sort((a, b) => a[0] - b[0])
  return PODS.map((pod, idx) => `=== ${pod.name} ===\n${lines.filter(l => l[2] === idx).map(([ts, text]) => `${ts}\t${text}`).join('\n')}`).join('\n')
}

function seedLogs(api: MockApi): void {
  const raw = logLines()
  api
    .on('GET', `${OW}/deployments/:did/logs`, () => ({ body: { logs: raw, pods: PODS, source: 'loki', last_ts_ms: NOW } }))
    .on('GET', `${OW}/deployments/:did/metrics`, (req) => ({ body: req.query.get('quick')
      ? { cpu: 0.184, memory: 236.4, rps: 23.7, pods: 2 }
      : { cpuAvg1h: 0.162, memAvg1h: 221.9, rps: 21.2 } }))
}

// ── Triggers of order-webhooks (docs/triggers.md) ─────────────────────────────────────────

const TRIGGER_TYPES: Json[] = [
  ['http', 'HTTP', true, false, false, false, false, [], 1],
  ['schedule', 'Schedule', false, true, false, false, false, [], null],
  ['queue', 'Queue', false, true, true, false, false, [], null],
  ['workflow', 'Workflow', true, false, false, true, false, [], 1],
  ['webhook', 'Webhook inbox', false, true, false, false, true, ['signing_secret'], null],
  ['topic', 'Topic', false, true, true, false, false, [], null],
  ['platform_event', 'Platform events', false, true, false, false, true, [], null],
].map(([type, label, serves_http, gateway, requires_redis, exclusive, stores_events, secrets, max_per_project]) => ({
  type, label, serves_http, gateway, requires_redis, exclusive, stores_events, secrets, max_per_project, fields: [],
}))
const PLATFORM_EVENTS = ['deployment.finished', 'deployment.failed', 'alert.fired', 'alert.resolved', 'runtime.patch_available', 'cost.limit_reached']
const DELIVERY = { concurrency: 4, max_retries: 5, retry_backoff_seconds: 2, retry_backoff_max_seconds: 300 }
const meta = (type: string) => TRIGGER_TYPES.find(x => x.type === type)!

function seedTriggers(api: MockApi): void {
  const rows: Json[] = [
    { name: 'http', type: 'http', config: {}, created: 61 * DAY },
    { name: 'shop-webhooks', type: 'webhook', config: { verify: 'stripe', retention_days: 14, delivery: { max_retries: 8 } }, secret: true, key: '3f9a1c7e5b2d8046a9c1e3f57b2d4a6c8e0f1b3d', created: 40 * DAY },
    { name: 'retry-sweep', type: 'schedule', config: { cron: '*/15 * * * *', timezone: 'Europe/Berlin' }, created: 24 * DAY },
    { name: 'payment-events', type: 'queue', config: { stream: 'payment-events', start_from: 'earliest' }, created: 16 * DAY },
    { name: 'order-events', type: 'topic', config: { topic: 'order.events', start_from: 'latest' }, created: 9 * DAY },
    { name: 'deploy-alerts', type: 'platform_event', config: { events: ['deployment.failed', 'alert.fired'] }, enabled: false, created: 5 * DAY },
  ].map((r, i) => ({ id: `01JTRG${String(i + 1).padStart(20, '0')}`, enabled: true, position: i, ...r }))

  const data = rows.map(r => ({
    id: r.id, name: r.name, type: r.type, label: meta(r.type).label, enabled: r.enabled, position: r.position,
    primary: r.position === 0,
    runs_on: !r.enabled ? 'disabled' : (meta(r.type).serves_http ? 'service' : 'gateway'),
    config: r.config, secrets: r.secret ? ['signing_secret'] : [],
    delivery: meta(r.type).gateway ? { ...DELIVERY, ...(r.config.delivery ?? {}) } : null,
    retention_days: meta(r.type).stores_events ? (r.config.retention_days ?? 7) : null,
    created_at: ago(r.created), updated_at: ago(r.created),
    ...(r.type === 'webhook' ? { url: `https://api.${DOMAIN}/api/hooks/central/${r.key}`, has_signing_secret: true, events_count: 1284, failed_count: 2 } : {}),
  }))
  const plan = { workload: 'service', public: true, gateway: true, runtime_trigger: 'http', requires_redis: true }
  const topics = [
    { id: 'tp-1', name: 'order.events', knative_region_id: 'r-eu', region_name: 'eu-1', retention_days: 7, subscriptions: 3, created_at: ago(30 * DAY) },
    { id: 'tp-2', name: 'ledger.events', knative_region_id: 'r-eu', region_name: 'eu-1', retention_days: 3, subscriptions: 1, created_at: ago(20 * DAY) },
  ]
  api
    .on('GET', '/trigger-types', () => ({ body: { data: TRIGGER_TYPES, platform_events: PLATFORM_EVENTS, webhook_presets: ['none', 'stripe', 'github', 'slack', 'hmac'] } }))
    .on('GET', OW, (_, db) => ({ body: { data: { ...db.projects.find(p => p.id === 'p-order-webhooks'), triggers: data } } }))
    .on('GET', `${OW}/triggers`, () => ({ body: { data, plan } }))
    .on('GET', `${OW}/triggers/:trigger/events`, () => ({ body: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } } }))
    .on('GET', '/topics', () => ({ body: { data: topics } }))
}

// ── Sandboxes (docs/sandbox-api.md) ───────────────────────────────────────────────────────

function seedSandboxes(api: MockApi): void {
  const region = (id: string, name: string, installed: string[], def: string) => ({
    region: { id, name }, enabled: true, installed_classes: installed, allowed_classes: null, effective_classes: installed,
    default_class: null, effective_default_class: def, allow_runc: false, default_ttl_seconds: 900, max_ttl_seconds: 86400,
    max_cpu_m: 4000, max_memory_mb: 8192, max_disk_mb: 10240, can_manage: true,
  })
  const regions = [region('r-eu', 'eu-1', ['kata-fc', 'kata-clh', 'gvisor'], 'kata-fc'), region('r-us', 'us-1', ['gvisor'], 'gvisor')]
  const sandbox = (n: number, o: Json): Json => {
    const created = NOW - (o.ageMin ?? 5) * 60_000
    const ttl = o.ttl ?? 900
    return {
      id: `01JSBX${String(n).padStart(20, '0')}`, status: o.status ?? 'running', region: o.region ?? { id: 'r-eu', name: 'eu-1' },
      runtime: o.runtime ?? 'python', runtime_version: o.version ?? '3.12', image: `ghcr.io/brf-tech/runr/exec-${o.runtime ?? 'python'}:${o.version ?? '3.12'}`,
      sandbox_class: o.cls ?? 'kata-fc', isolation: o.isolation ?? 'kata',
      resources: { cpu: o.cpu ?? '500m', cpu_m: Number(String(o.cpu ?? '500m').replace('m', '')), memory_mb: o.memory ?? 512, disk_mb: o.disk ?? 1024, pids_limit: 256 },
      network: o.network ?? { mode: 'none', egress_allow: [] },
      read_only_root: true, env_keys: [], metadata: o.metadata ?? {},
      ttl_seconds: ttl, idle_timeout_seconds: 600,
      expires_at: new Date(created + ttl * 1000).toISOString(), idle_expires_at: new Date(NOW + 540_000).toISOString(),
      last_activity_at: new Date(NOW - 20_000).toISOString(), created_at: new Date(created).toISOString(), started_at: new Date(created + 200).toISOString(),
      ended_at: o.ended_at ?? null, end_reason: o.end_reason ?? null, from_pool: o.from_pool ?? false, start_ms: o.start_ms ?? 1840, error: null, created_by: '1', tenant_id: null,
    }
  }
  // Start times follow the measured figures of README.md ("Measured cold starts"): 0.28-0.36 s
  // from a warm pool, cold 2.0 s under gVisor, 3.0 s under Kata Firecracker, 4.0 s under Kata
  // Cloud Hypervisor
  const sandboxes = [
    sandbox(1, { ageMin: 3, ttl: 1800, from_pool: true, start_ms: 312, metadata: { agent: 'support-copilot', run: 'r-4821' } }),
    sandbox(2, { ageMin: 9, runtime: 'nodejs', version: '22', cls: 'gvisor', isolation: 'gvisor', cpu: '1000m', memory: 1024, start_ms: 2040, metadata: { agent: 'code-review', pr: '#318' } }),
    sandbox(3, { ageMin: 14, status: 'paused', ttl: 3600, from_pool: true, start_ms: 296, metadata: { agent: 'data-analyst' } }),
    sandbox(4, { ageMin: 21, ttl: 3600, runtime: 'go', version: '1.23', cls: 'gvisor', isolation: 'gvisor', region: { id: 'r-us', name: 'us-1' }, cpu: '2000m', memory: 2048, disk: 4096, start_ms: 2110, metadata: { agent: 'build-bot' } }),
    sandbox(5, { ageMin: 2, version: '3.11', cls: 'kata-clh', isolation: 'kata', ttl: 900, start_ms: 4030, network: { mode: 'allowlist', egress_allow: ['pypi.org:443', 'files.pythonhosted.org:443'] }, metadata: { agent: 'support-copilot', run: 'r-4830' } }),
    sandbox(6, { ageMin: 6, ttl: 1800, runtime: 'nodejs', version: '22', cls: 'gvisor', isolation: 'gvisor', from_pool: true, start_ms: 341, metadata: { agent: 'code-review', pr: '#321' } }),
    sandbox(7, { ageMin: 1, ttl: 900, status: 'pending', cls: 'kata-fc', isolation: 'kata', from_pool: false, start_ms: null, metadata: { agent: 'support-copilot', run: 'r-4833' } }),
    sandbox(8, { ageMin: 47, status: 'terminated', ended_at: ago(31), end_reason: 'expired', from_pool: true, start_ms: 288, metadata: { agent: 'data-analyst' } }),
    sandbox(9, { ageMin: 80, status: 'terminated', ended_at: ago(62), end_reason: 'deleted', runtime: 'nodejs', version: '22', cls: 'gvisor', isolation: 'gvisor' }),
  ]
  const pools = [
    { id: 'pool-1', region: { id: 'r-eu', name: 'eu-1' }, runtime: 'python', runtime_version: '3.12', image: 'ghcr.io/brf-tech/runr/exec-python:3.12', sandbox_class: 'kata-fc', cpu_m: 500, memory_mb: 512, disk_mb: 1024, size: 4, is_enabled: true, warm: 3, starting: 1, hits: 182, misses: 9, tenant_id: null, created_at: ago(20 * DAY) },
    { id: 'pool-2', region: { id: 'r-eu', name: 'eu-1' }, runtime: 'nodejs', runtime_version: '22', image: 'ghcr.io/brf-tech/runr/exec-nodejs:22', sandbox_class: 'gvisor', cpu_m: 1000, memory_mb: 1024, disk_mb: 2048, size: 2, is_enabled: true, warm: 2, starting: 0, hits: 64, misses: 4, tenant_id: null, created_at: ago(12 * DAY) },
  ]
  const alive = ['pending', 'running', 'paused']
  api
    .on('GET', '/sandbox-regions', () => ({ body: { data: regions } }))
    .on('GET', '/sandbox-pools', () => ({ body: { data: pools } }))
    .on('GET', '/sandbox-quotas', () => ({ body: { data: { tenant_id: null,
      limits: { max_concurrent: 20, max_cpu_m: 16000, max_memory_mb: 32768, max_sandbox_seconds_month: 0, max_pool_size: 10, allowed_classes: null },
      usage: { concurrent: 5, cpu_m: 4500, memory_mb: 4608, sandbox_seconds_month: 418_200, pool_size: 6 } } } }))
    .on('GET', '/sandbox-quotas/tenants', () => ({ status: 403, body: { message: 'Forbidden' } }))
    .on('GET', '/sandbox-metrics', () => ({ body: { data: { window: '24h', active: 5, pool: { hits: 246, misses: 13, hit_rate: 0.95 },
      start_time: {
        pool_hit: { count: 246, sum_ms: 78_720, buckets: [{ le_ms: 250, count: 0 }, { le_ms: 500, count: 246 }, { le_ms: null, count: 246 }] },
        cold: { count: 13, sum_ms: 34_060, buckets: [{ le_ms: 1000, count: 0 }, { le_ms: 2500, count: 8 }, { le_ms: 5000, count: 13 }, { le_ms: null, count: 13 }] },
      } } } }))
    .on('GET', '/sandboxes', (req) => {
      const status = req.query.get('status') ?? 'active'
      const list = sandboxes.filter(s => status === 'all' || (status === 'ended' ? !alive.includes(s.status) : alive.includes(s.status)))
      return { body: { data: list, meta: { total: list.length, page: 1, per_page: 50 } } }
    })
    .on('GET', '/sandboxes/:id', (req) => {
      const s = sandboxes.find(x => x.id === req.params.id)
      return s ? { body: { data: s } } : notFound
    })
    .on('GET', '/sandboxes/:id/logs', (req) => {
      const s = sandboxes.find(x => x.id === req.params.id)!
      return { body: { data: {
        events: [
          { id: 'e1', type: 'created', message: s.from_pool ? 'Sandbox created (warm pool)' : 'Sandbox created', data: {}, user_id: '1', created_at: s.created_at },
          { id: 'e2', type: 'exec', message: 'python main.py (exit 0, 412 ms)', data: {}, user_id: '1', created_at: s.last_activity_at },
        ],
        pod: { phase: 'Running', conditions: [{ type: 'Ready', status: 'True' }], events: [], log: '' },
      } } }
    })
    .on('GET', '/sandboxes/:id/files', (req) => ({ body: { data: { path: req.query.get('path') ?? '/workspace', is_dir: true, truncated: false, entries: [
      { name: 'main.py', path: '/workspace/main.py', type: 'file', size: 1284, mode: '0644', modified_at: ago(2) },
      { name: 'requirements.txt', path: '/workspace/requirements.txt', type: 'file', size: 61, mode: '0644', modified_at: ago(3) },
      { name: 'data', path: '/workspace/data', type: 'dir', size: 4096, mode: '0755', modified_at: ago(3) },
      { name: 'report.csv', path: '/workspace/report.csv', type: 'file', size: 48_211, mode: '0644', modified_at: ago(1) },
    ] } } }))
    .on('GET', '/sandboxes/:id/processes', () => ({ body: { data: [] } }))
}

// ── Model services (docs/huggingface.md) ──────────────────────────────────────────────────

function seedModels(api: MockApi): void {
  const service = (o: Json): Json => ({
    id: o.id, name: o.name, service_name: `m-${o.name}`, ai_model_id: `am-${o.name}`,
    model: { id: `am-${o.name}`, name: o.name, hub_id: o.hub_id, task: o.task, library: o.library, size_bytes: o.size },
    knative_region_id: o.region ?? 'r-eu', region_name: o.region === 'r-us' ? 'us-1' : 'eu-1', engine: o.engine, device: o.device, mode: 'online', api: o.api,
    gpu: o.gpu ?? null, cpu: o.cpu ?? '1', memory_mb: o.memory ?? 2048, min_scale: o.min_scale ?? 0, max_scale: o.max_scale ?? 1, scale_to_zero: (o.min_scale ?? 0) === 0,
    timeout_seconds: 300, image: o.image, args: o.args ?? [], env_vars: {}, port: 8000, health_path: '/health', served_name: o.name,
    status: o.status ?? 'ready', status_message: o.status_message ?? null, status_reason: null,
    url: `http://m-${o.name}.eu-1.svc.cluster.local`, playground: o.playground,
    cold_start: { size_bytes: o.size, suggest_min_scale: o.size > 5_000_000_000 }, bindings_count: o.bindings ?? 0,
    deployed_at: o.status === 'ready' || !o.status ? ago(o.deployedMin ?? 3 * DAY) : null, created_at: ago((o.deployedMin ?? 3 * DAY) + 30),
  })
  const services = [
    service({ id: 'ms-qwen', name: 'qwen2-5-7b-instruct', hub_id: 'Qwen/Qwen2.5-7B-Instruct', task: 'text-generation', library: 'transformers', size: 15_231_000_000,
      engine: 'vllm', device: 'gpu', api: 'openai', gpu: { count: 1, type: 'NVIDIA-L4', memory_mb: 24576 }, cpu: '4', memory: 16384, min_scale: 1, max_scale: 2,
      image: 'ghcr.io/brf-tech/runr/reqs/vllm-openai:v0.8.5', args: ['--max-model-len', '8192'], playground: { kind: 'chat', path: '/v1/chat/completions' }, bindings: 2, deployedMin: 6 * DAY }),
    service({ id: 'ms-embed', name: 'all-minilm-l6-v2', hub_id: 'sentence-transformers/all-MiniLM-L6-v2', task: 'feature-extraction', library: 'sentence-transformers', size: 90_900_000,
      engine: 'tei', device: 'cpu', api: 'tei', cpu: '1', memory: 1024, max_scale: 4, image: 'ghcr.io/huggingface/text-embeddings-inference:cpu-1.9.4',
      playground: { kind: 'embeddings', path: '/v1/embeddings' }, bindings: 3, deployedMin: 21 * DAY }),
    service({ id: 'ms-rerank', name: 'bge-reranker-v2-m3', hub_id: 'BAAI/bge-reranker-v2-m3', task: 'text-classification', library: 'sentence-transformers', size: 2_270_000_000,
      engine: 'tei', device: 'cpu', api: 'tei', cpu: '2', memory: 4096, image: 'ghcr.io/huggingface/text-embeddings-inference:cpu-1.9.4',
      status: 'deploying', status_message: 'Pulling model weights (1.4 GiB of 2.1 GiB)', playground: { kind: 'generic', path: '/rerank' }, deployedMin: 8 }),
    service({ id: 'ms-whisper', name: 'whisper-large-v3', hub_id: 'openai/whisper-large-v3', task: 'automatic-speech-recognition', library: 'transformers', size: 3_090_000_000,
      engine: 'transformers', device: 'gpu', api: 'runr', gpu: { count: 1, type: 'NVIDIA-L4', memory_mb: 24576 }, cpu: '2', memory: 8192, region: 'r-us',
      image: 'ghcr.io/brf-tech/runr/hf-serve:1.4.0', status: 'stopped', playground: { kind: 'asr', path: '/transcribe' }, deployedMin: 15 * DAY }),
  ]
  const abilities = { view: true, deploy: true, manage: true, hub: true }
  const gpu = {
    mode: 'operator', status: 'ready', status_message: '[09:12:04] GPU Operator ready, 2 GPU nodes\n',
    settings: { time_slicing_replicas: null, runtime_class: 'nvidia', gvisor_nvproxy: false, operator_driver: true, operator_toolkit: true },
    summary: { gpu_nodes: 2, total: 2, allocatable: 2, allocated: 1, available: 1, products: [{ product: 'NVIDIA-L4', memory_mb: 24576, count: 2, allocatable: 2 }], hardware_without_plugin: [] },
    nodes: [
      { name: 'eu-1-gpu-1', ready: true, product: 'NVIDIA-L4', memory_mb: 24576, count: 1, allocatable: 1, allocated: 1, replicas: 1, compute_capability: '8.9', driver_version: '570.86.15', cuda_version: '12.8', has_device_plugin: true, hardware_detected: true, taints: [] },
      { name: 'eu-1-gpu-2', ready: true, product: 'NVIDIA-L4', memory_mb: 24576, count: 1, allocatable: 1, allocated: 0, replicas: 1, compute_capability: '8.9', driver_version: '570.86.15', cuda_version: '12.8', has_device_plugin: true, hardware_detected: true, taints: [] },
    ],
    sandbox: { xaas: { runtime_class: null, gpu: 'ok', message: null }, faas: { runtime_class: null, gpu: 'ok', message: null }, caas: { runtime_class: null, gpu: 'ok', message: null } },
    detected_at: ago(30),
  }
  const answer = [
    'Here is a short reply you can send:',
    '',
    'Hi Dana, thanks for reaching out. You can return the jacket within 30 days of delivery as long as the tags are still attached. ',
    'Start the return from "My orders", pick "wrong size" as the reason and print the prepaid label; the refund reaches your card 3-5 business days after we receive the parcel. ',
    'If you would rather swap it for another size, choose "exchange" instead and we will ship the new one as soon as the return is scanned.',
  ].join('\n')
  api
    .on('GET', '/models/engines', () => ({ body: { engines: {
      vllm: { label: 'vLLM', devices: ['gpu'], api: 'openai', tasks: ['text-generation'] }, tgi: { label: 'TGI', devices: ['gpu'], api: 'openai', tasks: ['text-generation'] },
      tei: { label: 'TEI', devices: ['cpu', 'gpu'], api: 'tei', tasks: ['feature-extraction', 'sentence-similarity', 'text-classification'] },
      transformers: { label: 'Transformers', devices: ['cpu', 'gpu'], api: 'runr', tasks: ['*'] }, custom: { label: 'Custom', devices: ['cpu', 'gpu'], api: 'custom', tasks: ['*'] },
    }, tasks: [] } }))
    .on('GET', '/models/hub-token', () => ({ body: { configured: true, hint: 'hf_****', updated_at: ago(40 * DAY) } }))
    .on('GET', '/models/gpu-quota', () => ({ body: { quota: 2, used: 1, remaining: 1 } }))
    .on('GET', '/models', () => ({ body: { data: [], abilities } }))
    .on('GET', '/model-services', () => ({ body: { data: services, abilities } }))
    .on('GET', '/model-services/:id', (req) => {
      const s = services.find(x => x.id === req.params.id)
      return s ? { body: { data: s } } : notFound
    })
    .on('GET', '/model-services/:id/bindings', (req) => ({ body: { data: req.params.id === 'ms-qwen' ? [
      { id: 'b1', project_id: 'p-weather-tools', project: { id: 'p-weather-tools', name: 'weather-tools' }, alias: 'LLM', mount_weights: false },
      { id: 'b2', project_id: 'p-order-webhooks', project: { id: 'p-order-webhooks', name: 'order-webhooks' }, alias: 'ASSISTANT', mount_weights: false },
    ] : [] } }))
    .on('GET', '/model-services/:id/logs', () => ({ body: { logs: '' } }))
    .on('POST', '/model-services/:id/invoke', () => ({ body: { status: 200, duration_ms: 812, content_type: 'application/json', body: {
      id: 'chatcmpl-8f3a2c', object: 'chat.completion', created: Math.floor(NOW / 1000), model: 'qwen2-5-7b-instruct',
      choices: [{ index: 0, message: { role: 'assistant', content: answer }, finish_reason: 'stop' }],
      usage: { prompt_tokens: 58, completion_tokens: 112, total_tokens: 170 },
    } } }))
    .on('GET', '/knative-regions/r-eu/gpu', () => ({ body: gpu }))
}

// ── Hosted MCP server weather-tools (docs/mcp.md) ─────────────────────────────────────────

function seedMcp(api: MockApi): void {
  const base = '/projects/p-weather-tools'
  const url = `https://console.${DOMAIN}/mcp/servers/weather-tools`
  const tools = [
    { name: 'forecast', description: 'Daily forecast for a city, up to 7 days ahead', inputSchema: { type: 'object', properties: { city: { type: 'string', description: 'City name' }, days: { type: 'integer', description: 'Days ahead (1-7)' } }, required: ['city'] } },
    { name: 'current_conditions', description: 'Temperature, wind and humidity right now', inputSchema: { type: 'object', properties: { city: { type: 'string' } }, required: ['city'] } },
    { name: 'alerts', description: 'Active weather warnings for a region', inputSchema: { type: 'object', properties: { region: { type: 'string' }, severity: { type: 'string', enum: ['minor', 'moderate', 'severe'] } }, required: ['region'] } },
  ]
  const call = (id: string, min: number, name: string, actor: string, auth: string, ms: number, outcome = 'ok', error: string | null = null) => ({
    id, occurred_at: ago(min), method: 'tools/call', name, jsonrpc_id: String(2 + Number(id.slice(1))), status_code: outcome === 'ok' ? 200 : 500, outcome, error, duration_ms: ms,
    actor: { type: 'user', id: '9', name: actor }, auth, session_id: null,
  })
  const calls = [
    call('c1', 2, 'forecast', 'support-copilot', 'oauth', 31),
    call('c2', 6, 'current_conditions', 'support-copilot', 'oauth', 24),
    call('c3', 11, 'alerts', 'ops-agent', 'token', 48),
    call('c4', 19, 'forecast', 'Alex Chen', 'oauth', 29),
    call('c5', 27, 'forecast', 'support-copilot', 'oauth', 2010, 'error', 'upstream weather API timed out after 2000 ms'),
    call('c6', 41, 'current_conditions', 'ops-agent', 'token', 22),
    call('c7', 58, 'alerts', 'support-copilot', 'oauth', 51),
  ]
  api
    .on('GET', `${base}/mcp-server`, () => ({ body: { data: {
      id: 'mcps-1', project_id: 'p-weather-tools', handle: 'weather-tools', url, access_mode: 'oauth', path: '/mcp', audit_calls: true,
      function_url: `https://weather-tools.eu-1.${DOMAIN}`, key_rotated_at: ago(12 * DAY), needs_redeploy: false, created_at: ago(12 * DAY), updated_at: ago(2 * DAY),
    } } }))
    .on('GET', `${base}/mcp-server/calls`, (req) => {
      const outcome = req.query.get('outcome')
      const rows = calls.filter(c => !outcome || c.outcome === outcome)
      return { body: { data: rows, meta: { current_page: 1, last_page: 1, total: rows.length, per_page: 25 } } }
    })
    .on('POST', `${base}/mcp-server/inspector`, (req) => {
      const m = req.body.message
      const reply = (result: Json) => ({ body: { status: 200, session_id: 'sess-7f3a9c', content_type: 'application/json', messages: [{ jsonrpc: '2.0', id: m.id, result }], raw: '', duration_ms: 4 } })
      if (!('id' in m)) return { body: { status: 202, session_id: 'sess-7f3a9c', content_type: '', messages: [], raw: '', duration_ms: 1 } }
      if (m.method === 'initialize') return reply({ protocolVersion: '2025-11-25', capabilities: { tools: {} }, serverInfo: { name: 'weather-tools', version: '1.3.0' } })
      if (m.method === 'tools/list') return reply({ tools })
      if (m.method === 'tools/call') return reply({ content: [{ type: 'text', text: JSON.stringify({ city: m.params.arguments.city, days: [
        { date: '2026-11-03', summary: 'Partly cloudy', high_c: 14, low_c: 7, wind_kmh: 18 },
        { date: '2026-11-04', summary: 'Light rain', high_c: 11, low_c: 6, wind_kmh: 24 },
        { date: '2026-11-05', summary: 'Sunny', high_c: 13, low_c: 4, wind_kmh: 9 },
      ] }, null, 2) }] })
      return reply({})
    })
}

// ── Roles (docs/permissions.md) ───────────────────────────────────────────────────────────

function seedRoles(api: MockApi): void {
  api.db.roles.push(
    {
      key: 'release-manager', name: 'Release manager', description: 'Promotes and approves releases, reads logs and metrics; does not change code.',
      permissions: ['projects.view', 'projects.deploy', 'environments.promote', 'environments.approve', 'logs.view', 'metrics.view', 'traces.view', 'security.view', 'regions.view', 'members.view'],
      builtin: false, grantable: true, members_count: 1,
    },
    {
      key: 'auditor', name: 'Auditor', description: 'Read-only access to the audit log, security reports and billing.',
      permissions: ['projects.view', 'security.view', 'audit.view', 'audit.export', 'billing.view', 'members.view'],
      builtin: false, grantable: true, members_count: 0,
    },
  )
  api.db.groups.push(
    { id: 'g-1', name: 'Storefront team', role: 'developer', members: [PEOPLE.priya, PEOPLE.jonas], members_count: 2, created_at: ago(60 * DAY) },
    { id: 'g-2', name: 'Release managers', role: 'release-manager', members: [PEOPLE.mei], members_count: 1, created_at: ago(30 * DAY) },
  )
}

// ── Durable workflows of order-pipeline (docs/workflows.md) ──────────────────────────────

function seedWorkflows(api: MockApi): void {
  const db = api.db
  const id = 'p-order-pipeline'
  db.projects.unshift(makeProject({
    id, name: 'order-pipeline', type: 'faas', runtime: 'python', version: '3.12',
    description: 'Durable order workflows: charge, reserve stock, wait for the carrier, ship.',
    config: { handler: 'workflows.app', trigger_type: 'workflow', max_scale: 6 }, createdBy: PEOPLE.priya, createdDaysAgo: 28,
    extra: { latest_version: { id: 'v-op-5', label: 'v5', build_status: 'ready' }, latest_deployment: { id: 'd-op-5', status: 'active', version: { id: 'v-op-5', label: 'v5' } } },
  }))
  db.versions[id] = [version('v-op-5', 'v5', 'Refund flow', 2 * DAY), version('v-op-4', 'v4', 'Carrier wait with a 7 day timeout', 9 * DAY)]
  db.deployments[id] = [deployment('d-op-5', 'v-op-5', 'v5', 'active', 'order-pipeline', 'Priya Patel', 2 * DAY - 20)]
  db.domains[id] = []
  db.files[id] = { 'workflows.py': '# runr-workflows app\n', 'requirements.txt': 'runr-workflows>=0.4\n' }
  const base = `/projects/${id}`

  const workflows = [
    { id: 'w1', project_id: id, name: 'process-order', description: 'Charge the card, reserve stock, wait for the carrier pickup, ship.', triggers: [{ event: 'order.created' }], retries: 3, timeout: '7d', concurrency: [{ limit: 20, key: 'event.data.customer_id' }], idempotency: 'event.data.order_id', cancel_on: [{ event: 'order.cancelled' }], is_active: true, archived: false, source: 'sdk', synced_at: ago(2 * DAY), run_counts: { completed: 1284, running: 3, sleeping: 41, failed: 2 }, created_at: ago(28 * DAY) },
    { id: 'w2', project_id: id, name: 'refund', description: 'Refund a payment and restock the items.', triggers: [{ event: 'order.refund_requested' }], retries: 5, timeout: '2d', concurrency: [], idempotency: 'event.data.refund_id', cancel_on: [], is_active: true, archived: false, source: 'sdk', synced_at: ago(2 * DAY), run_counts: { completed: 96, running: 1 }, created_at: ago(2 * DAY) },
    { id: 'w3', project_id: id, name: 'nightly-reconciliation', description: 'Compares the ledger with the payment provider every night.', triggers: [{ cron: '0 3 * * *' }], retries: 1, timeout: '1h', concurrency: [{ limit: 1 }], idempotency: null, cancel_on: [], is_active: true, archived: false, source: 'sdk', synced_at: ago(2 * DAY), run_counts: { completed: 27, failed: 1 }, created_at: ago(27 * DAY) },
  ]
  const app = {
    project_id: id, trigger_type: 'workflow', is_workflow_project: true, endpoint_url: `https://order-pipeline.eu-1.${DOMAIN}`, endpoint_override: null,
    key_fingerprint: '7a1c9e4d2b60', previous_key_expires_at: null, sync_status: 'ok', sync_error: null,
    sdk: { name: 'runr-workflows', language: 'python', version: '0.4.2' }, last_synced_at: ago(2 * DAY), queue_bridge: { enabled: true, stream: 'order-events', event: 'order.created', has_redis: true },
  }
  const step = (o: Json): Json => ({ id: o.step_key, name: o.step_key, type: 'run', status: 'completed', position: 1, attempt: 1, max_attempts: 4, input: null, output: null, error: null, attempts: [], wake_at: null, timeout_at: null, wait_event: null, wait_expression: null, child_run_id: null, started_at: ago(30), ended_at: ago(29), duration_ms: 25, ...o })
  const run = (n: number, o: Json): Json => ({
    id: `01JWFRUN${String(n).padStart(18, '0')}`, workflow: { id: 'w1', name: 'process-order' }, project_id: id, trigger_source: 'event', event_id: `ev${n}`,
    event_name: 'order.created', event: { id: `ev${n}`, name: 'order.created', data: { order_id: `NW-${48200 + n}` }, ts: 1 },
    output: null, error: null, attempt: 0, retry_count: 0, idempotency_key: `NW-${48200 + n}`, concurrency_key: null, parent_run_id: null, replay_of_run_id: null, replay_from_step: null,
    next_attempt_at: null, timeout_at: ago(-7 * DAY), started_at: ago(o.min ?? 10), ended_at: null, duration_ms: o.duration ?? 1200, steps_count: o.steps?.length ?? 4, created_at: ago(o.min ?? 10), children: [],
    steps: o.steps ?? [step({ step_key: 'charge-card', output: { charged: true } }), step({ step_key: 'reserve-stock', position: 2 }), step({ step_key: 'await-carrier', type: 'wait_event', position: 3, status: 'sleeping', wake_at: ago(-2 * DAY), duration_ms: null }), step({ step_key: 'ship', position: 4, status: 'pending' })],
    ...o,
  })
  const runs = [
    run(1, { status: 'running', min: 1, duration: 4100, steps_count: 4 }),
    run(2, { status: 'sleeping', min: 23, duration: 820, steps_count: 4 }),
    run(3, { status: 'completed', min: 47, duration: 182_400, ended_at: ago(44), output: { shipped: true, tracking: 'DHL-7731-2208' }, steps_count: 4 }),
    run(4, { status: 'completed', workflow: { id: 'w2', name: 'refund' }, event_name: 'order.refund_requested', min: 65, duration: 2_950, ended_at: ago(64), output: { refunded: 149.9, currency: 'EUR' }, steps_count: 3 }),
    run(5, { status: 'failed', workflow: { id: 'w3', name: 'nightly-reconciliation' }, trigger_source: 'cron', event_name: null, event: null, min: 9 * 60 + 15, duration: 61_000, ended_at: ago(9 * 60 + 14), attempt: 1, retry_count: 1, error: { name: 'TimeoutError', message: 'payment provider report not available after 60 s' }, steps_count: 2 }),
    run(6, { status: 'sleeping', min: 3 * 60 + 12, duration: 1_410, steps_count: 4 }),
    run(7, { status: 'completed', min: 5 * 60 + 40, duration: 240_100, ended_at: ago(5 * 60 + 36), output: { shipped: true, tracking: 'UPS-1Z-99A' }, steps_count: 4 }),
    run(8, { status: 'cancelled', min: 7 * 60 + 2, duration: 3_300, ended_at: ago(7 * 60), error: { name: 'Cancelled', message: 'order.cancelled received' }, steps_count: 2 }),
    run(9, { status: 'completed', workflow: { id: 'w2', name: 'refund' }, event_name: 'order.refund_requested', min: 8 * 60 + 30, duration: 2_210, ended_at: ago(8 * 60 + 29), steps_count: 3 }),
  ]
  const summary = ({ steps, ...rest }: Json) => rest
  api
    .on('GET', `${base}/workflows`, () => ({ body: { data: workflows, app } }))
    .on('GET', `${base}/workflow-runs`, (req) => {
      const status = req.query.get('status')
      const wf = req.query.get('workflow')
      const rows = runs.filter(r => !status || status.split(',').includes(r.status)).filter(r => !wf || r.workflow.name === wf).map(summary)
      return { body: { data: rows, meta: { current_page: 1, last_page: 1, total: rows.length } } }
    })
    .on('GET', `${base}/workflow-runs/:run`, (req) => {
      const r = runs.find(x => x.id === req.params.run)
      return r ? { body: { data: r } } : notFound
    })
    .on('GET', '/workflow-usage', () => ({ body: { from: ago(30 * DAY).slice(0, 10), to: ago(0).slice(0, 10), totals: { step_executions: 5_812, runs_started: 1_431 }, daily: [], by_workflow: [] } }))
}

// ── Global project shop (docs/global-projects.md) ─────────────────────────────────────────

function seedGlobal(api: MockApi): void {
  const db = api.db
  const p = db.projects.find(x => x.id === 'p-shop')!
  for (const r of db.regions) Object.assign(r, { global_dns_ready: true })
  for (const [id, name, address] of [['r-eu', 'eu-1', '203.0.113.53'], ['r-us', 'us-1', '198.51.100.53']]) {
    db.globalDnsServers[id] = {
      region_id: id, region_name: name, status: 'ready', status_message: null, address, ns_name: `ns-${name}.g.${DOMAIN}`, reachable: true,
      sync_status: 'in_sync', sync_message: null, synced_at: ago(4), tls_status: 'ready', tls_message: null, tls_expires_at: ago(-70 * DAY), eligible: true, reason: null,
      geoip: { source: 'default', url: null, effective_url: 'https://download.db-ip.com/free/dbip-city-lite-{yyyy}-{mm}.mmdb.gz', status: 'loaded', message: 'GeoIP database loaded (130.2 MiB).', checked_at: ago(60) },
    }
  }
  const g = globalObject(db, p, 'ha', ['r-eu', 'r-us'])
  g.regions[0].version = 9
  g.regions[1].version = 9
  g.regions[1].address = '198.51.100.20'
  g.data = [{ kind: 'postgres', name: 'shop-db', primary_region_id: 'r-eu', replicas: [{ region_id: 'r-us', status: 'streaming', lag_bytes: 2048 }] }]
  g.warnings = []
  db.globalProjects['p-shop'] = g
  p.global = g
  p.placement = 'global'
  p.endpoint_url = `https://${g.hostname}`
}

// ── Supply chain of checkout-api (docs/supply-chain.md) ───────────────────────────────────

function seedSupplyChain(api: MockApi): void {
  const digest = (seed: string) => 'sha256:' + seed.repeat(64).slice(0, 64)
  const report = (vid: string, label: string, counts: Json, packages: number, min: number, extra: Json = {}): Json => ({
    id: `rep-${label}`, subject_type: 'version', version_id: vid, image_kind: 'built', image_ref: `10.96.100.50:5000/runr-builds/checkout-api:${label}`,
    image_digest: digest(label.replace('v', 'c')), status: 'completed', sbom_status: 'ok', scan_status: 'ok', signature_status: 'ok', attestation_status: 'ok', referrer_status: 'ok',
    counts: { critical: 0, high: 0, medium: 0, low: 0, unknown: 0, ...counts }, fixable: { critical: counts.critical ?? 0, high: counts.high ?? 0 },
    findings_total: Object.values(counts as Record<string, number>).reduce((a, b) => a + b, 0), packages, scanned_at: ago(min), signed_at: ago(min - 1),
    signature_key_fingerprint: 'f3'.repeat(32), finished_at: ago(min - 1), error: null, ...extra,
  })
  const versions = [
    { id: 'v-ca-12', number: 12, label: 'v12', build_status: 'ready', applicable: true, image_kind: 'built', image: '10.96.100.50:5000/runr-builds/checkout-api:v12', report: report('v-ca-12', 'v12', { high: 1, medium: 3, low: 9 }, 128, 24) },
    { id: 'v-ca-11', number: 11, label: 'v11', build_status: 'ready', applicable: true, image_kind: 'built', image: '10.96.100.50:5000/runr-builds/checkout-api:v11', report: report('v-ca-11', 'v11', { high: 2, medium: 5, low: 11 }, 127, 3 * DAY) },
    { id: 'v-ca-10', number: 10, label: 'v10', build_status: 'ready', applicable: true, image_kind: 'built', image: '10.96.100.50:5000/runr-builds/checkout-api:v10', report: report('v-ca-10', 'v10', { critical: 1, high: 3, medium: 5, low: 12 }, 131, 8 * DAY) },
    { id: 'v-ca-9', number: 9, label: 'v9', build_status: 'ready', applicable: true, image_kind: 'built', image: '10.96.100.50:5000/runr-builds/checkout-api:v9', report: report('v-ca-9', 'v9', { medium: 3, low: 8 }, 119, 15 * DAY) },
  ]
  const findings = [
    { id: 'CVE-2024-45337', severity: 'HIGH', package: 'golang.org/x/crypto', installed: 'v0.27.0', fixed: 'v0.31.0', title: 'golang.org/x/crypto/ssh: misuse of ServerConfig.PublicKeyCallback may cause authorization bypass', url: 'https://nvd.nist.gov/vuln/detail/CVE-2024-45337' },
    { id: 'CVE-2025-22871', severity: 'MEDIUM', package: 'stdlib', installed: '1.23.6', fixed: '1.23.8', title: 'net/http: request smuggling through invalid chunked data', url: 'https://nvd.nist.gov/vuln/detail/CVE-2025-22871' },
    { id: 'CVE-2024-12797', severity: 'MEDIUM', package: 'libssl3', installed: '3.3.2-r0', fixed: '3.3.3-r0', title: 'openssl: RFC7250 handshakes with unauthenticated servers do not abort as expected', url: 'https://nvd.nist.gov/vuln/detail/CVE-2024-12797' },
    { id: 'CVE-2025-26519', severity: 'MEDIUM', package: 'musl', installed: '1.2.5-r0', fixed: '1.2.5-r1', title: 'musl: out-of-bounds write in iconv EUC-KR decoder', url: 'https://nvd.nist.gov/vuln/detail/CVE-2025-26519' },
    { id: 'CVE-2024-9143', severity: 'LOW', package: 'libcrypto3', installed: '3.3.2-r0', fixed: '3.3.3-r0', title: 'openssl: low-level GF(2^m) elliptic curve APIs with untrusted explicit values for the field polynomial', url: 'https://nvd.nist.gov/vuln/detail/CVE-2024-9143' },
    { id: 'CVE-2025-27789', severity: 'LOW', package: 'busybox', installed: '1.37.0-r9', fixed: '1.37.0-r12', title: 'busybox: unsafe tar extraction', url: 'https://nvd.nist.gov/vuln/detail/CVE-2025-27789' },
  ]
  api
    .on('GET', `${CA}/security`, () => ({ body: { data: {
      region: { id: 'r-eu', name: 'eu-1', data_location: 'DE' },
      policy: { mode: 'enforce', max_critical: 0, max_high: 5, only_fixable: true, require_scan: true, require_signature: true },
      pipeline: { sbom: true, scan: true, signing: true, admission: 'enforce' },
      versions, deployments: [], overrides: [],
    } } }))
    .on('GET', `${CA}/versions/:vid/security`, (req) => {
      const v = versions.find(x => x.id === req.params.vid)
      if (!v) return notFound
      const blocked = (v.report.counts.critical ?? 0) > 0
      return { body: { data: {
        version: v,
        report: { ...v.report, findings: v.label === 'v12' ? findings : findings.slice(0, 4), sbom_summary: { packages: v.report.packages, by_type: { 'go-module': 96, apk: 32 }, licenses: { MIT: 41, 'Apache-2.0': 33, 'BSD-3-Clause': 18, 'MPL-2.0': 2 }, generator: 'syft-1.18.1' }, scanner: { version: '0.58.1', db_updated_at: ago(300) }, sbom_formats: ['spdx-json', 'cyclonedx-json'] },
        decision: { applicable: true, blocked, violations: blocked ? [{ rule: 'critical', message: '1 CRITICAL vulnerabilities (allowed: 0)', enforced: true }] : [] },
      } } }
    })
    .on('GET', '/knative-regions/r-eu/supply-chain', () => ({ body: { data: {
      knative_region_id: 'r-eu', sbom_enabled: true, scan_enabled: true, scan_external_images: true, rescan_interval_hours: 24, scanner_db_mode: 'online', scanner_db_source: null, scanner_db_status: 'ready',
      gate_mode: 'enforce', gate_max_critical: 0, gate_max_high: 5, gate_only_fixable: true, gate_require_scan: true, signing_enabled: true, verify_on_deploy: true, attest_enabled: true,
      signing_status: 'ready', signing_public_key: '-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----\n', signing_key_fingerprint: 'f3'.repeat(32), signing_retired_keys: [],
      admission_mode: 'enforce', admission_status: 'ready', admission_require_sbom: true, admission_exempt_images: null, caas_policy: 'allowlist', caas_allowed_registries: ['ghcr.io/northwind/*'], caas_trusted_keys: null, data_location: 'DE',
      region: { id: 'r-eu', name: 'eu-1', has_registry: true, registry_mode: 'internal', project_namespace: 'eu-1' },
      scanner_db_repository: '10.96.100.50:5000/runr-supply-chain/trivy-db:2', scanner_db_default_source: 'mirror.gcr.io/aquasec/trivy-db:2', supply_chain_namespace: 'runr-supply-chain', can_manage: true,
    } } }))
}

// ── Region wizard ─────────────────────────────────────────────────────────────────────────

const KUBECONFIG = [
  'apiVersion: v1',
  'kind: Config',
  'clusters:',
  '  - cluster:',
  '      server: https://10.40.0.10:6443',
  '      certificate-authority-data: LS0tLS1CRUdJTiBDRVJUSUZJQ0FURS0tLS0t',
  '    name: eu-2',
  'contexts:',
  '  - context: { cluster: eu-2, user: runr-admin }',
  '    name: eu-2',
  'current-context: eu-2',
  'users:',
  '  - name: runr-admin',
  '    user: { token: REDACTED }',
].join('\n')

// ── Shared steps ──────────────────────────────────────────────────────────────────────────

async function openProject(page: Page, id: string, tab: string | null, ready: string): Promise<void> {
  await page.goto(`/projects/${id}${tab ? `#${tab}` : ''}`)
  await expect(page.locator(ready).first()).toBeVisible()
}

/** Scrolls the page so `selector` starts right under the sticky headers (two passes: the headers stack once stuck). */
async function scrollTo(page: Page, selector: string, gap = 16): Promise<void> {
  for (let pass = 0; pass < 2; pass++) {
    await page.evaluate(({ selector, gap }) => {
      const el = document.querySelector(selector)
      if (!el) return
      const stuck = [...document.querySelectorAll<HTMLElement>('.sticky')]
        .filter(s => getComputedStyle(s).position === 'sticky' && s.getBoundingClientRect().top <= 1)
      const offset = Math.max(0, ...stuck.map(s => s.getBoundingClientRect().bottom))
      window.scrollBy({ top: el.getBoundingClientRect().top - offset - gap, behavior: 'instant' as ScrollBehavior })
    }, { selector, gap })
    await page.waitForTimeout(100)
  }
}

export const SCENES: Scene[] = [
  {
    name: 'projects', title: 'Projects', page: '/projects',
    prepare: async (page) => {
      await page.goto('/projects')
      await expect(page.locator('#p-image-resizer')).toBeVisible()
      await expect(page.locator('[data-tour="resource-bars"]')).toBeVisible()
    },
  },
  {
    name: 'project-overview', title: 'Project overview', page: '/projects/order-webhooks#deployments',
    seed: seedEnvironments,
    prepare: async (page) => {
      await openProject(page, 'p-order-webhooks', 'deployments', 'tbody tr')
      await expect(page.locator('tbody tr')).toHaveCount(6)
      await expect(page.locator('[data-test="deploy-strategy-open"]')).toBeVisible()
    },
  },
  {
    name: 'code-editor', title: 'Code editor', page: '/projects/image-resizer',
    prepare: async (page) => {
      await openProject(page, 'p-image-resizer', null, '.monaco-editor .view-lines')
      await expect(page.locator('.monaco-editor .view-lines')).toContainText('def handler')
      await page.waitForTimeout(1_000)
    },
  },
  {
    name: 'deploy-rollout', title: 'Canary rollout', page: '/projects/checkout-api#deployments',
    seed: seedCanaryRollout,
    prepare: async (page) => {
      await openProject(page, 'p-checkout-api', 'deployments', '[data-test="current-rollout"]')
      await expect(page.locator('[data-test="current-rollout"]')).toContainText('50')
      const toggle = page.locator('[data-test="rollout-events-toggle"]')
      if (await toggle.isVisible()) await toggle.click()
      await expect(page.locator('tbody tr')).toHaveCount(2)
    },
  },
  {
    name: 'logs-metrics', title: 'Logs and metrics', page: '/projects/order-webhooks#deployments (logs)',
    seed: (api) => { seedEnvironments(api); seedLogs(api) },
    prepare: async (page) => {
      await openProject(page, 'p-order-webhooks', 'deployments', 'tbody tr')
      await page.locator('tbody tr').first().getByTitle('View logs').click()
      await expect(page.getByText('POST /hooks/shop').first()).toBeVisible()
      await expect(page.getByText('ECONNRESET').first()).toBeVisible()
      await page.waitForTimeout(2_600)
    },
  },
  {
    name: 'triggers', title: 'Triggers', page: '/projects/order-webhooks#triggers',
    seed: seedTriggers,
    prepare: async (page) => {
      await openProject(page, 'p-order-webhooks', 'triggers', '[data-test="trigger-list"]')
      await expect(page.locator('[data-test="runs-on"]')).toHaveCount(6)
    },
  },
  {
    name: 'environments', title: 'Environments and promotion', page: '/projects/order-webhooks#environments',
    seed: seedEnvironments,
    prepare: async (page) => {
      await openProject(page, 'p-order-webhooks', 'environments', '[data-testid="environment-card-staging"]')
      await expect(page.getByTestId('environment-card-production')).toBeVisible()
      await expect(page.getByText('Release v8: refund fan-out')).toBeVisible()
    },
  },
  {
    name: 'deploy-strategies', title: 'Deploy strategy', page: '/projects/checkout-api#deployments (strategy)',
    seed: seedCanaryRollout,
    prepare: async (page) => {
      await openProject(page, 'p-checkout-api', 'deployments', '[data-test="deploy-strategy-open"]')
      await page.locator('[data-test="deploy-strategy-open"]').click()
      const modal = page.locator('[data-test="deploy-strategy-modal"]')
      await expect(modal.locator('[data-test="deploy-strategy-canary"]')).toHaveAttribute('aria-checked', 'true')
    },
  },
  {
    name: 'sandbox', title: 'Sandboxes', page: '/sandboxes',
    seed: seedSandboxes,
    prepare: async (page) => {
      await page.goto('/sandboxes')
      await expect(page.locator('[data-sandbox-row]')).toHaveCount(7)
    },
  },
  {
    name: 'huggingface', title: 'Model services and playground', page: '/models?tab=services',
    seed: seedModels,
    prepare: async (page) => {
      await page.goto('/models?tab=services')
      await expect(page.locator('[data-service-row="qwen2-5-7b-instruct"]')).toBeVisible()
      await page.locator('[data-service-row="qwen2-5-7b-instruct"]').click()
      const detail = page.getByTestId('service-detail')
      await expect(detail.getByTestId('service-status')).toHaveText('Ready')
      await detail.getByTestId('pg-text').fill('A customer ordered a jacket in the wrong size and asks how to return or exchange it. Draft a short, friendly reply that explains our 30-day return policy.')
      await detail.getByTestId('pg-run').click()
      await expect(detail.getByTestId('pg-result')).toContainText('prepaid label')
    },
  },
  {
    name: 'mcp', title: 'Hosted MCP server', page: '/projects/weather-tools#mcp',
    seed: seedMcp,
    prepare: async (page) => {
      await openProject(page, 'p-weather-tools', 'mcp', '[data-testid="mcp-server-panel"]')
      await expect(page.getByTestId('mcp-call-c1')).toBeVisible()
      const inspector = page.getByTestId('mcp-inspector')
      await inspector.getByTestId('mcp-inspector-connect').click()
      await expect(inspector.getByTestId('mcp-inspector-session')).toBeVisible()
      await inspector.getByTestId('mcp-inspector-tool-forecast').click()
      await inspector.getByTestId('mcp-field-city').fill('Berlin')
      await inspector.getByTestId('mcp-field-days').fill('3')
      await inspector.getByTestId('mcp-inspector-call').click()
      await expect(inspector).toContainText('Partly cloudy')
      await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' as ScrollBehavior }))
    },
  },
  {
    name: 'region-wizard', title: 'Add Region wizard', page: '/settings (Add Region)',
    prepare: async (page) => {
      await page.goto('/settings')
      await page.getByRole('button', { name: '+ Add Region' }).click()
      await expect(page.getByRole('heading', { name: 'Add Region' })).toBeVisible()
      await page.getByPlaceholder('istanbul-1a').fill('eu-2')
      await page.getByRole('button', { name: 'Paste Content' }).click()
      await page.locator('textarea').fill(KUBECONFIG)
      await page.getByRole('button', { name: 'Continue →' }).click()
      await expect(page.getByText(/Step 2 \/ 7/)).toBeVisible()
      await expect(page.getByText('eu-2-worker-1', { exact: true })).toBeVisible()
    },
  },
  {
    name: 'rbac', title: 'Roles and permissions', page: '/settings?tab=roles',
    seed: seedRoles,
    prepare: async (page) => {
      await page.goto('/settings?tab=roles')
      await expect(page.getByTestId('roles-manager')).toBeVisible()
      await page.locator('[data-role="release-manager"] button').first().click()
      await expect(page.locator('[data-role="release-manager"] [data-testid="permission-matrix"]')).toBeVisible()
      await scrollTo(page, '[data-role="release-manager"]', 24)
    },
  },
  {
    name: 'workflows', title: 'Durable workflows', page: '/projects/order-pipeline#workflows',
    seed: seedWorkflows,
    prepare: async (page) => {
      await openProject(page, 'p-order-pipeline', 'workflows', '[data-test="workflows-tab"]')
      await expect(page.locator('[data-run]')).toHaveCount(9)
    },
  },
  {
    name: 'global-projects', title: 'Global project regions', page: '/projects/shop#regions',
    seed: seedGlobal,
    prepare: async (page) => {
      await openProject(page, 'p-shop', 'regions', '[data-global-row="eu-1"]')
      await expect(page.locator('[data-global-row="us-1"]')).toBeVisible()
    },
  },
  {
    name: 'supply-chain', title: 'Software supply chain', page: '/projects/checkout-api#security',
    seed: (api) => { seedCanaryRollout(api); seedSupplyChain(api) },
    prepare: async (page) => {
      await openProject(page, 'p-checkout-api', 'security', '[data-version-row="v12"]')
      await page.locator('[data-version-row="v12"]').getByRole('button', { name: 'Details' }).click()
      await expect(page.locator('[data-version-detail]')).toContainText('CVE-2024-45337')
    },
  },
]
