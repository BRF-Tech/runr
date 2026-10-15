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
})
