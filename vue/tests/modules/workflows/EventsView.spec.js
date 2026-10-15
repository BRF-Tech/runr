import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { usePermissionsStore } from '@/stores/permissions'
import { makeI18n } from './fixtures'

const push = vi.fn()
vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRouter: () => ({ push }), useRoute: () => ({ query: {} }) }))
vi.mock('@/modules/workflows/api/workflows', () => ({
  workflowsApi: {
    events:    vi.fn(),
    event:     vi.fn(),
    usage:     vi.fn(),
    sendEvent: vi.fn(),
  },
}))

import { workflowsApi } from '@/modules/workflows/api/workflows'
import EventsView from '@/modules/workflows/components/EventsView.vue'

const event = (overrides = {}) => ({
  id: '01JEVENT00000000000000000A', name: 'order.created', data: { order_id: 1 }, external_id: null,
  source: 'api', project_id: null, sent_by: '1', ts: 1, runs_started: 2, waits_resumed: 1, runs_cancelled: 0,
  created_at: '2026-10-10T10:00:00.000Z', ...overrides,
})

let pinia
function mountView() {
  return mount(EventsView, { global: { plugins: [makeI18n(), pinia] } })
}

describe('EventsView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    pinia = createPinia()
    setActivePinia(pinia)
    workflowsApi.usage.mockResolvedValue({ data: { totals: { step_executions: 42, runs_started: 7 } } })
  })

  it('lists events with their effects and usage', async () => {
    workflowsApi.events.mockResolvedValue({ data: { data: [event(), event({ id: '01JEVENT00000000000000000B', name: 'queue/orders', source: 'queue', runs_started: 0, waits_resumed: 0 })], meta: { current_page: 1, last_page: 1 } } })
    const w = mountView()
    await flushPromises()

    expect(workflowsApi.events).toHaveBeenCalledWith({ per_page: 25 })
    const rows = w.findAll('[data-event]')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('2 started')
    expect(rows[0].text()).toContain('1 resumed')
    expect(rows[1].text()).toContain('Queue')
    expect(w.find('[data-test="usage"]').text()).toContain('42')
    expect(w.find('[data-test="usage"]').text()).toContain('7')
  })

  it('shows the empty state', async () => {
    workflowsApi.events.mockResolvedValue({ data: { data: [], meta: {} } })
    const w = mountView()
    await flushPromises()
    expect(w.find('[data-test="events-empty"]').exists()).toBe(true)
  })

  it('opens an event with the runs it started and links to a run', async () => {
    workflowsApi.events.mockResolvedValue({ data: { data: [event()], meta: {} } })
    workflowsApi.event.mockResolvedValue({ data: { data: event(), runs: [{ id: '01JRUN0000000000000000000A', project_id: 'p9', status: 'completed', workflow: { name: 'on-order' } }] } })
    const w = mountView()
    await flushPromises()

    await w.find('[data-event]').trigger('click')
    await flushPromises()

    const detail = w.find('[data-test="event-detail"]')
    expect(detail.text()).toContain('"order_id": 1')
    expect(detail.text()).toContain('on-order')

    await detail.findAll('button').find((b) => b.text().startsWith('01JR')).trigger('click')
    expect(push).toHaveBeenCalledWith({ path: '/projects/p9', query: { run: '01JRUN0000000000000000000A' }, hash: '#workflows' })
  })

  it('filters by name (debounced) and source', async () => {
    workflowsApi.events.mockResolvedValue({ data: { data: [], meta: {} } })
    const w = mountView()
    await flushPromises()

    await w.find('[data-test="event-filter"]').setValue('order.')
    await new Promise((r) => setTimeout(r, 350))
    await flushPromises()
    expect(workflowsApi.events).toHaveBeenLastCalledWith({ per_page: 25, name: 'order.' })

    await w.find('select').setValue('test')
    await flushPromises()
    expect(workflowsApi.events).toHaveBeenLastCalledWith({ per_page: 25, name: 'order.', source: 'test' })
  })

  it('sends a test event', async () => {
    workflowsApi.events.mockResolvedValue({ data: { data: [], meta: {} } })
    workflowsApi.sendEvent.mockResolvedValue({ data: { ids: ['01JNEW'], events: [{ id: '01JNEW', runs: ['r1'], resumed: 0, duplicate: false }] } })
    const w = mountView()
    await flushPromises()

    await w.find('[data-test="open-send-event"]').trigger('click')
    await w.find('[data-test="event-name"]').setValue('user.signup')
    await w.find('[data-test="event-data"]').setValue('{"email": "a@example.com"}')
    await w.find('[data-test="send-event-modal"] form').trigger('submit')
    await flushPromises()

    expect(workflowsApi.sendEvent).toHaveBeenCalledWith({ name: 'user.signup', data: { email: 'a@example.com' } }, { test: true })
    expect(w.find('[data-test="send-event-modal"]').exists()).toBe(false)
    expect(workflowsApi.events).toHaveBeenCalledTimes(2)
  })

  it('hides "send test event" without events.send', async () => {
    usePermissionsStore().apply({ permissions: ['workflows.view'], projects: {} })
    workflowsApi.events.mockResolvedValue({ data: { data: [], meta: {} } })
    const w = mountView()
    await flushPromises()
    expect(w.find('[data-test="open-send-event"]').exists()).toBe(false)
  })

  it('keeps the modal open with the API error', async () => {
    workflowsApi.events.mockResolvedValue({ data: { data: [], meta: {} } })
    workflowsApi.sendEvent.mockRejectedValue({ response: { data: { message: 'Event names starting with "runr/" are reserved for the platform.' } } })
    const w = mountView()
    await flushPromises()

    await w.find('[data-test="open-send-event"]').trigger('click')
    await w.find('[data-test="event-name"]').setValue('runr/cron')
    await w.find('[data-test="send-event-modal"] form').trigger('submit')
    await flushPromises()

    expect(w.find('[data-test="send-error"]').text()).toContain('reserved')
  })
})
