import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { h, markRaw } from 'vue'
import { Bot, LifeBuoy, ScrollText } from 'lucide-vue-next'
import { moduleRegistry } from '@/modules/index'
import { usePermissionsStore } from '@/stores/permissions'
import { useAuthStore } from '@/stores/auth'
import { useTutorialStore } from '@/stores/tutorial'
import MainNav from '@/components/nav/MainNav.vue'
import MobileNavDrawer from '@/components/nav/MobileNavDrawer.vue'
import UserMenu from '@/components/nav/UserMenu.vue'
import AppHeader from '@/components/AppHeader.vue'
import { freshPinia, makeI18n } from '@/test-support/helpers'

// The menus rendered from the navigation definition: the desktop main menu with its mega
// menus, the mobile drawer, the user menu and the header around them.

vi.mock('@/modules/index', async () => {
  const { reactive } = await import('vue')
  return { moduleRegistry: reactive({ settingsTabs: [], navLinks: [], headerSlots: [], viewKey: 0 }) }
})
vi.mock('@/api/runtimes', () => ({ runtimesApi: { list: vi.fn(() => new Promise(() => {})) } }))
vi.mock('@/composables/useFallbackPolling', () => ({ useFallbackPolling: vi.fn() }))

const Page = { render: () => null }
let router
let pinia
let i18n
const mounted = []

function grant(permissions, { platformAdmin = false } = {}) {
  usePermissionsStore().apply({ permissions, platform_admin: platformAdmin, edition: 'single', role: null, roles: [], projects: {} })
}

async function mountWith(component, { path = '/projects', props = {} } = {}) {
  await router.push(path)
  const wrapper = mount(component, { props, attachTo: document.body, global: { plugins: [pinia, i18n, router] } })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

const key = (el, k, extra = {}) => el.trigger('keydown', { key: k, ...extra })
const focused = () => document.activeElement

beforeEach(() => {
  pinia = freshPinia()
  i18n = makeI18n()
  router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: Page }] })
  moduleRegistry.settingsTabs.splice(0, moduleRegistry.settingsTabs.length,
    { key: 'audit-log', labelKey: 'audit.tab', descKey: 'audit.navDesc', icon: markRaw(ScrollText), group: 'admin', section: 'access', order: 30, visibleIf: () => usePermissionsStore().can('audit.view') },
    { key: 'ai-agents', labelKey: 'mcp.settings.tab', descKey: 'mcp.settings.navDesc', icon: markRaw(Bot), group: 'data', section: 'integrations', order: 20 },
    { key: 'support-tickets', labelKey: 'nav.settings', icon: markRaw(LifeBuoy), group: 'account', order: 30 },
  )
  moduleRegistry.navLinks.splice(0, moduleRegistry.navLinks.length)
  moduleRegistry.headerSlots.splice(0, moduleRegistry.headerSlots.length)
  localStorage.setItem('locale', 'en')
})

afterEach(() => {
  mounted.splice(0).forEach(w => w.unmount())
  document.body.innerHTML = ''
  document.body.style.overflow = ''
})

describe('MainNav (wide screens)', () => {
  it('shows Projects first and alone, then one button per group the user can use', async () => {
    grant(['models.view', 'tokens.manage', 'regions.view'])
    const w = await mountWith(MainNav)
    const items = w.findAll('[data-nav-item]')
    expect(items[0].text()).toBe('Projects')
    expect(items[0].element.tagName).toBe('A')
    expect(items[0].attributes('aria-current')).toBe('page')
    expect(items.slice(1).map(i => i.text())).toEqual(['Build & Run', 'Data & Integrations', 'Administration'])
    // no alerts / tracing / status page permission: no Observability menu
    expect(w.find('[data-testid="nav-group-observability"]').exists()).toBe(false)
    expect(w.find('nav').attributes('aria-label')).toBe('Main menu')
  })

  it('opens a mega menu of labelled sections with icons and one-line descriptions', async () => {
    grant(['tokens.manage', 'audit.view', 'regions.view', 'members.view'])
    const w = await mountWith(MainNav)
    const trigger = w.get('[data-testid="nav-group-admin"]')
    expect(trigger.attributes('aria-expanded')).toBe('false')
    expect(trigger.attributes('aria-controls')).toBeUndefined()

    await trigger.trigger('click')
    expect(trigger.attributes('aria-expanded')).toBe('true')
    expect(trigger.attributes('aria-controls')).toBe('nav-panel-admin')
    const panel = w.get('#nav-panel-admin')
    expect(panel.attributes('role')).toBe('region')
    expect(panel.attributes('aria-labelledby')).toBe('nav-trigger-admin')
    expect(panel.findAll('section h3').map(h => h.text())).toEqual(['Members', 'Access & security', 'Platform'])
    expect(panel.get('[data-testid="nav-link-audit-log"]').text()).toContain('Who did what')
    expect(panel.get('[data-testid="nav-link-tokens"]').attributes('href')).toBe('/settings?tab=tokens')
    expect(panel.get('[data-testid="nav-link-platform-settings"]').attributes('href')).toBe('/settings')
    expect(panel.findAll('[data-nav-link] svg').length).toBe(panel.findAll('[data-nav-link]').length)

    // a second click closes it
    await trigger.trigger('click')
    expect(w.find('#nav-panel-admin').exists()).toBe(false)
  })

  it('keyboard: arrows move along the menu, ↓ opens a menu, ↓/↑/Home/End/→ move in it, Esc closes', async () => {
    grant(['tokens.manage', 'audit.view', 'regions.view', 'members.view', 'models.view'])
    const w = await mountWith(MainNav)
    const projects = w.get('[data-testid="nav-projects"]')
    projects.element.focus()
    await key(projects, 'ArrowRight')
    expect(focused().dataset.testid).toBe('nav-group-build')
    await key(w.get('[data-testid="nav-group-build"]'), 'End')
    expect(focused().dataset.testid).toBe('nav-group-admin')
    await key(w.get('[data-testid="nav-group-admin"]'), 'ArrowRight')
    expect(focused().dataset.testid).toBe('nav-projects')
    await key(projects, 'ArrowLeft')
    expect(focused().dataset.testid).toBe('nav-group-admin')

    const admin = w.get('[data-testid="nav-group-admin"]')
    await key(admin, 'ArrowDown')
    await flushPromises()
    expect(admin.attributes('aria-expanded')).toBe('true')
    expect(focused().dataset.testid).toBe('nav-link-users')

    const panel = w.get('#nav-panel-admin')
    await key(panel, 'ArrowDown')
    expect(focused().dataset.testid).toBe('nav-link-tokens')
    await key(panel, 'End')
    expect(focused().dataset.testid).toBe('nav-link-platform-settings')
    await key(panel, 'ArrowDown')
    expect(focused().dataset.testid).toBe('nav-link-users')
    await key(panel, 'ArrowUp')
    expect(focused().dataset.testid).toBe('nav-link-platform-settings')
    await key(panel, 'Home')
    expect(focused().dataset.testid).toBe('nav-link-users')
    // → the first link of the next section, ← back
    await key(panel, 'ArrowRight')
    expect(focused().dataset.testid).toBe('nav-link-tokens')
    await key(panel, 'ArrowLeft')
    expect(focused().dataset.testid).toBe('nav-link-users')

    await key(panel, 'Escape')
    expect(w.find('#nav-panel-admin').exists()).toBe(false)
    expect(focused().dataset.testid).toBe('nav-group-admin')

    // ↑ opens at the last link
    await key(admin, 'ArrowUp')
    await flushPromises()
    expect(focused().dataset.testid).toBe('nav-link-platform-settings')
  })

  it('with a menu open, moving along the menu bar switches the open menu', async () => {
    grant(['tokens.manage', 'models.view'])
    const w = await mountWith(MainNav)
    const build = w.get('[data-testid="nav-group-build"]')
    await build.trigger('click')
    build.element.focus()
    await key(build, 'ArrowRight')
    expect(w.find('#nav-panel-build').exists()).toBe(false)
    expect(w.find('#nav-panel-data').exists()).toBe(true)
    // hovering another group while one is open shows that one
    await w.get('[data-testid="nav-group-admin"]').trigger('pointerenter')
    expect(w.find('#nav-panel-admin').exists()).toBe(true)
    // …and the click that follows the move keeps it open; a later click closes it
    await w.get('[data-testid="nav-group-admin"]').trigger('click')
    expect(w.find('#nav-panel-admin').exists()).toBe(true)
    await w.get('[data-testid="nav-group-admin"]').trigger('click')
    expect(w.find('#nav-panel-admin').exists()).toBe(false)
    // hovering with no menu open opens nothing
    await w.get('[data-testid="nav-group-build"]').trigger('pointerenter')
    expect(w.find('#nav-panel-build').exists()).toBe(false)
  })

  it('closes on a click outside, on focus leaving the menu and on navigation', async () => {
    grant(['tokens.manage'])
    const w = await mountWith(MainNav)
    const admin = w.get('[data-testid="nav-group-admin"]')

    await admin.trigger('click')
    document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }))
    await flushPromises()
    expect(w.find('#nav-panel-admin').exists()).toBe(false)

    await admin.trigger('click')
    const outside = document.createElement('button')
    document.body.appendChild(outside)
    await w.get('nav').trigger('focusout', { relatedTarget: outside })
    expect(w.find('#nav-panel-admin').exists()).toBe(false)

    await admin.trigger('click')
    await w.get('[data-testid="nav-link-tokens"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/settings?tab=tokens')
    expect(w.find('#nav-panel-admin').exists()).toBe(false)
  })

  it('marks the group and the link of the current page', async () => {
    grant(['tokens.manage', 'regions.view'])
    const w = await mountWith(MainNav, { path: '/settings?tab=tokens' })
    expect(w.get('[data-testid="nav-projects"]').attributes('aria-current')).toBeUndefined()
    const admin = w.get('[data-testid="nav-group-admin"]')
    expect(admin.classes()).toContain('text-brand-500')
    await admin.trigger('click')
    expect(w.get('[data-testid="nav-link-tokens"]').attributes('aria-current')).toBe('page')
    expect(w.get('[data-testid="nav-link-platform-settings"]').attributes('aria-current')).toBeUndefined()
  })

  it('follows permission changes: a group appears and disappears with its links', async () => {
    grant(['models.view'])
    const w = await mountWith(MainNav)
    expect(w.find('[data-testid="nav-group-admin"]').exists()).toBe(false)
    grant(['models.view', 'tokens.manage'])
    await flushPromises()
    expect(w.find('[data-testid="nav-group-admin"]').exists()).toBe(true)
    await w.get('[data-testid="nav-group-admin"]').trigger('click')
    grant(['models.view'])
    await flushPromises()
    expect(w.find('[data-testid="nav-group-admin"]').exists()).toBe(false)
    expect(w.find('#nav-panel-admin').exists()).toBe(false)
  })

  it('places a module page by its declared group', async () => {
    grant(['sandboxes.view'])
    moduleRegistry.navLinks.push({ key: 'sandboxes-page', to: '/sandboxes', labelKey: 'nav.menu', group: 'build', section: 'workloads', visibleIf: () => usePermissionsStore().can('sandboxes.view') })
    const w = await mountWith(MainNav)
    await w.get('[data-testid="nav-group-build"]').trigger('click')
    expect(w.get('[data-testid="nav-link-sandboxes-page"]').attributes('href')).toBe('/sandboxes')
  })
})

describe('MobileNavDrawer (narrow screens)', () => {
  it('is a modal dialog with Projects and the groups as an accordion; the current group starts open', async () => {
    grant(['tokens.manage', 'models.view', 'regions.view'])
    const w = await mountWith(MobileNavDrawer, { path: '/settings?tab=tokens' })
    const dialog = w.get('[role="dialog"]')
    expect(dialog.attributes('aria-modal')).toBe('true')
    expect(dialog.attributes('id')).toBe('mobile-nav')
    expect(focused().dataset.testid).toBe('mobile-nav-close')
    expect(document.body.style.overflow).toBe('hidden')

    expect(w.get('[data-testid="nav-projects"]').text()).toBe('Projects')
    const admin = w.get('[data-testid="nav-group-admin"]')
    const build = w.get('[data-testid="nav-group-build"]')
    expect(admin.attributes('aria-expanded')).toBe('true')
    expect(admin.attributes('aria-controls')).toBe('mobile-nav-panel-admin')
    expect(build.attributes('aria-expanded')).toBe('false')
    expect(w.get('#mobile-nav-panel-admin').isVisible()).toBe(true)
    expect(w.get('#mobile-nav-panel-build').isVisible()).toBe(false)
    expect(w.get('[data-testid="nav-link-tokens"]').text()).toContain('Personal tokens')

    // one group open at a time
    await build.trigger('click')
    expect(build.attributes('aria-expanded')).toBe('true')
    expect(admin.attributes('aria-expanded')).toBe('false')
    expect(w.get('#mobile-nav-panel-build').isVisible()).toBe(true)
    await build.trigger('click')
    expect(build.attributes('aria-expanded')).toBe('false')
  })

  it('arrows move between the open items only, Tab stays in the drawer, Esc and the backdrop close it', async () => {
    grant(['tokens.manage', 'models.view'])
    const w = await mountWith(MobileNavDrawer)
    const dialog = w.get('[role="dialog"]')
    await key(dialog, 'ArrowDown')
    expect(focused().dataset.testid).toBe('nav-projects')
    await key(dialog, 'ArrowDown')
    expect(focused().dataset.testid).toBe('nav-group-build')
    // the links of the closed groups are skipped
    await key(dialog, 'ArrowDown')
    expect(focused().dataset.testid).toBe('nav-group-data')
    await key(dialog, 'End')
    expect(focused().dataset.testid).toBe('nav-group-admin')
    await key(dialog, 'Home')
    expect(focused().dataset.testid).toBe('mobile-nav-close')

    // Tab from the last item wraps to the first, Shift+Tab from the first to the last
    w.get('[data-testid="nav-group-admin"]').element.focus()
    await key(dialog, 'Tab')
    expect(focused().dataset.testid).toBe('mobile-nav-close')
    await key(dialog, 'Tab', { shiftKey: true })
    expect(focused().dataset.testid).toBe('nav-group-admin')

    await key(dialog, 'Escape')
    expect(w.emitted('close')).toHaveLength(1)
    await w.get('[data-testid="mobile-nav-backdrop"]').trigger('click')
    expect(w.emitted('close')).toHaveLength(2)

    w.unmount()
    mounted.splice(mounted.indexOf(w), 1)
    expect(document.body.style.overflow).toBe('')
  })
})

describe('UserMenu', () => {
  beforeEach(() => {
    localStorage.setItem('user', JSON.stringify({ id: 1, email: 'ada@example.test', is_admin: false }))
    localStorage.setItem('token', 't')
  })

  it('holds the account pages, theme, language, tutorial and sign-out', async () => {
    grant([])
    const w = await mountWith(UserMenu)
    const button = w.get('[data-testid="user-menu-button"]')
    expect(button.attributes('aria-label')).toBe('Account menu')
    expect(button.attributes('aria-expanded')).toBe('false')
    await button.trigger('click')
    expect(button.attributes('aria-expanded')).toBe('true')
    expect(button.attributes('aria-controls')).toBe('user-menu')

    const menu = w.get('#user-menu')
    expect(menu.text()).toContain('ada@example.test')
    expect(menu.findAll('[data-nav-link]').map(l => l.attributes('data-testid'))).toEqual(['nav-link-account', 'nav-link-support-tickets'])
    expect(menu.get('[data-testid="nav-link-account"]').text()).toBe('Account settings')

    await menu.get('[data-theme-option="light"]').trigger('click')
    expect(document.documentElement.dataset.theme).toBe('light')
    expect(menu.get('[data-theme-option="light"]').attributes('aria-pressed')).toBe('true')
    expect(menu.get('[data-theme-option="dark"]').attributes('aria-pressed')).toBe('false')

    await menu.get('[data-locale-option="tr"]').trigger('click')
    expect(i18n.global.locale.value).toBe('tr')
    expect(localStorage.getItem('locale')).toBe('tr')
    expect(menu.get('[data-locale-option="tr"]').attributes('aria-pressed')).toBe('true')
    i18n.global.locale.value = 'en'

    const tutorial = useTutorialStore()
    const restarts = tutorial.restartCounter
    await menu.get('[data-testid="user-menu-tutorial"]').trigger('click')
    expect(tutorial.restartCounter).toBe(restarts + 1)
    expect(w.find('#user-menu').exists()).toBe(false)
  })

  it('keyboard: ↓ opens at the first item, arrows move, Esc closes back to the avatar', async () => {
    grant([])
    const w = await mountWith(UserMenu)
    const button = w.get('[data-testid="user-menu-button"]')
    button.element.focus()
    await key(button, 'ArrowDown')
    await flushPromises()
    expect(focused().dataset.testid).toBe('nav-link-account')
    const menu = w.get('#user-menu')
    await key(menu, 'ArrowDown')
    expect(focused().dataset.testid).toBe('nav-link-support-tickets')
    await key(menu, 'End')
    expect(focused().dataset.testid).toBe('user-menu-logout')
    await key(menu, 'ArrowDown')
    expect(focused().dataset.testid).toBe('nav-link-account')
    await key(menu, 'Escape')
    expect(w.find('#user-menu').exists()).toBe(false)
    expect(focused()).toBe(button.element)
  })

  it('signs out and goes to the sign-in page', async () => {
    grant([])
    const w = await mountWith(UserMenu)
    const auth = useAuthStore()
    const logout = vi.spyOn(auth, 'logout').mockResolvedValue()
    await w.get('[data-testid="user-menu-button"]').trigger('click')
    await w.get('[data-testid="user-menu-logout"]').trigger('click')
    await flushPromises()
    expect(logout).toHaveBeenCalled()
    expect(router.currentRoute.value.path).toBe('/login')
  })
})

describe('AppHeader', () => {
  beforeEach(() => {
    localStorage.setItem('user', JSON.stringify({ id: 1, email: 'ada@example.test', is_admin: true }))
    localStorage.setItem('token', 't')
  })

  it('keeps the main menu, notifications and the user menu; theme, language and tutorial live in the user menu', async () => {
    grant([], { platformAdmin: true })
    const w = await mountWith(AppHeader)
    expect(w.find('[data-testid="main-nav"]').exists()).toBe(true)
    expect(w.find('[data-testid="notifications-button"]').attributes('aria-label')).toBe('Notifications')
    expect(w.find('[data-testid="user-menu-button"]').exists()).toBe(true)
    // nothing else in the header row
    expect(w.find('[data-theme-option]').exists()).toBe(false)
    expect(w.find('[data-locale-option]').exists()).toBe(false)
    expect(w.find('[data-testid="user-menu-tutorial"]').exists()).toBe(false)
    // the approvals inbox of environment promotions (a platform administrator may approve)
    expect(w.find('[data-testid="nav-approvals"]').exists()).toBe(true)
    expect(w.findAll('header > div > div.ml-auto > *').length).toBe(4)
  })

  it('opens the drawer from the menu button and closes it on navigation, back to the button', async () => {
    grant(['tokens.manage'])
    const w = await mountWith(AppHeader)
    const menuButton = w.get('[data-testid="mobile-nav-button"]')
    expect(menuButton.attributes('aria-label')).toBe('Open the menu')
    expect(menuButton.attributes('aria-controls')).toBe('mobile-nav')
    expect(menuButton.attributes('aria-expanded')).toBe('false')

    await menuButton.trigger('click')
    expect(menuButton.attributes('aria-expanded')).toBe('true')
    const drawer = w.get('[data-testid="mobile-nav"]')
    await drawer.get('[data-testid="mobile-nav-close"]').trigger('click')
    expect(w.find('[data-testid="mobile-nav"]').exists()).toBe(false)
    expect(focused()).toBe(menuButton.element)

    await menuButton.trigger('click')
    await w.get('[data-testid="mobile-nav"] [data-testid="nav-group-admin"]').trigger('click')
    await w.get('[data-testid="mobile-nav"] [data-testid="nav-link-tokens"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/settings?tab=tokens')
    expect(w.find('[data-testid="mobile-nav"]').exists()).toBe(false)
  })

  it('renders the modules\' header items (tenant switcher)', async () => {
    grant([])
    moduleRegistry.headerSlots.push(markRaw({ render: () => h('span', { 'data-testid': 'tenant-switcher' }, 'Acme') }))
    const w = await mountWith(AppHeader)
    expect(w.find('[data-testid="tenant-switcher"]').exists()).toBe(true)
  })
})
