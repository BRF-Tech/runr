import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import GpuTab from '@/components/region/GpuTab.vue'
import { aiApi } from '@/api/ai'
import { useToast } from '@/composables/useToast'
import { clearRegionGpuCache } from '@/composables/useRegionGpu'
import { makeI18n, freshPinia, apiError, gpuStatus } from '@/test-support/helpers'

vi.mock('@/api/ai', () => ({
  aiApi: {
    gpu: vi.fn(), installGpu: vi.fn(), uninstallGpu: vi.fn(), updateGpuSettings: vi.fn(),
    modelStorage: vi.fn(), updateModelStorage: vi.fn(),
  },
}))
const confirmMock = vi.fn(() => Promise.resolve(true))
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: confirmMock }) }))

const i18n = makeI18n()
let pinia
const mounted = []

async function mountTab(status = gpuStatus()) {
  aiApi.gpu.mockResolvedValue({ data: status })
  const wrapper = mount(GpuTab, { props: { regionId: 'r1', region: { id: 'r1', name: 'eu-1' } }, global: { plugins: [pinia, i18n] } })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
  clearRegionGpuCache()
  useToast().toasts.value = []
  aiApi.modelStorage.mockResolvedValue({ data: { storage_class: null, size_gi: 100, access_mode: 'ReadWriteMany', pvc_name: 'runr-models', status: 'absent', message: null } })
})
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('Region → GPU tab', () => {
  it('shows the GPU nodes with model, memory, allocation, driver and the summary', async () => {
    const w = await mountTab()

    expect(aiApi.gpu).toHaveBeenCalledWith('r1', false)
    const row = w.find('[data-gpu-node="gpu-1"]')
    expect(row.text()).toContain('NVIDIA-GeForce-RTX-4050-Laptop-GPU')
    expect(row.text()).toContain('6 GiB')
    expect(row.text()).toContain('0 / 1')
    expect(row.text()).toContain('550.54.15')
    expect(row.text()).toContain('CUDA 12.4')
    expect(row.text()).toContain('Running')
    expect(w.find('[data-testid="gpu-summary-total"]').text()).toBe('1')
    expect(w.find('[data-testid="gpu-status"]').text()).toBe('Ready')
    // installed: no install form, a remove button
    expect(w.find('[data-testid="gpu-install-form"]').exists()).toBe(false)
    expect(w.find('[data-testid="gpu-uninstall"]').exists()).toBe(true)
  })

  it('shows GPU support per project type: runc ok, gVisor nvproxy, Kata unsupported', async () => {
    const w = await mountTab()

    expect(w.find('[data-gpu-sandbox="xaas"]').text()).toContain('GPU supported')
    expect(w.find('[data-gpu-sandbox="faas"]').text()).toContain('GPU through gVisor nvproxy')
    expect(w.find('[data-gpu-sandbox="caas"]').text()).toContain('No GPU')
    expect(w.find('[data-gpu-sandbox="caas"]').text()).toContain('Kata VMs get no GPU passthrough')
  })

  it('names nodes with NVIDIA hardware but no device plugin', async () => {
    const status = gpuStatus({ status: 'not_installed', mode: 'none' })
    status.summary.hardware_without_plugin = ['gpu-2']
    const w = await mountTab(status)

    expect(w.find('[data-testid="gpu-without-plugin"]').text()).toContain('gpu-2')
  })

  it('installs the device plugin with time-slicing and the RuntimeClass choice', async () => {
    const w = await mountTab(gpuStatus({ status: 'not_installed', mode: 'none' }))
    aiApi.installGpu.mockResolvedValue({ status: 202, data: { message: 'queued', gpu: gpuStatus({ status: 'installing' }) } })

    await w.find('[data-testid="gpu-time-slicing"]').setValue(4)
    await w.find('[data-testid="gpu-runtime-class"]').setValue('nvidia')
    await w.find('[data-testid="gpu-install"]').trigger('click')
    await flushPromises()

    expect(aiApi.installGpu).toHaveBeenCalledWith('r1', {
      mode: 'device-plugin', runtime_class: 'nvidia', time_slicing_replicas: 4, restrict_to_gpu_nodes: true, gpu_nodes: null,
    })
    expect(w.find('[data-testid="gpu-status"]').text()).toBe('Installing')
  })

  it('restricts the device plugin to the named GPU nodes, or lets it run on every NVIDIA node', async () => {
    let w = await mountTab(gpuStatus({ status: 'not_installed', mode: 'none' }))
    aiApi.installGpu.mockResolvedValue({ data: { gpu: gpuStatus({ status: 'installing' }) } })

    expect(w.find('[data-testid="gpu-restrict"]').element.checked).toBe(true)
    await w.find('[data-testid="gpu-nodes"]').setValue('kind-worker3, gpu-b')
    await w.find('[data-testid="gpu-install"]').trigger('click')
    await flushPromises()
    expect(aiApi.installGpu).toHaveBeenLastCalledWith('r1', expect.objectContaining({ restrict_to_gpu_nodes: true, gpu_nodes: ['kind-worker3', 'gpu-b'] }))

    // installing now: the form is gone; the other choice on a region that has no GPU support yet
    expect(w.find('[data-testid="gpu-install-form"]').exists()).toBe(false)
    clearRegionGpuCache()
    w = await mountTab(gpuStatus({ status: 'not_installed', mode: 'none' }))
    await w.find('[data-testid="gpu-restrict"]').setValue(false)
    expect(w.find('[data-testid="gpu-nodes"]').exists()).toBe(false)
    await w.find('[data-testid="gpu-install"]').trigger('click')
    await flushPromises()
    expect(aiApi.installGpu).toHaveBeenLastCalledWith('r1', expect.objectContaining({ restrict_to_gpu_nodes: false, gpu_nodes: null }))
  })

  it('installs the GPU Operator with the driver / toolkit choices; one replica means no time-slicing', async () => {
    const w = await mountTab(gpuStatus({ status: 'not_installed', mode: 'none' }))
    aiApi.installGpu.mockResolvedValue({ data: { gpu: gpuStatus({ status: 'installing', mode: 'operator' }) } })

    await w.find('[data-testid="gpu-mode-operator"]').trigger('click')
    await w.find('[data-testid="gpu-operator-driver"]').setValue(false)
    await w.find('[data-testid="gpu-install"]').trigger('click')
    await flushPromises()

    expect(aiApi.installGpu).toHaveBeenCalledWith('r1', {
      mode: 'operator', runtime_class: 'auto', time_slicing_replicas: null, restrict_to_gpu_nodes: true, gpu_nodes: null,
      operator_driver: false, operator_toolkit: true,
    })
  })

  it('removes GPU support after a confirmation', async () => {
    const w = await mountTab()
    aiApi.uninstallGpu.mockResolvedValue({ data: { gpu: gpuStatus({ status: 'uninstalling' }) } })

    await w.find('[data-testid="gpu-uninstall"]').trigger('click')
    await flushPromises()

    expect(confirmMock).toHaveBeenCalled()
    expect(aiApi.uninstallGpu).toHaveBeenCalledWith('r1')
    expect(w.find('[data-testid="gpu-status"]').text()).toBe('Removing')
  })

  it('answers a 403 with a toast instead of failing', async () => {
    const w = await mountTab(gpuStatus({ status: 'not_installed', mode: 'none' }))
    aiApi.installGpu.mockRejectedValue(apiError(403, { message: 'Forbidden' }))

    await w.find('[data-testid="gpu-install"]').trigger('click')
    await flushPromises()

    const toasts = useToast().toasts.value
    expect(toasts.at(-1)).toMatchObject({ type: 'error' })
    expect(toasts.at(-1).message).toContain('region GPU permission')
  })

  it('saves time-slicing, the RuntimeClass and gVisor nvproxy', async () => {
    const w = await mountTab()
    aiApi.updateGpuSettings.mockResolvedValue({ data: { gpu: gpuStatus({ settings: { time_slicing_replicas: 2, runtime_class: 'nvidia', gvisor_nvproxy: true } }) } })

    await w.find('#gpu-settings-slicing').setValue(2)
    await w.find('[data-testid="gpu-nvproxy"]').setValue(true)
    await w.find('[data-testid="gpu-settings-save"]').trigger('click')
    await flushPromises()

    expect(aiApi.updateGpuSettings).toHaveBeenCalledWith('r1', {
      time_slicing_replicas: 2, gvisor_nvproxy: true, runtime_class: 'nvidia', restrict_to_gpu_nodes: true, gpu_nodes: null,
    })
  })

  it('shows the admin\'s RuntimeClass choice and the allocation per node', async () => {
    const status = gpuStatus({ allocation: { total: 1, by_node: { 'gpu-1': 1 } } })
    status.settings.runtime_class_choice = 'auto'
    delete status.nodes[0].allocated
    const w = await mountTab(status)

    expect(w.find('#gpu-settings-rc').element.value).toBe('auto')
    expect(w.find('[data-gpu-node="gpu-1"]').text()).toContain('1 / 1')
  })

  it('refreshes the detection on demand', async () => {
    const w = await mountTab()

    await w.find('[data-testid="gpu-refresh"]').trigger('click')
    await flushPromises()

    expect(aiApi.gpu).toHaveBeenLastCalledWith('r1', true)
  })

  it('says why the model volume is pending', async () => {
    const reason = 'The model volume runr-models is Pending: failed to provision volume with StorageClass "local-path": NodePath only supports ReadWriteOnce and ReadWriteOncePod (1.22+) access modes'
    aiApi.modelStorage.mockResolvedValue({ data: { storage_class: 'local-path', size_gi: 100, access_mode: 'ReadWriteMany', pvc_name: 'runr-models', status: 'pending', message: reason } })
    const w = await mountTab()

    const card = w.find('[data-testid="model-storage"]')
    expect(card.text()).toContain('Pending')
    expect(card.text()).toContain(reason)
  })

  it('edits the model volume (storage class, size, access mode)', async () => {
    const w = await mountTab()
    aiApi.updateModelStorage.mockResolvedValue({ data: { storage_class: 'local-path', size_gi: 50, access_mode: 'ReadWriteOnce', status: 'absent' } })

    await w.find('#model-storage-class').setValue('local-path')
    await w.find('#model-storage-size').setValue(50)
    await w.find('#model-storage-mode').setValue('ReadWriteOnce')
    await w.find('[data-testid="model-storage-save"]').trigger('click')
    await flushPromises()

    expect(aiApi.updateModelStorage).toHaveBeenCalledWith('r1', { storage_class: 'local-path', size_gi: 50, access_mode: 'ReadWriteOnce' })
  })
})
