import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { h, markRaw } from 'vue'
import { ShieldCheck, UsersRound } from 'lucide-vue-next'
import SettingsView from '@/views/SettingsView.vue'
import { moduleRegistry } from '@/modules/index'
import { usePermissionsStore } from '@/stores/permissions'
import { freshPinia, makeI18n } from '@/test-support/helpers'

// The settings page under the new navigation: its title is the menu group, the tabs above the
// content are the pages of the same menu section, and a page shows when the menu shows its link.

vi.mock('@/modules/index', async () => {
  const { reactive } = await import('vue')
  return { moduleRegistry: reactive({ settingsTabs: [], navLinks: [], headerSlots: [], viewKey: 0 }) }
})
vi.mock('@/api/runtimes', () => ({ runtimesApi: { list: vi.fn(() => new Promise(() => {})) } }))
vi.mock('@/composables/useSettingsPage', async () => {
  const { ref: r } = await import('vue')
  return {
    useSettingsPage: () => ({
      baseDomain: r(''), registrationEnabled: r(true), gpuDemoEnabled: r(false), customRuntimesEnabled: r(false),
      totalCpuM: r(0), totalMemoryMb: r(0), regions: r([]), demoStatus: r('idle'),
      loading: r(false), saving: r(false), error: r(null), saved: r(false), usedCpuM: r(0), usedMemoryMb: r(0),
      submit: vi.fn(), openAdd: vi.fn(), openEdit: vi.fn(), closeRegionDetail: vi.fn(), onRegionSaved: vi.fn(),
      deleteRegion: vi.fn(), downloadKubeconfig: vi.fn(), refreshRegions: vi.fn(),
      regionDetailOpen: r(false), regionDetailTarget: r(null), fmtCpu: String, fmtMem: String,
    }),
  }
})

const stub = (name) => ({ name, render: () => h('div', { 'data-stub': name }) })
const STUBS = {
  AppHeader: true, RegionDetailModal: true, DemoManager: true, RegionsList: true,
  UsersManager: stub('UsersManager'), ApiTokensManager: stub('ApiTokensManager'), RuntimesManager: stub('RuntimesManager'),
}

let router
let pinia
const mounted = []

function grant(permissions) {
  usePermissionsStore().apply({ permissions, platform_admin: false, edition: 'single', role: null, roles: [], projects: {} })
}

async function open(path) {
  await router.push(path)
  const wrapper = mount(SettingsView, { global: { plugins: [pinia, makeI18n(), router], stubs: STUBS } })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

const tabs = (w) => w.findAll('[data-testid="settings-section-tabs"] a').map(a => a.text())

beforeEach(() => {
  pinia = freshPinia()
  router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  const can = (p) => () => usePermissionsStore().can(p)
  moduleRegistry.settingsTabs.splice(0, moduleRegistry.settingsTabs.length,
    { key: 'roles', labelKey: 'rbac.tabRoles', icon: markRaw(ShieldCheck), group: 'admin', section: 'members', order: 20, component: markRaw(stub('RolesManager')), visibleIf: can('members.view') },
    { key: 'groups', labelKey: 'rbac.tabGroups', icon: markRaw(UsersRound), group: 'admin', section: 'members', order: 30, component: markRaw(stub('GroupsManager')), visibleIf: can('members.view') },
    { key: 'audit-log', labelKey: 'audit.tab', group: 'admin', section: 'access', order: 30, component: markRaw(stub('AuditLog')), visibleIf: can('audit.view'), dataTour: 'settings-audit-tab' },
    { key: 'my-bills', labelKey: 'nav.settings', group: 'account', component: markRaw(stub('MyBills')) },
  )
})

afterEach(() => {
  mounted.splice(0).forEach(w => w.unmount())
})

describe('SettingsView', () => {
  it('titles a page with its menu group and section and tabs the pages of that section', async () => {
    grant(['members.view', 'tokens.manage', 'audit.view'])
    const w = await open('/settings?tab=users')
    expect(w.get('[data-testid="settings-title"]').text()).toBe('Administration')
    expect(w.get('[data-testid="settings-section"]').text()).toBe('Members')
    expect(tabs(w)).toEqual(['Users', 'Roles', 'Groups'])
    const active = w.get('[data-settings-tab="users"]')
    expect(active.attributes('aria-current')).toBe('page')
    expect(active.classes()).toContain('is-active')
    expect(w.get('[data-settings-tab="roles"]').attributes('href')).toBe('/settings?tab=roles')
    expect(w.find('[data-stub="UsersManager"]').exists()).toBe(true)
  })

  it('renders a module page and follows the section tabs', async () => {
    grant(['members.view', 'tokens.manage', 'audit.view'])
    const w = await open('/settings?tab=roles')
    expect(w.find('[data-stub="RolesManager"]').exists()).toBe(true)
    await w.get('[data-settings-tab="groups"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/settings?tab=groups')
    expect(w.find('[data-stub="GroupsManager"]').exists()).toBe(true)
    expect(w.find('[data-stub="RolesManager"]').exists()).toBe(false)
  })

  it('tabs only the section\'s pages the user may open', async () => {
    grant(['tokens.manage'])
    const w = await open('/settings?tab=tokens')
    expect(w.get('[data-testid="settings-section"]').text()).toBe('Access & security')
    // API tokens alone in the section: no tab bar
    expect(w.find('[data-testid="settings-section-tabs"]').exists()).toBe(false)
    expect(w.find('[data-stub="ApiTokensManager"]').exists()).toBe(true)

    grant(['tokens.manage', 'audit.view'])
    await flushPromises()
    expect(tabs(w)).toEqual(['API Tokens', 'Audit log'])
    expect(w.get('[data-settings-tab="audit-log"]').attributes('data-tour')).toBe('settings-audit-tab')
  })

  it('shows nothing of a page whose link the user does not have', async () => {
    grant(['tokens.manage'])
    const w = await open('/settings?tab=audit-log')
    expect(w.find('[data-stub="AuditLog"]').exists()).toBe(false)
    expect(w.get('[data-testid="settings-title"]').text()).toBe('Settings')

    const users = await open('/settings?tab=users')
    expect(users.find('[data-stub="UsersManager"]').exists()).toBe(false)
  })

  it('is Administration → Platform without a tab; a user-menu page carries its own name', async () => {
    grant(['regions.view'])
    const w = await open('/settings')
    expect(w.get('[data-testid="settings-title"]').text()).toBe('Administration')
    expect(w.get('[data-testid="settings-section"]').text()).toBe('Platform')
    expect(w.text()).toContain('Platform Management')

    const bills = await open('/settings?tab=my-bills')
    expect(bills.get('[data-testid="settings-title"]').text()).toBe('Settings')
    expect(bills.find('[data-testid="settings-section"]').exists()).toBe(false)
    expect(bills.find('[data-stub="MyBills"]').exists()).toBe(true)
  })

  it('falls back to the platform page for an unknown tab', async () => {
    grant(['regions.view'])
    const w = await open('/settings?tab=nope')
    expect(w.text()).toContain('Platform Management')
  })

  it('a module tab registered after the first render (async modules) still opens from its deep link', async () => {
    grant(['members.view'])
    const w = await open('/settings?tab=late')
    expect(w.find('[data-stub="Late"]').exists()).toBe(false)
    moduleRegistry.settingsTabs.push({ key: 'late', labelKey: 'nav.settings', group: 'admin', section: 'members', order: 90, component: markRaw(stub('Late')) })
    await flushPromises()
    expect(w.find('[data-stub="Late"]').exists()).toBe(true)
    expect(tabs(w)).toEqual(['Users', 'Roles', 'Groups', 'Settings'])
  })
})
