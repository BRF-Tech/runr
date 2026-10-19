import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { withPlugins } from '../../helpers'

// Provider form: the redirect URL to register at an OAuth / OpenID Connect provider.
vi.mock('@/api/rbac', () => ({ rbacApi: { roles: vi.fn(() => Promise.resolve({ data: { roles: [] } })) } }))

import AuthProviderForm from '@/modules/auth-provider/components/AuthProviderForm.vue'
import en from '@/modules/auth-provider/locales/en'

function mountForm(props = {}) {
  const plugins = withPlugins('en')
  plugins.i18n.global.mergeLocaleMessage('en', en)
  return mount(AuthProviderForm, { props, global: plugins.global })
}

function callbackUrl(w) {
  return w.find('[data-testid="auth-provider-callback-url"] input').element.value
}

describe('AuthProviderForm redirect URL', () => {
  it('shows the driver’s callback URL for a new OpenID Connect provider', async () => {
    const w = mountForm({ initialData: { driver: 'oidc' } })
    await flushPromises()

    expect(callbackUrl(w)).toBe(`${window.location.origin}/oauth/oidc/callback`)
    expect(w.text()).toContain('Register this redirect (callback) URL')
  })

  it('shows the API’s URL for a saved provider', async () => {
    const w = mountForm({ editingId: '01P', initialData: { driver: 'google' }, callbackUrl: 'https://runr.example.test/oauth/google/callback' })
    await flushPromises()

    expect(callbackUrl(w)).toBe('https://runr.example.test/oauth/google/callback')
  })

  it('has none for SAML, which shows its service provider URLs instead', async () => {
    const w = mountForm({ initialData: { driver: 'saml' } })
    await flushPromises()

    expect(w.find('[data-testid="auth-provider-callback-url"]').exists()).toBe(false)
  })
})
