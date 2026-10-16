import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SandboxSettingsTab from '@/modules/sandboxes/components/SandboxSettingsTab.vue'
import { sandboxAdminApi } from '@/modules/sandboxes/api/sandboxes'
import { runtimesApi } from '@/api/runtimes'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { freshPinia } from '@/test-support/helpers'
import { histogramBars } from '@/modules/sandboxes/utils'
import { sandboxI18n, regionRows, pythonRuntime } from './fixtures'

vi.mock('@/modules/sandboxes/api/sandboxes', () => ({
  sandboxesApi: {},
  sandboxAdminApi: {
    regions: vi.fn(), updateRegion: vi.fn(), pools: vi.fn(), createPool: vi.fn(), updatePool: vi.fn(), deletePool: vi.fn(),
    quota: vi.fn(), tenantQuotas: vi.fn(), updateQuota: vi.fn(), resetQuota: vi.fn(), metrics: vi.fn(), usage: vi.fn(),
  },
}))
vi.mock('@/api/runtimes', () => ({ runtimesApi: { list: vi.fn() } }))
vi.mock('@/api/settings', () => ({ settingsApi: { get: vi.fn() } }))
// Single-tenant install: no tenancy module, the quota table is open to the user
vi.mock('@/modules/index', () => ({ moduleRegistry: { isCentralAdminFn: null } }))

const i18n = sandboxI18n()
let pinia
const mounted = []

const LIMITS = { max_concurrent: 20, max_cpu_m: 8000, max_memory_mb: 16384, max_sandbox_seconds_month: 0, max_pool_size: 10, allowed_classes: null }
const USAGE  = { concurrent: 3, cpu_m: 1500, memory_mb: 1536, sandbox_seconds_month: 7200, pool_size: 2 }

const pool = {
  id: 'p1', region: { id: 'r1', name: 'eu-1' }, runtime: 'python', runtime_version: '3.14', image: 'ghcr.io/brf-tech/runr/exec-python:3.14',
  sandbox_class: 'kata-fc', cpu_m: 500, memory_mb: 512, disk_mb: 1024, size: 2, is_enabled: true, warm: 2, starting: 0, hits: 10, misses: 3,
  tenant_id: null, created_at: '2026-10-10T10:00:00Z',
}

async function mountTab() {
  const wrapper = mount(SandboxSettingsTab, { global: { plugins: [pinia, i18n] } })
  mounted.push(wrapper)
  await flushPromises()
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
  // a fresh copy per call: the tab replaces a saved row in the array it got
  sandboxAdminApi.regions.mockImplementation(() => Promise.resolve({ data: { data: structuredClone(regionRows) } }))
  sandboxAdminApi.pools.mockResolvedValue({ data: { data: [pool] } })
  sandboxAdminApi.quota.mockResolvedValue({ data: { data: { tenant_id: null, limits: LIMITS, usage: USAGE } } })
  sandboxAdminApi.tenantQuotas.mockResolvedValue({ data: { data: [
    { tenant: null, limits: LIMITS, usage: USAGE, custom: false },
    { tenant: { id: 't1', name: 'Acme', slug: 'acme' }, limits: { ...LIMITS, max_concurrent: 5 }, usage: USAGE, custom: true },
  ] } })
  sandboxAdminApi.metrics.mockResolvedValue({ data: { data: {
    window: '24h', active: 4, pool: { hits: 30, misses: 10, hit_rate: 0.75 },
    start_time: {
      pool_hit: { count: 30, sum_ms: 9000, buckets: [{ le_ms: 100, count: 5 }, { le_ms: 250, count: 20 }, { le_ms: 500, count: 30 }, { le_ms: null, count: 30 }] },
      cold:     { count: 0, sum_ms: 0, buckets: [] },
    },
  } } })
  runtimesApi.list.mockResolvedValue({ data: { data: [pythonRuntime], abilities: {}, custom_enabled: false } })
  useToast().toasts.value = []
})

afterEach(() => {
  while (mounted.length) mounted.pop().unmount()
})

describe('SandboxSettingsTab regions', () => {
  it('saves a region with the allowed classes it was narrowed to', async () => {
    sandboxAdminApi.updateRegion.mockImplementation((id, payload) => Promise.resolve({ data: { data: { ...regionRows[0], ...payload } } }))
    const wrapper = await mountTab()
    const card = wrapper.find('[data-region-card="r1"]')

    await card.find('input[name="class-kata-clh"]').setValue(false)
    await card.find('select[name="default_class"]').setValue('gvisor')
    await card.find('input[name="max_ttl_seconds"]').setValue(7200)
    await card.find('[data-action="save-region"]').trigger('click')
    await flushPromises()

    expect(sandboxAdminApi.updateRegion).toHaveBeenCalledWith('r1', {
      enabled:             true,
      allowed_classes:     ['kata-fc', 'gvisor'],
      default_class:       'gvisor',
      allow_runc:          false,
      default_ttl_seconds: 900,
      max_ttl_seconds:     7200,
      max_cpu_m:           4000,
      max_memory_mb:       8192,
      max_disk_mb:         10240,
    })
    expect(useToast().toasts.value.map(t => t.message)).toContain('Sandbox settings of eu-1 saved.')
  })

  it('sends null when every installed class stays allowed and warns about runc', async () => {
    sandboxAdminApi.updateRegion.mockResolvedValue({ data: { data: { ...regionRows[0], allow_runc: true } } })
    const wrapper = await mountTab()
    const card = wrapper.find('[data-region-card="r1"]')

    expect(card.find('[data-runc-warning]').exists()).toBe(false)
    await card.find('input[name="allow_runc"]').setValue(true)
    expect(card.find('[data-runc-warning]').exists()).toBe(true)
    await card.find('[data-action="save-region"]').trigger('click')
    await flushPromises()

    const payload = sandboxAdminApi.updateRegion.mock.calls[0][1]
    expect(payload.allowed_classes).toBeNull()
    expect(payload.allow_runc).toBe(true)
  })

  it('is read-only without the manage ability', async () => {
    sandboxAdminApi.regions.mockResolvedValue({ data: { data: [{ ...regionRows[0], can_manage: false }] } })
    const wrapper = await mountTab()
    const card = wrapper.find('[data-region-card="r1"]')

    expect(card.find('[data-action="save-region"]').exists()).toBe(false)
    expect(card.find('input[name="allow_runc"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-action="add-pool"]').exists()).toBe(false)
  })
})

describe('SandboxSettingsTab warm pools', () => {
  it('lists pools with their counters and creates one', async () => {
    sandboxAdminApi.createPool.mockResolvedValue({ data: { data: { ...pool, id: 'p2', size: 3 } } })
    const wrapper = await mountTab()

    const row = wrapper.find('[data-pool-row="p1"]')
    expect(row.text()).toContain('python')
    expect(row.text()).toContain('kata-fc')
    expect(row.text()).toContain('10 hits')
    expect(row.text()).toContain('3 misses')

    await wrapper.find('[data-action="add-pool"]').trigger('click')
    const form = wrapper.find('[data-pool-form]')
    await form.find('input[name="pool_size"]').setValue(3)
    await form.trigger('submit')
    await flushPromises()

    expect(sandboxAdminApi.createPool).toHaveBeenCalledWith({
      region: 'r1', runtime: 'python', version: '3.14', size: 3, cpu: '500m', memory: 512, disk: 1024,
    })
    expect(sandboxAdminApi.pools).toHaveBeenCalledTimes(2)
  })

  it('changes the size of a pool', async () => {
    sandboxAdminApi.updatePool.mockResolvedValue({ data: { data: { ...pool, size: 5 } } })
    const wrapper = await mountTab()
    const row = wrapper.find('[data-pool-row="p1"]')

    await row.find('input[name="size-p1"]').setValue(5)
    await row.find('[data-action="save-pool"]').trigger('click')
    await flushPromises()

    expect(sandboxAdminApi.updatePool).toHaveBeenCalledWith('p1', { size: 5 })
  })

  it('deletes a pool after the confirmation', async () => {
    sandboxAdminApi.deletePool.mockResolvedValue({ status: 204 })
    const wrapper = await mountTab()

    await wrapper.find('[data-pool-row="p1"] button[title="Delete"]').trigger('click')
    await flushPromises()
    expect(useConfirm().current.value?.title).toBe('Delete Warm Pool')
    useConfirm().accept()
    await flushPromises()

    expect(sandboxAdminApi.deletePool).toHaveBeenCalledWith('p1')
    expect(wrapper.find('[data-pool-row="p1"]').exists()).toBe(false)
  })
})

describe('SandboxSettingsTab quotas and metrics', () => {
  it('shows the own quota usage and edits an organization quota', async () => {
    sandboxAdminApi.updateQuota.mockResolvedValue({ data: { data: {} } })
    const wrapper = await mountTab()

    expect(wrapper.find('[data-own-quota] [data-quota="max_concurrent"]').text()).toContain('3 / 20')
    expect(wrapper.find('[data-own-quota] [data-quota="max_sandbox_seconds_month"]').text()).toContain('unlimited')

    const row = wrapper.find('[data-quota-row="t1"]')
    await row.find('input[name="max_concurrent"]').setValue(8)
    await row.find('input[name="allowed_classes"]').setValue('kata-fc, gvisor')
    await row.find('[data-action="save-quota"]').trigger('click')
    await flushPromises()

    expect(sandboxAdminApi.updateQuota).toHaveBeenCalledWith('t1', {
      max_concurrent: 8, max_cpu_m: 8000, max_memory_mb: 16384, max_sandbox_seconds_month: 0, max_pool_size: 10,
      allowed_classes: ['kata-fc', 'gvisor'],
    })
  })

  it('saves the defaults row under "default" and resets a custom quota', async () => {
    sandboxAdminApi.updateQuota.mockResolvedValue({ data: { data: {} } })
    sandboxAdminApi.resetQuota.mockResolvedValue({ status: 204 })
    const wrapper = await mountTab()

    expect(wrapper.find('[data-quota-row="default"] [data-action="reset-quota"]').exists()).toBe(false)
    await wrapper.find('[data-quota-row="default"] [data-action="save-quota"]').trigger('click')
    await flushPromises()
    expect(sandboxAdminApi.updateQuota.mock.calls[0][0]).toBe('default')
    expect(sandboxAdminApi.updateQuota.mock.calls[0][1].allowed_classes).toBeNull()

    await wrapper.find('[data-quota-row="t1"] [data-action="reset-quota"]').trigger('click')
    await flushPromises()
    useConfirm().accept()
    await flushPromises()
    expect(sandboxAdminApi.resetQuota).toHaveBeenCalledWith('t1')
  })

  it('hides the organizations table when the API refuses it', async () => {
    const err = new Error('HTTP 403')
    err.response = { status: 403, data: { message: 'Forbidden' } }
    sandboxAdminApi.tenantQuotas.mockRejectedValue(err)
    const wrapper = await mountTab()

    expect(wrapper.find('[data-tenant-quotas]').exists()).toBe(false)
    expect(wrapper.find('[data-own-quota]').exists()).toBe(true)
  })

  it('shows the pool hit rate and the start-time histogram', async () => {
    const wrapper = await mountTab()

    expect(sandboxAdminApi.metrics).toHaveBeenCalledWith({ window: '24h' })
    expect(wrapper.find('[data-metric="hit-rate"]').text()).toBe('75 %')
    expect(wrapper.find('[data-metric="active"]').text()).toBe('4')
    const hist = wrapper.find('[data-histogram="pool_hit"]')
    expect(hist.text()).toContain('30 sandboxes')
    expect(hist.text()).toContain('average 300 ms')
    expect(wrapper.find('[data-histogram="cold"]').text()).toContain('No sandboxes started in this window.')

    await wrapper.find('select[name="metrics_window"]').setValue('7d')
    await flushPromises()
    expect(sandboxAdminApi.metrics).toHaveBeenLastCalledWith({ window: '7d' })
  })

  it('turns cumulative buckets into per-bucket counts', () => {
    expect(histogramBars({ buckets: [{ le_ms: 100, count: 5 }, { le_ms: 250, count: 20 }, { le_ms: null, count: 21 }] }))
      .toEqual([{ le_ms: 100, count: 5 }, { le_ms: 250, count: 15 }, { le_ms: null, count: 1 }])
  })
})
