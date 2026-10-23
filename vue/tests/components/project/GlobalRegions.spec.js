import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import GlobalRegions from '@/components/project/GlobalRegions.vue'
import { globalProjectsApi } from '@/api/globalProjects'
import { useSettingsStore } from '@/stores/settings'
import { usePermissionsStore } from '@/stores/permissions'
import { makeI18n, freshPinia, apiError } from '@/test-support/helpers'

vi.mock('@/api/globalProjects', () => ({
  globalProjectsApi: { get: vi.fn(), update: vi.fn(), sync: vi.fn(), failover: vi.fn() },
  globalDnsApi: {},
}))
const confirmMock = vi.fn(() => Promise.resolve(true))
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: confirmMock }) }))

const i18n = makeI18n()
const mounted = []
const project = { id: 'p-shop', name: 'shop', placement: 'global', knative_region_id: 'r-eu' }

function row(id, name, extra = {}) {
  return {
    region_id: id, region_name: name, role: 'secondary', position: 1, status: 'ready', status_message: null,
    version: 3, target_version: null, healthy: true, health_checked_at: '2026-10-21T10:00:00Z',
    address: '203.0.113.10', in_dns: true, ...extra,
  }
}

function globalObject(extra = {}) {
  return {
    mode: 'ha',
    hostname: 'shop.g.runr.test',
    primary_region_id: 'r-eu',
    regions: [
      row('r-us', 'us-1', { position: 1, address: '198.51.100.20' }),
      row('r-eu', 'eu-1', { role: 'primary', position: 0 }),
    ],
    warnings: ['Only external bindings reach every region.'],
    ...extra,
  }
}

async function mountTab(global = globalObject()) {
  globalProjectsApi.get.mockResolvedValue({ data: global })
  const w = mount(GlobalRegions, { props: { project }, global: { plugins: [pinia, i18n] } })
  mounted.push(w)
  await flushPromises()
  return w
}

let pinia
beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
  useSettingsStore().data.regions = [
    { id: 'r-eu', name: 'eu-1', is_active: true, is_synced: true, global_dns_ready: true },
    { id: 'r-us', name: 'us-1', is_active: true, is_synced: true, global_dns_ready: true },
    { id: 'r-ap', name: 'ap-1', is_active: true, is_synced: true, global_dns_ready: true },
    { id: 'r-old', name: 'old-1', is_active: true, is_synced: true, global_dns_ready: false },
  ]
  useSettingsStore().loaded = true
})
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('Project → Regions (global project)', () => {
  it('shows the host name, the mode, the warnings and every region in position order', async () => {
    const w = await mountTab()
    expect(globalProjectsApi.get).toHaveBeenCalledWith('p-shop')
    expect(w.find('[data-testid="global-hostname"]').text()).toContain('shop.g.runr.test')
    expect(w.find('[data-global-mode="ha"]').attributes('aria-pressed')).toBe('true')
    expect(w.find('[data-testid="global-warning"]').text()).toContain('Only external bindings')
    const rows = w.findAll('[data-global-row]').map(r => r.attributes('data-global-row'))
    expect(rows).toEqual(['eu-1', 'us-1'])
    expect(w.find('[data-global-row="eu-1"]').text()).toContain('Primary')
    expect(w.find('[data-global-row="us-1"]').text()).toContain('198.51.100.20')
    expect(w.text()).toContain('2 of 2 regions ready · 2 in DNS')
  })

  it('shows a rollout with its target version, health and DNS membership', async () => {
    const w = await mountTab(globalObject({
      regions: [
        row('r-eu', 'eu-1', { role: 'primary', position: 0, version: 4 }),
        row('r-us', 'us-1', { status: 'deploying', version: 3, target_version: 4, healthy: false, in_dns: false, status_message: 'Waiting for the revision' }),
      ],
    }))
    const us = w.find('[data-global-row="us-1"]')
    expect(us.text()).toContain('Deploying')
    expect(us.text()).toContain('v3')
    expect(us.text()).toContain('→ v4')
    expect(us.text()).toContain('Unhealthy')
    expect(us.text()).toContain('No')
    expect(us.text()).toContain('Waiting for the revision')
    // a region that is not ready cannot become the primary
    expect(us.find('[data-testid="global-make-primary"]').attributes('disabled')).toBeDefined()
  })

  it('changes the DNS mode', async () => {
    const w = await mountTab()
    globalProjectsApi.update.mockResolvedValue({ data: globalObject({ mode: 'proximity' }) })
    await w.find('[data-global-mode="proximity"]').trigger('click')
    await flushPromises()
    expect(globalProjectsApi.update).toHaveBeenCalledWith('p-shop', { mode: 'proximity' })
    expect(w.find('[data-global-mode="proximity"]').attributes('aria-pressed')).toBe('true')
    expect(w.emitted('updated')).toHaveLength(1)
  })

  it('makes a ready secondary region the primary after confirmation', async () => {
    const w = await mountTab()
    globalProjectsApi.update.mockResolvedValue({ data: globalObject() })
    await w.find('[data-global-row="us-1"] [data-testid="global-make-primary"]').trigger('click')
    await flushPromises()
    expect(confirmMock).toHaveBeenCalled()
    expect(globalProjectsApi.update).toHaveBeenCalledWith('p-shop', { primary_region_id: 'r-us' })
  })

  it('adds a region with global DNS and removes one after confirmation', async () => {
    const w = await mountTab(globalObject({
      regions: [
        row('r-eu', 'eu-1', { role: 'primary', position: 0 }),
        row('r-us', 'us-1', { position: 1 }),
        row('r-ap', 'ap-1', { position: 2 }),
      ],
    }))
    // every region with global DNS is in the project already
    expect(w.text()).toContain('No other region has a ready global DNS server.')

    globalProjectsApi.update.mockResolvedValue({ data: globalObject() })
    await w.find('[data-global-row="ap-1"] [data-testid="global-remove"]').trigger('click')
    await flushPromises()
    expect(globalProjectsApi.update).toHaveBeenCalledWith('p-shop', { region_ids: ['r-eu', 'r-us'] })

    // now ap-1 can be added again
    const select = w.find('[data-testid="global-add-region"]')
    expect(select.findAll('option').map(o => o.text())).toEqual(['Add a region…', 'ap-1'])
    await select.setValue('r-ap')
    await w.find('[data-testid="global-add"]').trigger('click')
    await flushPromises()
    expect(globalProjectsApi.update).toHaveBeenLastCalledWith('p-shop', { region_ids: ['r-eu', 'r-us', 'r-ap'] })
  })

  it('keeps at least two regions', async () => {
    const w = await mountTab()
    expect(w.find('[data-global-row="us-1"] [data-testid="global-remove"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-global-row="eu-1"] [data-testid="global-remove"]').exists()).toBe(false)
  })

  it('shows the API error of a refused change', async () => {
    const w = await mountTab()
    globalProjectsApi.update.mockRejectedValue(apiError(422, { message: 'invalid', errors: { primary_region_id: ['The new primary region must be ready.'] } }))
    await w.find('[data-global-row="us-1"] [data-testid="global-make-primary"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-testid="global-error"]').text()).toBe('The new primary region must be ready.')
  })

  it('syncs and reloads', async () => {
    const w = await mountTab()
    globalProjectsApi.sync.mockResolvedValue({ data: { queued: true } })
    await w.find('[data-testid="global-sync"]').trigger('click')
    await flushPromises()
    expect(globalProjectsApi.sync).toHaveBeenCalledWith('p-shop')
    expect(globalProjectsApi.get).toHaveBeenCalledTimes(2)
  })

  it('hides the changes without projects.update and the sync without projects.deploy', async () => {
    usePermissionsStore().apply({ permissions: ['projects.view'], projects: {} })
    const w = await mountTab()
    expect(w.find('[data-testid="global-make-primary"]').exists()).toBe(false)
    expect(w.find('[data-testid="global-sync"]').exists()).toBe(false)
    expect(w.find('[data-global-mode="proximity"]').attributes('disabled')).toBeDefined()
  })

  it('says when the platform has no global host name', async () => {
    const w = await mountTab(globalObject({ hostname: null }))
    expect(w.text()).toContain('No global host name yet')
  })
})

describe('Project → Regions: failover, triggers and data (phase 2)', () => {
  it('shows the failover setting and changes it', async () => {
    const w = await mountTab(globalObject({ failover: 'automatic', failover_after_seconds: 240 }))
    expect(w.find('[data-global-failover="automatic"]').attributes('aria-pressed')).toBe('true')
    expect(w.find('[data-testid="global-failover-hint"]').text()).toContain('240 s')

    globalProjectsApi.update.mockResolvedValue({ data: globalObject({ failover: 'manual' }) })
    await w.find('[data-global-failover="manual"]').trigger('click')
    await flushPromises()
    expect(globalProjectsApi.update).toHaveBeenCalledWith('p-shop', { failover: 'manual' })
    expect(w.find('[data-global-failover="manual"]').attributes('aria-pressed')).toBe('true')
    expect(w.find('[data-testid="global-failover-hint"]').text()).toContain('only when you fail over')
    expect(confirmMock).not.toHaveBeenCalled()
  })

  it('names the limits of an automatic failover where it is chosen', async () => {
    const w = await mountTab(globalObject({ failover: 'manual' }))
    expect(w.find('[data-testid="global-failover-limits"]').exists()).toBe(false)

    // declined: nothing changes
    confirmMock.mockResolvedValueOnce(false)
    await w.find('[data-global-failover="automatic"]').trigger('click')
    await flushPromises()
    expect(confirmMock.mock.calls[0][0].message).toContain('wait there until the primary moves back')
    expect(confirmMock.mock.calls[0][0].message).toContain('accepts writes until RunR reaches it')
    expect(globalProjectsApi.update).not.toHaveBeenCalled()

    globalProjectsApi.update.mockResolvedValue({ data: globalObject({ failover: 'automatic' }) })
    await w.find('[data-global-failover="automatic"]').trigger('click')
    await flushPromises()
    expect(globalProjectsApi.update).toHaveBeenCalledWith('p-shop', { failover: 'automatic' })
    const limits = w.find('[data-testid="global-failover-limits"]')
    expect(limits.text()).toContain('Redis stream')
    expect(limits.text()).toContain('lost when it rejoins')
  })

  it('fails over to a ready secondary after a confirmation that names the loss', async () => {
    const w = await mountTab()
    globalProjectsApi.failover.mockResolvedValue({ data: globalObject({ moving_to: 'r-us' }) })
    await w.find('[data-global-row="us-1"] [data-testid="global-failover"]').trigger('click')
    await flushPromises()
    expect(confirmMock).toHaveBeenCalled()
    expect(confirmMock.mock.calls[0][0].message).toContain('last received WAL is lost')
    expect(confirmMock.mock.calls[0][0].destructive).toBe(true)
    expect(globalProjectsApi.failover).toHaveBeenCalledWith('p-shop', 'r-us')
    // the move runs: shown, no other move or primary change meanwhile
    expect(w.find('[data-testid="global-moving"]').text()).toContain('us-1')
    expect(w.find('[data-global-row="us-1"] [data-testid="global-failover"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-global-row="us-1"] [data-testid="global-make-primary"]').attributes('disabled')).toBeDefined()
    // the primary has no failover button
    expect(w.find('[data-global-row="eu-1"] [data-testid="global-failover"]').exists()).toBe(false)
  })

  it('does not fail over without a confirmation, nor to a region that is not ready', async () => {
    confirmMock.mockResolvedValueOnce(false)
    const w = await mountTab(globalObject({
      regions: [
        row('r-eu', 'eu-1', { role: 'primary', position: 0 }),
        row('r-us', 'us-1', { position: 1 }),
        row('r-ap', 'ap-1', { position: 2, status: 'deploying' }),
      ],
    }))
    await w.find('[data-global-row="us-1"] [data-testid="global-failover"]').trigger('click')
    await flushPromises()
    expect(globalProjectsApi.failover).not.toHaveBeenCalled()
    expect(w.find('[data-global-row="ap-1"] [data-testid="global-failover"]').attributes('disabled')).toBeDefined()
  })

  it('shows the API error of a refused failover', async () => {
    const w = await mountTab()
    globalProjectsApi.failover.mockRejectedValue(apiError(422, { message: 'invalid', errors: { region_id: ['A move of the primary is running.'] } }))
    await w.find('[data-global-row="us-1"] [data-testid="global-failover"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-testid="global-error"]').text()).toBe('A move of the primary is running.')
  })

  it('shows where the triggers run, the data state, a fenced region and an unhealthy primary', async () => {
    const w = await mountTab(globalObject({
      regions: [
        row('r-eu', 'eu-1', { role: 'primary', position: 0, triggers_active: true, data_status: 'ready' }),
        row('r-us', 'us-1', { position: 1, data_status: 'syncing', data_message: 'The replica cluster does not stream from the primary yet.', in_dns: false }),
        row('r-ap', 'ap-1', {
          position: 2, status: 'fenced', healthy: false, in_dns: false, unhealthy_since: '2026-10-22T08:00:00Z',
          fenced_at: '2026-10-22T08:03:00Z', status_message: 'Failed over to eu-1: this region is out of the DNS answers until it answers again.',
        }),
      ],
    }))
    expect(w.find('[data-global-row="eu-1"] [data-testid="global-triggers-badge"]').exists()).toBe(true)
    expect(w.find('[data-global-row="us-1"] [data-testid="global-triggers-badge"]').exists()).toBe(false)
    expect(w.find('[data-global-row="eu-1"] [data-testid="global-data-status"]').text()).toBe('Ready')
    const us = w.find('[data-global-row="us-1"]')
    expect(us.find('[data-testid="global-data-status"]').text()).toBe('Syncing')
    expect(us.text()).toContain('does not stream from the primary yet')
    const ap = w.find('[data-global-row="ap-1"]')
    expect(ap.find('[data-testid="global-region-status"]').text()).toBe('Fenced')
    expect(ap.text()).toContain('Failed over to eu-1')
    expect(ap.text()).toContain('Fenced since')
    expect(ap.find('[data-testid="global-unhealthy-since"]').exists()).toBe(true)
  })

  it('lists how every binding is reached in each region', async () => {
    const w = await mountTab(globalObject({
      bindings: [
        {
          binding_id: 'b-orders', name: 'orders-db', type: 'postgres', plan: 'dedicated', home_region_id: 'r-eu', home_region_name: 'eu-1',
          access: 'replicas',
          regions: [
            { region_id: 'r-eu', region_name: 'eu-1', kind: 'home', status: 'ready', status_message: null, lag_seconds: null },
            { region_id: 'r-us', region_name: 'us-1', kind: 'replica', status: 'ready', status_message: null, lag_seconds: 3 },
          ],
        },
        {
          binding_id: 'b-cache', name: 'cache', type: 'kv', plan: 'shared', home_region_id: 'r-eu', home_region_name: 'eu-1', access: 'link',
          regions: [{ region_id: 'r-us', region_name: 'us-1', kind: 'link', status: 'provisioning', status_message: 'Waiting for the data link to the binding’s region.', lag_seconds: null }],
        },
        { binding_id: 'b-pay', name: 'payments', type: 'external', plan: 'external', home_region_id: null, home_region_name: null, access: 'direct', regions: [] },
      ],
    }))
    const orders = w.find('[data-global-binding="orders-db"]')
    expect(orders.find('[data-testid="global-data-access"]').text()).toBe('Replica per region')
    expect(orders.text()).toContain('data in eu-1')
    expect(orders.text()).toContain('DATABASE_URL_RO')
    expect(orders.find('[data-global-binding-region="us-1"]').text()).toContain('Replica')
    expect(orders.find('[data-global-binding-region="us-1"]').text()).toContain('3 s')
    const cache = w.find('[data-global-binding="cache"]')
    expect(cache.find('[data-testid="global-data-access"]').text()).toBe('Data link')
    expect(cache.find('[data-global-binding-region="us-1"]').text()).toContain('Starting')
    expect(cache.text()).toContain('Waiting for the data link')
    expect(w.find('[data-global-binding="payments"] [data-testid="global-data-access"]').text()).toBe('Direct')
  })

  it('says when the project has no bindings', async () => {
    const w = await mountTab(globalObject({ bindings: [] }))
    expect(w.find('[data-testid="global-data-empty"]').exists()).toBe(true)
  })
})
