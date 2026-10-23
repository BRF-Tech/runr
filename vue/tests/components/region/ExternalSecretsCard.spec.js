import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ExternalSecretsCard from '@/components/region/ExternalSecretsCard.vue'
import { externalSecretsApi } from '@/api/secretStores'
import { withPlugins } from '../../helpers'

// The region's Secrets tab (docs/secret-stores.md): External Secrets Operator's status, install,
// upgrade to this release's chart, removal (not while references exist).

vi.mock('@/api/secretStores', () => ({
  secretStoresApi: {},
  externalSecretsApi: { status: vi.fn(), install: vi.fn(), uninstall: vi.fn() },
}))

const confirmMock = vi.fn()
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: confirmMock }) }))

const REGION = { id: 'r1', name: 'eu-1', is_demo: false }

function status(extra = {}) {
  return { data: { data: {
    status: 'not_installed', message: null, chart_version: null, release_chart_version: '0.19.2',
    installed_at: null, references: 0, can_manage: true, ...extra,
  } } }
}

async function factory(region = REGION) {
  const w = mount(ExternalSecretsCard, { props: { region }, ...withPlugins() })
  await flushPromises()
  return w
}

beforeEach(() => {
  vi.clearAllMocks()
  confirmMock.mockResolvedValue(true)
  externalSecretsApi.status.mockResolvedValue(status())
  externalSecretsApi.install.mockResolvedValue({ status: 202, data: { message: 'queued', data: status({ status: 'installing' }).data.data } })
  externalSecretsApi.uninstall.mockResolvedValue({ status: 202, data: { message: 'queued', data: status({ status: 'uninstalling' }).data.data } })
})

describe('ExternalSecretsCard', () => {
  it('installs the operator', async () => {
    const w = await factory()
    expect(w.find('[data-testid="eso-status"]').text()).toBe('Not installed')

    await w.find('[data-testid="eso-install"]').trigger('click')
    await flushPromises()
    expect(externalSecretsApi.install).toHaveBeenCalledWith('r1')
    expect(w.find('[data-testid="eso-status"]').text()).toBe('Installing')
    // no buttons while it runs
    expect(w.find('[data-testid="eso-install"]').exists()).toBe(false)
  })

  it('offers the upgrade when the region runs an older chart', async () => {
    externalSecretsApi.status.mockResolvedValue(status({ status: 'ready', chart_version: '0.18.0' }))
    const w = await factory()
    expect(w.find('[data-testid="eso-chart-version"]').text()).toContain('this release brings 0.19.2')
    expect(w.find('[data-testid="eso-install"]').text()).toBe('Upgrade')
  })

  it('does not remove the operator while references exist', async () => {
    externalSecretsApi.status.mockResolvedValue(status({ status: 'ready', chart_version: '0.19.2', references: 3 }))
    const w = await factory()
    expect(w.find('[data-testid="eso-references"]').text()).toBe('3')
    expect(w.find('[data-testid="eso-uninstall"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-testid="eso-install"]').exists()).toBe(false)
  })

  it('removes the operator after asking', async () => {
    externalSecretsApi.status.mockResolvedValue(status({ status: 'ready', chart_version: '0.19.2' }))
    const w = await factory()
    await w.find('[data-testid="eso-uninstall"]').trigger('click')
    await flushPromises()
    expect(confirmMock).toHaveBeenCalled()
    expect(externalSecretsApi.uninstall).toHaveBeenCalledWith('r1')
    expect(w.find('[data-testid="eso-status"]').text()).toBe('Removing')
  })

  it('is read-only for users who may not manage regions', async () => {
    externalSecretsApi.status.mockResolvedValue(status({ can_manage: false }))
    const w = await factory()
    expect(w.find('[data-testid="eso-install"]').exists()).toBe(false)
    expect(w.text()).toContain('Manage regions permission')
  })

  it('reports its status to the cards that depend on it, also when a poll sees it ready', async () => {
    vi.useFakeTimers()
    try {
      const w = await factory()
      expect(w.emitted('status')?.at(-1)).toEqual(['not_installed'])

      externalSecretsApi.status.mockResolvedValue(status({ status: 'ready', chart_version: '0.19.2' }))
      await w.find('[data-testid="eso-install"]').trigger('click')
      await flushPromises()
      expect(w.emitted('status')?.at(-1)).toEqual(['installing'])

      await vi.advanceTimersByTimeAsync(3000)
      await flushPromises()
      expect(w.find('[data-testid="eso-status"]').text()).toBe('Ready')
      expect(w.emitted('status')?.at(-1)).toEqual(['ready'])
      w.unmount()
    } finally {
      vi.useRealTimers()
    }
  })
})
