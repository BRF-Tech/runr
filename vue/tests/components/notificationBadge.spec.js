import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { defineComponent, h } from 'vue'
import { moduleRegistry } from '@/modules/index'
import { notificationsApi } from '@/api/notifications'
import { useAuthStore } from '@/stores/auth'
import { setWsConnected } from '@/composables/useWsConnection'
import { useRealtimeNotifications } from '@/composables/useRealtimeNotifications'
import AppHeader from '@/components/AppHeader.vue'
import { freshPinia, makeI18n } from '@/test-support/helpers'
import { deferred } from '../helpers'

// The bell's badge (AppHeader, NotificationPanel, stores/notifications): the signed-in member's
// unread count right after the load, with the socket up or down; then one more for each
// notification the socket brings, one less for each the member reads or deletes in the panel;
// the next member's own count after a sign-out and another sign-in.

vi.mock('@/modules/index', async () => {
  const { reactive } = await import('vue')
  return { moduleRegistry: reactive({ settingsTabs: [], navLinks: [], headerSlots: [], viewKey: 0 }) }
})
vi.mock('@/api/runtimes', () => ({ runtimesApi: { list: vi.fn(() => new Promise(() => {})) } }))
vi.mock('@/api/settings', () => ({ settingsApi: { get: vi.fn(() => new Promise(() => {})) } }))
vi.mock('@/api/notifications', () => ({
  notificationsApi: {
    unreadCount: vi.fn(), list: vi.fn(), markRead: vi.fn(), markAllRead: vi.fn(), remove: vi.fn(), removeAll: vi.fn(),
  },
}))
const echo = vi.hoisted(() => {
  const channel = { listen: vi.fn(() => channel) }
  return { channel, private: vi.fn(() => channel), leave: vi.fn(), connector: {} }
})
vi.mock('@/plugins/echo', () => ({ default: echo }))

const POLL = 120_000
const Page = { render: () => null }
let pinia
let i18n
let router
let realtime
const mounted = []

// The header with the console's socket subscription (App.vue), as on every page
const Console = defineComponent({
  setup() {
    realtime = useRealtimeNotifications()
    return () => h(AppHeader)
  },
})

async function mountHeader() {
  const w = mount(Console, { attachTo: document.body, global: { plugins: [pinia, i18n, router] } })
  mounted.push(w)
  await flushPromises()
  return w
}

function unmount(w) {
  mounted.splice(mounted.indexOf(w), 1)
  w.unmount()
}

const badge = (w) => w.find('[data-testid="notifications-count"]')

function signIn(id, name) {
  const auth = useAuthStore()
  auth.setTokenDirectly(`${id}|${name}`)
  auth.setUser({ id, name })
}

const row = (id, readAt = null) => ({
  id, user_id: 1, type: 'model_service.deploy_failed', level: 'warning', title: `${id}: deploy failed`,
  body: null, data: {}, read_at: readAt, created_at: '2026-11-04T09:00:00Z',
})

beforeEach(async () => {
  localStorage.setItem('locale', 'en')
  localStorage.setItem('token', '1|ada')
  localStorage.setItem('user', JSON.stringify({ id: 1, name: 'ada' }))
  pinia = freshPinia()
  i18n = makeI18n()
  router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: Page }] })
  await router.push('/projects')
  moduleRegistry.viewKey = 0
  setWsConnected(false)
  for (const fn of Object.values(notificationsApi)) fn.mockReset().mockResolvedValue({ data: {} })
})

afterEach(() => {
  mounted.splice(0).forEach(w => w.unmount())
  vi.useRealTimers()
  document.body.innerHTML = ''
})

describe('the notification badge', () => {
  it('shows the member\'s unread count right after the load with the socket up, and polls nothing', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    setWsConnected(true)
    notificationsApi.unreadCount.mockResolvedValue({ data: { count: 533 } })

    const w = await mountHeader()
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(1)
    expect(badge(w).text()).toBe('99+')

    await vi.advanceTimersByTimeAsync(5 * POLL)
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(1)

    // The next page mounts a header of its own: the count stays, nothing is asked again
    unmount(w)
    const next = await mountHeader()
    expect(badge(next).text()).toBe('99+')
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(1)

    // Another organization (a tenant switch re-mounts the views): its count
    notificationsApi.unreadCount.mockResolvedValue({ data: { count: 4 } })
    moduleRegistry.viewKey++
    await flushPromises()
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(2)
    expect(badge(next).text()).toBe('4')
  })

  it('shows it right after the load with the socket down, then polls every 2 minutes until the socket is back', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    notificationsApi.unreadCount.mockResolvedValueOnce({ data: { count: 7 } }).mockResolvedValueOnce({ data: { count: 9 } })

    const w = await mountHeader()
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(1)
    expect(badge(w).text()).toBe('7')

    await vi.advanceTimersByTimeAsync(POLL)
    await flushPromises()
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(2)
    expect(badge(w).text()).toBe('9')

    setWsConnected(true)
    await vi.advanceTimersByTimeAsync(3 * POLL)
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(2)
  })

  it('counts a notification the socket brings', async () => {
    notificationsApi.unreadCount.mockResolvedValue({ data: { count: 3 } })
    const w = await mountHeader()
    await realtime.subscribe()
    expect(echo.private.mock.calls.map(c => c[0])).toContain('notifications.1')

    // NotificationCreated::broadcastWith(): the notification's fields at the top level
    const onCreated = echo.channel.listen.mock.calls.find(c => c[0] === 'NotificationCreated')[1]
    onCreated(row('n4'))
    await flushPromises()
    expect(badge(w).text()).toBe('4')
    onCreated(row('n5', '2026-11-04T09:01:00Z'))
    await flushPromises()
    expect(badge(w).text()).toBe('4')
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(1)
  })

  it('counts down when the member reads or deletes notifications in the panel', async () => {
    notificationsApi.unreadCount.mockResolvedValue({ data: { count: 12 } })
    notificationsApi.list.mockResolvedValue({ data: { data: [row('a'), row('b'), row('c', '2026-11-03T09:00:00Z')] } })
    const w = await mountHeader()
    await w.get('[data-testid="notifications-button"]').trigger('click')
    await flushPromises()
    const items = () => w.findAll('[data-testid="notification-item"]')
    expect(items()).toHaveLength(3)

    await items()[0].trigger('click')   // read
    await flushPromises()
    expect(notificationsApi.markRead).toHaveBeenCalledWith('a')
    expect(badge(w).text()).toBe('11')

    await items()[1].get('[data-testid="notification-delete"]').trigger('click')   // an unread one
    await flushPromises()
    expect(notificationsApi.remove).toHaveBeenCalledWith('b')
    expect(badge(w).text()).toBe('10')

    await items()[1].get('[data-testid="notification-delete"]').trigger('click')   // a read one
    await flushPromises()
    expect(badge(w).text()).toBe('10')

    // Every row the panel lists is read, older ones are not: "mark all as read" stays
    await w.get('[data-testid="notifications-mark-all-read"]').trigger('click')
    await flushPromises()
    expect(notificationsApi.markAllRead).toHaveBeenCalledTimes(1)
    expect(badge(w).exists()).toBe(false)
    expect(w.find('[data-testid="notifications-mark-all-read"]').exists()).toBe(false)
  })

  it('shows nothing once all are deleted', async () => {
    notificationsApi.unreadCount.mockResolvedValue({ data: { count: 5 } })
    notificationsApi.list.mockResolvedValue({ data: { data: [row('a')] } })
    const w = await mountHeader()
    await w.get('[data-testid="notifications-button"]').trigger('click')
    await flushPromises()

    await w.get('[data-testid="notifications-delete-all"]').trigger('click')
    await flushPromises()
    expect(notificationsApi.removeAll).toHaveBeenCalledTimes(1)
    expect(badge(w).exists()).toBe(false)
  })

  it('shows the next member\'s count after a sign-out and another sign-in, never the last one\'s', async () => {
    notificationsApi.unreadCount.mockResolvedValueOnce({ data: { count: 533 } })
    const w = await mountHeader()
    expect(badge(w).text()).toBe('99+')

    // The user menu's sign-out: the session ends on this page, then the login page (no header)
    useAuthStore().forceLogout()
    await flushPromises()
    expect(badge(w).exists()).toBe(false)
    unmount(w)

    const gracesCount = deferred()
    notificationsApi.unreadCount.mockReturnValueOnce(gracesCount.promise)
    signIn(2, 'grace')
    const next = await mountHeader()
    expect(badge(next).exists()).toBe(false)   // not ada's 533 while grace's count loads
    gracesCount.resolve({ data: { count: 2 } })
    await flushPromises()
    expect(badge(next).text()).toBe('2')
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(2)
  })

  it('shows the next member\'s count when the header did not see the sign-out', async () => {
    notificationsApi.unreadCount.mockResolvedValueOnce({ data: { count: 533 } }).mockResolvedValueOnce({ data: { count: 0 } })
    const w = await mountHeader()
    expect(badge(w).text()).toBe('99+')
    unmount(w)

    signIn(2, 'grace')
    const next = await mountHeader()
    expect(notificationsApi.unreadCount).toHaveBeenCalledTimes(2)
    expect(badge(next).exists()).toBe(false)
  })
})
