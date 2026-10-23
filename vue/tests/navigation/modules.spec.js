import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { buildNavigation } from '@/navigation/menu'
import { navigationContext } from '@/navigation/useNavigation'
import { usePermissionsStore } from '@/stores/permissions'
import { useSettingsStore } from '@/stores/settings'
import { useBrandingStore } from '@/modules/white-label/stores/branding'
import audit from '@/modules/audit/plugin'
import authProvider from '@/modules/auth-provider/plugin'
import billing from '@/modules/billing/plugin'
import bindings from '@/modules/bindings/plugin'
import gitDeploy from '@/modules/git-deploy/plugin'
import images from '@/modules/images/plugin'
import mcp from '@/modules/mcp/plugin'
import observability from '@/modules/observability/plugin'
import rbac from '@/modules/rbac/plugin'
import sandboxes from '@/modules/sandboxes/plugin'
import supplyChain from '@/modules/supply-chain/plugin'
import tenancy from '@/modules/tenancy/plugin'
import ticket from '@/modules/ticket/plugin'
import whiteLabel from '@/modules/white-label/plugin'
import workflows from '@/modules/workflows/plugin'
import apiProduct from '@/modules/api-product/plugin'
import orgTransfer from '@/modules/org-transfer/plugin'
import secretStores from '@/modules/secret-stores/plugin'
import triggers from '@/modules/triggers/plugin'

// The main menu with the real module plugins, per role and edition: every module entry lands
// in its declared group and section, and each user sees only what their permissions allow.

vi.mock('@/router', () => ({ default: { addRoute: vi.fn() } }))
vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), interceptors: { request: { use: vi.fn() }, response: { use: vi.fn() } } },
}))

const CORE_PLUGINS = [apiProduct, audit, bindings, gitDeploy, images, mcp, observability, orgTransfer, rbac, sandboxes, secretStores, supplyChain, triggers, whiteLabel, workflows]
const OPTIONAL_PLUGINS = [authProvider, ticket, billing]

function registry() {
  return {
    headerSlots: [], appTopSlots: [], settingsTabs: [], navLinks: [], accountSections: [], projectTabs: [],
    regionTabs: [], regionCreateExtensions: [], projectsActions: [], loginHooks: [], requestInterceptors: [],
    localeMessages: [], loginSlots: {}, registerSlots: {}, registerExtensions: [], routes: [], viewKey: 0,
  }
}

/** Installs the plugins the way modules/index.js does (tenancy only in the multi-tenant build). */
function install({ multiTenant = false } = {}) {
  const r = registry()
  for (const plugin of [...CORE_PLUGINS, ...OPTIONAL_PLUGINS, ...(multiTenant ? [tenancy] : [])]) plugin(r)
  return r
}

function navFor(r, { runtimes = { loaded: false } } = {}) {
  return buildNavigation({
    tabs: r.settingsTabs,
    pages: r.navLinks,
    ctx: navigationContext({ permissions: usePermissionsStore(), runtimes, registry: r }),
  })
}

/** { group: { section: [link keys] } } */
function tree(nav) {
  return Object.fromEntries(nav.groups.map(group => [
    group.key,
    Object.fromEntries(group.sections.map(section => [section.key, section.links.map(link => link.key)])),
  ]))
}
const accountKeys = (nav) => nav.account.map(link => link.key)

function signIn({ admin = false, tenant = null } = {}) {
  localStorage.setItem('token', 'test-token')
  localStorage.setItem('user', JSON.stringify({ id: 'u1', email: 'me@example.test', is_admin: admin }))
  if (tenant) localStorage.setItem('active_tenant', JSON.stringify(tenant))
  else localStorage.removeItem('active_tenant')
}

function grant(permissions, { platformAdmin = false, edition = 'single' } = {}) {
  usePermissionsStore().apply({ permissions, platform_admin: platformAdmin, edition, role: null, roles: [], projects: {} })
}

function features(extra = {}) {
  useBrandingStore().set({ features: { billing: true, tickets: true, multi_tenant: false, ...extra } })
}

// The built-in roles of docs/permissions.md (single-tenant edition)
const VIEWER = ['projects.view', 'git.pull', 'logs.view', 'metrics.view', 'traces.view', 'workflows.view', 'sandboxes.view', 'security.view', 'regions.view', 'images.view', 'models.view', 'bindings.view', 'mcp.servers.call', 'members.view', 'tokens.manage', 'tickets.view', 'tickets.create']
const DEVELOPER = [...VIEWER, 'projects.create', 'projects.update', 'projects.deploy', 'code.edit', 'env.edit', 'sandboxes.create', 'sandboxes.exec', 'runtimes.use_custom', 'images.upload', 'models.deploy', 'bindings.manage', 'mcp.servers.manage']
const BILLING = ['billing.view', 'billing.manage', 'billing.approve', 'projects.stop', 'regions.view', 'bindings.view', 'members.view', 'tokens.manage', 'tickets.view', 'tickets.create']
const OWNER = [...new Set([...DEVELOPER, 'alerts.manage', 'status_page.manage', 'roles.manage', 'settings.manage', 'branding.manage', 'signup.manage', 'sso.manage', 'audit.view', 'git_providers.manage', 'runtimes.manage', 'billing.view', 'billing.manage'])]

describe('main menu with the module plugins', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.unstubAllEnvs()
    vi.stubEnv('VITE_TENANCY_ENABLED', 'false')
  })
  afterEach(() => vi.unstubAllEnvs())

  it('single-tenant owner: every page, grouped', () => {
    const r = install()
    signIn()
    features()
    grant(OWNER)
    const nav = navFor(r)
    expect(tree(nav)).toEqual({
      build: {
        workloads: ['sandboxes-page', 'sandboxes', 'models', 'gateways'],
        runtimes: ['runtimes', 'images'],
        automation: ['events', 'topics'],
      },
      data: { data: ['data'], integrations: ['git-providers', 'ai-agents'] },
      observability: { monitoring: ['alerts', 'tracing', 'status-page', 'rightsizing'] },
      admin: {
        members: ['users', 'roles', 'groups'],
        access: ['tokens', 'auth-providers', 'audit-log'],
        organization: ['billing'],
        platform: ['platform-settings', 'branding', 'signup'],
      },
    })
    expect(accountKeys(nav)).toEqual(['account', 'my-bills', 'support-tickets'])
  })

  it('every module settings tab declares a known group (none falls into More)', () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'true')
    const r = install({ multiTenant: true })
    for (const tab of r.settingsTabs) {
      expect(['build', 'data', 'observability', 'admin', 'account'], tab.key).toContain(tab.group)
      expect(tab.descKey, tab.key).toBeTruthy()
      expect(tab.icon, tab.key).toBeTruthy()
    }
    for (const page of r.navLinks) expect(page.group, page.key).toBeTruthy()
  })

  it('single-tenant viewer: read-only pages, no administration of the platform but its regions', () => {
    const r = install()
    signIn()
    features()
    grant(VIEWER)
    const nav = navFor(r, { runtimes: { loaded: true, canUseCustom: false } })
    expect(tree(nav)).toEqual({
      build: { workloads: ['sandboxes-page', 'sandboxes', 'models', 'gateways'], runtimes: ['images'], automation: ['events', 'topics'] },
      data: { data: ['data'], integrations: ['ai-agents'] },
      observability: { monitoring: ['tracing', 'rightsizing'] },
      admin: { members: ['users', 'roles', 'groups'], access: ['tokens'], platform: ['platform-settings'] },
    })
    expect(accountKeys(nav)).toEqual(['account', 'my-bills', 'support-tickets'])
  })

  it('developer: Runtimes follows the catalog abilities (custom runtimes allowed or not)', () => {
    const r = install()
    signIn()
    features()
    grant(DEVELOPER)
    expect(tree(navFor(r)).build.runtimes).toEqual(['runtimes', 'images'])
    expect(tree(navFor(r, { runtimes: { loaded: true, canUseCustom: false } })).build.runtimes).toEqual(['images'])
  })

  it('billing role: billing and the members it may see', () => {
    const r = install()
    signIn()
    features()
    grant(BILLING)
    expect(tree(navFor(r))).toEqual({
      data: { data: ['data'], integrations: ['ai-agents'] },
      observability: { monitoring: ['tracing'] },
      admin: { members: ['users', 'roles', 'groups'], access: ['tokens'], organization: ['billing'], platform: ['platform-settings'] },
    })
  })

  it('a member without permissions keeps the user menu; empty groups disappear', () => {
    const r = install()
    signIn()
    features({ billing: false, tickets: false })
    grant(['projects.view'])
    const nav = navFor(r, { runtimes: { loaded: true, canUseCustom: false } })
    // AI agents (MCP) is for everyone (connection instructions); with projects.view the API
    // gateways and the organization's topics; nothing else is left
    expect(tree(nav)).toEqual({ build: { workloads: ['gateways'], automation: ['topics'] }, data: { integrations: ['ai-agents'] } })
    expect(accountKeys(nav)).toEqual(['account'])
  })

  it('billing off in the API: no Billing and no My bills', () => {
    const r = install()
    signIn()
    features({ billing: false })
    grant(OWNER)
    const nav = navFor(r)
    expect(tree(nav).admin.organization).toBeUndefined()
    expect(accountKeys(nav)).toEqual(['account', 'support-tickets'])
  })

  describe('multi-tenant edition', () => {
    beforeEach(() => {
      vi.stubEnv('VITE_TENANCY_ENABLED', 'true')
    })

    it('platform administrator in the central context: the platform, its accounts and tenants', () => {
      const r = install({ multiTenant: true })
      useSettingsStore().update({ tenancy_enabled: true })
      signIn({ admin: true })
      features({ multi_tenant: true })
      grant([], { platformAdmin: true, edition: 'multi' })
      const nav = navFor(r)
      expect(tree(nav).admin).toEqual({
        members: ['users'],
        access: ['tokens', 'auth-providers', 'audit-log', 'secret-stores'],
        organization: ['billing'],
        platform: ['platform-settings', 'tenants', 'resource-usage', 'branding', 'signup'],
      })
      // the accounts of the platform are the tenancy module's page
      expect(nav.links.find(link => link.tab === 'users').source).toBe('module')
    })

    it('tenant owner: the organization\'s pages, no platform administration', () => {
      const r = install({ multiTenant: true })
      useSettingsStore().update({ tenancy_enabled: true })
      signIn({ tenant: { id: 't1', slug: 'acme' } })
      features({ multi_tenant: true, tenant_branding: true })
      grant(OWNER.filter(p => !['git_providers.manage', 'runtimes.manage', 'signup.manage'].includes(p)), { edition: 'multi' })
      const nav = navFor(r)
      expect(tree(nav).admin).toEqual({
        members: ['users', 'roles', 'groups'],
        access: ['tokens', 'auth-providers', 'audit-log'],
        organization: ['org-settings', 'org-branding', 'billing'],
      })
      expect(tree(nav).data.integrations).toEqual(['ai-agents'])
    })

    it('tenant viewer: the organization\'s regions and members', () => {
      const r = install({ multiTenant: true })
      useSettingsStore().update({ tenancy_enabled: true })
      signIn({ tenant: { id: 't1', slug: 'acme' } })
      features({ multi_tenant: true })
      grant(VIEWER, { edition: 'multi' })
      const nav = navFor(r, { runtimes: { loaded: true, canUseCustom: false } })
      expect(tree(nav).admin).toEqual({
        members: ['users', 'roles', 'groups'],
        access: ['tokens'],
        organization: ['org-settings'],
      })
    })
  })
})
