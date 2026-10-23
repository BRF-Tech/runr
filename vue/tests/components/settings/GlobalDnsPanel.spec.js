import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import GlobalDnsPanel from '@/components/settings/GlobalDnsPanel.vue'
import { globalDnsApi } from '@/api/globalProjects'
import { usePermissionsStore } from '@/stores/permissions'
import { makeI18n, freshPinia } from '@/test-support/helpers'

vi.mock('@/api/globalProjects', () => ({
  globalProjectsApi: {},
  globalDnsApi: { overview: vi.fn(), sync: vi.fn() },
}))

const i18n = makeI18n()
const mounted = []
let pinia

function overview(extra = {}) {
  return {
    zone: 'g.runr.test',
    base_domain: 'runr.test',
    tls: 'acme',
    update_region_id: 'r-eu',
    delegation: {
      records: [
        { name: 'g.runr.test', type: 'NS', value: 'ns-eu-1.g.runr.test' },
        { name: 'g.runr.test', type: 'NS', value: 'ns-us-1.g.runr.test' },
        { name: 'ns-eu-1.g.runr.test', type: 'A', value: '203.0.113.53' },
        { name: 'ns-us-1.g.runr.test', type: 'A', value: '198.51.100.53' },
      ],
      status: 'partial',
      message: 'ns-us-1.g.runr.test is not in the delegation yet.',
    },
    servers: [
      { region_id: 'r-eu', region_name: 'eu-1', status: 'ready', address: '203.0.113.53', reachable: true, sync_status: 'in_sync', tls_status: 'ready',
        geoip: { source: 'file', status: 'missing', message: 'No GeoIP file on the server’s volume.' } },
      { region_id: 'r-us', region_name: 'us-1', status: 'ready', address: '198.51.100.53', reachable: false, sync_status: 'drift', sync_message: '1 record differs', tls_status: 'pending' },
    ],
    last_sync_at: '2026-10-21T10:00:00Z',
    ...extra,
  }
}

async function mountPanel(payload = overview()) {
  globalDnsApi.overview.mockResolvedValue({ data: payload })
  const w = mount(GlobalDnsPanel, { global: { plugins: [pinia, i18n] } })
  mounted.push(w)
  await flushPromises()
  return w
}

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
  Object.assign(navigator, { clipboard: { writeText: vi.fn(() => Promise.resolve()) } })
})
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('Administration → Regions & settings: global DNS', () => {
  it('shows the zone, the delegation records and the servers', async () => {
    const w = await mountPanel()
    expect(w.find('[data-testid="global-dns-zone"]').text()).toBe('g.runr.test')
    expect(w.find('[data-testid="global-dns-delegation"]').text()).toBe('Partly delegated')
    expect(w.text()).toContain('ns-us-1.g.runr.test is not in the delegation yet.')
    expect(w.findAll('[data-testid="global-dns-record"]')).toHaveLength(4)
    expect(w.find('[data-server="eu-1"]').text()).toContain('updates')
    expect(w.find('[data-server="us-1"]').text()).toContain('No answer')
    expect(w.find('[data-server="us-1"]').text()).toContain('Drift')
    expect(w.find('[data-server="us-1"]').text()).toContain('1 record differs')
  })

  it('copies a record value', async () => {
    const w = await mountPanel()
    const record = w.findAll('[data-testid="global-dns-record"]')[2]
    await record.findAll('button').at(-1).trigger('click')
    expect(navigator.clipboard.writeText).toHaveBeenCalledWith('203.0.113.53')
  })

  it('asks for a base domain before there is a zone', async () => {
    const w = await mountPanel(overview({ zone: null, delegation: { records: [], status: 'unknown', message: null }, servers: [] }))
    expect(w.find('[data-testid="global-dns-no-zone"]').exists()).toBe(true)
    expect(w.find('[data-testid="global-dns-sync"]').exists()).toBe(false)
    expect(w.text()).toContain('No region runs a global DNS server.')
  })

  it('starts a zone sync', async () => {
    globalDnsApi.sync.mockResolvedValue({ data: { queued: true } })
    const w = await mountPanel()
    await w.find('[data-testid="global-dns-sync"]').trigger('click')
    await flushPromises()
    expect(globalDnsApi.sync).toHaveBeenCalled()
  })

  it('has no sync without regions.manage', async () => {
    usePermissionsStore().apply({ permissions: ['regions.view'], projects: {} })
    const w = await mountPanel()
    expect(w.find('[data-testid="global-dns-sync"]').exists()).toBe(false)
  })

  it('shows the GeoIP database of each server', async () => {
    const w = await mountPanel()
    const eu = w.find('[data-server="eu-1"] [data-testid="global-dns-server-geoip"]')
    expect(eu.text()).toContain('Missing')
    expect(eu.text()).toContain('File on the server')
    expect(eu.text()).toContain('No GeoIP file')
    expect(w.find('[data-server="us-1"] [data-testid="global-dns-server-geoip"]').text()).toBe('—')
  })
})
