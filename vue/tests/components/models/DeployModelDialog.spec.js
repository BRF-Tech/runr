import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import DeployModelDialog from '@/components/models/DeployModelDialog.vue'
import { aiApi } from '@/api/ai'
import { useAiStore } from '@/stores/ai'
import { useSettingsStore } from '@/stores/settings'
import { clearRegionGpuCache } from '@/composables/useRegionGpu'
import { makeI18n, freshPinia, regions, hubInfo, gpuStatus, modelService } from '@/test-support/helpers'

vi.mock('@/api/ai', () => ({
  aiApi: { engines: vi.fn(), gpu: vi.fn(), gpuQuota: vi.fn(), createService: vi.fn() },
}))
vi.mock('@/api/settings', () => ({ settingsApi: { get: vi.fn(() => new Promise(() => {})) } }))

const i18n = makeI18n()
let pinia
const mounted = []

const ENGINES = {
  engines: {
    vllm:         { label: 'vLLM', devices: ['gpu'], api: 'openai', tasks: ['text-generation'] },
    tgi:          { label: 'TGI', devices: ['gpu'], api: 'openai', tasks: ['text-generation'] },
    tei:          { label: 'TEI', devices: ['cpu', 'gpu'], api: 'tei', tasks: ['feature-extraction', 'sentence-similarity'] },
    transformers: { label: 'Transformers', devices: ['cpu', 'gpu'], api: 'runr', tasks: ['text-generation', 'feature-extraction'] },
    custom:       { label: 'Custom', devices: ['cpu', 'gpu'], api: 'custom', tasks: ['*'] },
  },
  tasks: [
    { task: 'feature-extraction', engines: ['tei', 'transformers'], default: { cpu: 'tei', gpu: 'tei' } },
    { task: 'text-generation', engines: ['vllm', 'tgi', 'transformers'], default: { cpu: 'transformers', gpu: 'vllm' } },
  ],
}

async function mountDialog(props) {
  const wrapper = mount(DeployModelDialog, { props, global: { plugins: [pinia, i18n] } })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
  clearRegionGpuCache()
  const settings = useSettingsStore()
  settings.data = { ...settings.data, regions: regions.slice(0, 1) }
  settings.loaded = true
  aiApi.engines.mockResolvedValue({ data: ENGINES })
  aiApi.gpu.mockResolvedValue({ data: gpuStatus() })
  aiApi.gpuQuota.mockResolvedValue({ data: { quota: 2, used: 1, remaining: 1 } })
  aiApi.createService.mockImplementation(async (payload) => ({ data: modelService({ name: payload.name }) }))
})
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('One-click deploy of a Hub model', () => {
  it('deploys online on CPU with RunR\'s pick and the pinned revision', async () => {
    const w = await mountDialog({ hubInfo: hubInfo() })

    // the only ready region is preselected, the name comes from the repository
    expect(w.find('[data-testid="deploy-region"]').element.value).toBe('r1')
    expect(w.find('[data-testid="deploy-name"]').element.value).toBe('all-minilm-l6-v2')

    await w.find('[data-testid="deploy-submit"]').trigger('click')
    await flushPromises()

    expect(aiApi.createService).toHaveBeenCalledWith(expect.objectContaining({
      hub_id: 'sentence-transformers/all-MiniLM-L6-v2', revision: '8b3219a92973c328a8e22fadcfa821b5dc75636a',
      name: 'all-minilm-l6-v2', knative_region_id: 'r1', mode: 'online', device: 'cpu', engine: 'auto',
      cpu: '1', memory_mb: 1024, scale_to_zero: true, min_scale: 0, max_scale: 1, timeout_seconds: 300, deploy: true,
    }))
    const payload = aiApi.createService.mock.calls[0][0]
    expect(payload).not.toHaveProperty('gpu')
    expect(payload).not.toHaveProperty('image')
    expect(useAiStore().services.map(s => s.name)).toEqual(['all-minilm-l6-v2'])
    expect(w.emitted('deployed')).toBeTruthy()
  })

  it('runs offline and on GPU with the GPU fields of the region', async () => {
    const w = await mountDialog({ hubInfo: hubInfo() })

    await w.find('[data-testid="deploy-mode-offline"]').trigger('click')
    await w.find('[data-testid="deploy-device-gpu"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-testid="deploy-gpu-quota"]').text()).toContain('1 of 2')
    await w.find('[data-testid="gpu-type"]').setValue('NVIDIA-GeForce-RTX-4050-Laptop-GPU')
    expect(w.find('[data-testid="deploy-cold-start"]').exists()).toBe(true)

    await w.find('[data-testid="deploy-submit"]').trigger('click')
    await flushPromises()

    expect(aiApi.createService).toHaveBeenCalledWith(expect.objectContaining({
      mode: 'offline', device: 'gpu', gpu: { count: 1, type: 'NVIDIA-GeForce-RTX-4050-Laptop-GPU' },
    }))
  })

  it('limits the device to what the chosen engine runs on (vLLM: GPU only)', async () => {
    const w = await mountDialog({
      hubInfo: hubInfo({
        id: 'Qwen/Qwen2.5-0.5B-Instruct', task: 'text-generation', size_bytes: 990_000_000,
        engines: [
          { engine: 'vllm', devices: ['gpu'], recommended: true, api: 'openai' },
          { engine: 'tgi', devices: ['gpu'], recommended: false, api: 'openai' },
          { engine: 'transformers', devices: ['cpu', 'gpu'], recommended: false, api: 'runr' },
        ],
        suggested: { engine: 'vllm', device: 'gpu', cpu: '2', memory_mb: 6144, gpu: { count: 1, memory_min_hint_mb: 3072 }, suggest_min_scale: true },
      }),
    })

    expect(w.find('[data-testid="deploy-device-gpu"]').classes().join(' ')).not.toContain('bg-gray-800')
    await w.find('[data-testid="deploy-engine"]').setValue('vllm')
    expect(w.find('[data-testid="deploy-device-cpu"]').attributes('disabled')).toBeDefined()
    expect(w.text()).toContain('about 3 GiB of GPU memory')

    await w.find('[data-testid="deploy-engine"]').setValue('transformers')
    expect(w.find('[data-testid="deploy-device-cpu"]').attributes('disabled')).toBeUndefined()
  })

  it('serves a custom image with arguments, env and port (advanced)', async () => {
    const w = await mountDialog({ hubInfo: hubInfo() })

    await w.find('[data-testid="deploy-engine"]').setValue('custom')
    expect(w.find('[data-testid="deploy-submit"]').attributes('disabled')).toBeDefined()   // image required
    await w.find('[data-testid="deploy-image"]').setValue('ghcr.io/acme/embedder:2')
    await w.find('#deploy-args').setValue('--pooling\nmean')
    await w.find('#deploy-env').setValue('LOG_LEVEL=debug')
    await w.find('#deploy-port').setValue(9000)
    await w.find('#deploy-health').setValue('/ready')
    await w.find('[data-testid="deploy-submit"]').trigger('click')
    await flushPromises()

    expect(aiApi.createService).toHaveBeenCalledWith(expect.objectContaining({
      engine: 'custom', image: 'ghcr.io/acme/embedder:2', args: ['--pooling', 'mean'], env_vars: { LOG_LEVEL: 'debug' }, port: 9000, health_path: '/ready',
    }))
  })

  it('deploys a library model by its id', async () => {
    const w = await mountDialog({ model: { id: 'am7', name: 'my-ner', source: 'upload', task: 'token-classification', size_bytes: 400_000_000 } })

    await w.find('[data-testid="deploy-submit"]').trigger('click')
    await flushPromises()

    const payload = aiApi.createService.mock.calls[0][0]
    expect(payload.ai_model_id).toBe('am7')
    expect(payload).not.toHaveProperty('hub_id')
    expect(payload.name).toBe('my-ner')
  })

  it('shows the API error', async () => {
    aiApi.createService.mockRejectedValue(Object.assign(new Error('422'), { response: { status: 422, data: { message: 'GPU quota exceeded on eu-1: 2 of 2 GPUs claimed.' } } }))
    const w = await mountDialog({ hubInfo: hubInfo() })

    await w.find('[data-testid="deploy-submit"]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid="deploy-error"]').text()).toContain('GPU quota exceeded')
  })
})
