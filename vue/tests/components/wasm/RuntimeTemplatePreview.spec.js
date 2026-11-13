import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import RuntimeTemplatePreview from '@/components/project/RuntimeTemplatePreview.vue'
import { runtimesApi } from '@/api/runtimes'
import { withPlugins } from '../../helpers'

vi.mock('@/api/runtimes', () => ({
  runtimesApi: {
    list: vi.fn(), get: vi.fn(), validate: vi.fn(), create: vi.fn(), update: vi.fn(), destroy: vi.fn(),
    testDeploy: vi.fn(), builds: vi.fn(), build: vi.fn(), template: vi.fn(),
  },
}))

const starters = {
  '4.1': { 'lib.rs': '#[http_component]\nfn handle(req: Request) {}' },
  '4.2': { 'lib.rs': '#[http_service]\nasync fn handle(req: Request) {}' },
}

async function mountPreview(props) {
  const { global } = withPlugins()
  const wrapper = mount(RuntimeTemplatePreview, {
    props,
    global: { ...global, stubs: { ...(global.stubs || {}), MonacoEditor: { props: ['modelValue'], template: '<pre class="code">{{ modelValue }}</pre>' } } },
  })
  await wrapper.find('button').trigger('click')
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
  runtimesApi.template.mockImplementation((id, params) => Promise.resolve({ data: { files: starters[params.version ?? '4.1'] } }))
})

// A runtime version with a starter of its own (versions[].template: wasm-rust 4.2 is Spin SDK 8):
// the preview shows the starter of the version picked in the form
describe('starter code preview — the picked runtime version', () => {
  it('asks for the starter of the picked version and shows it', async () => {
    const wrapper = await mountPreview({ runtime: 'wasm-rust', version: '4.2', serviceType: 'xaas' })

    expect(runtimesApi.template).toHaveBeenCalledWith('wasm-rust', { service_type: 'xaas', version: '4.2' })
    expect(wrapper.find('.code').text()).toContain('#[http_service]')
  })

  it('loads again when the version changes', async () => {
    const wrapper = await mountPreview({ runtime: 'wasm-rust', version: '4.2', serviceType: 'xaas' })
    await wrapper.setProps({ version: '4.1' })
    await flushPromises()

    expect(runtimesApi.template).toHaveBeenLastCalledWith('wasm-rust', { service_type: 'xaas', version: '4.1' })
    expect(wrapper.find('.code').text()).toContain('#[http_component]')
  })

  it('sends no version before one is picked (the runtime\'s default)', async () => {
    await mountPreview({ runtime: 'wasm-rust', version: null, serviceType: 'faas' })

    expect(runtimesApi.template).toHaveBeenCalledWith('wasm-rust', { service_type: 'faas' })
  })
})
