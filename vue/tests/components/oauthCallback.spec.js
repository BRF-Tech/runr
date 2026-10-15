import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { withPlugins } from '../helpers'

// SSO callback (docs/security.md): the URL carries a one-time code, exchanged for the token.
const api = vi.hoisted(() => ({ exchangeCode: vi.fn() }))
vi.mock('@/modules/auth-provider/api/authProviders', () => ({ default: api }))

const route = vi.hoisted(() => ({ query: {} }))
const router = vi.hoisted(() => ({ push: vi.fn(), replace: vi.fn() }))
vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRoute: () => route, useRouter: () => router }))

import OAuthCallback from '@/modules/auth-provider/components/OAuthCallback.vue'

function mountCallback() {
  return mount(OAuthCallback, { global: { ...withPlugins('en').global, stubs: { RouterLink: true } } })
}

beforeEach(() => {
  route.query = {}
  globalThis.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ id: 1, name: 'Jane' }) }))
})

describe('OAuthCallback', () => {
  it('exchanges the one-time code for the token and signs in', async () => {
    route.query = { code: 'one-time-code' }
    api.exchangeCode.mockResolvedValue({ data: { token: '5|token-from-exchange', user_id: '1' } })

    mountCallback()
    await flushPromises()

    expect(api.exchangeCode).toHaveBeenCalledWith('one-time-code')
    expect(localStorage.getItem('token')).toBe('5|token-from-exchange')
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

  it('shows the error of a used or expired code', async () => {
    route.query = { code: 'used' }
    api.exchangeCode.mockRejectedValue({ response: { data: { message: 'The sign-in link has expired or was already used. Sign in again.' } } })

    const w = mountCallback()
    await flushPromises()

    expect(w.find('p.text-red-400').text()).toContain('expired or was already used')
    expect(router.push).not.toHaveBeenCalled()
  })
})
