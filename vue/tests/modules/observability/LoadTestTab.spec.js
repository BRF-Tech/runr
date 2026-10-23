import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { makeI18n } from './fixtures'

const observabilityApi = vi.hoisted(() => ({
  loadTests: vi.fn(), loadTest: vi.fn(), startLoadTest: vi.fn(), cancelLoadTest: vi.fn(),
}))
const confirm = vi.hoisted(() => vi.fn())

vi.mock('@/modules/observability/api/observability', () => ({ observabilityApi }))
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm }) }))

import LoadTestTab from '@/modules/observability/components/LoadTestTab.vue'
import { dispatchPlatformEvent } from '@/composables/useJobEvents'
import { usePermissionsStore } from '@/stores/permissions'

const PROJECT = { id: 'p1', name: 'orders', slug: 'orders', service_type: 'xaas', config: { trigger_type: 'http' } }

const META = {
  enabled: true, users: [10, 50, 100], durations: [30, 60, 300], methods: ['GET', 'POST'],
  targets: ['orders.eu.runr.test', 'shop.example.com'], daily_limit: 20, used_today: 3, max_body_bytes: 4096, metered: true,
}

function test(id, overrides = {}) {
  return {
    id, project_id: 'p1', status: 'completed', method: 'GET', host: 'orders.eu.runr.test', path: '/', url: 'https://orders.eu.runr.test/',
    users: 10, duration_seconds: 30, error: null, started_at: '2026-10-21T10:00:00Z', finished_at: '2026-10-21T10:00:35Z',
    created_at: `2026-10-21T10:0${id}:00Z`,
    results: { requests: 3000, failed_requests: 0, rps: 100, error_rate: 0, latency_ms: { p50: 10, p95: 20 + Number(id), p99: 40 }, pods_peak: 2, pods_source: 'prometheus', cold_starts: 1 },
    ...overrides,
  }
}

async function mountTab({ tests = [], meta = META, permissions = null } = {}) {
  const pinia = createPinia()
  setActivePinia(pinia)
  if (permissions) usePermissionsStore().apply({ permissions, projects: {} })
  observabilityApi.loadTests.mockResolvedValue({ data: { data: tests, meta } })
  const w = mount(LoadTestTab, { props: { project: PROJECT }, global: { plugins: [makeI18n(), pinia] } })
  await flushPromises()
  return w
}

beforeEach(() => {
  confirm.mockResolvedValue(true)
})

describe('Load test tab', () => {
  it('offers the configured choices, says the traffic is metered and shows the day\'s count', async () => {
    const w = await mountTab()

    expect(w.find('[data-test="load-test-metered"]').text()).toContain('metered and billed')
    expect(w.find('[data-test="load-test-quota"]').text()).toBe('3 of 20 tests today')
    expect(w.findAll('[data-test="load-test-host"] option').map((o) => o.text())).toEqual(META.targets)
    expect(w.find('[data-test="load-test-users-100"]').exists()).toBe(true)
    expect(w.find('[data-test="load-test-duration-300"]').text()).toBe('5 min')
    expect(w.find('[data-test="load-test-body"]').exists()).toBe(false)
    expect(w.find('[data-test="load-test-empty"]').exists()).toBe(true)
  })

  it('starts a POST test with the chosen users, duration and body', async () => {
    const w = await mountTab()
    observabilityApi.startLoadTest.mockResolvedValue({ data: { data: test('9', { status: 'pending', results: null, started_at: null, finished_at: null }) } })

    await w.find('[data-test="load-test-host"]').setValue('shop.example.com')
    await w.find('[data-test="load-test-path"]').setValue('/api/orders?page=2')
    await w.find('[data-test="load-test-method"]').setValue('POST')
    await w.find('[data-test="load-test-body"]').setValue('{"item":42}')
    await w.find('[data-test="load-test-users-50"]').trigger('click')
    await w.find('[data-test="load-test-duration-60"]').trigger('click')
    await w.find('[data-test="load-test-form"]').trigger('submit')
    await flushPromises()

    expect(observabilityApi.startLoadTest).toHaveBeenCalledWith('p1', {
      host: 'shop.example.com', path: '/api/orders?page=2', method: 'POST', users: 50, duration_seconds: 60,
      body: '{"item":42}', content_type: 'application/json',
    })
    expect(w.find('[data-test="load-test-active"]').attributes('data-status')).toBe('pending')
    expect(w.find('[data-test="load-test-start"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-test="load-test-quota"]').text()).toBe('4 of 20 tests today')
  })

  it('follows the running test through the broadcasts and shows its results', async () => {
    const w = await mountTab({ tests: [test('1')] })
    dispatchPlatformEvent('load_test.updated', { project_id: 'p1', load_test: test('2', { status: 'running', results: null, finished_at: null }) })
    await flushPromises()
    expect(w.find('[data-test="load-test-active"]').attributes('data-status')).toBe('running')

    // another project's test changes nothing
    dispatchPlatformEvent('load_test.updated', { project_id: 'p2', load_test: test('7', { project_id: 'p2', status: 'running' }) })
    dispatchPlatformEvent('load_test.completed', { project_id: 'p1', load_test: test('2') })
    await flushPromises()

    expect(w.find('[data-test="load-test-active"]').exists()).toBe(false)
    const latest = w.find('[data-test="load-test-latest"]')
    expect(latest.attributes('data-status')).toBe('completed')
    expect(latest.find('[data-metric="p95"]').text()).toContain('22 ms')
    expect(w.findAll('[data-test="load-test-compare"] [data-run]').map((c) => c.attributes('data-run'))).toEqual(['2', '1'])
    // the lower p95 of the two runs is the better one
    expect(w.find('[data-row="p95"] [data-best="true"]').text()).toBe('21 ms')
  })

  it('shows why a run failed and cancels a running one', async () => {
    const running = test('3', { status: 'running', results: null, finished_at: null })
    const failed = test('2', { status: 'failed', results: null, error: 'k6 ended without results: dial tcp: i/o timeout' })
    const w = await mountTab({ tests: [running, failed] })
    expect(w.find('[data-test="load-test-error"]').text()).toContain('i/o timeout')

    observabilityApi.cancelLoadTest.mockResolvedValue({ data: { data: { ...running, status: 'cancelled' } } })
    await w.find('[data-test="load-test-cancel"]').trigger('click')
    await flushPromises()

    expect(confirm).toHaveBeenCalled()
    expect(observabilityApi.cancelLoadTest).toHaveBeenCalledWith('p1', '3')
    expect(w.find('[data-test="load-test-active"]').exists()).toBe(false)
  })

  it('holds the start back at the daily limit and shows the API\'s refusal of a field', async () => {
    const w = await mountTab({ meta: { ...META, used_today: 20 } })
    expect(w.find('[data-test="load-test-limit"]').exists()).toBe(true)
    expect(w.find('[data-test="load-test-start"]').attributes('disabled')).toBeDefined()

    const v = await mountTab()
    observabilityApi.startLoadTest.mockRejectedValue({ response: { status: 422, data: { message: 'invalid', errors: { path: ['The path format is invalid.'] } } } })
    await v.find('[data-test="load-test-form"]').trigger('submit')
    await flushPromises()
    expect(v.find('[data-test="load-test-target-error"]').text()).toBe('The path format is invalid.')
  })

  it('reading only: no form without projects.deploy', async () => {
    const w = await mountTab({ tests: [test('1')], permissions: ['projects.view', 'metrics.view'] })

    expect(w.find('[data-test="load-test-form"]').exists()).toBe(false)
    expect(w.find('[data-test="load-test-compare"]').exists()).toBe(true)
  })
})
