import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import OpenBaoCard from '@/components/region/OpenBaoCard.vue'
import { openBaoApi } from '@/api/secretStores'
import { withPlugins, deferred } from '../../helpers'

// The region's Secrets tab (docs/secret-stores.md, "OpenBao in the region"): the region's own
// OpenBao — install (only with External Secrets Operator ready), where new values go, upgrade to
// this release's version, removal (not while values live in it).

vi.mock('@/api/secretStores', () => ({
  secretStoresApi: {},
  externalSecretsApi: {},
  openBaoApi: { status: vi.fn(), install: vi.fn(), update: vi.fn(), uninstall: vi.fn() },
}))

const confirmMock = vi.fn()
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: confirmMock }) }))

const REGION = { id: 'r1', name: 'eu-1', is_demo: false }

function status(extra = {}) {
  return { data: { data: {
    status: 'not_installed', message: null, version: null, release_version: '2.4.1', installed_at: null,
    default_for_values: true, values: 0, eso_status: 'ready', can_manage: true, ...extra,
  } } }
}

async function factory(region = REGION, extra = {}) {
  const w = mount(OpenBaoCard, { props: { region, ...extra }, ...withPlugins() })
  await flushPromises()
  return w
}

beforeEach(() => {
  vi.clearAllMocks()
  confirmMock.mockResolvedValue(true)
  openBaoApi.status.mockResolvedValue(status())
  openBaoApi.install.mockResolvedValue({ status: 202, data: { message: 'queued', data: status({ status: 'installing' }).data.data } })
  openBaoApi.uninstall.mockResolvedValue({ status: 202, data: { message: 'queued', data: status({ status: 'uninstalling' }).data.data } })
  openBaoApi.update.mockImplementation((id, payload) => Promise.resolve({ data: { data: status({ status: 'ready', version: '2.4.1', ...payload }).data.data } }))
})

describe('OpenBaoCard', () => {
  it('installs OpenBao when External Secrets Operator runs', async () => {
    const w = await factory()
    expect(w.find('[data-testid="openbao-status"]').text()).toBe('Not installed')
    expect(w.text()).toContain('RunR keeps no OpenBao token')

    await w.find('[data-testid="openbao-install"]').trigger('click')
    await flushPromises()
    expect(openBaoApi.install).toHaveBeenCalledWith('r1')
    expect(w.find('[data-testid="openbao-status"]').text()).toBe('Installing')
    expect(w.find('[data-testid="openbao-install"]').exists()).toBe(false)
  })

  it('waits for External Secrets Operator before it can be installed', async () => {
    openBaoApi.status.mockResolvedValue(status({ eso_status: 'not_installed' }))
    const w = await factory()

    expect(w.find('[data-testid="openbao-install"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-testid="openbao-needs-eso"]').exists()).toBe(true)
  })

  it('shows the server\'s refusal of an install', async () => {
    openBaoApi.install.mockRejectedValue({ response: { status: 422, data: { message: 'OpenBao in region "eu-1" needs External Secrets Operator there.' } } })
    const w = await factory()
    await w.find('[data-testid="openbao-install"]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid="openbao-error"]').text()).toContain('needs External Secrets Operator')
  })

  it('switches where new secret values go', async () => {
    openBaoApi.status.mockResolvedValue(status({ status: 'ready', version: '2.4.1', values: 2 }))
    const w = await factory()
    const toggle = w.find('[data-testid="openbao-default"]')
    expect(toggle.element.checked).toBe(true)
    expect(w.find('[data-testid="openbao-values"]').text()).toBe('2')

    await toggle.setValue(false)
    await flushPromises()
    expect(openBaoApi.update).toHaveBeenCalledWith('r1', { default_for_values: false })
  })

  it('offers the upgrade when the region runs an older version', async () => {
    openBaoApi.status.mockResolvedValue(status({ status: 'ready', version: '2.3.0' }))
    const w = await factory()
    expect(w.find('[data-testid="openbao-version"]').text()).toContain('this release brings 2.4.1')
    expect(w.find('[data-testid="openbao-install"]').text()).toBe('Upgrade')
  })

  it('does not remove it while values live in it, and removes it after asking otherwise', async () => {
    openBaoApi.status.mockResolvedValue(status({ status: 'ready', version: '2.4.1', values: 1 }))
    let w = await factory()
    expect(w.find('[data-testid="openbao-uninstall"]').attributes('disabled')).toBeDefined()

    openBaoApi.status.mockResolvedValue(status({ status: 'ready', version: '2.4.1', values: 0 }))
    w = await factory()
    await w.find('[data-testid="openbao-uninstall"]').trigger('click')
    await flushPromises()
    expect(confirmMock).toHaveBeenCalled()
    expect(openBaoApi.uninstall).toHaveBeenCalledWith('r1')
    expect(w.find('[data-testid="openbao-status"]').text()).toBe('Removing')
  })

  it('is read-only without regions.manage and says why on a demo region', async () => {
    openBaoApi.status.mockResolvedValue(status({ status: 'ready', version: '2.4.1', can_manage: false }))
    let w = await factory()
    expect(w.find('[data-testid="openbao-install"]').exists()).toBe(false)
    expect(w.find('[data-testid="openbao-default"]').attributes('disabled')).toBeDefined()
    expect(w.text()).toContain('Manage regions')

    w = await factory({ ...REGION, is_demo: true })
    expect(w.text()).toContain('demo region')
  })

  it('unlocks the install when External Secrets Operator becomes ready on the same tab', async () => {
    openBaoApi.status.mockResolvedValue(status({ eso_status: 'installing' }))
    const w = await factory(REGION, { esoStatus: 'installing' })
    expect(w.find('[data-testid="openbao-install"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-testid="openbao-needs-eso"]').exists()).toBe(true)

    // The operator card's poll saw it ready: no reload of the tab
    openBaoApi.status.mockResolvedValue(status({ eso_status: 'ready' }))
    await w.setProps({ esoStatus: 'ready' })
    expect(w.find('[data-testid="openbao-install"]').attributes('disabled')).toBeUndefined()
    expect(w.find('[data-testid="openbao-needs-eso"]').exists()).toBe(false)
    await flushPromises()
    expect(openBaoApi.status).toHaveBeenCalledTimes(2)

    await w.find('[data-testid="openbao-install"]').trigger('click')
    await flushPromises()
    expect(openBaoApi.install).toHaveBeenCalledWith('r1')
  })

  it('keeps the operator\'s new status over a read that started before it', async () => {
    const first = deferred()
    openBaoApi.status.mockReturnValueOnce(first.promise)
    const w = mount(OpenBaoCard, { props: { region: REGION, esoStatus: 'installing' }, ...withPlugins() })
    await flushPromises()

    // The operator is ready before the card's first read answers — with what it read earlier
    await w.setProps({ esoStatus: 'ready' })
    first.resolve(status({ eso_status: 'installing' }))
    await flushPromises()

    expect(w.find('[data-testid="openbao-install"]').attributes('disabled')).toBeUndefined()
    expect(openBaoApi.status).toHaveBeenCalledTimes(1)
  })

  it('shows the newest read when two answer out of order', async () => {
    const w = await factory()
    const older = deferred()
    const newer = deferred()
    openBaoApi.status.mockReturnValueOnce(older.promise).mockReturnValueOnce(newer.promise)
    w.vm.load(true)
    w.vm.load(true)

    newer.resolve(status({ status: 'ready', version: '2.4.1', values: 3 }))
    await flushPromises()
    older.resolve(status({ status: 'not_installed', values: 0 }))
    await flushPromises()

    expect(w.find('[data-testid="openbao-status"]').text()).toBe('Ready')
    expect(w.find('[data-testid="openbao-values"]').text()).toBe('3')
  })

  it('does not put an install back to "not installed" when a read in flight answers after it', async () => {
    const w = await factory()
    const poll = deferred()
    openBaoApi.status.mockReturnValueOnce(poll.promise)
    w.vm.load(true)

    await w.find('[data-testid="openbao-install"]').trigger('click')
    await flushPromises()
    poll.resolve(status({ status: 'not_installed' }))
    await flushPromises()

    expect(w.find('[data-testid="openbao-status"]').text()).toBe('Installing')
    expect(w.find('[data-testid="openbao-install"]').exists()).toBe(false)
  })
})
