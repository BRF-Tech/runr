import { test, expect, type Page, type Route } from '@playwright/test'

/**
 * AI on RunR in the UI — self-contained: every /api/* call is answered by an in-memory fake of
 * the RunR API (no backend). Covers the one-click Hugging Face flow (browse → deploy → ready →
 * playground → bind to a project → env vars), the region GPU tab (install GPU support) and a
 * GPU CaaS project.
 */

type Json = Record<string, any>

const ADMIN = { id: 1, name: 'Admin', username: 'admin', email: 'admin@runr.test', is_admin: true, tutorial_completed_at: '2026-01-01T00:00:00Z' }

const REGIONS = [
  {
    id: 'r1', name: 'gpu-lab', is_active: true, is_synced: false, is_demo: false, has_serving: true, has_redis: true, has_kubeconfig: true,
    has_registry: true, cluster_status: 'online', knative_status: 'ready', region_status: 'active', region_mode: 'managed',
    knative_operator_status: 'ready', knative_serving_status: 'ready', total_cpu_m: 8000, total_memory_mb: 16384,
    used_cpu_m: 0, used_memory_mb: 0, project_count: 1, selected_workers: [], knative_settings: { request_timeout: 600 },
  },
]

const PRODUCT = 'NVIDIA-GeForce-RTX-4050-Laptop-GPU'

function gpuStatus(status: string, mode: string): Json {
  const ready = status === 'ready'
  return {
    mode, status, status_message: ready ? '[10:00:00] NVIDIA device plugin ready\n' : '',
    settings: { time_slicing_replicas: null, runtime_class: 'nvidia', gvisor_nvproxy: false, operator_driver: true, operator_toolkit: true },
    summary: {
      gpu_nodes: 1, total: ready ? 1 : 0, allocatable: ready ? 1 : 0, allocated: 0, available: ready ? 1 : 0,
      products: ready ? [{ product: PRODUCT, memory_mb: 6141, count: 1, allocatable: 1 }] : [],
      hardware_without_plugin: ready ? [] : ['gpu-1'],
    },
    nodes: [{
      name: 'gpu-1', ready: true, product: ready ? PRODUCT : null, memory_mb: ready ? 6141 : null, count: 1, allocatable: ready ? 1 : 0,
      allocated: 0, replicas: 1, compute_capability: ready ? '8.9' : null, driver_version: '550.54.15', cuda_version: ready ? '12.4' : null,
      has_device_plugin: ready, hardware_detected: true, taints: [],
    }],
    sandbox: {
      xaas: { runtime_class: null, gpu: 'ok', message: null },
      faas: { runtime_class: null, gpu: 'ok', message: null },
      caas: { runtime_class: null, gpu: 'ok', message: null },
    },
    detected_at: '2026-10-10T10:00:00Z',
  }
}

const HUB_RESULTS = [
  { id: 'sentence-transformers/all-MiniLM-L6-v2', author: 'sentence-transformers', task: 'feature-extraction', library: 'sentence-transformers', downloads: 98_000_000, likes: 3200, gated: false, private: false, last_modified: '2024-11-01T00:00:00Z', tags: [] },
  { id: 'Qwen/Qwen2.5-0.5B-Instruct', author: 'Qwen', task: 'text-generation', library: 'transformers', downloads: 1_200_000, likes: 300, gated: false, private: false, last_modified: '2024-10-01T00:00:00Z', tags: [] },
]

const HUB_INFO: Json = {
  id: 'sentence-transformers/all-MiniLM-L6-v2', sha: '8b3219a92973c328a8e22fadcfa821b5dc75636a', task: 'feature-extraction',
  library: 'sentence-transformers', license: 'apache-2.0', gated: false, private: false, downloads: 98_000_000, likes: 3200,
  tags: ['sentence-transformers'], size_bytes: 90_900_000, parameters: 22_700_000,
  files: [{ name: 'model.safetensors', size: 90_868_376 }, { name: 'config.json', size: 612 }],
  card_summary: 'This is a sentence-transformers model: it maps sentences to a 384 dimensional dense vector space.',
  engines: [
    { engine: 'tei', devices: ['cpu', 'gpu'], recommended: true, api: 'tei' },
    { engine: 'transformers', devices: ['cpu', 'gpu'], recommended: false, api: 'runr' },
  ],
  suggested: { engine: 'tei', device: 'cpu', cpu: '1', memory_mb: 1024, gpu: null, suggest_min_scale: false },
  warnings: [],
}

const ENGINES = {
  engines: {
    vllm:         { label: 'vLLM', devices: ['gpu'], api: 'openai', tasks: ['text-generation'] },
    tgi:          { label: 'TGI', devices: ['gpu'], api: 'openai', tasks: ['text-generation'] },
    tei:          { label: 'TEI', devices: ['cpu', 'gpu'], api: 'tei', tasks: ['feature-extraction', 'sentence-similarity'] },
    transformers: { label: 'Transformers', devices: ['cpu', 'gpu'], api: 'runr', tasks: ['text-classification', 'feature-extraction', 'text-generation'] },
    diffusers:    { label: 'Diffusers', devices: ['gpu'], api: 'runr', tasks: ['text-to-image'] },
    custom:       { label: 'Custom', devices: ['cpu', 'gpu'], api: 'custom', tasks: ['*'] },
  },
  tasks: [
    { task: 'feature-extraction', engines: ['tei', 'transformers'], default: { cpu: 'tei', gpu: 'tei' } },
    { task: 'text-generation', engines: ['vllm', 'tgi', 'transformers'], default: { cpu: 'transformers', gpu: 'vllm' } },
  ],
}

function project(id: string, name: string, extra: Json = {}): Json {
  return {
    id, name, slug: name, service_type: 'faas', runtime: 'python', runtime_version: '3.14', description: null,
    knative_region_id: 'r1', region: { id: 'r1', name: 'gpu-lab' },
    config: { handler: 'main.handler', trigger_type: 'http', cpu: '250m', memory_mb: 256, timeout_seconds: 30, scale_to_zero: true, min_scale: 0, max_scale: 1, env_vars: null, gpu: null },
    endpoint_type: 'subdomain', endpoint_url: null, gateway_route: null, latest_version: null, latest_deployment: null,
    created_by: { id: 1, name: 'Admin' }, runtime_eol: null, created_at: '2026-10-10T08:00:00Z',
    ...extra,
  }
}

/** In-memory API state of one test. */
class FakeApi {
  gpu: Json = gpuStatus('not_installed', 'none')
  services: Json[] = []
  bindings: Json[] = []
  projects: Record<string, Json> = { p1: project('p1', 'search-api') }
  serviceCreates: Json[] = []
  invokes: Json[] = []
  gpuInstalls: Json[] = []
  projectCreates: Json[] = []
  bindCreates: Json[] = []

  async install(page: Page) {
    await page.route((url) => url.pathname.startsWith('/api/'), (route) => this.handle(route))
  }

  private json(route: Route, body: unknown, status = 200) {
    return route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  }

  private service(body: Json): Json {
    return {
      id: 'ms1', name: body.name, service_name: `m-${body.name}`, ai_model_id: 'am1',
      model: { id: 'am1', name: 'all-minilm-l6-v2', hub_id: body.hub_id, task: 'feature-extraction', library: 'sentence-transformers', size_bytes: 90_900_000 },
      knative_region_id: body.knative_region_id, region_name: 'gpu-lab', engine: 'tei', device: body.device, mode: body.mode, api: 'tei',
      gpu: body.gpu ?? null, cpu: body.cpu, memory_mb: body.memory_mb, min_scale: body.min_scale, max_scale: body.max_scale,
      scale_to_zero: body.scale_to_zero, timeout_seconds: body.timeout_seconds, image: 'ghcr.io/huggingface/text-embeddings-inference:cpu-1.5',
      args: [], env_vars: {}, port: 8080, health_path: '/health', served_name: body.name, status: 'deploying', status_message: 'Pulling image',
      url: `http://m-${body.name}.fn.svc.cluster.local`, playground: { kind: 'embeddings', path: '/v1/embeddings' },
      cold_start: { size_bytes: 90_900_000, suggest_min_scale: false }, bindings_count: 0, deployed_at: null, created_at: '2026-10-10T10:00:00Z',
    }
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
        base_domain: 'runr.test', registration_enabled: true, gpu_demo_enabled: false, custom_runtimes_enabled: false,
        total_cpu_m: 8000, total_memory_mb: 16384, regions: REGIONS, demo_status: 'idle',
        executor_images: [{ id: 'e1', runtime: 'python', version: '3.14', is_default: true, sort_order: 1, eol_at: null }],
      })
    }
    if (path === '/runtimes') return this.json(route, { data: [], abilities: { manage: true, use_custom: true }, custom_enabled: false })
    if (path === '/knative-regions' && method === 'GET') return this.json(route, { data: REGIONS })

    // ── GPU support (region) ──
    if (path === '/knative-regions/r1/gpu') return this.json(route, this.gpu)
    if (path === '/knative-regions/r1/gpu/install') {
      this.gpuInstalls.push(body)
      this.gpu = { ...gpuStatus('ready', body.mode), status: 'installing' }
      return this.json(route, { message: 'GPU support installation queued.', gpu: this.gpu }, 202)
    }
    if (path === '/knative-regions/r1/model-storage') {
      return this.json(route, { storage_class: null, size_gi: 100, access_mode: 'ReadWriteMany', pvc_name: 'runr-models', status: 'absent', message: null })
    }

    // ── Models ──
    if (path === '/models/engines') return this.json(route, ENGINES)
    if (path === '/models/hub-token') return this.json(route, { configured: false, hint: null, updated_at: null })
    if (path === '/models/gpu-quota') return this.json(route, { quota: null, used: 0, remaining: null })
    if (path === '/models/hub/search') {
      const q = (url.searchParams.get('search') ?? '').toLowerCase()
      return this.json(route, { data: HUB_RESULTS.filter(r => !q || r.id.toLowerCase().includes(q)), next_cursor: null })
    }
    if (path === '/models/hub/info') return this.json(route, HUB_INFO)
    if (path === '/models' && method === 'GET') return this.json(route, { data: [], abilities: { view: true, deploy: true, manage: true } })
    if (path === '/model-services' && method === 'GET') {
      return this.json(route, { data: this.services, abilities: { view: true, deploy: true, manage: true } })
    }
    if (path === '/model-services' && method === 'POST') {
      this.serviceCreates.push(body)
      const s = this.service(body)
      this.services.push(s)
      return this.json(route, { data: s }, 201)
    }
    if ((m = path.match(/^\/model-services\/([^/]+)$/))) {
      const s = this.services.find(x => x.id === m![1])
      return s ? this.json(route, { data: s }) : this.json(route, { message: 'Not found' }, 404)
    }
    if ((m = path.match(/^\/model-services\/([^/]+)\/invoke$/))) {
      this.invokes.push(body)
      return this.json(route, { status: 200, duration_ms: 37, content_type: 'application/json', body: { object: 'list', data: [{ object: 'embedding', index: 0, embedding: Array(384).fill(0.0123) }] } })
    }
    if ((m = path.match(/^\/model-services\/([^/]+)\/bindings$/))) return this.json(route, { data: this.bindings })

    // ── Projects ──
    if (path === '/projects' && method === 'GET') return this.json(route, { data: Object.values(this.projects) })
    if (path === '/projects' && method === 'POST') {
      this.projectCreates.push(body)
      const p = project('p-new', body.name, { service_type: body.service_type, runtime: null, config: { ...body.config } })
      this.projects[p.id] = p
      return this.json(route, { data: p }, 201)
    }
    if ((m = path.match(/^\/projects\/([^/]+)\/models$/))) {
      if (method === 'POST') {
        this.bindCreates.push(body)
        const s = this.services.find(x => x.id === body.ai_model_service_id)!
        const a = body.alias
        const b = {
          id: 'b1', project_id: m[1], ai_model_service_id: s.id, alias: a, mount_weights: !!body.mount_weights, service: s, applies_on_next_deploy: true,
          env: {
            [`RUNR_MODEL_${a}_URL`]: s.url, [`RUNR_MODEL_${a}_MODEL`]: s.served_name, [`RUNR_MODEL_${a}_API`]: s.api,
            [`RUNR_MODEL_${a}_TASK`]: 'feature-extraction', [`RUNR_MODEL_${a}_OPENAI_BASE`]: `${s.url}/v1`,
          },
        }
        this.bindings.push(b)
        return this.json(route, { data: b }, 201)
      }
      return this.json(route, { data: this.bindings })
    }
    if ((m = path.match(/^\/projects\/([^/]+)$/))) {
      const p = this.projects[m[1]]
      return p ? this.json(route, { data: p }) : this.json(route, { message: 'Not found' }, 404)
    }
    if ((m = path.match(/^\/projects\/([^/]+)\/files$/))) return this.json(route, { files: { 'main.py': 'def handler(event, context):\n    return 1\n' } })

    // Region detail endpoints the modal reads on open: nothing installed beyond the basics
    if (path.startsWith('/knative-regions/')) return this.json(route, {})

    // Everything else (deployments, versions, metrics …): an empty list
    return this.json(route, { data: [] })
  }

  markReady() {
    for (const s of this.services) Object.assign(s, { status: 'ready', status_message: null, deployed_at: '2026-10-10T10:01:00Z' })
  }
}

test.beforeEach(async ({ page }) => {
  await page.addInitScript((user) => {
    localStorage.setItem('token', 'e2e-token')
    localStorage.setItem('user', JSON.stringify(user))
    localStorage.setItem('locale', 'en')
  }, ADMIN)
})

test('one-click Hugging Face model: browse, deploy, playground, bind to a project', async ({ page }) => {
  const api = new FakeApi()
  await api.install(page)

  // Browse the Hub from the header navigation
  await page.goto('/projects')
  await page.getByTestId('nav-models').click()
  await expect(page.getByTestId('hub-browser')).toBeVisible()
  await page.getByTestId('hub-search').fill('minilm')
  await page.getByTestId('hub-search-submit').click()
  await expect(page.locator('[data-hub-model]')).toHaveCount(1)

  // Model card → one-click deploy (online, CPU, RunR's pick)
  await page.locator('[data-hub-model="sentence-transformers/all-MiniLM-L6-v2"]').click()
  const drawer = page.getByTestId('hub-drawer')
  await expect(drawer.getByTestId('hub-card-summary')).toContainText('384 dimensional')
  await expect(drawer.locator('[data-hub-engine="tei"]')).toContainText('recommended')
  await drawer.getByTestId('hub-deploy').click()

  const dialog = page.getByTestId('deploy-dialog')
  await expect(dialog.getByTestId('deploy-name')).toHaveValue('all-minilm-l6-v2')
  await dialog.getByTestId('deploy-region').selectOption('r1')
  await dialog.getByTestId('deploy-submit').click()

  expect(api.serviceCreates[0]).toMatchObject({
    hub_id: 'sentence-transformers/all-MiniLM-L6-v2', revision: '8b3219a92973c328a8e22fadcfa821b5dc75636a',
    knative_region_id: 'r1', mode: 'online', device: 'cpu', engine: 'auto', deploy: true,
  })

  // The service detail opens while it deploys; the page refreshes until it is ready
  const detail = page.getByTestId('service-detail')
  await expect(detail.getByTestId('service-status')).toHaveText('Deploying')
  api.markReady()
  await expect(detail.getByTestId('service-status')).toHaveText('Ready', { timeout: 15_000 })
  await expect(detail.getByTestId('service-url')).toHaveText('http://m-all-minilm-l6-v2.fn.svc.cluster.local')

  // Playground: embeddings
  await detail.getByTestId('pg-text').fill('RunR runs functions')
  await detail.getByTestId('pg-run').click()
  await expect(detail.getByTestId('pg-embeddings')).toContainText('1 vector(s) with 384 dimensions')
  expect(api.invokes[0]).toEqual({ method: 'POST', path: '/v1/embeddings', body: { model: 'all-minilm-l6-v2', input: 'RunR runs functions' } })

  // Bind it to a project of the same region
  await page.goto('/projects/p1#models')
  const models = page.getByTestId('project-models')
  await expect(models.getByTestId('bind-next-deploy')).toContainText('next deploy')
  await models.getByTestId('bind-open').click()
  await models.getByTestId('bind-service').selectOption('ms1')
  await expect(models.getByTestId('bind-alias')).toHaveValue('ALL_MINILM_L6_V2')
  await models.getByTestId('bind-alias').fill('EMBED')
  await expect(models.getByTestId('bind-env-preview')).toContainText('RUNR_MODEL_EMBED_URL=http://m-all-minilm-l6-v2.fn.svc.cluster.local')
  await models.getByTestId('bind-submit').click()

  expect(api.bindCreates[0]).toEqual({ ai_model_service_id: 'ms1', alias: 'EMBED', mount_weights: false })
  const bound = models.locator('[data-binding="EMBED"]')
  await expect(bound.getByTestId('binding-env')).toContainText('RUNR_MODEL_EMBED_OPENAI_BASE=http://m-all-minilm-l6-v2.fn.svc.cluster.local/v1')
  await bound.getByTestId('snippet-node').click()
  await expect(bound.getByTestId('binding-snippet')).toContainText('process.env.RUNR_MODEL_EMBED_URL')
})

test('region GPU tab: GPU node without a device plugin, install GPU support', async ({ page }) => {
  const api = new FakeApi()
  await api.install(page)

  await page.goto('/settings')
  await expect(page.getByText('gpu-lab').first()).toBeVisible()
  await page.locator('[data-region-row="gpu-lab"]').getByRole('button', { name: 'Edit', exact: true }).click()
  await page.locator('button.tab-line', { hasText: /^GPU$/ }).click()

  const tab = page.getByTestId('gpu-tab')
  await expect(tab.getByTestId('gpu-without-plugin')).toContainText('gpu-1')
  await expect(tab.getByTestId('gpu-status')).toHaveText('Not installed')

  await tab.getByTestId('gpu-time-slicing').fill('2')
  // GPU nodes only (default on): the device plugin is restricted to the named GPU node
  await expect(tab.getByTestId('gpu-restrict')).toBeChecked()
  await tab.getByTestId('gpu-nodes').fill('gpu-1')
  await tab.getByTestId('gpu-install').click()

  await expect(tab.getByTestId('gpu-status')).toHaveText('Installing')
  expect(api.gpuInstalls[0]).toEqual({
    mode: 'device-plugin', runtime_class: 'auto', time_slicing_replicas: 2, restrict_to_gpu_nodes: true, gpu_nodes: ['gpu-1'],
  })

  // the device plugin comes up: the node shows its GPU
  api.gpu = gpuStatus('ready', 'device-plugin')
  await expect(tab.getByTestId('gpu-status')).toHaveText('Ready', { timeout: 15_000 })
  await expect(tab.locator('[data-gpu-node="gpu-1"]')).toContainText(PRODUCT)
  await expect(tab.locator('[data-gpu-node="gpu-1"]')).toContainText('CUDA 12.4')
})

test('a GPU CaaS project: count, GPU type and the longer default timeout', async ({ page }) => {
  const api = new FakeApi()
  api.gpu = gpuStatus('ready', 'device-plugin')
  await api.install(page)

  await page.goto('/projects')
  await page.getByRole('button', { name: '+ New Project' }).click()

  await page.getByPlaceholder('my-awesome-function').fill('gpu-infer')
  await page.getByRole('button', { name: /gpu-lab/ }).click()
  await page.getByRole('button', { name: 'Continue →' }).click()
  await page.getByRole('button', { name: /Container/ }).click()
  await page.getByRole('button', { name: 'Continue →' }).click()
  await page.getByPlaceholder('nginx:1.27-alpine').fill('ghcr.io/acme/torch-infer:1.0')
  await page.getByRole('button', { name: 'Continue →' }).click()

  const gpu = page.getByTestId('gpu-config')
  await gpu.getByTestId('gpu-toggle').click()
  await expect(gpu.getByTestId('gpu-cold-start')).toContainText('min scale 1')
  await gpu.getByTestId('gpu-type').selectOption(PRODUCT)
  await page.getByRole('button', { name: 'Create Project' }).click()

  await expect.poll(() => api.projectCreates.length).toBe(1)
  expect(api.projectCreates[0]).toMatchObject({
    name: 'gpu-infer', service_type: 'caas', knative_region_id: 'r1',
    config: { image: 'ghcr.io/acme/torch-infer:1.0', timeout_seconds: 300, gpu: { count: 1, type: PRODUCT } },
  })
})
