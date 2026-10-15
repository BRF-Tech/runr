import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils'
import WasmSupportCard from '@/components/region/WasmSupportCard.vue'
import { wasmApi } from '@/api/wasm'
import { useConfirm } from '@/composables/useConfirm'
import { dispatchPlatformEvent } from '@/composables/useJobEvents'
import { withPlugins } from '../../helpers'

vi.mock('@/api/wasm', () => ({
  wasmApi: { status: vi.fn(), install: vi.fn(), uninstall: vi.fn() },
}))

const SHIMS = [
  { id: 'spin', name: 'Spin', version: 'v0.26.0', runtime_class: 'wasmtime-spin-v2', handler: 'spin-runr', description: 'Spin apps' },
  { id: 'wasmtime', name: 'Wasmtime', version: 'v0.6.1', runtime_class: 'wasmtime-v1', handler: 'wasmtime-runr', description: 'WASI modules' },
]
const NODES = [
  { name: 'worker-1', arch: 'amd64', flavor: 'containerd', eligible: true, reason: null, target: false, shims: [] },
  { name: 'worker-2', arch: 'arm64', flavor: 'containerd', eligible: true, reason: null, target: true, shims: ['spin'] },
  { name: 'gpu-0', arch: 'amd64', flavor: 'cri-o', eligible: false, reason: 'runtime', target: false, shims: [] },
]

function status(extra = {}) {
  return {
    data: {
      status: 'not_installed', message: null, shims: [], nodes: null, available_shims: SHIMS, spin_runtime: '4.1.0',
      warning: 'w', can_manage: true, cluster_error: null,
      cluster: { nodes: NODES, runtime_classes: {}, knative_features_to_enable: [], installer: null },
      ...extra,
    },
  }
}

// job event handlers are global: every card of a test goes with it
enableAutoUnmount(afterEach)

const REGION = { id: 'r1', name: 'eu-1', sandbox_wasm_status: 'not_installed', sandbox_wasm_shims: [] }

async function mountCard(region = REGION) {
  const { global } = withPlugins()
  const wrapper = mount(WasmSupportCard, { props: { regionId: 'r1', region }, global })
  await flushPromises()
  return wrapper
}

const statusText = (w) => w.find('[data-testid="wasm-status"]').text()
const input = (w, testid) => w.find(`[data-testid="${testid}"] input`)

async function acceptConfirm() {
  await flushPromises()
  const { current, accept } = useConfirm()
  const asked = current.value
  accept()
  await flushPromises()
  return asked
}

beforeEach(() => {
  vi.clearAllMocks()
  wasmApi.status.mockResolvedValue(status())
})

describe('WasmSupportCard', () => {
  it('shows the status, the shims and every node with its eligibility', async () => {
    const w = await mountCard()

    expect(wasmApi.status).toHaveBeenCalledWith('r1', {})
    expect(statusText(w)).toBe('Not Installed')
    expect(w.text()).toContain('Spin v0.26.0 · RuntimeClass wasmtime-spin-v2')
    // Spin is what the Wasm runtimes run on: always selected
    expect(input(w, 'wasm-shim-spin').element.checked).toBe(true)
    expect(input(w, 'wasm-shim-spin').attributes('disabled')).toBeDefined()
    expect(input(w, 'wasm-shim-wasmtime').element.checked).toBe(false)
    expect(w.text()).toContain('All eligible nodes (2)')

    await input(w, 'wasm-all-nodes').setValue(false)
    expect(w.find('[data-testid="wasm-node-gpu-0"]').text()).toContain('not containerd')
    expect(input(w, 'wasm-node-gpu-0').attributes('disabled')).toBeDefined()
    expect(w.find('[data-testid="wasm-node-worker-2"]').text()).toContain('shims: spin')
  })

  it('installs on every eligible node after the restart warning', async () => {
    wasmApi.install.mockResolvedValue({ data: { region: { data: { ...REGION, sandbox_wasm_status: 'installing', sandbox_wasm_status_message: 'Installation queued...', sandbox_wasm_shims: ['spin'] } } } })
    const w = await mountCard()

    await w.find('[data-testid="wasm-install"]').trigger('click')
    const asked = await acceptConfirm()

    expect(asked.message).toContain('restarts the container runtime')
    expect(asked.confirmLabel).toBe('Install and restart')
    expect(wasmApi.install).toHaveBeenCalledWith('r1', { shims: ['spin'], nodes: null })
    expect(statusText(w)).toBe('Installing...')
    expect(w.emitted('saved')[0][0].sandbox_wasm_status).toBe('installing')
  })

  it('sends the selected nodes and the optional Wasmtime shim', async () => {
    wasmApi.install.mockResolvedValue({ data: { region: { data: { ...REGION, sandbox_wasm_status: 'installing' } } } })
    const w = await mountCard()

    await input(w, 'wasm-shim-wasmtime').setValue(true)
    await input(w, 'wasm-all-nodes').setValue(false)
    // nothing selected yet: nothing to install
    expect(w.find('[data-testid="wasm-install"]').attributes('disabled')).toBeDefined()
    await input(w, 'wasm-node-worker-2').setValue(true)
    await w.find('[data-testid="wasm-install"]').trigger('click')
    await acceptConfirm()

    expect(wasmApi.install).toHaveBeenCalledWith('r1', { shims: ['spin', 'wasmtime'], nodes: ['worker-2'] })
  })

  it('cancelling the warning installs nothing', async () => {
    const w = await mountCard()

    await w.find('[data-testid="wasm-install"]').trigger('click')
    await flushPromises()
    useConfirm().cancel()
    await flushPromises()

    expect(wasmApi.install).not.toHaveBeenCalled()
  })

  it('shows why an install was refused', async () => {
    wasmApi.install.mockRejectedValue(Object.assign(new Error('HTTP 422'), { response: { status: 422, data: { message: 'Node gpu-0 cannot get WebAssembly support' } } }))
    const w = await mountCard()

    await w.find('[data-testid="wasm-install"]').trigger('click')
    await acceptConfirm()

    expect(w.find('[data-testid="wasm-error"]').text()).toContain('Node gpu-0 cannot get WebAssembly support')
    expect(statusText(w)).toBe('Not Installed')
  })

  it('a ready install offers changes and an uninstall that asks first', async () => {
    wasmApi.status.mockResolvedValue(status({ status: 'ready', shims: ['spin', 'wasmtime'], nodes: ['worker-2'], message: '[10:00:00] WebAssembly support installed.\n' }))
    wasmApi.uninstall.mockResolvedValue({ data: { region: { data: { ...REGION, sandbox_wasm_status: 'uninstalling' } } } })
    const w = await mountCard({ ...REGION, sandbox_wasm_status: 'ready', sandbox_wasm_shims: ['spin', 'wasmtime'], sandbox_wasm_nodes: ['worker-2'] })

    expect(statusText(w)).toBe('Ready')
    expect(w.find('[data-testid="wasm-install"]').text()).toBe('Apply changes')
    expect(input(w, 'wasm-shim-wasmtime').element.checked).toBe(true)
    // the stored selection, not "all nodes"
    expect(input(w, 'wasm-all-nodes').element.checked).toBe(false)
    expect(input(w, 'wasm-node-worker-2').element.checked).toBe(true)

    await w.find('[data-testid="wasm-uninstall"]').trigger('click')
    const asked = await acceptConfirm()

    expect(asked.destructive).toBe(true)
    expect(wasmApi.uninstall).toHaveBeenCalledWith('r1')
    expect(statusText(w)).toBe('Uninstalling...')
  })

  it('follows the install: progress lines, then the result', async () => {
    wasmApi.status.mockResolvedValue(status({ status: 'installing', message: '[10:00:00] Installation queued...\n' }))
    const w = await mountCard({ ...REGION, sandbox_wasm_status: 'installing' })

    // controls stay hidden while it runs
    expect(w.find('[data-testid="wasm-install"]').exists()).toBe(false)

    dispatchPlatformEvent('install.progress', { region_id: 'r1', component: 'wasm', message: '[10:00:05] Deploying the WebAssembly installer\n' })
    dispatchPlatformEvent('install.progress', { region_id: 'r1', component: 'kata', message: 'not ours' })
    dispatchPlatformEvent('install.progress', { region_id: 'r2', component: 'wasm', message: 'other region' })
    await flushPromises()
    expect(w.find('[data-testid="wasm-log"]').text()).toContain('Deploying the WebAssembly installer')

    wasmApi.status.mockResolvedValue(status({ status: 'ready', shims: ['spin'], message: '[10:02:00] WebAssembly support installed.\n' }))
    dispatchPlatformEvent('wasm.installed', { region_id: 'r1', region_name: 'eu-1' })
    await flushPromises()
    expect(statusText(w)).toBe('Ready')
    expect(wasmApi.status).toHaveBeenCalledTimes(2)
  })

  it('without sandbox.manage it shows the status only', async () => {
    wasmApi.status.mockResolvedValue(status({ status: 'ready', shims: ['spin'], can_manage: false }))
    const w = await mountCard({ ...REGION, sandbox_wasm_status: 'ready', sandbox_wasm_shims: ['spin'] })

    expect(statusText(w)).toBe('Ready')
    expect(w.text()).toContain('needs the sandbox.manage permission')
    expect(w.find('[data-testid="wasm-install"]').exists()).toBe(false)
    expect(w.find('[data-testid="wasm-uninstall"]').exists()).toBe(false)
  })

  it('a region without an eligible node says what is needed', async () => {
    wasmApi.status.mockResolvedValue(status({ cluster: { nodes: [NODES[2]], runtime_classes: {}, knative_features_to_enable: [], installer: null } }))
    const w = await mountCard()

    expect(w.find('[data-testid="wasm-no-eligible"]').text()).toContain('containerd')
    expect(w.find('[data-testid="wasm-install"]').attributes('disabled')).toBeDefined()
  })
})
