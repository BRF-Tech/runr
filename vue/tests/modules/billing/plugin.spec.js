import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import client from '@/api/client'
import plugin, { canSeeProjectBilling } from '@/modules/billing/plugin'
import ticketPlugin, { ticketsEnabled } from '@/modules/ticket/plugin'
import { useBrandingStore } from '@/modules/white-label/stores/branding'
import { billingOn, grant, makePinia, ok, signIn } from './helpers'

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

function install() {
  const registry = { settingsTabs: [], projectTabs: [], regionTabs: [], regionCreateExtensions: [], localeMessages: [] }
  plugin(registry)
  return registry
}

const visibleTabs = (registry) => registry.settingsTabs.filter(tab => !tab.visibleIf || tab.visibleIf()).map(tab => tab.key)
const project = (over = {}) => ({ id: 'p1', service_type: 'faas', owner_id: 'u9', ...over })

describe('billing plugin', () => {
  beforeEach(() => {
    localStorage.clear()
    makePinia()
    vi.mocked(client.put).mockReset()
  })
  afterEach(() => vi.unstubAllEnvs())

  it('registers the settings tabs, the project tab, the region hooks and the locales', () => {
    const registry = install()
    expect(registry.settingsTabs.map(t => t.key)).toEqual(['billing', 'my-bills'])
    expect(registry.projectTabs.map(t => t.id)).toEqual(['billing'])
    expect(registry.regionTabs.map(t => t.key)).toEqual(['billing'])
    expect(registry.regionCreateExtensions.map(e => e.key)).toEqual(['billing'])
    expect(registry.regionCreateExtensions[0].initial()).toEqual({ billing_type: 'payg' })
    expect(registry.localeMessages[0]).toHaveProperty('en.billing')
    expect(registry.localeMessages[0]).toHaveProperty('tr.billing')
  })

  it('hides every page while the API reports billing off', () => {
    const registry = install()
    signIn()
    grant(['billing.view', 'billing.manage'])
    billingOn(false)
    expect(visibleTabs(registry)).toEqual([])
    expect(registry.regionTabs[0].visibleIf()).toBe(false)
    expect(canSeeProjectBilling(project({ owner_id: 'u1' }))).toBe(false)
  })

  it('billing.view opens the Billing tab, everyone signed in sees their own bills', () => {
    const registry = install()
    signIn()
    billingOn(true)
    grant(['projects.view'])
    expect(visibleTabs(registry)).toEqual(['my-bills'])
    grant(['billing.view'])
    expect(visibleTabs(registry)).toEqual(['billing', 'my-bills'])
    expect(registry.regionTabs[0].visibleIf()).toBe(true)
  })

  it('on the platform only administrators see the Billing tab', () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'true')
    const registry = install()
    billingOn(true, { multi_tenant: true })
    signIn({ admin: false })
    grant(['billing.view'], { edition: 'multi' })
    expect(visibleTabs(registry)).toEqual(['my-bills'])
    signIn({ admin: true })
    grant([], { platformAdmin: true, edition: 'multi' })
    expect(visibleTabs(registry)).toEqual(['billing', 'my-bills'])
    // inside a tenant the tenant's billing.view counts
    signIn({ admin: false, tenant: { id: 't1', slug: 'acme' } })
    grant(['billing.view'], { edition: 'multi' })
    expect(visibleTabs(registry)).toEqual(['billing', 'my-bills'])
  })

  it('the project tab is for the owner, projects.billing on the project and billing.view', () => {
    install()
    signIn({ id: 'u1' })
    billingOn(true)
    grant(['projects.view'])
    expect(canSeeProjectBilling(project())).toBe(false)
    expect(canSeeProjectBilling(project({ owner_id: 'u1' }))).toBe(true)
    grant(['projects.view'], { projects: { p1: ['projects.billing'] } })
    expect(canSeeProjectBilling(project())).toBe(true)
    grant(['billing.view'])
    expect(canSeeProjectBilling(project())).toBe(true)
    // retired AI types are not billed as projects
    expect(canSeeProjectBilling(project({ service_type: 'maas' }))).toBe(false)
  })

  it('saves the billing type chosen in the region wizard once the region exists', async () => {
    const registry = install()
    const ext = registry.regionCreateExtensions[0]
    signIn()
    billingOn(true)
    grant(['billing.manage'])
    vi.mocked(client.put).mockReturnValue(ok({ data: { id: 'r9', billing_type: 'fixed' } }))

    await ext.afterCreate({ id: 'r9' }, { billing_type: 'fixed' })
    expect(client.put).toHaveBeenCalledWith('/billing/regions/r9', { billing_type: 'fixed' })

    await ext.afterCreate({ id: 'r9' }, null)
    expect(client.put).toHaveBeenLastCalledWith('/billing/regions/r9', { billing_type: 'payg' })

    // without billing.manage nothing is sent
    vi.mocked(client.put).mockClear()
    grant(['billing.view'])
    await ext.afterCreate({ id: 'r9' }, { billing_type: 'fixed' })
    expect(client.put).not.toHaveBeenCalled()
  })
})

describe('ticket plugin: runtime hiding', () => {
  beforeEach(() => {
    localStorage.clear()
    makePinia()
  })

  it('shows the support tab unless the API reports the Ticket module off', () => {
    const registry = { settingsTabs: [], localeMessages: [] }
    ticketPlugin(registry)
    const tab = registry.settingsTabs[0]
    expect(tab.key).toBe('support-tickets')
    // an API without the flag (before 0.5.8) always had tickets
    useBrandingStore().set({ features: {} })
    expect(tab.visibleIf()).toBe(true)
    useBrandingStore().set({ features: { tickets: false } })
    expect(tab.visibleIf()).toBe(false)
    expect(ticketsEnabled()).toBe(false)
    useBrandingStore().set({ features: { tickets: true } })
    expect(ticketsEnabled()).toBe(true)
  })
})
