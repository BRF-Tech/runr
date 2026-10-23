import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import GlobalDnsSection from '@/components/region/GlobalDnsSection.vue'
import { globalDnsApi } from '@/api/globalProjects'
import { usePermissionsStore } from '@/stores/permissions'
import { makeI18n, freshPinia, apiError } from '@/test-support/helpers'

vi.mock('@/api/globalProjects', () => ({
  globalProjectsApi: {},
  globalDnsApi: { region: vi.fn(), enable: vi.fn(), disable: vi.fn(), setGeoip: vi.fn(), reloadGeoip: vi.fn() },
}))
const confirmMock = vi.fn(() => Promise.resolve(true))
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: confirmMock }) }))

const i18n = makeI18n()
const region = { id: 'r-eu', name: 'eu-1', has_serving: true }
const mounted = []
let pinia

function server(extra = {}) {
  return {
    region_id: 'r-eu', region_name: 'eu-1', status: 'disabled', status_message: null, address: null, ns_name: null,
    reachable: null, sync_status: null, sync_message: null, synced_at: null, tls_status: null, tls_message: null,
    tls_expires_at: null, eligible: true, reason: null, ...extra,
  }
}

async function mountSection(payload = server()) {
  globalDnsApi.region.mockResolvedValue({ data: payload })
  const w = mount(GlobalDnsSection, { props: { region }, global: { plugins: [pinia, i18n] } })
  mounted.push(w)
  await flushPromises()
  return w
}

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
})
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('Region → Global DNS', () => {
  it('offers to enable an eligible region', async () => {
    const w = await mountSection()
    expect(globalDnsApi.region).toHaveBeenCalledWith('r-eu')
    expect(w.find('[data-testid="global-dns-status"]').text()).toBe('Off')

    globalDnsApi.enable.mockResolvedValue({ data: server({ status: 'installing', status_message: 'Waiting for the LoadBalancer address' }) })
    await w.find('[data-testid="global-dns-enable"]').trigger('click')
    await flushPromises()
    expect(globalDnsApi.enable).toHaveBeenCalledWith('r-eu')
    expect(w.find('[data-testid="global-dns-status"]').text()).toBe('Installing')
    expect(w.text()).toContain('Waiting for the LoadBalancer address')
  })

  it('says why a region cannot run a server', async () => {
    const w = await mountSection(server({ eligible: false, reason: 'The region belongs to an organization.' }))
    expect(w.find('[data-testid="global-dns-reason"]').text()).toBe('The region belongs to an organization.')
    expect(w.find('[data-testid="global-dns-enable"]').attributes('disabled')).toBeDefined()
  })

  it('shows a ready server: address, name server, answers, zone and certificate', async () => {
    const w = await mountSection(server({
      status: 'ready', address: '203.0.113.53', ns_name: 'ns-eu-1.g.runr.test', reachable: true,
      sync_status: 'in_sync', synced_at: '2026-10-21T10:00:00Z', tls_status: 'ready', tls_expires_at: '2027-01-01T00:00:00Z',
    }))
    expect(w.find('[data-testid="global-dns-address"]').text()).toBe('203.0.113.53')
    expect(w.text()).toContain('ns-eu-1.g.runr.test')
    expect(w.text()).toContain('Answers')
    expect(w.text()).toContain('In sync')
    expect(w.text()).toContain('valid until')
    expect(w.find('[data-testid="global-dns-enable"]').exists()).toBe(false)
  })

  it('disables after confirmation and shows a refusal', async () => {
    const w = await mountSection(server({ status: 'ready', address: '203.0.113.53' }))
    globalDnsApi.disable.mockRejectedValue(apiError(422, { message: 'Global projects still use eu-1: shop.' }))
    await w.find('[data-testid="global-dns-disable"]').trigger('click')
    await flushPromises()
    expect(confirmMock).toHaveBeenCalled()
    expect(globalDnsApi.disable).toHaveBeenCalledWith('r-eu')
    expect(w.find('[data-testid="global-dns-error"]').text()).toBe('Global projects still use eu-1: shop.')
  })

  it('offers an install again after an error', async () => {
    const w = await mountSection(server({ status: 'error', status_message: 'no external address after 300 s' }))
    expect(w.find('[data-testid="global-dns-enable"]').text()).toBe('Install again')
    expect(w.text()).toContain('no external address after 300 s')
  })

  it('has no buttons without regions.manage', async () => {
    usePermissionsStore().apply({ permissions: ['regions.view'], projects: {} })
    const w = await mountSection()
    expect(w.find('[data-testid="global-dns-enable"]').exists()).toBe(false)
  })
})

describe('Region → Global DNS: GeoIP database', () => {
  const geoip = (extra = {}) => ({
    source: 'default', url: null, effective_url: 'https://download.db-ip.com/free/dbip-city-lite-{yyyy}-{mm}.mmdb.gz',
    status: 'loaded', message: 'GeoIP database loaded from https://download.db-ip.com/free/dbip-city-lite-2026-10.mmdb.gz (130.2 MiB, 2026-10-01T00:00:00Z).',
    checked_at: '2026-10-22T10:00:00Z', ...extra,
  })
  const ready = (g = geoip()) => server({ status: 'ready', address: '203.0.113.53', geoip: g })

  it('shows where the database comes from and whether the server has it', async () => {
    const w = await mountSection(ready())
    expect(w.find('[data-testid="global-dns-geoip-status"]').text()).toBe('Loaded')
    expect(w.find('[data-testid="global-dns-geoip-message"]').text()).toContain('130.2 MiB')
    expect(w.find('[data-geoip-source="default"]').attributes('aria-pressed')).toBe('true')
    // the hint keeps the placeholders the server fills in
    expect(w.text()).toContain('downloaded monthly')
  })

  it('shows a missing database in red with its reason', async () => {
    const w = await mountSection(ready(geoip({ status: 'missing', message: 'No GeoIP database: the download failed (timed out).' })))
    expect(w.find('[data-testid="global-dns-geoip-status"]').text()).toBe('Missing')
    expect(w.find('[data-testid="global-dns-geoip-message"]').classes()).toContain('text-red-400')
  })

  it('sets an operator URL (a mirror, the region object store)', async () => {
    const w = await mountSection(ready())
    await w.find('[data-geoip-source="url"]').trigger('click')
    const input = w.find('[data-testid="global-dns-geoip-url"]')
    expect(input.attributes('placeholder')).toContain('{yyyy}-{mm}')
    // not a URL yet: nothing to save
    await input.setValue('mirror.internal/geoip.mmdb.gz')
    expect(w.find('[data-testid="global-dns-geoip-save"]').attributes('disabled')).toBeDefined()

    await input.setValue('http://runr-objects.runr-data.svc.cluster.local:3900/geoip/city.mmdb.gz')
    globalDnsApi.setGeoip.mockResolvedValue({ data: ready(geoip({ source: 'url', url: 'http://runr-objects.runr-data.svc.cluster.local:3900/geoip/city.mmdb.gz', status: 'unknown', message: null })) })
    await w.find('[data-testid="global-dns-geoip-save"]').trigger('click')
    await flushPromises()
    expect(globalDnsApi.setGeoip).toHaveBeenCalledWith('r-eu', { source: 'url', url: 'http://runr-objects.runr-data.svc.cluster.local:3900/geoip/city.mmdb.gz' })
    expect(w.find('[data-testid="global-dns-geoip-status"]').text()).toBe('Not checked')
  })

  it('shows the copy command of an operator file and reloads the server', async () => {
    const w = await mountSection(ready(geoip({ source: 'file', effective_url: null, status: 'missing', message: 'No GeoIP file on the server’s volume.' })))
    expect(w.find('[data-testid="global-dns-geoip-file"]').text()).toContain('kubectl -n runr-global-dns cp geoip.mmdb')
    // nothing changed: save stays off
    expect(w.find('[data-testid="global-dns-geoip-save"]').attributes('disabled')).toBeDefined()

    globalDnsApi.reloadGeoip.mockResolvedValue({ data: ready(geoip({ source: 'file', status: 'unknown', message: 'The server reads its GeoIP source again.' })) })
    await w.find('[data-testid="global-dns-geoip-reload"]').trigger('click')
    await flushPromises()
    expect(globalDnsApi.reloadGeoip).toHaveBeenCalledWith('r-eu')
    expect(w.text()).toContain('reads its GeoIP source again')
  })

  it('turns GeoIP off', async () => {
    const w = await mountSection(ready())
    await w.find('[data-geoip-source="none"]').trigger('click')
    globalDnsApi.setGeoip.mockResolvedValue({ data: ready(geoip({ source: 'none', effective_url: null, status: 'off', message: 'No GeoIP database (source none).' })) })
    await w.find('[data-testid="global-dns-geoip-save"]').trigger('click')
    await flushPromises()
    expect(globalDnsApi.setGeoip).toHaveBeenCalledWith('r-eu', { source: 'none' })
    expect(w.find('[data-testid="global-dns-geoip-status"]').text()).toBe('Off')
  })

  it('shows the API error of a refused source', async () => {
    const w = await mountSection(ready())
    await w.find('[data-geoip-source="file"]').trigger('click')
    globalDnsApi.setGeoip.mockRejectedValue(apiError(422, { message: 'The region does not run a global DNS server.' }))
    await w.find('[data-testid="global-dns-geoip-save"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-testid="global-dns-error"]').text()).toBe('The region does not run a global DNS server.')
  })

  it('has no GeoIP buttons without regions.manage', async () => {
    usePermissionsStore().apply({ permissions: ['regions.view'], projects: {} })
    const w = await mountSection(ready())
    expect(w.find('[data-testid="global-dns-geoip-status"]').exists()).toBe(true)
    expect(w.find('[data-testid="global-dns-geoip-save"]').exists()).toBe(false)
    expect(w.find('[data-geoip-source="url"]').attributes('disabled')).toBeDefined()
  })
})
