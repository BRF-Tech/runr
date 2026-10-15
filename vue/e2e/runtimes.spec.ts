import { test, expect, type Page, type Route } from '@playwright/test'

/**
 * Runtime plugins in the UI — self-contained: every /api/* call is answered by an in-memory
 * fake of the RunR API (no backend). The base URL comes from the Playwright config.
 */

type Json = Record<string, any>

const Q_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxNiAxNiI+PHJlY3Qgd2lkdGg9IjE2IiBoZWlnaHQ9IjE2IiBmaWxsPSIjOGI1Y2Y2Ii8+PC9zdmc+'

const BROKEN_Q_MANIFEST = `apiVersion: runr.sh/v1
kind: Runtime
id: q
name: Q
language: lua
extensions: [q]
versions:
  - version: "1.0"
    image: ""
run:
  mode: persistent
  command: ["q", "/app/code/{file}"]
`

const Q_MANIFEST = `apiVersion: runr.sh/v1
kind: Runtime
id: q
name: Q
description: Tiny toy language
language: lua
extensions: [q]
versions:
  - version: "1.0"
    dockerfile: Dockerfile
    default: true
template:
  files:
    "{file}": |
      set name = query.name or "World"
      print "Hello, {name}! (from Q)"
run:
  mode: persistent
  command: ["q", "/app/code/{file}"]
triggers: [http, queue]
`

const Q_DOCKERFILE = 'FROM ghcr.io/brf-tech/reqs/golang:1.23-alpine AS build\nCOPY . /src\nRUN cd /src && go build -o /q .\nFROM ghcr.io/brf-tech/reqs/alpine:3.21\nCOPY --from=build /q /usr/local/bin/q\n'

const REGIONS = [
  { id: 'r1', name: 'eu-1', is_active: true, is_synced: true, has_serving: true, has_redis: true, has_kubeconfig: true, cluster_status: 'online', total_cpu_m: 4000, total_memory_mb: 8192, used_cpu_m: 0, used_memory_mb: 0 },
]

function builtin(id: string, name: string, ext: string, language: string, version: string, pattern: string): Json {
  return {
    id, name, description: '', icon: null, language, extensions: [ext], category: 'general', source: 'builtin',
    run_mode: 'http', compiled: id === 'go', triggers: ['http', 'schedule', 'queue'], sandbox: ['runc', 'gvisor', 'kata'],
    handler: { file: 'main', function: 'handler', filename: `main.${ext}`, pattern }, dependency_file: null,
    versions: [{ version, is_default: true, eol_at: null, gpu_required: false, image: `ghcr.io/brf-tech/exec-${id}:${version}`, built: false }],
    regions: null, is_enabled: true, projects_count: 0, updated_at: '2026-10-10T08:00:00Z',
  }
}

function qRuntime(extra: Json = {}): Json {
  return {
    id: 'q', name: 'Q', description: 'Tiny toy language', icon: Q_ICON, language: 'lua', extensions: ['q'], category: 'general',
    source: 'custom', run_mode: 'persistent', compiled: false, triggers: ['http', 'queue'], sandbox: ['runc', 'gvisor', 'kata'],
    handler: { file: 'main', function: 'handler', filename: 'main.q', pattern: null }, dependency_file: null,
    versions: [{ version: '1.0', is_default: true, eol_at: null, gpu_required: false, image: null, built: true }],
    regions: null, is_enabled: true, projects_count: 0, updated_at: '2026-10-10T08:00:00Z',
    ...extra,
  }
}

function project(id: string, name: string, runtime: string, version: string): Json {
  return {
    id, name, slug: name, service_type: 'xaas', runtime, runtime_version: version, description: null,
    knative_region_id: 'r1', region: { id: 'r1', name: 'eu-1' },
    config: { filename: 'main.q', handler: 'handler', trigger_type: 'http', cpu: '250m', memory_mb: 256, timeout_seconds: 30, scale_to_zero: true, min_scale: 0, max_scale: 1, env_vars: null },
    endpoint_type: 'subdomain', endpoint_url: null, gateway_route: null, latest_version: null, latest_deployment: null,
    created_by: { id: 1, name: 'Admin' }, runtime_eol: null, created_at: '2026-10-10T08:00:00Z',
  }
}

/** In-memory API state of one test. */
class FakeApi {
  runtimes: Json[]
  sources: Record<string, { manifest: string; files: Record<string, string> }> = {}
  builds: Json[] = []
  projects: Record<string, Json> = {}
  created: Json | null = null
  updates: Array<{ id: string; body: Json }> = []
  buildRequests: Json[] = []
  testDeploys: Json[] = []
  createdProjects: Json[] = []

  constructor(runtimes: Json[]) {
    this.runtimes = runtimes
  }

  async install(page: Page) {
    await page.route((url) => url.pathname.startsWith('/api/'), (route) => this.handle(route))
  }

  private json(route: Route, body: unknown, status = 200) {
    return route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
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
        base_domain: 'runr.test', registration_enabled: true, gpu_demo_enabled: false, custom_runtimes_enabled: true,
        total_cpu_m: 4000, total_memory_mb: 8192, regions: REGIONS, demo_status: 'idle',
        executor_images: [{ id: 'e1', runtime: 'python', version: '3.14', is_default: true, sort_order: 1, eol_at: null }],
      })
    }

    // ── Runtimes ──
    if (path === '/runtimes' && method === 'GET') {
      const all = url.searchParams.get('all') === '1'
      return this.json(route, {
        data: all ? this.runtimes : this.runtimes.filter(r => r.is_enabled),
        abilities: { manage: true, use_custom: true },
        custom_enabled: true,
      })
    }
    if (path === '/runtimes/validate') return this.validate(route, String(body.manifest ?? ''))
    if (path === '/runtimes' && method === 'POST') return this.createRuntime(route, body)
    if ((m = path.match(/^\/runtimes\/([^/]+)$/))) {
      const runtime = this.runtimes.find(r => r.id === m![1])
      if (!runtime) return this.json(route, { message: 'Not found' }, 404)
      if (method === 'PUT') {
        this.updates.push({ id: runtime.id, body })
        Object.assign(runtime, 'is_enabled' in body ? { is_enabled: body.is_enabled } : {}, 'regions' in body ? { regions: body.regions } : {})
        return this.json(route, { data: runtime })
      }
      const source = this.sources[runtime.id]
      return this.json(route, { data: {
        ...runtime,
        manifest: { apiVersion: 'runr.sh/v1', kind: 'Runtime', id: runtime.id, name: runtime.name },
        manifest_source: source?.manifest ?? `apiVersion: runr.sh/v1\nkind: Runtime\nid: ${runtime.id}\nname: ${runtime.name}\n`,
        files: Object.keys(source?.files ?? {}),
      } })
    }
    if ((m = path.match(/^\/runtimes\/([^/]+)\/builds$/))) {
      if (method === 'POST') {
        this.buildRequests.push({ runtime: m[1], ...body })
        const build = { version: body.version, knative_region_id: body.knative_region_id, region_name: 'eu-1', status: 'building', image: null, error: null, updated_at: '2026-10-10T08:01:00Z' }
        this.builds = this.builds.filter(b => !(b.version === build.version && b.knative_region_id === build.knative_region_id)).concat(build)
        return this.json(route, { data: [build] }, 202)
      }
      return this.json(route, { data: this.builds })
    }
    if ((m = path.match(/^\/runtimes\/([^/]+)\/test-deploy$/))) {
      this.testDeploys.push({ runtime: m[1], ...body })
      const p = project('p-test', `rt-${m[1]}-e2e`, m[1], body.version ?? '1.0')
      this.projects[p.id] = p
      return this.json(route, { data: p }, 201)
    }
    if ((m = path.match(/^\/runtimes\/([^/]+)\/template$/))) {
      return this.json(route, { files: { 'main.q': 'set name = query.name or "World"\nprint "Hello, {name}! (from Q)"\n' } })
    }

    // ── Projects ──
    if (path === '/projects' && method === 'GET') return this.json(route, { data: Object.values(this.projects) })
    if (path === '/projects' && method === 'POST') {
      this.createdProjects.push(body)
      const p = project('p-q', body.name, body.runtime, body.runtime_version)
      this.projects[p.id] = p
      return this.json(route, { data: p }, 201)
    }
    if ((m = path.match(/^\/projects\/([^/]+)$/))) {
      const p = this.projects[m[1]]
      return p ? this.json(route, { data: p }) : this.json(route, { message: 'Not found' }, 404)
    }
    if ((m = path.match(/^\/projects\/([^/]+)\/code$/))) {
      return this.json(route, { code: 'set name = query.name or "World"\nprint "Hello, {name}! (from Q)"\n', version: null, is_default: true })
    }

    // Everything else (deployments, versions, metrics, gateways …): an empty list
    return this.json(route, { data: [] })
  }

  private validate(route: Route, manifest: string) {
    if (/image:\s*""/.test(manifest)) {
      return this.json(route, {
        message: 'The runtime manifest is invalid.',
        errors: { manifest: ['The runtime manifest is invalid.'] },
        problems: [{ path: '/versions/0/image', message: 'Must be a container image reference.' }],
      }, 422)
    }
    return this.json(route, { valid: true, manifest: parseManifest(manifest) })
  }

  private createRuntime(route: Route, body: Json) {
    this.created = body
    const parsed = parseManifest(String(body.manifest ?? ''))
    const runtime = qRuntime({ id: parsed.id, name: parsed.name, source: 'platform', regions: body.regions ?? null })
    this.sources[runtime.id] = { manifest: body.manifest, files: body.files ?? {} }
    this.runtimes.push(runtime)
    return this.json(route, { data: runtime }, 201)
  }
}

/** The few manifest fields the UI shows after validation. */
function parseManifest(text: string): Json {
  const field = (name: string) => text.match(new RegExp(`^${name}:\\s*(.+)$`, 'm'))?.[1].trim() ?? ''
  const versions = [...text.matchAll(/-\s+version:\s*"?([^"\n]+)"?(?:\n\s+dockerfile:\s*(\S+))?/g)]
    .map(v => ({ version: v[1], ...(v[2] ? { dockerfile: v[2] } : {}) }))
  return { apiVersion: 'runr.sh/v1', kind: 'Runtime', id: field('id'), name: field('name'), language: field('language'), versions }
}

const ADMIN = { id: 1, name: 'Admin', username: 'admin', email: 'admin@runr.test', is_admin: true, tutorial_completed_at: '2026-01-01T00:00:00Z' }

test.beforeEach(async ({ page }) => {
  await page.addInitScript((user) => {
    localStorage.setItem('token', 'e2e-token')
    localStorage.setItem('user', JSON.stringify(user))
    localStorage.setItem('locale', 'en')
  }, ADMIN)
})

const modal = (page: Page, text: string | RegExp) => page.locator('div.fixed.inset-0').filter({ hasText: text }).last()

test('an admin registers, manages and test-deploys a custom runtime in Settings → Runtimes', async ({ page }) => {
  const api = new FakeApi([
    builtin('python', 'Python', 'py', 'python', '3.14', 'def\\s+handler\\s*\\('),
    builtin('nodejs', 'Node.js', 'js', 'javascript', '24.14', 'function\\s+handler'),
    builtin('go', 'Go', 'go', 'go', '1.26', 'func\\s+Handler\\s*\\('),
    qRuntime({ id: 'bash', name: 'Bash', icon: null, language: 'shell', extensions: ['sh'], run_mode: 'exec', versions: [{ version: '5.2', is_default: true, eol_at: null, image: 'docker.io/library/bash:5.2', built: false }] }),
  ])
  await api.install(page)

  await page.goto('/settings?tab=runtimes')

  // Built-ins and the custom runtime are listed
  await expect(page.locator('[data-runtime-row="python"]')).toContainText('Built-in')
  await expect(page.locator('[data-runtime-row="go"]')).toContainText('Built-in')
  await expect(page.locator('[data-runtime-row="bash"]')).toContainText('Custom')
  await expect(page.locator('[data-runtime-row="python"]').getByTitle('Delete')).toHaveCount(0)

  // Add the Q runtime: a broken manifest first
  await page.getByRole('button', { name: 'Add Runtime' }).first().click()
  const editor = modal(page, 'Manifest (runtime.yaml)')
  await editor.locator('input[type=file][accept=".yaml,.yml,.json"]').setInputFiles({
    name: 'runtime.yaml', mimeType: 'text/yaml', buffer: Buffer.from(BROKEN_Q_MANIFEST),
  })
  await expect(editor.getByText('Manifest problems: 1')).toBeVisible()
  await expect(editor.getByText('/versions/0/image')).toBeVisible()
  await expect(editor.getByText('Must be a container image reference.')).toBeVisible()

  // … then the fixed one, which builds its image from a Dockerfile
  await editor.locator('input[type=file][accept=".yaml,.yml,.json"]').setInputFiles({
    name: 'runtime.yaml', mimeType: 'text/yaml', buffer: Buffer.from(Q_MANIFEST),
  })
  await expect(editor.getByText('Valid manifest: Q (q), versions: 1')).toBeVisible()
  await expect(editor.getByText('Version 1.0 builds from Dockerfile: add the file below.')).toBeVisible()

  await editor.locator('input[type=file][multiple]').setInputFiles({
    name: 'Dockerfile', mimeType: 'text/plain', buffer: Buffer.from(Q_DOCKERFILE),
  })
  await expect(editor.getByText('Version 1.0 builds from Dockerfile: add the file below.')).toHaveCount(0)

  await editor.getByRole('button', { name: 'Add Runtime' }).click()
  const qRow = page.locator('[data-runtime-row="q"]')
  await expect(qRow).toContainText('Q')
  await expect(qRow).toContainText('Platform')
  expect(api.created).toEqual({ manifest: Q_MANIFEST, regions: null, files: { Dockerfile: Q_DOCKERFILE } })

  // Disable it
  await qRow.getByTitle('Disable').click()
  await expect(qRow).toContainText('Disabled')
  expect(api.updates).toContainEqual({ id: 'q', body: { is_enabled: false } })

  // View the manifest
  await qRow.getByTitle('View manifest').click()
  const viewer = modal(page, 'Q manifest')
  await expect(viewer.locator('.monaco-editor')).toContainText('runr.sh/v1')
  await expect(viewer.locator('p', { hasText: 'Files:' })).toContainText('Dockerfile')
  await viewer.getByRole('button', { name: '✕' }).click()

  // Enable again, build the image in the region
  await qRow.getByTitle('Enable').click()
  await expect(qRow).toContainText('Enabled')
  await qRow.getByTitle('Image builds').click()
  const builds = modal(page, 'Image builds: Q')
  await expect(builds.getByText('not built')).toBeVisible()
  await builds.getByRole('button', { name: 'Build' }).first().click()
  await expect(builds.getByText('building')).toBeVisible()
  expect(api.buildRequests).toEqual([{ runtime: 'q', knative_region_id: 'r1', version: '1.0' }])
  await builds.getByRole('button', { name: '✕' }).click()

  // Test deploy opens the created project
  await qRow.getByTitle('Test deploy').click()
  const deploy = modal(page, 'Test deploy: Q')
  await deploy.getByRole('button', { name: 'eu-1' }).click()
  await deploy.getByRole('button', { name: 'Create and deploy' }).click()
  await expect(page).toHaveURL(/\/projects\/p-test$/)
  expect(api.testDeploys).toEqual([{ runtime: 'q', knative_region_id: 'r1', version: '1.0' }])
})

test('a new project picks the custom runtime and edits it in the manifest language', async ({ page }) => {
  const api = new FakeApi([
    builtin('python', 'Python', 'py', 'python', '3.14', 'def\\s+handler\\s*\\('),
    qRuntime(),
  ])
  await api.install(page)

  await page.goto('/projects')
  await page.getByRole('button', { name: '+ New Project' }).first().click()
  const create = modal(page, /Step \d \/ 4/)

  // Step 1: name + region
  await create.getByPlaceholder('my-awesome-function').fill('q-hello')
  await create.getByRole('button', { name: /eu-1/ }).click()
  await create.getByRole('button', { name: 'Continue →' }).click()
  // Step 2: type
  await create.getByRole('button', { name: /Code Snippet/ }).click()
  await create.getByRole('button', { name: 'Continue →' }).click()

  // Step 3: the catalog picker offers Q with its manifest icon and source badge
  const q = create.locator('[data-runtime="q"]')
  await expect(q).toContainText('Q')
  await expect(q).toContainText('Custom')
  await expect(q.locator('img')).toHaveAttribute('src', Q_ICON)
  await q.click()
  await expect(create.locator('[data-version="1.0"]')).toContainText('Default')

  // Starter code preview comes from the manifest template
  await create.getByRole('button', { name: 'Starter code' }).click()
  await expect(create.locator('.monaco-editor').last()).toContainText('from Q')

  await create.getByRole('button', { name: 'Continue →' }).click()
  await create.getByRole('button', { name: 'Create Project' }).click()
  await expect.poll(() => api.createdProjects.length).toBe(1)
  expect(api.createdProjects[0]).toMatchObject({ name: 'q-hello', service_type: 'xaas', runtime: 'q', runtime_version: '1.0', knative_region_id: 'r1' })

  // The editor uses the manifest language (lua) for .q files — no extension mapping knows .q
  await page.goto('/projects/p-q')
  // (Monaco puts data-mode-id on the editor's container, the parent of .monaco-editor)
  await expect(page.locator('[data-mode-id="lua"] .monaco-editor')).toBeVisible()
  await expect(page.getByText('Entry file: main.q')).toBeVisible()
})
