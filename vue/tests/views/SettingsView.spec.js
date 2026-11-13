import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { h, markRaw } from 'vue'
import { ShieldCheck, UsersRound } from '@lucide/vue'
import SettingsView from '@/views/SettingsView.vue'
import { moduleRegistry } from '@/modules/index'
import { usePermissionsStore } from '@/stores/permissions'
import { useTutorialStore } from '@/stores/tutorial'
import { freshPinia, makeI18n } from '@/test-support/helpers'

// The settings page under the new navigation: its title is the menu group, the tabs above the
// content are the pages of the same menu section, and a page shows when the menu shows its link.

vi.mock('@/modules/index', async () => {
  const { reactive } = await import('vue')
  return { moduleRegistry: reactive({ settingsTabs: [], navLinks: [], headerSlots: [], viewKey: 0 }) }
})
vi.mock('@/api/runtimes', () => ({ runtimesApi: { list: vi.fn(() => new Promise(() => {})) } }))
// The tours the page starts on a tutorial restart (driver.js itself is not under test here)
const tours = vi.hoisted(() => ({ settings: vi.fn(), tokens: vi.fn(), users: vi.fn(), tabs: vi.fn(), runtimes: vi.fn(), patches: vi.fn() }))
// The platform tour's options (its onComplete opens the region wizard) and the page's openAdd
const tourOptions = vi.hoisted(() => ({ settings: null }))
const openRegionWizard = vi.hoisted(() => vi.fn())
vi.mock('@/composables/useTutorial', () => ({
  createTutorial:          (steps, options = {}) => { tourOptions.settings = options; return { start: tours.settings } },
  useTokensTutorial:       () => ({ start: tours.tokens }),
  useUsersTutorial:        () => ({ start: tours.users }),
  useSettingsTabsTutorial: () => ({ start: tours.tabs }),
  useRuntimesTutorial:     () => ({ start: tours.runtimes }),
  usePatchesTutorial:      () => ({ start: tours.patches }),
}))
vi.mock('@/composables/useSettingsPage', async () => {
  const { ref: r } = await import('vue')
  return {
    useSettingsPage: () => ({
      baseDomain: r(''), registrationEnabled: r(true), gpuDemoEnabled: r(false), customRuntimesEnabled: r(false),
      totalCpuM: r(0), totalMemoryMb: r(0), regions: r([]), demoStatus: r('idle'),
      loading: r(false), saving: r(false), error: r(null), saved: r(false), usedCpuM: r(0), usedMemoryMb: r(0),
      submit: vi.fn(), openAdd: openRegionWizard, openEdit: vi.fn(), closeRegionDetail: vi.fn(), onRegionSaved: vi.fn(),
      deleteRegion: vi.fn(), downloadKubeconfig: vi.fn(), refreshRegions: vi.fn(),
      regionDetailOpen: r(false), regionDetailTarget: r(null), fmtCpu: String, fmtMem: String,
    }),
  }
})

const stub = (name) => ({ name, render: () => h('div', { 'data-stub': name }) })
// The users tour opens the add form first (UsersManager.openAdd)
const openUsersForm = vi.fn()
const usersStub = {
  name: 'UsersManager',
  setup(_, { expose }) {
    expose({ openAdd: openUsersForm })
    return () => h('div', { 'data-stub': 'UsersManager' })
  },
}
const STUBS = {
  AppHeader: true, RegionDetailModal: true, DemoManager: true, RegionsList: true,
  UsersManager: usersStub, ApiTokensManager: stub('ApiTokensManager'), RuntimesManager: stub('RuntimesManager'),
  RuntimePatchesManager: stub('RuntimePatchesManager'),
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

  it('opens API tokens without tokens.manage: everyone revokes their own', async () => {
    grant(['projects.view'])
    const w = await open('/settings?tab=tokens')
    expect(w.get('[data-testid="settings-section"]').text()).toBe('Access & security')
    expect(w.find('[data-stub="ApiTokensManager"]').exists()).toBe(true)
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

  // The public demo's read-only role saw the platform settings editable (only Save was
  // disabled) and the demo data's Remove button: they show read-only without settings.manage
  it('shows the platform settings read-only without settings.manage', async () => {
    grant(['regions.view'])
    const w = await open('/settings')
    expect(w.get('[data-testid="settings-base-domain"]').attributes('disabled')).toBeDefined()
    for (const toggle of ['settings-registration-toggle', 'settings-gpu-demo-toggle']) {
      expect(w.get(`[data-testid="${toggle}"]`).attributes('disabled')).toBeDefined()
    }
    expect(w.find('[data-testid="settings-save"]').exists()).toBe(false)
    expect(w.find('[data-tour="settings-save"]').exists()).toBe(false)
    expect(w.get('[data-testid="settings-read-only"]').text()).toBe('You can view this, changes need the settings.manage permission.')
    expect(w.findComponent({ name: 'DemoManager' }).props('canManage')).toBe(false)

    grant(['regions.view', 'settings.manage'])
    await flushPromises()
    expect(w.get('[data-testid="settings-base-domain"]').attributes('disabled')).toBeUndefined()
    expect(w.get('[data-testid="settings-registration-toggle"]').attributes('disabled')).toBeUndefined()
    expect(w.get('[data-testid="settings-save"]').attributes('disabled')).toBeUndefined()
    expect(w.find('[data-tour="settings-save"]').exists()).toBe(true)
    expect(w.find('[data-testid="settings-read-only"]').exists()).toBe(false)
    expect(w.findComponent({ name: 'DemoManager' }).props('canManage')).toBe(true)
  })

  // The single-tenant edition has no platform administrators: platform.manage (the Owner's) opens
  // Build & Run → Patches, which sits next to Runtimes; without it (Admin, Developer) it is not there
  it('shows the Patches page to a single-tenant holder of platform.manage, next to Runtimes', async () => {
    grant(['runtimes.manage', 'platform.manage'])
    const w = await open('/settings?tab=patches')
    expect(w.find('[data-stub="RuntimePatchesManager"]').exists()).toBe(true)
    expect(w.get('[data-testid="settings-section"]').text()).toBe('Runtimes & images')
    expect(tabs(w)).toEqual(['Runtimes', 'Patches'])
    expect(w.get('[data-settings-tab="patches"]').attributes('data-tour')).toBe('settings-patches-tab')

    grant(['runtimes.manage'])
    await flushPromises()
    expect(w.find('[data-stub="RuntimePatchesManager"]').exists()).toBe(false)
    expect(w.find('[data-testid="settings-section-tabs"]').exists()).toBe(false)
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

describe('SettingsView tutorial', () => {
  async function restart() {
    useTutorialStore().requestRestart()
    await flushPromises()
  }
  const started = () => Object.keys(tours).filter(key => tours[key].mock.calls.length > 0)

  it('starts the tour of the page shown: platform, API tokens, users', async () => {
    grant(['regions.view', 'tokens.manage', 'members.view', 'members.invite'])
    await open('/settings')
    await restart()
    expect(started()).toEqual(['settings'])

    vi.clearAllMocks()
    await router.push('/settings?tab=tokens')
    await restart()
    expect(started()).toEqual(['tokens'])

    vi.clearAllMocks()
    await router.push('/settings?tab=users')
    await restart()
    expect(started()).toEqual(['users'])
    expect(openUsersForm).toHaveBeenCalled()
  })

  // Build & Run → Runtimes: the tour of its Runtimes / Sources tabs (tutorialSteps runtimesSteps)
  it('starts the runtimes tour on the Runtimes page', async () => {
    vi.clearAllMocks()
    grant(['runtimes.manage'])
    const w = await open('/settings?tab=runtimes')
    expect(w.find('[data-stub="RuntimesManager"]').exists()).toBe(true)
    await restart()
    expect(started()).toEqual(['runtimes'])
  })

  // Build & Run → Patches: the feed, the policy, the patches, the releases (tutorialSteps patchesSteps)
  it('starts the patches tour on the Patches page', async () => {
    vi.clearAllMocks()
    grant(['platform.manage'])
    await open('/settings?tab=patches')
    await restart()
    expect(started()).toEqual(['patches'])
  })

  it('gives a module page the tour of the tabs of its menu section', async () => {
    grant(['members.view'])
    await open('/settings?tab=roles')
    await restart()
    expect(started()).toEqual(['tabs'])
  })

  // A read-only role (the public demo's, a Viewer) sees no Add user button: the users tour shows
  // the page without opening the add form, whose step then drops out
  it('opens the add-user form for the users tour only for a user who may add users', async () => {
    vi.clearAllMocks()
    grant(['members.view'])
    await open('/settings?tab=users')
    await restart()
    expect(started()).toEqual(['users'])
    expect(openUsersForm).not.toHaveBeenCalled()
  })

  // The platform tour ends with the Add region button and goes on with the region wizard: only
  // for a user who may add regions (the button's permission, regions.manage)
  it('goes on with the region wizard only for a user who may add regions', async () => {
    vi.clearAllMocks()
    grant(['regions.view'])
    await open('/settings')
    await restart()
    expect(started()).toEqual(['settings'])
    tourOptions.settings.onComplete()
    expect(openRegionWizard).not.toHaveBeenCalled()

    grant(['regions.view', 'regions.manage'])
    await flushPromises()
    tourOptions.settings.onComplete()
    expect(openRegionWizard).toHaveBeenCalledTimes(1)
  })

  it('leaves a module page with a tour of its own (tutorial: true) to that tour', async () => {
    grant(['members.view'])
    moduleRegistry.settingsTabs.push({ key: 'own', labelKey: 'nav.settings', group: 'admin', section: 'members', order: 90, component: markRaw(stub('Own')), tutorial: true })
    await open('/settings?tab=own')
    await restart()
    expect(started()).toEqual([])
  })
})
