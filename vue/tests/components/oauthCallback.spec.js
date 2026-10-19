import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { withPlugins } from '../helpers'

// SSO callback (docs/security.md): the URL carries a one-time code, exchanged for the token. The
// console starts where the exchange says — after a sign-in from a tenant's sign-in page, in that
// tenant.
const api = vi.hoisted(() => ({ exchangeCode: vi.fn() }))
vi.mock('@/modules/auth-provider/api/authProviders', () => ({ default: api }))

const route = vi.hoisted(() => ({ query: {} }))
const router = vi.hoisted(() => ({ push: vi.fn(), replace: vi.fn() }))
vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRoute: () => route, useRouter: () => router }))

const registry = vi.hoisted(() => ({ moduleRegistry: { loginHooks: [] } }))
vi.mock('@/modules/index', () => registry)

const realtime = vi.hoisted(() => ({ setBroadcastChannelPrefix: vi.fn(), resubscribeBroadcast: vi.fn() }))
vi.mock('@/composables/useRealtimeNotifications', () => realtime)

import OAuthCallback from '@/modules/auth-provider/components/OAuthCallback.vue'
import tenancyPlugin from '@/modules/tenancy/plugin'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/modules/tenancy/stores/tenant'

const jane = { id: 1, name: 'Jane' }
const acme = { id: 't-acme', name: 'Acme', slug: 'acme', role: 'developer' }
const other = { id: 't-other', name: 'Other', slug: 'other', role: 'owner' }

function mountCallback() {
  return mount(OAuthCallback, { global: { ...withPlugins('en').global, stubs: { RouterLink: true } } })
}

/** The tenancy module's login hook, as the console registers it */
function withTenancyHook() {
  const tenancy = {
    headerSlots: [], appTopSlots: [], settingsTabs: [], registerSlots: {}, loginHooks: [],
    requestInterceptors: [], routes: [], localeMessages: [],
  }
  tenancyPlugin(tenancy)
  registry.moduleRegistry.loginHooks = tenancy.loginHooks
}

beforeEach(() => {
  localStorage.clear()
  route.query = {}
  registry.moduleRegistry.loginHooks = []
  api.exchangeCode.mockReset()
  router.push.mockReset()
  realtime.setBroadcastChannelPrefix.mockReset()
  globalThis.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve(jane) }))
})

afterEach(() => {
  delete globalThis.fetch
})

describe('OAuthCallback', () => {
  it('exchanges the one-time code for the token and signs in', async () => {
    route.query = { code: 'one-time-code' }
    api.exchangeCode.mockResolvedValue({ data: { token: '5|token-from-exchange', user_id: '1' } })

    mountCallback()
    await flushPromises()

    expect(api.exchangeCode).toHaveBeenCalledWith('one-time-code')
    expect(globalThis.fetch).toHaveBeenCalledWith('/api/auth/me', { headers: { Authorization: 'Bearer 5|token-from-exchange' } })
    expect(localStorage.getItem('token')).toBe('5|token-from-exchange')
    expect(useAuthStore().user).toEqual(jane)
    expect(router.push).toHaveBeenCalledWith('/projects')
  })

  it('never takes a token from the URL', async () => {
    route.query = { token: '5|from-the-url', user_id: '1' }

    const w = mountCallback()
    await flushPromises()

    expect(api.exchangeCode).not.toHaveBeenCalled()
    expect(localStorage.getItem('token')).toBeNull()
    expect(w.find('p.text-red-400').exists()).toBe(true)
  })

  it("shows the provider's error when the callback carries no code", async () => {
    route.query = { oauth_error: 'The sign-in was cancelled.' }

    const w = mountCallback()
    await flushPromises()

    expect(w.find('p.text-red-400').text()).toBe('The sign-in was cancelled.')
    expect(api.exchangeCode).not.toHaveBeenCalled()
  })

  it('shows the error of a used or expired code', async () => {
    route.query = { code: 'used' }
    api.exchangeCode.mockRejectedValue({ response: { data: { message: 'The sign-in link has expired or was already used. Sign in again.' } } })

    const w = mountCallback()
    await flushPromises()

    expect(w.find('p.text-red-400').text()).toContain('expired or was already used')
    expect(localStorage.getItem('token')).toBeNull()
    expect(router.push).not.toHaveBeenCalled()
  })

  it('hands the whole sign-in to the module hooks', async () => {
    route.query = { code: 'one-time-code' }
    api.exchangeCode.mockResolvedValue({ data: { token: '7|tok', user_id: '1', tenants: [acme, other], active_tenant: acme } })
    const hook = vi.fn()
    registry.moduleRegistry.loginHooks = [hook]

    mountCallback()
    await flushPromises()

    expect(hook).toHaveBeenCalledWith({ token: '7|tok', user_id: '1', tenants: [acme, other], active_tenant: acme, user: jane })
  })

  it("starts in the tenant whose sign-in page the sign-in came from, replacing an earlier session's", async () => {
    localStorage.setItem('active_tenant', JSON.stringify(other))
    route.query = { code: 'one-time-code' }
    api.exchangeCode.mockResolvedValue({ data: { token: '7|tok', user_id: '1', tenants: [acme, other], active_tenant: acme } })
    withTenancyHook()

    mountCallback()
    await flushPromises()

    const tenants = useTenantStore()
    expect(tenants.activeTenantId).toBe('t-acme')
    expect(tenants.tenants).toHaveLength(2)
    // The API client sends it as X-Tenant-ID from now on; broadcasts follow the tenant
    expect(JSON.parse(localStorage.getItem('active_tenant')).slug).toBe('acme')
    expect(realtime.setBroadcastChannelPrefix).toHaveBeenCalledWith('tenant.t-acme')
    expect(router.push).toHaveBeenCalledWith('/projects')
  })

  it('a platform administrator signing in on the platform page starts in the central context', async () => {
    localStorage.setItem('active_tenant', JSON.stringify(acme))
    route.query = { code: 'one-time-code' }
    api.exchangeCode.mockResolvedValue({ data: { token: '8|tok', user_id: '2', tenants: [acme], active_tenant: null } })
    withTenancyHook()

    mountCallback()
    await flushPromises()

    expect(useTenantStore().isCentralMode).toBe(true)
    expect(localStorage.getItem('active_tenant')).toBeNull()
  })

  it('runs the hooks even when the account could not be read or a hook fails', async () => {
    globalThis.fetch = vi.fn().mockRejectedValue(new Error('offline'))
    route.query = { code: 'one-time-code' }
    api.exchangeCode.mockResolvedValue({ data: { token: '7|tok', user_id: '1', tenants: [acme], active_tenant: acme } })
    const failing = vi.fn().mockRejectedValue(new Error('module down'))
    const hook = vi.fn()
    registry.moduleRegistry.loginHooks = [failing, hook]

    mountCallback()
    await flushPromises()

    expect(hook).toHaveBeenCalledWith(expect.objectContaining({ token: '7|tok', user: null, active_tenant: acme }))
    expect(router.push).toHaveBeenCalledWith('/projects')
  })
})
