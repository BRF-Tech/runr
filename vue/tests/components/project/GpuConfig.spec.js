import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, reactive } from 'vue'
import GpuConfig from '@/components/project/GpuConfig.vue'
import { aiApi } from '@/api/ai'
import { useRuntimesStore, normalizeRuntime } from '@/stores/runtimes'
import { clearRegionGpuCache } from '@/composables/useRegionGpu'
import { makeI18n, freshPinia, catalog, gpuStatus } from '@/test-support/helpers'

vi.mock('@/api/ai', () => ({ aiApi: { gpu: vi.fn() } }))
vi.mock('@/api/runtimes', () => ({ runtimesApi: { list: vi.fn(() => new Promise(() => {})) } }))
vi.mock('@/api/settings', () => ({ settingsApi: { get: vi.fn(() => new Promise(() => {})) } }))

const i18n = makeI18n()
let pinia
const mounted = []

const pytorch = {
  ...catalog.python, id: 'pytorch', name: 'PyTorch', category: 'ml',
  versions: [
    { version: '2.3', is_default: true, eol_at: null, gpu_required: false, image: 'ghcr.io/brf-tech/runr/exec-pytorch:2.3', built: false },
    { version: '2.3-gpu', is_default: false, eol_at: null, gpu_required: true, image: 'ghcr.io/brf-tech/runr/exec-pytorch:2.3-gpu', built: false },
  ],
}

/** The project form's use: v-model on config.gpu and config.timeout_seconds. */
function host(initial = {}) {
  return defineComponent({
    setup() {
      const form = reactive({
        service_type: 'caas', knative_region_id: 'r1', runtime: null, runtime_version: null,
        config: { gpu: { enabled: false, count: 1, type: '', memory_min: null }, timeout_seconds: 30, scale_to_zero: true, min_scale: 0 },
        ...initial,
      })
      return { form }
    },
    render() {
      return h(GpuConfig, {
        modelValue: this.form.config.gpu,
        'onUpdate:modelValue': (v) => { this.form.config.gpu = v },
        timeoutSeconds: this.form.config.timeout_seconds,
        'onUpdate:timeoutSeconds': (v) => { this.form.config.timeout_seconds = v },
        serviceType: this.form.service_type,
        regionId: this.form.knative_region_id,
        runtime: this.form.runtime,
        runtimeVersion: this.form.runtime_version,
        maxTimeout: 600,
        scaleToZero: this.form.config.scale_to_zero,
        minScale: this.form.config.min_scale,
      })
    },
  })
}

async function mountHost(initial) {
  const wrapper = mount(host(initial), { global: { plugins: [pinia, i18n] } })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
  clearRegionGpuCache()
  useRuntimesStore().list = [normalizeRuntime(catalog.python), normalizeRuntime(pytorch)]
  aiApi.gpu.mockResolvedValue({ data: gpuStatus() })
})
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('GpuConfig (project GPU fields)', () => {
  it('is offered for CaaS and off by default', async () => {
    const w = await mountHost()

    expect(w.find('[data-testid="gpu-config"]').exists()).toBe(true)
    expect(w.find('[data-testid="gpu-count"]').exists()).toBe(false)
    expect(w.vm.form.config.gpu.enabled).toBe(false)
  })

  it('is not offered for XaaS / FaaS on a CPU runtime version', async () => {
    const w = await mountHost({ service_type: 'xaas', runtime: 'python', runtime_version: '3.14' })

    expect(w.find('[data-testid="gpu-config"]').exists()).toBe(false)
  })

  it('turns on for a GPU runtime version (at least one GPU, the toggle is locked)', async () => {
    const w = await mountHost({ service_type: 'xaas', runtime: 'pytorch', runtime_version: '2.3-gpu' })

    expect(w.find('[data-testid="gpu-config"]').exists()).toBe(true)
    expect(w.vm.form.config.gpu.enabled).toBe(true)
    expect(w.find('[data-testid="gpu-toggle"]').attributes('disabled')).toBeDefined()
  })

  it('switching it on raises the 30 s default timeout to 300 s and shows the cold-start hint', async () => {
    const w = await mountHost()

    await w.find('[data-testid="gpu-toggle"]').trigger('click')
    await flushPromises()

    expect(w.vm.form.config.gpu.enabled).toBe(true)
    expect(w.vm.form.config.timeout_seconds).toBe(300)
    expect(w.find('[data-testid="gpu-cold-start"]').text()).toContain('min scale 1')
  })

  it('offers the GPU types of the region and writes count, type and memory', async () => {
    const w = await mountHost({ config: { gpu: { enabled: true, count: 1, type: '', memory_min: null }, timeout_seconds: 300, scale_to_zero: true, min_scale: 0 } })

    expect(aiApi.gpu).toHaveBeenCalledWith('r1', false)
    const options = w.findAll('[data-testid="gpu-type"] option').map(o => o.text())
    expect(options[1]).toContain('NVIDIA-GeForce-RTX-4050-Laptop-GPU')
    expect(options[1]).toContain('6 GiB')

    await w.find('[data-testid="gpu-type"]').setValue('NVIDIA-GeForce-RTX-4050-Laptop-GPU')
    await w.find('[data-testid="gpu-memory-min"]').setValue('4096')
    expect(w.vm.form.config.gpu).toMatchObject({ enabled: true, count: 1, type: 'NVIDIA-GeForce-RTX-4050-Laptop-GPU', memory_min: 4096 })
  })

  it('warns when the project type runs under a sandbox without GPUs on the region (Kata)', async () => {
    const w = await mountHost({ config: { gpu: { enabled: true, count: 1, type: '', memory_min: null }, timeout_seconds: 300, scale_to_zero: true, min_scale: 0 } })

    expect(w.find('[data-testid="gpu-sandbox-unsupported"]').text()).toContain('Kata VMs get no GPU passthrough')
  })

  it('warns when the region has no GPU nodes', async () => {
    aiApi.gpu.mockResolvedValue({ data: gpuStatus({ summary: { gpu_nodes: 0, total: 0, allocatable: 0, allocated: 0, available: 0, products: [], hardware_without_plugin: [] }, nodes: [] }) })
    const w = await mountHost({ config: { gpu: { enabled: true, count: 1, type: '', memory_min: null }, timeout_seconds: 300, scale_to_zero: true, min_scale: 0 } })

    expect(w.find('[data-testid="gpu-none"]').exists()).toBe(true)
  })
})
