import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import { withPlugins } from '../helpers'
import { useAuthStore } from '@/stores/auth'

// The broadcast channels are private: each subscription is authorized with the session token
// (POST /api/broadcasting/auth). Signed out there is nothing to join — on the login page the
// 401 of every authorization used to send the browser to /login again, in a loop.
const echo = vi.hoisted(() => {
  const channel = { listen: vi.fn(() => channel) }
  return { channel, private: vi.fn(() => channel), leave: vi.fn(), connector: {} }
})
vi.mock('@/plugins/echo', () => ({ default: echo }))
const inbox = vi.hoisted(() => vi.fn(() => Promise.resolve({ data: { data: [{ id: 'p1', can_approve: true }] } })))
vi.mock('@/api/environments', () => ({ environmentsApi: { inbox } }))

import { useRealtimeNotifications } from '@/composables/useRealtimeNotifications'

function setup() {
  const plugins = withPlugins()
  let api
  const wrapper = mount(defineComponent({
    setup() {
      api = useRealtimeNotifications()
      return () => h('div')
    },
  }), plugins)
  return { ...api, wrapper }
}

describe('useRealtimeNotifications', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.clearAllMocks()
  })

  afterEach(() => localStorage.clear())

  it('joins no channel while signed out', async () => {
    const { subscribe, wrapper } = setup()
    await subscribe()
    expect(echo.private).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('joins the private channels of the signed-in user and leaves them again', async () => {
    const { subscribe, unsubscribe, wrapper } = setup()
    const auth = useAuthStore()
    auth.setTokenDirectly('1|token')
    auth.setUser({ id: 7 })

    await subscribe()
    expect(echo.private.mock.calls.map(c => c[0])).toEqual(['notifications', 'notifications.7', 'platform'])

    unsubscribe()
    expect(echo.leave.mock.calls.map(c => c[0]).sort()).toEqual(['notifications', 'notifications.7', 'platform'])
    wrapper.unmount()
  })

  // The sign-in watcher and the tenancy login hook both subscribe while the socket client still
  // loads: one set of listeners, or every notification shows twice
  it('listens once when a second subscribe starts while the first waits for the client', async () => {
    const { subscribe, unsubscribe, wrapper } = setup()
    const auth = useAuthStore()
    auth.setTokenDirectly('1|token')
    auth.setUser({ id: 7 })

    await Promise.all([subscribe(), subscribe()])
    expect(echo.private).toHaveBeenCalledTimes(3)

    unsubscribe()
    wrapper.unmount()
  })

  it('a resubscribe while the client loads joins one set of channels', async () => {
    const { subscribe, unsubscribe, wrapper } = setup()
    const auth = useAuthStore()
    auth.setTokenDirectly('1|token')
    auth.setUser({ id: 7 })

    const first = subscribe()
    unsubscribe()            // resubscribeBroadcast(): unsubscribe, then subscribe
    const second = subscribe()
    await Promise.all([first, second])
    expect(echo.private).toHaveBeenCalledTimes(3)

    unsubscribe()
    wrapper.unmount()
  })

  // docs/environments.md#notifications: the header's approvals count follows a promotion that waits
  // for the user (a notification) and one decided or ended anywhere (the environment.promotion broadcast)
  it('refreshes the approvals count when a promotion changes', async () => {
    const { subscribe, unsubscribe, wrapper } = setup()
    const auth = useAuthStore()
    auth.setTokenDirectly('1|token')
    auth.setUser({ id: 7 })
    await subscribe()

    const handler = (name) => echo.channel.listen.mock.calls.find(c => c[0] === name)[1]
    handler('NotificationCreated')({ notification: { type: 'deployment.ready', data: { project_name: 'shop' } } })
    expect(inbox).not.toHaveBeenCalled()

    handler('NotificationCreated')({ notification: { type: 'environment.promotion.pending_approval', level: 'info', data: { project_name: 'shop', version: 2, target_environment: 'production' } } })
    expect(inbox).toHaveBeenCalledTimes(1)
    expect(inbox).toHaveBeenCalledWith({ status: 'pending_approval', limit: 100 })

    handler('.platform.event')({ topic: 'environment.promotion', payload: { promotion_id: 'p1', status: 'deployed' } })
    expect(inbox).toHaveBeenCalledTimes(2)

    unsubscribe()
    wrapper.unmount()
  })
})
