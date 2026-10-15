import { describe, it, expect, vi, beforeEach } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { withPlugins } from '../helpers'

// Every broadcast channel is private (POST /api/broadcasting/auth). Signed out, that answers 401
// and the API client's 401 handling sends the browser to /login again — on the login page an
// endless reload. The console subscribes only with a session, and after sign-in without a reload.
const channel = vi.hoisted(() => ({ listen: vi.fn() }))
const echo = vi.hoisted(() => ({
  private: vi.fn(),
  leave: vi.fn(),
  connector: { pusher: { connection: { bind: vi.fn() } } },
}))
vi.mock('@/plugins/echo', () => ({ default: echo }))
vi.mock('@/api/version', () => ({ fetchVersion: vi.fn().mockResolvedValue(null) }))
// App.vue alone: no module slots (they need the router)
vi.mock('@/modules/index', () => ({ moduleRegistry: { appTopSlots: [], viewKey: 0 } }))

import App from '@/App.vue'
import { useAuthStore } from '@/stores/auth'

function mountApp() {
  const { global } = withPlugins()
  const Stub = defineComponent({ render: () => h('div') })
  return mount(App, { global: { ...global, stubs: { RouterView: Stub, ToastContainer: Stub, ConfirmContainer: Stub, MigrationWarningBar: Stub } } })
}

async function settle() {
  for (let i = 0; i < 5; i++) await nextTick()
  await new Promise((resolve) => setTimeout(resolve, 0))
}

beforeEach(() => {
  localStorage.clear()
  vi.clearAllMocks()
  channel.listen.mockReturnValue(channel)
  echo.private.mockReturnValue(channel)
})

describe('realtime subscriptions and the session', () => {
  it('subscribes to no private channel while signed out (login page)', async () => {
    const wrapper = mountApp()
    await settle()

    expect(echo.private).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('subscribes once the user signs in, without a page reload', async () => {
    const wrapper = mountApp()
    await settle()
    expect(echo.private).not.toHaveBeenCalled()

    useAuthStore().setTokenDirectly('1|token')
    await settle()

    expect(echo.private).toHaveBeenCalledWith('platform')
    expect(echo.private).toHaveBeenCalledWith('notifications')
    wrapper.unmount()
  })

  it('subscribes right away with a stored session', async () => {
    localStorage.setItem('token', '1|token')
    const wrapper = mountApp()
    await settle()

    expect(echo.private).toHaveBeenCalledWith('platform')
    wrapper.unmount()
  })
})
