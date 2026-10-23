import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { usePermissionsStore } from '@/stores/permissions'
import { useToast } from '@/composables/useToast'
import { triggersApi } from '@/modules/triggers/api/triggers'
import TriggerInbox from '@/modules/triggers/components/TriggerInbox.vue'
import { withPlugins } from '../../helpers'
import { HOOKS, PROJECT, event, eventDetail } from './fixtures'

// Inbox of a webhook trigger: stored events by status with paging, the detail (headers, body as
// text or base64, last error), replay of one event and of every failed one; replay.manage.

vi.mock('@/modules/triggers/api/triggers', () => ({
  triggersApi: { events: vi.fn(), event: vi.fn(), replayEvent: vi.fn(), replayEvents: vi.fn() },
}))
const confirmMock = vi.fn()
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: confirmMock }) }))

const A = '01JEVT0000000000000000000A'
const B = '01JEVT0000000000000000000B'

async function mountInbox(permissions = null) {
  const plugins = withPlugins()
  if (permissions) {
    usePermissionsStore().apply({ edition: 'single', platform_admin: false, role: 'custom', roles: ['custom'], permissions, projects: {}, token_scoped: false })
  }
  const w = mount(TriggerInbox, { props: { project: PROJECT, trigger: HOOKS }, ...plugins })
  await flushPromises()
  return w
}

describe('TriggerInbox', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useToast().toasts.value = []
    triggersApi.events.mockResolvedValue({ data: { data: [event(A, 'delivered'), event(B, 'failed')], meta: { current_page: 1, last_page: 2, per_page: 25, total: 30 } } })
    triggersApi.event.mockResolvedValue({ data: { data: eventDetail(B, 'failed') } })
  })

  it('lists the stored events with status, attempts and the inbox URL', async () => {
    const w = await mountInbox()
    expect(triggersApi.events).toHaveBeenCalledWith('p1', HOOKS.id, { per_page: 25 })
    expect(w.text()).toContain('Inbox of "hooks"')
    expect(w.find('[data-test="inbox-url"]').text()).toBe(HOOKS.url)
    expect(w.findAll('[data-event]')).toHaveLength(2)
    expect(w.find(`[data-event="${B}"]`).text()).toContain('Failed')
    expect(w.find(`[data-event="${B}"]`).text()).toContain('6')
    expect(w.text()).toContain('Page 1 of 2')
  })

  it('filters by status and pages', async () => {
    const w = await mountInbox()
    await w.find('[data-field="status"]').setValue('failed')
    await flushPromises()
    expect(triggersApi.events).toHaveBeenLastCalledWith('p1', HOOKS.id, { per_page: 25, status: 'failed' })
    await w.find('[data-action="next"]').trigger('click')
    await flushPromises()
    expect(triggersApi.events).toHaveBeenLastCalledWith('p1', HOOKS.id, { per_page: 25, status: 'failed', page: 2 })
  })

  it('opens an event: headers, pretty JSON body, base64, last error', async () => {
    const w = await mountInbox()
    await w.find(`[data-event="${B}"]`).trigger('click')
    await flushPromises()
    expect(triggersApi.event).toHaveBeenCalledWith('p1', HOOKS.id, B)
    const detail = w.find('[data-test="event-detail"]')
    expect(detail.find('[data-test="detail-headers"]').text()).toContain('X-GitHub-Event: pull_request')
    expect(detail.find('[data-test="detail-body"]').text()).toContain('"number": 42')
    expect(detail.find('[data-test="detail-error"]').text()).toBe('HTTP 500 from the function')

    await detail.find('[data-action="body-base64"]').trigger('click')
    expect(detail.find('[data-test="detail-body"]').text()).toBe(eventDetail(B, 'failed').body_base64)
  })

  it('shows a body that is not text as base64', async () => {
    triggersApi.event.mockResolvedValue({ data: { data: eventDetail(B, 'failed', { body_text: null }) } })
    const w = await mountInbox()
    await w.find(`[data-event="${B}"]`).trigger('click')
    await flushPromises()
    const detail = w.find('[data-test="event-detail"]')
    expect(detail.find('[data-action="body-base64"]').exists()).toBe(false)
    expect(detail.text()).toContain('shown as base64')
  })

  it('replays one event', async () => {
    triggersApi.replayEvent.mockResolvedValue({ data: { data: event(B, 'pending', { attempts: 0, replays: 1, last_error: null }) } })
    const w = await mountInbox()
    await w.find(`[data-event="${B}"]`).trigger('click')
    await flushPromises()
    await w.find('[data-action="replay"]').trigger('click')
    await flushPromises()
    expect(triggersApi.replayEvent).toHaveBeenCalledWith('p1', HOOKS.id, B)
    expect(w.find('[data-test="detail-status"]').text()).toBe('Pending')
    expect(w.find(`[data-event="${B}"]`).attributes('data-status')).toBe('pending')
    expect(w.find('[data-action="replay"]').attributes('disabled')).toBeDefined()
    expect(useToast().toasts.value.at(-1).message).toBe('The event is queued for delivery again.')
  })

  it('replays every failed event after the confirmation', async () => {
    triggersApi.replayEvents.mockResolvedValue({ data: { replayed: 3 } })
    const w = await mountInbox()
    confirmMock.mockResolvedValueOnce(false)
    await w.find('[data-action="replay-failed"]').trigger('click')
    await flushPromises()
    expect(triggersApi.replayEvents).not.toHaveBeenCalled()

    confirmMock.mockResolvedValueOnce(true)
    await w.find('[data-action="replay-failed"]').trigger('click')
    await flushPromises()
    expect(triggersApi.replayEvents).toHaveBeenCalledWith('p1', HOOKS.id, { status: 'failed' })
    expect(useToast().toasts.value.at(-1).message).toBe('3 events queued for delivery again.')
    expect(triggersApi.events).toHaveBeenCalledTimes(2)
  })

  it('needs replay.manage on the project', async () => {
    const w = await mountInbox(['projects.view'])
    expect(w.find('[data-test="inbox-no-permission"]').exists()).toBe(true)
    expect(w.find('[data-event]').exists()).toBe(false)
  })

  it('goes back to the list', async () => {
    const w = await mountInbox()
    await w.find('[data-action="back"]').trigger('click')
    expect(w.emitted('back')).toHaveLength(1)
  })
})
