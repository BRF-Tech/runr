import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import { h } from 'vue'
import ModelsView from '@/views/ModelsView.vue'
import { aiApi } from '@/api/ai'
import { useSettingsStore } from '@/stores/settings'
import { makeI18n, freshPinia, regions, modelService } from '@/test-support/helpers'

vi.mock('@/api/ai', () => ({
  aiApi: {
    engines: vi.fn(), services: vi.fn(), models: vi.fn(), hubToken: vi.fn(), hubSearch: vi.fn(), hubInfo: vi.fn(),
    getService: vi.fn(), deployService: vi.fn(), stopService: vi.fn(), deleteService: vi.fn(), serviceLogs: vi.fn(),
    serviceBindings: vi.fn(), invokeService: vi.fn(), gpuQuota: vi.fn(), createSnapshot: vi.fn(), snapshotLogs: vi.fn(),
  },
}))
vi.mock('@/api/settings', () => ({ settingsApi: { get: vi.fn(() => new Promise(() => {})) } }))
// The header pulls notifications / tutorial stores: a stub keeps the view under test alone
vi.mock('@/components/AppHeader.vue', () => ({ default: { name: 'AppHeader', render: () => h('header') } }))

const i18n = makeI18n()
let pinia
let router
const mounted = []

const library = [{
  id: 'am1', name: 'all-minilm-l6-v2', source: 'hub', hub_id: 'sentence-transformers/all-MiniLM-L6-v2',
  revision: 'main', revision_sha: '8b3219a92973c328a8e22fadcfa821b5dc75636a', task: 'feature-extraction', library: 'sentence-transformers',
  size_bytes: 90_900_000, gated: false, private: false, services_count: 1,
  snapshots: [{ id: 'sn1', ai_model_id: 'am1', knative_region_id: 'r1', region_name: 'eu-1', source: 'hub', revision: '8b32', status: 'ready', size_bytes: 90_900_000, files_count: 12 }],
}]

async function mountView(query = {}, abilities = { view: true, deploy: true, manage: true }) {
  aiApi.services.mockResolvedValue({ data: { data: [modelService(), modelService({ id: 'ms2', name: 'qwen-chat', engine: 'vllm', device: 'gpu', status: 'deploying' })], abilities } })
  aiApi.models.mockResolvedValue({ data: { data: library, abilities } })
  await router.push({ path: '/models', query })
  const wrapper = mount(ModelsView, { global: { plugins: [pinia, i18n, router] } })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  pinia = freshPinia()
  router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  vi.clearAllMocks()
  vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
  const settings = useSettingsStore()
  settings.data = { ...settings.data, regions }
  settings.loaded = true
  aiApi.engines.mockResolvedValue({ data: { engines: {}, tasks: [] } })
  aiApi.hubToken.mockResolvedValue({ data: { configured: true, hint: 'abcd' } })
  aiApi.hubSearch.mockResolvedValue({ data: { data: [], next_cursor: null } })
  aiApi.serviceLogs.mockResolvedValue({ data: { pods: [{ name: 'm-minilm-00001-deployment-abc', phase: 'Running', ready: true, logs: 'Ready' }] } })
})
afterEach(() => {
  mounted.splice(0).forEach(w => w.unmount())
  vi.useRealTimers()
})

describe('Models page', () => {
  it('opens on the Hub browser and counts the services', async () => {
    const w = await mountView()

    expect(w.find('[data-testid="hub-browser"]').exists()).toBe(true)
    expect(w.find('[data-testid="models-tab-services"]').text()).toContain('2')
  })

  it('lists the services and opens one with its playground, logs and status', async () => {
    const w = await mountView({ tab: 'services' })

    expect(w.find('[data-service-row="minilm"]').text()).toContain('TEI')
    expect(w.find('[data-service-row="qwen-chat"]').text()).toContain('GPU')
    expect(w.find('[data-service-row="qwen-chat"]').text()).toContain('Deploying')

    await w.find('[data-service-row="minilm"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-testid="service-detail"]').exists()).toBe(true)
    expect(w.find('[data-testid="service-url"]').text()).toBe('http://m-minilm.fn.svc.cluster.local')
    expect(w.find('[data-testid="playground"]').exists()).toBe(true)

    await w.find('[data-testid="service-tab-logs"]').trigger('click')
    await flushPromises()
    expect(aiApi.serviceLogs).toHaveBeenCalledWith('ms1')
    expect(w.text()).toContain('m-minilm-00001-deployment-abc')
  })

  it('refreshes while a service is deploying', async () => {
    await mountView({ tab: 'services' })
    expect(aiApi.services).toHaveBeenCalledTimes(1)

    vi.advanceTimersByTime(5000)
    await flushPromises()
    expect(aiApi.services).toHaveBeenCalledTimes(2)
  })

  it('shows the library with the offline copies per region', async () => {
    const w = await mountView({ tab: 'library' })

    expect(aiApi.models).toHaveBeenCalled()
    const card = w.find('[data-library-model="all-minilm-l6-v2"]')
    expect(card.text()).toContain('sentence-transformers/all-MiniLM-L6-v2@8b3219a92973')
    expect(card.find('[data-snapshot-region="eu-1"]').text()).toContain('Ready')
  })

  it('mirrors a Hub model to a region', async () => {
    aiApi.createSnapshot.mockResolvedValue({ data: { id: 'sn2', ai_model_id: 'am1', knative_region_id: 'r2', region_name: 'us-1', status: 'pending' } })
    const w = await mountView({ tab: 'library' })

    await w.find('[data-testid="library-mirror-region-all-minilm-l6-v2"]').setValue('r2')
    await w.find('[data-testid="library-mirror-all-minilm-l6-v2"]').trigger('click')
    await flushPromises()

    expect(aiApi.createSnapshot).toHaveBeenCalledWith('am1', 'r2')
    expect(w.find('[data-snapshot-region="us-1"]').text()).toContain('Pending')
  })

  it('opens on the library without Hub access (air-gapped console) and offers no Hub search', async () => {
    const w = await mountView({}, { view: true, deploy: true, manage: true, hub: false })

    expect(w.find('[data-testid="model-library"]').exists()).toBe(true)
    await w.find('[data-testid="models-tab-browse"]').trigger('click')
    expect(w.find('[data-testid="hub-browser"]').exists()).toBe(false)
    expect(w.find('[data-testid="hub-unavailable"]').exists()).toBe(true)
    expect(aiApi.hubSearch).not.toHaveBeenCalled()
  })

  it('hides deploy and manage actions for members with view only', async () => {
    const w = await mountView({ tab: 'library' }, { view: true, deploy: false, manage: false })

    expect(w.find('[data-testid="library-add"]').exists()).toBe(false)
    expect(w.find('[data-testid="library-deploy-all-minilm-l6-v2"]').exists()).toBe(false)
  })
})
