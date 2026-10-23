import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import RegionSecretsTab from '@/components/region/RegionSecretsTab.vue'
import { externalSecretsApi, openBaoApi } from '@/api/secretStores'
import { withPlugins } from '../../helpers'

// The region's Secrets tab (docs/secret-stores.md): OpenBao needs External Secrets Operator ready.
// Installing the operator on the tab unlocks OpenBao's install as soon as the operator card sees it
// ready — no reload of the tab.

vi.mock('@/api/secretStores', () => ({
  secretStoresApi: {},
  externalSecretsApi: { status: vi.fn(), install: vi.fn(), uninstall: vi.fn() },
  openBaoApi: { status: vi.fn(), install: vi.fn(), update: vi.fn(), uninstall: vi.fn() },
}))
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: vi.fn(async () => true) }) }))

const REGION = { id: 'r1', name: 'eu-1', is_demo: false }

const eso = (extra = {}) => ({ data: { data: {
  status: 'not_installed', message: null, chart_version: null, release_chart_version: '0.19.2',
  installed_at: null, references: 0, can_manage: true, ...extra,
} } })
const bao = (extra = {}) => ({ data: { data: {
  status: 'not_installed', message: null, version: null, release_version: '2.4.1', installed_at: null,
  default_for_values: true, values: 0, eso_status: 'not_installed', can_manage: true, ...extra,
} } })

let w
beforeEach(() => {
  vi.clearAllMocks()
  vi.useFakeTimers()
  externalSecretsApi.status.mockResolvedValue(eso())
  externalSecretsApi.install.mockResolvedValue({ status: 202, data: { data: eso({ status: 'installing' }).data.data } })
  openBaoApi.status.mockResolvedValue(bao())
  openBaoApi.install.mockResolvedValue({ status: 202, data: { data: bao({ status: 'installing', eso_status: 'ready' }).data.data } })
})
afterEach(() => {
  w?.unmount()
  w = null
  vi.useRealTimers()
})

describe('RegionSecretsTab', () => {
  it('unlocks OpenBao once the operator installed on the tab is ready', async () => {
    w = mount(RegionSecretsTab, { props: { region: REGION }, ...withPlugins() })
    await flushPromises()
    const install = () => w.find('[data-testid="openbao-install"]')
    expect(install().attributes('disabled')).toBeDefined()

    await w.find('[data-testid="eso-install"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-testid="eso-status"]').text()).toBe('Installing')
    expect(install().attributes('disabled')).toBeDefined()

    // The operator card polls every 3 s; the server now reports it ready to both cards
    externalSecretsApi.status.mockResolvedValue(eso({ status: 'ready', chart_version: '0.19.2' }))
    openBaoApi.status.mockResolvedValue(bao({ eso_status: 'ready' }))
    await vi.advanceTimersByTimeAsync(3000)
    await flushPromises()

    expect(w.find('[data-testid="eso-status"]').text()).toBe('Ready')
    expect(install().attributes('disabled')).toBeUndefined()
    expect(w.find('[data-testid="openbao-needs-eso"]').exists()).toBe(false)

    await install().trigger('click')
    await flushPromises()
    expect(openBaoApi.install).toHaveBeenCalledWith('r1')
    expect(w.find('[data-testid="openbao-status"]').text()).toBe('Installing')
  })

  it('locks OpenBao again when the operator is removed on the tab', async () => {
    externalSecretsApi.status.mockResolvedValue(eso({ status: 'uninstalling' }))
    openBaoApi.status.mockResolvedValue(bao({ eso_status: 'uninstalling' }))
    w = mount(RegionSecretsTab, { props: { region: REGION }, ...withPlugins() })
    await flushPromises()

    externalSecretsApi.status.mockResolvedValue(eso({ status: 'not_installed' }))
    openBaoApi.status.mockResolvedValue(bao({ eso_status: 'not_installed' }))
    await vi.advanceTimersByTimeAsync(3000)
    await flushPromises()

    expect(w.find('[data-testid="eso-status"]').text()).toBe('Not installed')
    expect(w.find('[data-testid="openbao-needs-eso"]').exists()).toBe(true)
    expect(w.find('[data-testid="openbao-install"]').attributes('disabled')).toBeDefined()
  })
})
