import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import CreateSandboxDialog from '@/modules/sandboxes/components/CreateSandboxDialog.vue'
import { sandboxesApi, sandboxAdminApi } from '@/modules/sandboxes/api/sandboxes'
import { runtimesApi } from '@/api/runtimes'
import { useToast } from '@/composables/useToast'
import { freshPinia } from '@/test-support/helpers'
import { buildCreatePayload, parseAllowlist } from '@/modules/sandboxes/utils'
import { sandboxI18n, sandbox, regionRows, pythonRuntime, nodeRuntime, apiReject } from './fixtures'

vi.mock('@/modules/sandboxes/api/sandboxes', () => ({
  sandboxesApi: { create: vi.fn() },
  sandboxAdminApi: { regions: vi.fn() },
}))
vi.mock('@/api/runtimes', () => ({
  runtimesApi: { list: vi.fn() },
}))
vi.mock('@/api/settings', () => ({
  settingsApi: { get: vi.fn() },
}))

const i18n = sandboxI18n()
let pinia
const mounted = []

async function mountDialog() {
  const wrapper = mount(CreateSandboxDialog, { global: { plugins: [pinia, i18n] } })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
  sandboxAdminApi.regions.mockResolvedValue({ data: { data: [...regionRows, { ...regionRows[1], region: { id: 'r3', name: 'off' }, enabled: false }] } })
  runtimesApi.list.mockResolvedValue({ data: { data: [pythonRuntime, nodeRuntime], abilities: { manage: false, use_custom: false }, custom_enabled: false } })
  useToast().toasts.value = []
})

afterEach(() => {
  while (mounted.length) mounted.pop().unmount()
})

describe('CreateSandboxDialog', () => {
  it('offers the enabled regions and the runtime catalog with the default version', async () => {
    const wrapper = await mountDialog()

    expect(wrapper.findAll('[data-region]').map(b => b.attributes('data-region'))).toEqual(['r1', 'r2'])
    expect(wrapper.find('select[name="runtime"]').element.value).toBe('python')
    expect(wrapper.find('select[name="version"]').element.value).toBe('3.14')

    await wrapper.find('select[name="runtime"]').setValue('nodejs')
    await flushPromises()
    expect(wrapper.find('select[name="version"]').element.value).toBe('24.14')
  })

  it('builds the create payload from the form (network allowlist, env, resources)', async () => {
    sandboxesApi.create.mockResolvedValue({ status: 202, data: { data: sandbox('01jb0000000000000000cccc03', { status: 'pending' }) } })
    const wrapper = await mountDialog()

    await wrapper.find('[data-region="r2"]').trigger('click')
    await wrapper.find('input[name="cpu"]').setValue('1')
    await wrapper.find('input[name="memory"]').setValue(1024)
    await wrapper.find('input[name="ttl"]').setValue(30)
    await wrapper.find('input[name="idle"]').setValue(0)
    await wrapper.find('[data-network="allowlist"]').trigger('click')
    await wrapper.find('textarea[name="allowlist"]').setValue('pypi.org:443\n10.0.0.0/8\n\npypi.org:443')
    await wrapper.find('[data-add-row="env"]').trigger('click')
    const envInputs = wrapper.findAll('[data-row="env"] input')
    await envInputs[0].setValue('OPENAI_API_KEY')
    await envInputs[1].setValue('sk-test')

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(sandboxesApi.create).toHaveBeenCalledWith({
      region:         'r2',
      runtime:        'python',
      version:        '3.14',
      cpu:            '1',
      memory:         1024,
      disk:           1024,
      ttl_seconds:    1800,
      idle_timeout:   0,
      network:        { egress_allow: ['pypi.org:443', '10.0.0.0/8'] },
      env:            { OPENAI_API_KEY: 'sk-test' },
      read_only_root: true,
      wait:           false,
    })
    expect(wrapper.emitted('created')?.[0]?.[0]?.id).toBe('01jb0000000000000000cccc03')
    expect(useToast().toasts.value[0]?.message).toContain('is starting')
  })

  it('sends a custom image instead of a runtime, with a sandbox class and metadata', async () => {
    sandboxesApi.create.mockResolvedValue({ status: 201, data: { data: sandbox('01jb0000000000000000dddd04') } })
    const wrapper = await mountDialog()

    await wrapper.find('[data-source="image"]').trigger('click')
    await wrapper.find('input[name="image"]').setValue('docker.io/library/python:3.13-slim')
    await wrapper.find('[data-toggle="advanced"]').trigger('click')
    await wrapper.find('select[name="sandbox_class"]').setValue('kata-clh')
    await wrapper.find('input[name="read_only_root"]').setValue(false)
    await wrapper.find('[data-add-row="metadata"]').trigger('click')
    const metaInputs = wrapper.findAll('[data-row="metadata"] input')
    await metaInputs[0].setValue('agent')
    await metaInputs[1].setValue('coder')

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const payload = sandboxesApi.create.mock.calls[0][0]
    expect(payload.image).toBe('docker.io/library/python:3.13-slim')
    expect(payload.runtime).toBeUndefined()
    expect(payload.version).toBeUndefined()
    expect(payload.sandbox_class).toBe('kata-clh')
    expect(payload.read_only_root).toBe(false)
    expect(payload.metadata).toEqual({ agent: 'coder' })
    expect(payload.network).toBe('none')
    expect(useToast().toasts.value[0]?.message).toContain('is running')
  })

  it('shows a quota refusal inline and keeps the dialog open', async () => {
    sandboxesApi.create.mockRejectedValue(apiReject(422, {
      message: 'The organization already runs 20 sandboxes, its limit.',
      reason:  'quota_concurrent',
    }))
    const wrapper = await mountDialog()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-create-error]').text()).toContain('The organization already runs 20 sandboxes, its limit.')
    expect(wrapper.emitted('created')).toBeUndefined()
  })

  it('lists every validation problem', async () => {
    sandboxesApi.create.mockRejectedValue(apiReject(422, {
      message: 'The given data was invalid.',
      errors:  { cpu: ['The CPU must be at most 4000m.'], 'network.egress_allow.0': ['Not a CIDR, IP address or host name.'] },
    }))
    const wrapper = await mountDialog()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    const text = wrapper.find('[data-create-error]').text()
    expect(text).toContain('cpu: The CPU must be at most 4000m.')
    expect(text).toContain('network.egress_allow.0: Not a CIDR, IP address or host name.')
  })
})

describe('create payload helpers', () => {
  it('drops comments, blanks and duplicates from the allowlist', () => {
    expect(parseAllowlist('pypi.org # index\n\n 10.0.0.0/8 ,pypi.org')).toEqual(['pypi.org', '10.0.0.0/8'])
  })

  it('omits empty optional fields', () => {
    expect(buildCreatePayload({
      region: '', source: 'runtime', runtime: 'python', version: '', cpu: '', memory: '', disk: '',
      ttlMinutes: '', idleMinutes: '', sandboxClass: 'auto', networkMode: 'none', allowlist: '',
      env: [{ key: ' ', value: 'x' }], metadata: [], readOnlyRoot: true,
    })).toEqual({ runtime: 'python', network: 'none', read_only_root: true, wait: false })
  })
})
