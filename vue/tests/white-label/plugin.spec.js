import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import router from '@/router'
import plugin from '@/modules/white-label/plugin'
import { useBrandingStore } from '@/modules/white-label/stores/branding'
import { grant, makePinia, signIn } from './helpers'

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))
vi.mock('@/router', () => ({ default: { addRoute: vi.fn() } }))

const tenant = { id: 't1', slug: 'acme', name: 'Acme', role: 'owner' }

function install() {
  const registry = {
    appTopSlots: [], registerExtensions: [], settingsTabs: [], loginHooks: [], localeMessages: [],
  }
  plugin(registry)
  return registry
}

/** Keys of the settings tabs the current user sees. */
const visible = (registry) => registry.settingsTabs.filter(tab => !tab.visibleIf || tab.visibleIf()).map(tab => tab.key)

describe('white-label plugin', () => {
  beforeEach(() => {
    localStorage.clear()
    makePinia()
    vi.mocked(router.addRoute).mockClear()
  })
  afterEach(() => {
    vi.unstubAllEnvs()
  })

  it('registers the public verification route, the signup extension, locales and a login hook', () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'false')
    const registry = install()
    expect(router.addRoute).toHaveBeenCalledWith(expect.objectContaining({ path: '/signup/verify', name: 'signup-verify', meta: { public: true } }))
    expect(registry.registerExtensions).toHaveLength(1)
    expect(registry.loginHooks).toHaveLength(1)
    expect(registry.localeMessages[0]).toHaveProperty('en.whiteLabel')
    expect(registry.localeMessages[0]).toHaveProperty('tr.whiteLabel')
    // single-tenant: only the branding loader on top, no tenant tabs at all
    expect(registry.appTopSlots).toHaveLength(1)
    expect(registry.settingsTabs.map(t => t.key)).toEqual(['branding', 'signup'])
  })

  it('single-tenant: branding / signup tabs follow branding.manage / signup.manage', () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'false')
    const registry = install()
    signIn()

    grant(['branding.manage'])
    expect(visible(registry)).toEqual(['branding'])
    grant(['signup.manage'])
    expect(visible(registry)).toEqual(['signup'])
    grant([])
    expect(visible(registry)).toEqual([])
    grant([], { platformAdmin: true })
    expect(visible(registry)).toEqual(['branding', 'signup'])
  })

  it('multi-tenant central administrator: branding and signup (plans are gone)', () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'true')
    const registry = install()
    expect(registry.appTopSlots).toHaveLength(2) // branding loader, onboarding wizard
    signIn({ admin: true })
    grant([], { platformAdmin: true, edition: 'multi' })
    useBrandingStore().set({ features: { billing: true, tenant_branding: true, multi_tenant: true } })

    expect(visible(registry)).toEqual(['branding', 'signup'])
    expect(registry.settingsTabs.map(t => t.key)).not.toContain('plans')
    expect(registry.settingsTabs.map(t => t.key)).not.toContain('subscription')
  })

  it('multi-tenant tenant: organization branding needs branding.manage + tenant branding on', () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'true')
    const registry = install()
    signIn({ tenant })
    const branding = useBrandingStore()

    grant(['billing.view', 'branding.manage'], { edition: 'multi' })
    branding.set({ features: { tenant_branding: true, multi_tenant: true } })
    expect(visible(registry)).toEqual(['org-branding'])

    // tenant branding turned off by the platform
    branding.set({ features: { tenant_branding: false, multi_tenant: true } })
    expect(visible(registry)).toEqual([])

    // without branding.manage
    grant(['projects.view'], { edition: 'multi' })
    branding.set({ features: { tenant_branding: true, multi_tenant: true } })
    expect(visible(registry)).toEqual([])
  })
})
