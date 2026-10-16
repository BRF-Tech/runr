import { test, expect, type Page, type Route } from '@playwright/test'

/**
 * Sandbox API in the UI — self-contained: every /api/* call is answered by an in-memory fake of
 * the RunR API (no backend). The base URL comes from the Playwright config.
 */

type Json = Record<string, any>

const ADMIN = { id: 1, name: 'Admin', username: 'admin', email: 'admin@example.com', is_admin: true, tutorial_completed_at: '2026-01-01T00:00:00Z' }

const REGION_ROWS: Json[] = [
  {
    region: { id: 'r1', name: 'eu-1' }, enabled: true,
    installed_classes: ['kata-fc', 'kata-clh', 'gvisor'], allowed_classes: null,
    effective_classes: ['kata-fc', 'kata-clh', 'gvisor'], default_class: null, effective_default_class: 'kata-fc',
    allow_runc: false, default_ttl_seconds: 900, max_ttl_seconds: 86400,
    max_cpu_m: 4000, max_memory_mb: 8192, max_disk_mb: 10240, can_manage: true,
  },
]

const PYTHON = {
  id: 'python', name: 'Python', description: '', icon: null, language: 'python', extensions: ['py'], category: 'general', source: 'builtin',
  run_mode: 'http', compiled: false, triggers: ['http', 'schedule', 'queue'], sandbox: ['runc', 'gvisor', 'kata'],
  handler: { file: 'main', function: 'handler', filename: 'main.py', pattern: null }, dependency_file: null,
  versions: [{ version: '3.14', is_default: true, eol_at: null, gpu_required: false, image: 'ghcr.io/brf-tech/runr/exec-python:3.14', built: false }],
  regions: null, is_enabled: true, projects_count: 0, updated_at: '2026-10-10T08:00:00Z',
}

const b64 = (text: string) => Buffer.from(text, 'utf8').toString('base64')

/** In-memory API state of one test. */
class FakeApi {
  sandboxes: Json[] = []
  files: Record<string, string> = { '/workspace/main.py': 'print("hello")\n' }
  pools: Json[] = []
  created: Json[] = []
  uploads: Array<{ path: string; content: string }> = []
  runs: Json[] = []
  timeouts: Array<{ id: string; ttl: number }> = []
  deleted: string[] = []
  regionUpdates: Array<{ id: string; body: Json }> = []
  poolCreates: Json[] = []
  regions: Json[] = JSON.parse(JSON.stringify(REGION_ROWS))
  private seq = 0

  async install(page: Page) {
    await page.route((url) => url.pathname.startsWith('/api/'), (route) => this.handle(route))
  }

  private json(route: Route, body: unknown, status = 200) {
    return route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  }

  private sandbox(body: Json): Json {
    this.seq++
    const id = `01jb2x7k3m9q4r5s6t7v8w${String(this.seq).padStart(4, '0')}`
    const now = Date.now()
    return {
      id, status: 'running', region: { id: 'r1', name: 'eu-1' },
      runtime: body.runtime ?? 'python', runtime_version: body.version ?? '3.14', image: 'ghcr.io/brf-tech/runr/exec-python:3.14',
      sandbox_class: 'kata-fc', isolation: 'kata',
      resources: { cpu: body.cpu ?? '500m', cpu_m: 500, memory_mb: body.memory ?? 512, disk_mb: body.disk ?? 1024, pids_limit: 256 },
      network: body.network === 'none' || !body.network ? { mode: 'none', egress_allow: [] } : { mode: 'allowlist', egress_allow: body.network.egress_allow },
      read_only_root: body.read_only_root ?? true, env_keys: Object.keys(body.env ?? {}), metadata: body.metadata ?? {},
      ttl_seconds: body.ttl_seconds ?? 900, idle_timeout_seconds: body.idle_timeout ?? 600,
      expires_at: new Date(now + (body.ttl_seconds ?? 900) * 1000).toISOString(),
      idle_expires_at: new Date(now + 600 * 1000).toISOString(), last_activity_at: new Date(now).toISOString(),
      created_at: new Date(now).toISOString(), started_at: new Date(now).toISOString(), ended_at: null, end_reason: null,
      from_pool: true, start_ms: 140, error: null, created_by: '1', tenant_id: null,
    }
  }

  private entries(dir: string): Json[] {
    const prefix = dir.replace(/\/+$/, '') + '/'
    const seen = new Map<string, Json>()
    for (const [path, content] of Object.entries(this.files)) {
      if (!path.startsWith(prefix)) continue
      const rest = path.slice(prefix.length)
      const name = rest.split('/')[0]
      const isDir = rest.includes('/')
      seen.set(name, {
        name, path: prefix + name, type: isDir ? 'dir' : 'file', size: isDir ? 4096 : Buffer.byteLength(content),
        mode: isDir ? '0755' : '0644', modified_at: '2026-10-10T12:00:00Z',
      })
    }
    return [...seen.values()]
  }

  private async handle(route: Route) {
    const req    = route.request()
    const url    = new URL(req.url())
    const path   = url.pathname.replace(/^\/api/, '')
    const method = req.method()
    const body   = (() => { try { return req.postDataJSON() ?? {} } catch { return {} } })()
    let m: RegExpMatchArray | null

    // ── Platform ──
    if (path === '/version') return this.json(route, { version: 'e2e' })
    if (path === '/auth/me') return this.json(route, ADMIN)
    if (path === '/notifications/unread-count') return this.json(route, { count: 0 })
    if (path === '/notifications') return this.json(route, { data: [], meta: { total: 0 } })
    if (path === '/settings') {
      return this.json(route, {
        base_domain: 'example.com', registration_enabled: true, gpu_demo_enabled: false, custom_runtimes_enabled: false,
        total_cpu_m: 4000, total_memory_mb: 8192, demo_status: 'idle', executor_images: [],
        regions: [{ id: 'r1', name: 'eu-1', is_active: true, is_synced: true, has_serving: true, has_kubeconfig: true, cluster_status: 'online', total_cpu_m: 4000, total_memory_mb: 8192, used_cpu_m: 0, used_memory_mb: 0 }],
      })
    }
    if (path === '/runtimes') return this.json(route, { data: [PYTHON], abilities: { manage: true, use_custom: true }, custom_enabled: false })

    // ── Sandbox settings ──
    if (path === '/sandbox-regions') return this.json(route, { data: this.regions })
    if ((m = path.match(/^\/sandbox-regions\/([^/]+)$/)) && method === 'PUT') {
      this.regionUpdates.push({ id: m[1], body })
      const row = this.regions.find(r => r.region.id === m![1])!
      Object.assign(row, body, { effective_classes: body.allowed_classes ?? row.installed_classes })
      return this.json(route, { data: row })
    }
    if (path === '/sandbox-pools' && method === 'GET') return this.json(route, { data: this.pools })
    if (path === '/sandbox-pools' && method === 'POST') {
      this.poolCreates.push(body)
      const pool = {
        id: `p${this.pools.length + 1}`, region: { id: body.region, name: 'eu-1' }, runtime: body.runtime, runtime_version: body.version,
        image: 'ghcr.io/brf-tech/runr/exec-python:3.14', sandbox_class: 'kata-fc', cpu_m: 500, memory_mb: body.memory, disk_mb: body.disk,
        size: body.size, is_enabled: true, warm: 0, starting: body.size, hits: 0, misses: 0, tenant_id: null, created_at: '2026-10-10T12:00:00Z',
      }
      this.pools.push(pool)
      return this.json(route, { data: pool }, 201)
    }
    if (path === '/sandbox-quotas') {
      return this.json(route, { data: { tenant_id: null,
        limits: { max_concurrent: 20, max_cpu_m: 8000, max_memory_mb: 16384, max_sandbox_seconds_month: 0, max_pool_size: 10, allowed_classes: null },
        usage: { concurrent: this.sandboxes.filter(s => s.status === 'running').length, cpu_m: 500, memory_mb: 512, sandbox_seconds_month: 60, pool_size: 0 } } })
    }
    if (path === '/sandbox-quotas/tenants') return this.json(route, { message: 'Forbidden' }, 403)
    if (path === '/sandbox-metrics') {
      return this.json(route, { data: { window: '24h', active: this.sandboxes.length, pool: { hits: 1, misses: 0, hit_rate: 1 },
        start_time: { pool_hit: { count: 1, sum_ms: 140, buckets: [{ le_ms: 100, count: 0 }, { le_ms: 250, count: 1 }, { le_ms: null, count: 1 }] }, cold: { count: 0, sum_ms: 0, buckets: [] } } } })
    }

    // ── Sandboxes ──
    if (path === '/sandboxes' && method === 'GET') {
      const status = url.searchParams.get('status') ?? 'active'
      const alive = ['pending', 'running', 'paused']
      const list = this.sandboxes.filter(s => status === 'all' || (status === 'ended' ? !alive.includes(s.status) : alive.includes(s.status)))
      return this.json(route, { data: list, meta: { total: list.length, page: 1, per_page: 50 } })
    }
    if (path === '/sandboxes' && method === 'POST') {
      this.created.push(body)
      const s = this.sandbox(body)
      this.sandboxes.unshift(s)
      return this.json(route, { data: s }, 201)
    }
    if ((m = path.match(/^\/sandboxes\/([^/]+)(\/.*)?$/))) {
      const s = this.sandboxes.find(x => x.id === m![1])
      if (!s) return this.json(route, { message: 'Not found' }, 404)
      const sub = m[2] ?? ''

      if (sub === '' && method === 'GET') return this.json(route, { data: s })
      if (sub === '' && method === 'DELETE') {
        this.deleted.push(s.id)
        Object.assign(s, { status: 'terminated', end_reason: 'deleted', ended_at: new Date().toISOString() })
        return this.json(route, { data: s })
      }
      if (sub === '/timeout') {
        this.timeouts.push({ id: s.id, ttl: body.ttl_seconds })
        s.expires_at = new Date(Date.now() + body.ttl_seconds * 1000).toISOString()
        return this.json(route, { data: s })
      }
      if (sub === '/files' && method === 'GET') {
        const dir = url.searchParams.get('path') ?? '/workspace'
        return this.json(route, { data: { path: dir, is_dir: true, entries: this.entries(dir), truncated: false } })
      }
      if (sub === '/files/content' && method === 'PUT') {
        const file = url.searchParams.get('path')!
        const content = Buffer.from(body.content_base64 ?? '', 'base64').toString('utf8')
        this.uploads.push({ path: file, content })
        this.files[file] = content
        return this.json(route, { data: { path: file, size: Buffer.byteLength(content) } })
      }
      if (sub === '/files/content' && method === 'GET') {
        const file = url.searchParams.get('path')!
        const content = this.files[file] ?? ''
        return this.json(route, { data: { path: file, size: Buffer.byteLength(content), content_base64: b64(content) } })
      }
      if (sub === '/run') {
        this.runs.push(body)
        const printed = String(body.code ?? '').match(/print\("(.*)"\)/)?.[1] ?? ''
        return this.json(route, { data: {
          language: body.language, exit_code: 0, stdout: `${printed}\n`, stderr: '', timed_out: false, duration_ms: 31, truncated: false,
          files_changed: [{ path: '/output/plot.svg', size: 120 }],
          outputs: [{ path: '/output/plot.svg', mime: 'image/svg+xml', size: 120, data_base64: b64('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="#009fe3"/></svg>') }],
        } })
      }
      if (sub === '/logs') {
        return this.json(route, { data: {
          events: [{ id: 'e1', type: 'created', message: 'Sandbox created (warm pool)', data: {}, user_id: '1', created_at: s.created_at }],
          pod: { phase: 'Running', conditions: [{ type: 'Ready', status: 'True' }], events: [], log: '' },
        } })
      }
      if (sub === '/processes') return this.json(route, { data: [] })
      return this.json(route, { data: s })
    }

    // Everything else: an empty list
    return this.json(route, { data: [] })
  }
}

test.beforeEach(async ({ page }) => {
  await page.addInitScript((user) => {
    localStorage.setItem('token', 'e2e-token')
    localStorage.setItem('user', JSON.stringify(user))
    localStorage.setItem('locale', 'en')
  }, ADMIN)
})

const confirmModal = (page: Page) => page.locator('div[style*="z-index: 70"]')

test('creates a sandbox, works with its files and code, extends and kills it', async ({ page }) => {
  const api = new FakeApi()
  await api.install(page)

  await page.goto('/sandboxes')
  await expect(page.locator('[data-empty="sandboxes"]')).toContainText('No sandboxes yet')

  // Create from the dialog
  await page.locator('[data-action="create-sandbox"]').click()
  const dialog = page.locator('[data-dialog="create-sandbox"]')
  await expect(dialog.locator('[data-region="r1"]')).toBeVisible()
  await expect(dialog.locator('select[name="runtime"]')).toHaveValue('python')
  await dialog.locator('[data-network="allowlist"]').click()
  await dialog.locator('textarea[name="allowlist"]').fill('pypi.org:443')
  await dialog.locator('[data-action="submit-sandbox"]').click()

  await expect(dialog).toHaveCount(0)
  expect(api.created).toHaveLength(1)
  expect(api.created[0]).toMatchObject({ region: 'r1', runtime: 'python', version: '3.14', network: { egress_allow: ['pypi.org:443'] }, wait: false })

  const id = api.sandboxes[0].id
  const row = page.locator(`[data-sandbox-row="${id}"]`)
  await expect(row).toContainText('Running')
  await expect(row).toContainText('kata-fc')
  await expect(row).toContainText('warm pool')

  // Details → files: list, upload, preview
  await row.getByTitle('Details').click()
  const panel = page.locator('[data-sandbox-detail]')
  await expect(panel.locator('[data-field="sandboxClass"]')).toHaveText('kata-fc')
  await panel.locator('[data-tab="files"]').click()
  await expect(panel.locator('[data-file-row="main.py"]')).toBeVisible()
  await panel.locator('input[data-upload-input]').setInputFiles({ name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('hello notes') })
  await expect(panel.locator('[data-file-row="notes.txt"]')).toBeVisible()
  expect(api.uploads).toEqual([{ path: '/workspace/notes.txt', content: 'hello notes' }])
  await panel.locator('[data-file-row="main.py"] button').first().click()
  await expect(panel.locator('[data-file-preview]')).toContainText('print("hello")')

  // Run code: output and a rich output image
  await panel.locator('[data-tab="run"]').click()
  await panel.locator('textarea[name="code"]').fill('print("Hello from the sandbox")')
  await panel.locator('[data-action="run-code"]').click()
  await expect(panel.locator('[data-stdout]')).toContainText('Hello from the sandbox')
  await expect(panel.locator('[data-exit-code]')).toHaveText('exit 0')
  await expect(panel.locator('[data-output-image]')).toHaveCount(1)
  expect(api.runs[0]).toEqual({ language: 'python', code: 'print("Hello from the sandbox")' })

  // Logs
  await panel.locator('[data-tab="logs"]').click()
  await expect(panel.locator('[data-event="created"]')).toContainText('Sandbox created (warm pool)')
  await expect(panel.locator('[data-pod-phase]')).toHaveText('Running')

  // Overview: extend by one hour, then kill
  await panel.locator('[data-tab="overview"]').click()
  await panel.locator('select[name="extend_by"]').selectOption('3600')
  await panel.locator('[data-action="extend"]').click()
  await expect.poll(() => api.timeouts.length).toBe(1)
  expect(api.timeouts[0].id).toBe(id)
  expect(api.timeouts[0].ttl).toBeGreaterThan(3600)

  await panel.locator('[data-action="kill"]').click()
  await expect(confirmModal(page)).toContainText('Kill Sandbox')
  await confirmModal(page).getByRole('button', { name: 'Kill' }).click()
  await expect.poll(() => api.deleted).toEqual([id])
  await expect(panel.locator('[data-status="terminated"]')).toBeVisible()

  // It leaves the active list and shows under "Ended"
  await panel.getByTitle('Close').click()
  await expect(row).toHaveCount(0)
  await page.locator('[data-status-filter="ended"]').click()
  await expect(page.locator(`[data-sandbox-row="${id}"]`)).toContainText('deleted')
})

test('Settings → Sandboxes saves a region and adds a warm pool', async ({ page }) => {
  const api = new FakeApi()
  await api.install(page)

  await page.goto('/settings?tab=sandboxes')
  const card = page.locator('[data-region-card="r1"]')
  await expect(card).toContainText('eu-1')

  // Narrow the classes, set the default and save
  await card.locator('input[name="class-kata-clh"]').uncheck()
  await card.locator('select[name="default_class"]').selectOption('gvisor')
  await card.locator('[data-action="save-region"]').click()
  await expect.poll(() => api.regionUpdates.length).toBe(1)
  expect(api.regionUpdates[0]).toEqual({ id: 'r1', body: expect.objectContaining({ allowed_classes: ['kata-fc', 'gvisor'], default_class: 'gvisor', allow_runc: false }) })
  await expect(page.getByText('Sandbox settings of eu-1 saved.')).toBeVisible()

  // Add a warm pool
  await page.locator('[data-action="add-pool"]').click()
  const form = page.locator('[data-pool-form]')
  await expect(form.locator('select[name="pool_runtime"]')).toHaveValue('python')
  await form.locator('input[name="pool_size"]').fill('3')
  await form.locator('[data-action="create-pool"]').click()
  await expect(page.locator('[data-pool-row="p1"]')).toContainText('python')
  expect(api.poolCreates).toEqual([{ region: 'r1', runtime: 'python', version: '3.14', size: 3, cpu: '500m', memory: 512, disk: 1024 }])

  // Quotas: own usage shown, organizations table hidden (403)
  await expect(page.locator('[data-own-quota]')).toContainText('This organization')
  await expect(page.locator('[data-tenant-quotas]')).toHaveCount(0)

  // Metrics
  await expect(page.locator('[data-metric="hit-rate"]')).toHaveText('100 %')
})
