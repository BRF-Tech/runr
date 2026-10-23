import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ProjectNetwork from '@/components/project/ProjectNetwork.vue'
import { projectsApi } from '@/api/projects'
import { usePermissionsStore } from '@/stores/permissions'
import { makeI18n, freshPinia, apiError } from '@/test-support/helpers'

vi.mock('@/api/projects', () => ({
  projectsApi: { network: vi.fn(), updateNetwork: vi.fn(), addNetworkCaller: vi.fn(), removeNetworkCaller: vi.fn() },
}))
const confirmMock = vi.fn(() => Promise.resolve(true))
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: confirmMock }) }))

const i18n = makeI18n()
let pinia
const mounted = []

function network(extra = {}) {
  return {
    access_mode: 'public', allowed_cidrs: [], internal_name: 'orders.acme', internal_host: 'orders.acme.runr.internal',
    internal_url: 'http://orders.acme.runr.internal', organization: 'acme', token_header: 'X-Runr-Internal-Token',
    token_env: 'RUNR_INTERNAL_TOKEN', cross_tenant_grants: true, enforced: true, callers: [],
    ...extra,
  }
}

async function mountNetwork(data = network()) {
  projectsApi.network.mockResolvedValue({ data: { data } })
  const wrapper = mount(ProjectNetwork, { props: { projectId: 'p1' }, global: { plugins: [pinia, i18n] } })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
})
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('Project → Network', () => {
  it('shows the internal URL and how other projects call it', async () => {
    const w = await mountNetwork()

    expect(projectsApi.network).toHaveBeenCalledWith('p1')
    expect(w.find('[data-testid="network-internal-url"]').text()).toBe('http://orders.acme.runr.internal')
    expect(w.find('[data-testid="network-curl"]').text())
      .toBe('curl -H "X-Runr-Internal-Token: $RUNR_INTERNAL_TOKEN" http://orders.acme.runr.internal/')
    expect(w.find('[data-testid="network-mode-public"]').element.checked).toBe(true)
    expect(w.find('[data-testid="network-callers-empty"]').exists()).toBe(true)
    expect(w.find('[data-testid="network-audit-note"]').exists()).toBe(false)
  })

  it('says so when an administrator set the region to audit', async () => {
    const w = await mountNetwork(network({ enforced: false }))
    expect(w.find('[data-testid="network-audit-note"]').text()).toContain('to audit the internal network')
  })

  it('sets access mode allowlist with its ranges', async () => {
    const w = await mountNetwork()
    projectsApi.updateNetwork.mockResolvedValue({ data: { message: 'Network settings saved.', data: network({ access_mode: 'allowlist', allowed_cidrs: ['203.0.113.0/24', '198.51.100.7'] }) } })

    expect(w.find('[data-testid="network-save"]').attributes('disabled')).toBeDefined()
    await w.find('[data-testid="network-mode-allowlist"]').setValue(true)
    await w.find('[data-testid="network-cidrs"]').setValue('203.0.113.0/24\n198.51.100.7, ')
    await w.find('[data-testid="network-save"]').trigger('click')
    await flushPromises()

    expect(projectsApi.updateNetwork).toHaveBeenCalledWith('p1', { access_mode: 'allowlist', allowed_cidrs: ['203.0.113.0/24', '198.51.100.7'] })
    expect(confirmMock).not.toHaveBeenCalled()
    expect(w.find('[data-testid="network-save"]').attributes('disabled')).toBeDefined()
  })

  it('asks before taking the public URL away', async () => {
    const w = await mountNetwork()
    confirmMock.mockResolvedValueOnce(false)

    await w.find('[data-testid="network-mode-internal"]').setValue(true)
    await w.find('[data-testid="network-save"]').trigger('click')
    await flushPromises()

    expect(confirmMock).toHaveBeenCalledTimes(1)
    expect(projectsApi.updateNetwork).not.toHaveBeenCalled()
  })

  it('shows the API\'s validation message', async () => {
    const w = await mountNetwork()
    projectsApi.updateNetwork.mockRejectedValue(apiError(422, { errors: { access_mode: ['A workflow project stays public.'] } }))

    await w.find('[data-testid="network-mode-internal"]').setValue(true)
    await w.find('[data-testid="network-save"]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid="network-save-error"]').text()).toBe('A workflow project stays public.')
  })

  it('adds and removes callers, marking a grant to another organization', async () => {
    const w = await mountNetwork(network({ callers: [{ caller: 'shop.acme', organization: 'acme', cross_tenant: false }] }))
    projectsApi.addNetworkCaller.mockResolvedValue({ data: { data: { caller: 'billing.globex', organization: 'globex', cross_tenant: true } } })
    projectsApi.removeNetworkCaller.mockResolvedValue({ data: null })

    await w.find('[data-testid="network-caller-input"]').setValue('billing.globex')
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(projectsApi.addNetworkCaller).toHaveBeenCalledWith('p1', 'billing.globex')
    expect(w.findAll('[data-caller]').map(li => li.attributes('data-caller'))).toEqual(['billing.globex', 'shop.acme'])
    expect(w.find('[data-caller="billing.globex"]').text()).toContain('grant to globex')

    await w.find('[data-caller="shop.acme"] [data-testid="network-caller-remove"]').trigger('click')
    await flushPromises()
    expect(projectsApi.removeNetworkCaller).toHaveBeenCalledWith('p1', 'shop.acme')
    expect(w.findAll('[data-caller]').map(li => li.attributes('data-caller'))).toEqual(['billing.globex'])
  })

  it('shows why a caller was refused', async () => {
    const w = await mountNetwork(network({ cross_tenant_grants: false }))
    projectsApi.addNetworkCaller.mockRejectedValue(apiError(422, { errors: { caller: ['The platform does not allow grants to projects of other organizations.'] } }))

    expect(w.find('[data-testid="network-cross-off"]').exists()).toBe(true)
    await w.find('[data-testid="network-caller-input"]').setValue('billing.globex')
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(w.find('[data-testid="network-caller-error"]').text()).toContain('does not allow grants')
  })

  it('is read-only without network.manage', async () => {
    usePermissionsStore().apply({ permissions: ['projects.view'], projects: {} })
    const w = await mountNetwork(network({ callers: [{ caller: 'shop.acme', organization: 'acme', cross_tenant: false }] }))

    expect(w.find('[data-testid="network-save"]').exists()).toBe(false)
    expect(w.find('[data-testid="network-caller-input"]').exists()).toBe(false)
    expect(w.find('[data-testid="network-caller-remove"]').exists()).toBe(false)
    expect(w.find('[data-testid="network-mode-internal"]').attributes('disabled')).toBeDefined()
  })
})
