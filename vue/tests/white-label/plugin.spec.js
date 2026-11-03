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
    expect(registry.settingsTabs.map(t => t.key)).toEqual(['branding', 'signup', 'bot-protection'])
    // the bot protection page tours itself
    expect(registry.settingsTabs.find(t => t.key === 'bot-protection')).toMatchObject({ group: 'admin', section: 'access', dataTour: 'settings-bot-protection-tab', tutorial: true })
  })

  it('single-tenant: branding / signup / bot protection tabs follow branding.manage / signup.manage / settings.manage', () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'false')
    const registry = install()
    signIn()

    grant(['branding.manage'])
    expect(visible(registry)).toEqual(['branding'])
    grant(['signup.manage'])
    expect(visible(registry)).toEqual(['signup'])
    grant(['settings.manage'])
    expect(visible(registry)).toEqual(['bot-protection'])
    grant([])
    expect(visible(registry)).toEqual([])
    grant([], { platformAdmin: true })
    expect(visible(registry)).toEqual(['branding', 'signup', 'bot-protection'])
  })

  it('multi-tenant central administrator: branding, signup and the bot protection (plans are gone)', () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'true')
    const registry = install()
    expect(registry.appTopSlots).toHaveLength(2) // branding loader, onboarding wizard
    signIn({ admin: true })
    grant([], { platformAdmin: true, edition: 'multi' })
    useBrandingStore().set({ features: { billing: true, tenant_branding: true, multi_tenant: true } })

    expect(visible(registry)).toEqual(['branding', 'signup', 'bot-protection'])
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

    // settings.manage in the tenant: its own bot protection keys
    grant(['settings.manage'], { edition: 'multi' })
    expect(visible(registry)).toEqual(['bot-protection'])
  })
})
