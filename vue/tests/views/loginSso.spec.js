import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { h } from 'vue'
import { withPlugins } from '../helpers'

// Sign-in page, SSO buttons: SAML starts at its service provider endpoint
// (/api/saml/{id}/login), OAuth / OIDC at /api/oauth/{driver}/redirect naming the provider. The
// page asks for them only with the AuthProvider module in the build and on in the API.
const client = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@/api/client', () => ({ default: client }))
vi.mock('@/api/auth', () => ({ authApi: { registrationStatus: vi.fn(() => Promise.resolve({ data: { enabled: false } })) } }))
vi.mock('@/components/RegisterForm.vue', () => ({ default: { name: 'RegisterForm', render: () => h('div') } }))
vi.mock('@/components/RunrWordmark.vue', () => ({ default: { name: 'RunrWordmark', render: () => h('div') } }))

const route = vi.hoisted(() => ({ query: {} }))
const router = vi.hoisted(() => ({ push: vi.fn(), replace: vi.fn() }))
vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRoute: () => route, useRouter: () => router }))

import LoginView from '@/views/LoginView.vue'
import { normalizeBranding, useBrandingStore } from '@/modules/white-label/stores/branding'

const providers = [
  { id: '01SAML', name: 'Corporate SSO', driver: 'saml', display_order: 0 },
  { id: '01OIDC', name: 'Corporate IdP', driver: 'oidc', display_order: 1 },
  { id: '01OIDC2', name: 'Partner IdP', driver: 'oidc', display_order: 2 },
]

let location
const originalLocation = window.location

/** The sign-in page; `sso`: the API reports the AuthProvider module on (GET /api/branding). */
function mountLogin({ sso = true } = {}) {
  const { global } = withPlugins('en')
  useBrandingStore().data = normalizeBranding({ features: { auth_providers: sso } })
  return mount(LoginView, { global })
}

const discoverCalls = () => client.get.mock.calls.filter(([url]) => url === '/auth-providers/discover')

async function clickProvider(w, name) {
  const button = w.findAll('[data-testid="sso-provider"]').find(b => b.text().includes(name))
  await button.trigger('click')
  await flushPromises()
}

beforeEach(() => {
  vi.stubEnv('VITE_AUTH_PROVIDER_ENABLED', 'true')
  route.query = {}
  location = { href: '' }
  Object.defineProperty(window, 'location', { configurable: true, value: location })
  client.get.mockImplementation((url) => {
    if (url === '/auth-providers/discover') return Promise.resolve({ data: providers })
    return Promise.resolve({ data: { redirect_url: `https://idp.example.test${url}` } })
  })
})

afterEach(() => {
  vi.unstubAllEnvs()
  Object.defineProperty(window, 'location', { configurable: true, value: originalLocation })
})

describe('LoginView SSO buttons', () => {
  it('starts a SAML sign-in at the provider’s SAML login endpoint', async () => {
    const w = mountLogin()
    await flushPromises()

    await clickProvider(w, 'Corporate SSO')

    expect(client.get).toHaveBeenCalledWith('/saml/01SAML/login', { params: {} })
    expect(client.get).not.toHaveBeenCalledWith('/oauth/saml/redirect', expect.anything())
    expect(location.href).toBe('https://idp.example.test/saml/01SAML/login')
  })

  it('names the chosen one of several OIDC providers', async () => {
    const w = mountLogin()
    await flushPromises()

    await clickProvider(w, 'Partner IdP')

    expect(client.get).toHaveBeenCalledWith('/oauth/oidc/redirect', { params: { provider: '01OIDC2' } })
    expect(location.href).toBe('https://idp.example.test/oauth/oidc/redirect')
  })

  it('shows the API’s reason when a sign-in cannot start', async () => {
    client.get.mockImplementation((url) => url === '/auth-providers/discover'
      ? Promise.resolve({ data: providers })
      : Promise.reject({ response: { data: { message: 'Provider not found or disabled.' } } }))
    const w = mountLogin()
    await flushPromises()

    await clickProvider(w, 'Corporate IdP')

    expect(w.text()).toContain('Provider not found or disabled.')
    expect(location.href).toBe('')
  })
})

// Without the module the discover route does not exist: asking would answer 404 on every visit
describe('LoginView without the AuthProvider module', () => {
  it('does not ask for SSO providers while the API runs without the module', async () => {
    const w = mountLogin({ sso: false })
    await flushPromises()

    expect(discoverCalls()).toHaveLength(0)
    expect(w.findAll('[data-testid="sso-provider"]')).toHaveLength(0)
  })

  it('nor in a console built without the module’s screens', async () => {
    vi.stubEnv('VITE_AUTH_PROVIDER_ENABLED', 'false')
    mountLogin({ sso: true })
    await flushPromises()

    expect(discoverCalls()).toHaveLength(0)
  })

  it('asks once the branding reports the module on', async () => {
    const w = mountLogin({ sso: false })
    await flushPromises()
    expect(discoverCalls()).toHaveLength(0)

    useBrandingStore().data = normalizeBranding({ features: { auth_providers: true } })
    await flushPromises()

    expect(discoverCalls()).toEqual([['/auth-providers/discover', { params: {} }]])
    expect(w.findAll('[data-testid="sso-provider"]')).toHaveLength(3)
  })
})
