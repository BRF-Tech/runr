import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, reactive } from 'vue'
import RuntimeSelector from '@/components/project/RuntimeSelector.vue'
import { useRuntimeData } from '@/composables/useProjectForm'
import { useSettingsStore } from '@/stores/settings'
import { runtimesApi } from '@/api/runtimes'
import { withPlugins } from '../../helpers'

vi.mock('@/api/runtimes', () => ({
  runtimesApi: {
    list: vi.fn(), get: vi.fn(), validate: vi.fn(), create: vi.fn(), update: vi.fn(), destroy: vi.fn(),
    testDeploy: vi.fn(), builds: vi.fn(), build: vi.fn(), template: vi.fn(),
  },
}))

const python = {
  id: 'python', name: 'Python', source: 'builtin', run_mode: 'http', triggers: ['http', 'schedule', 'queue'], regions: null,
  is_enabled: true, wasm: null, runtime_class: null,
  versions: [{ version: '3.14', is_default: true, eol_at: null }],
}
const wasmRust = {
  id: 'wasm-rust', name: 'Rust (Wasm)', source: 'builtin', run_mode: 'http', compiled: true, triggers: ['http', 'schedule', 'queue'],
  regions: null, is_enabled: true, wasm: 'spin', runtime_class: 'wasmtime-spin-v2',
  versions: [{ version: '4.1', is_default: true, eol_at: null }],
}
const wasmGo = { ...wasmRust, id: 'wasm-go', name: 'Go (Wasm)', regions: ['r-wasm'] }

const regions = [
  { id: 'r-wasm', name: 'eu-1', is_active: true, is_synced: true, sandbox_wasm_status: 'ready', sandbox_wasm_shims: ['spin'] },
  { id: 'r-plain', name: 'us-1', is_active: true, is_synced: true, sandbox_wasm_status: 'not_installed', sandbox_wasm_shims: [] },
  { id: 'r-busy', name: 'lab', is_active: true, is_synced: true, sandbox_wasm_status: 'installing', sandbox_wasm_shims: ['spin'] },
]

// The picker as the create form uses it: useRuntimeData (catalog + region filter) → RuntimeSelector
const Picker = defineComponent({
  props: { regionId: { type: String, default: 'r-wasm' }, trigger: { type: String, default: 'http' } },
  setup(props) {
    const form = reactive({ runtime: 'python', runtime_version: null, knative_region_id: props.regionId, config: { trigger_type: props.trigger } })
    const { runtimes } = useRuntimeData(form)
    return () => h(RuntimeSelector, { runtimes: runtimes.value, selectedRuntime: form.runtime, triggerType: form.config.trigger_type })
  },
})

async function mountPicker(props = {}) {
  const { global } = withPlugins()
  useSettingsStore().data.regions = regions
  const wrapper = mount(Picker, { props, global })
  await flushPromises()
  return wrapper
}

const ids = (wrapper) => wrapper.findAll('[data-runtime]').map(b => b.attributes('data-runtime'))

beforeEach(() => {
  vi.clearAllMocks()
  runtimesApi.list.mockResolvedValue({ data: { data: [python, wasmRust, wasmGo], abilities: { manage: false, use_custom: false }, custom_enabled: false } })
})

describe('runtime picker — WebAssembly runtimes', () => {
  it('offers the Wasm runtimes in a region whose WebAssembly support is ready, with a Wasm badge', async () => {
    const wrapper = await mountPicker({ regionId: 'r-wasm' })

    expect(ids(wrapper)).toEqual(['python', 'wasm-rust', 'wasm-go'])
    expect(wrapper.find('[data-runtime="wasm-rust"] .wasm-badge').text()).toBe('Wasm')
    expect(wrapper.find('[data-runtime="wasm-rust"] .wasm-badge').attributes('title')).toContain('WebAssembly')
    expect(wrapper.find('[data-runtime="python"] .wasm-badge').exists()).toBe(false)
  })

  it('hides them in regions without it, or while it is being installed', async () => {
    expect(ids(await mountPicker({ regionId: 'r-plain' }))).toEqual(['python'])
    expect(ids(await mountPicker({ regionId: 'r-busy' }))).toEqual(['python'])
  })

  it('still applies the runtime\'s own region list', async () => {
    // wasm-go is offered in r-wasm only — and needs WebAssembly there as well
    const wrapper = await mountPicker({ regionId: 'r-wasm' })
    expect(ids(wrapper)).toContain('wasm-go')
    expect(ids(await mountPicker({ regionId: 'r-plain' }))).not.toContain('wasm-go')
  })
})
