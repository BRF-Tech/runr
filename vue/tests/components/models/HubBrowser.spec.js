import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import HubBrowser from '@/components/models/HubBrowser.vue'
import HubModelDrawer from '@/components/models/HubModelDrawer.vue'
import { aiApi } from '@/api/ai'
import { useAiStore } from '@/stores/ai'
import { makeI18n, freshPinia, apiError, hubInfo } from '@/test-support/helpers'

vi.mock('@/api/ai', () => ({ aiApi: { hubSearch: vi.fn(), hubInfo: vi.fn(), createModel: vi.fn() } }))

const i18n = makeI18n()
let pinia
const mounted = []

const results = [
  { id: 'sentence-transformers/all-MiniLM-L6-v2', author: 'sentence-transformers', task: 'feature-extraction', library: 'sentence-transformers', downloads: 98_000_000, likes: 3200, gated: false, private: false, tags: [] },
  { id: 'meta-llama/Llama-3.2-1B-Instruct', author: 'meta-llama', task: 'text-generation', library: 'transformers', downloads: 2_100_000, likes: 900, gated: 'manual', private: false, tags: [] },
]

function mountIt(component, props = {}) {
  const wrapper = mount(component, { props, global: { plugins: [pinia, i18n] } })
  mounted.push(wrapper)
  return wrapper
}

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
  useAiStore().tasks = [{ task: 'feature-extraction', engines: ['tei', 'transformers'] }, { task: 'text-generation', engines: ['vllm', 'tgi', 'transformers'] }]
})
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('Hub search', () => {
  it('searches the Hub through the API with text, task, library and sort', async () => {
    aiApi.hubSearch.mockResolvedValue({ data: { data: results, next_cursor: null } })
    const w = mountIt(HubBrowser)
    await flushPromises()

    expect(aiApi.hubSearch).toHaveBeenCalledWith({ limit: 20, sort: 'downloads' })
    expect(w.findAll('[data-hub-model]')).toHaveLength(2)
    expect(w.find('[data-hub-model="sentence-transformers/all-MiniLM-L6-v2"]').text()).toContain('Embeddings')

    await w.find('[data-testid="hub-search"]').setValue('minilm')
    await w.find('[data-testid="hub-task"]').setValue('feature-extraction')
    await flushPromises()
    expect(aiApi.hubSearch).toHaveBeenLastCalledWith({ limit: 20, sort: 'downloads', search: 'minilm', task: 'feature-extraction' })
  })

  it('pages with the cursor of the previous answer', async () => {
    aiApi.hubSearch.mockResolvedValueOnce({ data: { data: [results[0]], next_cursor: 'abc' } })
      .mockResolvedValueOnce({ data: { data: [results[1]], next_cursor: null } })
    const w = mountIt(HubBrowser)
    await flushPromises()

    await w.find('button.btn-secondary').trigger('click')
    await flushPromises()

    expect(aiApi.hubSearch).toHaveBeenLastCalledWith({ limit: 20, sort: 'downloads', cursor: 'abc' })
    expect(w.findAll('[data-hub-model]')).toHaveLength(2)
  })

  it('explains an air-gapped console (503) and points to the library', async () => {
    aiApi.hubSearch.mockRejectedValue(apiError(503, { message: 'The Hugging Face Hub is not reachable from RunR.' }))
    const w = mountIt(HubBrowser)
    await flushPromises()

    expect(w.find('[data-testid="hub-offline"]').text()).toContain('Library')
  })

  it('opens a model card', async () => {
    aiApi.hubSearch.mockResolvedValue({ data: { data: results, next_cursor: null } })
    const w = mountIt(HubBrowser)
    await flushPromises()

    await w.find('[data-hub-model="meta-llama/Llama-3.2-1B-Instruct"]').trigger('click')
    expect(w.emitted('open')[0]).toEqual(['meta-llama/Llama-3.2-1B-Instruct'])
  })
})

describe('Hub model card', () => {
  it('shows summary, size, license, engines and RunR\'s pick', async () => {
    aiApi.hubInfo.mockResolvedValue({ data: hubInfo() })
    const w = mountIt(HubModelDrawer, { modelId: 'sentence-transformers/all-MiniLM-L6-v2', canDeploy: true })
    await flushPromises()

    expect(aiApi.hubInfo).toHaveBeenCalledWith('sentence-transformers/all-MiniLM-L6-v2')
    expect(w.find('[data-testid="hub-card-summary"]').text()).toContain('384 dimensional')
    expect(w.find('[data-testid="hub-size"]').text()).toBe('86.7 MiB')
    expect(w.text()).toContain('apache-2.0')
    expect(w.find('[data-hub-engine="tei"]').text()).toContain('recommended')
    expect(w.text()).toContain('TEI on CPU')
  })

  it('warns about a gated model without a Hugging Face token', async () => {
    aiApi.hubInfo.mockResolvedValue({ data: hubInfo({ id: 'meta-llama/Llama-3.2-1B-Instruct', gated: 'manual' }) })
    const w = mountIt(HubModelDrawer, { modelId: 'meta-llama/Llama-3.2-1B-Instruct', canDeploy: true, hubTokenConfigured: false })
    await flushPromises()

    expect(w.find('[data-testid="hub-gated"]').exists()).toBe(true)
    expect(w.text()).toContain('set your Hugging Face token')
  })

  it('deploys with one click and adds to the library pinned to the commit', async () => {
    aiApi.hubInfo.mockResolvedValue({ data: hubInfo() })
    aiApi.createModel.mockResolvedValue({ data: { id: 'am1', name: 'all-minilm-l6-v2' } })
    const w = mountIt(HubModelDrawer, { modelId: 'sentence-transformers/all-MiniLM-L6-v2', canDeploy: true })
    await flushPromises()

    await w.find('[data-testid="hub-deploy"]').trigger('click')
    expect(w.emitted('deploy')[0][0].id).toBe('sentence-transformers/all-MiniLM-L6-v2')

    await w.find('[data-testid="hub-add-library"]').trigger('click')
    await flushPromises()
    expect(aiApi.createModel).toHaveBeenCalledWith({ source: 'hub', hub_id: 'sentence-transformers/all-MiniLM-L6-v2', revision: '8b3219a92973c328a8e22fadcfa821b5dc75636a' })
    expect(w.emitted('added')[0][0].id).toBe('am1')
  })

  it('hides deploy actions without the deploy ability', async () => {
    aiApi.hubInfo.mockResolvedValue({ data: hubInfo() })
    const w = mountIt(HubModelDrawer, { modelId: 'x/y', canDeploy: false })
    await flushPromises()

    expect(w.find('[data-testid="hub-deploy"]').exists()).toBe(false)
  })
})
