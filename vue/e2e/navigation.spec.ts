import { test, expect } from './fixtures/test'
import type { Page } from '@playwright/test'
import { effectivePermissions } from './fixtures/data'
import { navigateTo, openNavMenu, openUserMenu } from './helpers/ui'

// The console's navigation (src/navigation/menu.js): Projects on its own, the mega menus of
// Build & Run, Data & Integrations, Observability and Administration, the user menu, keyboard
// use, the permission-aware menus and the drawer of narrow screens.

const mainNav = (page: Page) => page.getByTestId('main-nav')
const focusedTestId = (page: Page) => page.evaluate(() => document.activeElement?.getAttribute('data-testid'))

test.describe('Main menu', () => {
  test.skip(({ e2eMode }) => e2eMode === 'stack', 'asserts the menus of the mocked owner and roles')

  test('Projects stands alone; the groups open mega menus of described links', async ({ page, auth }) => {
    await auth.signIn()
    await page.goto('/projects')

    await expect(mainNav(page).locator('[data-nav-item]')).toHaveText(['Projects', 'Build & Run', 'Data & Integrations', 'Observability', 'Administration'])
    await expect(mainNav(page).getByTestId('nav-projects')).toHaveAttribute('aria-current', 'page')

    const admin = await openNavMenu(page, 'admin')
    await expect(mainNav(page).getByTestId('nav-group-admin')).toHaveAttribute('aria-expanded', 'true')
    // Organization: the export / import of the organization (org-transfer module)
    await expect(admin.locator('section h3')).toHaveText(['Members', 'Access & security', 'Organization', 'Platform'])
    await expect(admin.locator('[data-nav-link]')).toHaveCount(10)
    await expect(admin.getByTestId('nav-link-tokens')).toContainText('Personal tokens for the CLI, CI and AI agents')
    await expect(admin.getByTestId('nav-link-platform-settings')).toContainText('Regions & settings')

    const build = await openNavMenu(page, 'build')
    await expect(page.locator('#nav-panel-admin')).toHaveCount(0)
    await expect(build.locator('section h3')).toHaveText(['Workloads', 'Runtimes & images', 'Automation'])
    await expect(build.getByTestId('nav-link-sandboxes-page')).toBeVisible()
    await expect(build.getByTestId('nav-link-models')).toBeVisible()

    // a click outside closes the menu
    await page.mouse.click(720, 880)
    await expect(page.locator('#nav-panel-build')).toHaveCount(0)
  })

  test('a menu link opens its page; the settings page tabs the pages of the same section', async ({ page, auth }) => {
    await auth.signIn()
    await page.goto('/projects')
    await navigateTo(page, 'admin', 'tokens')

    await expect(page).toHaveURL(/\/settings\?tab=tokens$/)
    await expect(page.locator('#nav-panel-admin')).toHaveCount(0)
    await expect(page.getByTestId('settings-title')).toHaveText('Administration')
    await expect(page.getByTestId('settings-section')).toHaveText('Access & security')
    const tabs = page.getByTestId('settings-section-tabs').getByRole('link')
    await expect(tabs).toHaveText(['API Tokens', 'Audit log', 'Secret stores'])
    await expect(tabs.first()).toHaveAttribute('aria-current', 'page')

    await tabs.nth(1).click()
    await expect(page).toHaveURL(/\/settings\?tab=audit-log$/)
    await expect(tabs.nth(1)).toHaveAttribute('aria-current', 'page')
    // the group of the current page is marked in the main menu
    await expect(mainNav(page).getByTestId('nav-group-admin')).toHaveClass(/text-brand-500/)

    await navigateTo(page, 'build', 'models')
    await expect(page).toHaveURL(/\/models$/)
    await navigateTo(page, 'build', 'sandboxes-page')
    await expect(page).toHaveURL(/\/sandboxes$/)
    await mainNav(page).getByTestId('nav-projects').click()
    await expect(page).toHaveURL(/\/projects$/)
  })

  test('keyboard: arrows along the menu, ↓ into a mega menu, arrows in it, Esc back', async ({ page, auth }) => {
    await auth.signIn()
    await page.goto('/projects')

    await mainNav(page).getByTestId('nav-projects').focus()
    await page.keyboard.press('ArrowRight')
    expect(await focusedTestId(page)).toBe('nav-group-build')
    await page.keyboard.press('ArrowDown')
    await expect(page.locator('#nav-panel-build')).toBeVisible()
    expect(await focusedTestId(page)).toBe('nav-link-sandboxes-page')
    await page.keyboard.press('ArrowDown')
    expect(await focusedTestId(page)).toBe('nav-link-sandboxes')
    await page.keyboard.press('ArrowRight')
    expect(await focusedTestId(page)).toBe('nav-link-runtimes')
    await page.keyboard.press('End')
    expect(await focusedTestId(page)).toBe('nav-link-topics')

    await page.keyboard.press('Escape')
    await expect(page.locator('#nav-panel-build')).toHaveCount(0)
    expect(await focusedTestId(page)).toBe('nav-group-build')

    await page.keyboard.press('End')
    expect(await focusedTestId(page)).toBe('nav-group-admin')
    await page.keyboard.press('Enter')
    await expect(page.locator('#nav-panel-admin')).toBeVisible()
    await page.keyboard.press('ArrowUp')
    expect(await focusedTestId(page)).toBe('nav-link-signup')
    await page.keyboard.press('Enter')
    await expect(page).toHaveURL(/\/settings\?tab=signup$/)
    await expect(page.locator('#nav-panel-admin')).toHaveCount(0)

    // Tab out of an open menu (past its last link, to the header's next control) closes it
    await mainNav(page).getByTestId('nav-group-observability').focus()
    await page.keyboard.press('ArrowDown')
    await expect(page.locator('#nav-panel-observability')).toBeVisible()
    expect(await focusedTestId(page)).toBe('nav-link-alerts')
    await page.keyboard.press('Tab')
    await page.keyboard.press('Tab')
    expect(await focusedTestId(page)).toBe('nav-link-status-page')
    await page.keyboard.press('Tab')
    expect(await focusedTestId(page)).toBe('nav-link-rightsizing')
    // past the last link: the header's approvals inbox (environments), then the notifications
    await page.keyboard.press('Tab')
    expect(await focusedTestId(page)).toBe('nav-approvals')
    await expect(page.locator('#nav-panel-observability')).toHaveCount(0)
  })

  test('menus follow the permissions: links, sections and empty groups disappear', async ({ page, auth, mockApi }) => {
    mockApi.db.user = { ...mockApi.db.user, is_admin: false }
    mockApi.db.permissions = effectivePermissions('custom', ['projects.view', 'models.view', 'tokens.manage'])
    await auth.signIn({ user: mockApi.db.user })
    await page.goto('/projects')

    await expect(mainNav(page).locator('[data-nav-item]')).toHaveText(['Projects', 'Build & Run', 'Data & Integrations', 'Administration'])
    const build = await openNavMenu(page, 'build')
    // models; the API gateways and the topics come with projects.view
    await expect(build.locator('[data-nav-link]')).toHaveCount(3)
    await expect(build.getByTestId('nav-link-models')).toBeVisible()
    await expect(build.getByTestId('nav-link-gateways')).toBeVisible()
    await expect(build.getByTestId('nav-link-topics')).toBeVisible()
    const admin = await openNavMenu(page, 'admin')
    await expect(admin.locator('section h3')).toHaveText(['Access & security'])
    await expect(admin.locator('[data-nav-link]')).toHaveCount(1)
    await expect(admin.getByTestId('nav-link-tokens')).toBeVisible()
  })

  test('the user menu holds the account page, theme, language, tutorial and sign-out', async ({ page, auth }) => {
    await auth.signIn()
    await page.goto('/projects')
    // the header has no theme / language / tutorial buttons of its own
    await expect(page.locator('header [data-theme-option]')).toHaveCount(0)

    const menu = await openUserMenu(page)
    await expect(page.getByTestId('user-menu-button')).toHaveAttribute('aria-expanded', 'true')
    await expect(menu.getByTestId('nav-link-account')).toHaveText('Account settings')
    await menu.locator('[data-theme-option="light"]').click()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')
    await expect(menu.locator('[data-theme-option="light"]')).toHaveAttribute('aria-pressed', 'true')

    await menu.locator('[data-locale-option="tr"]').click()
    await expect(menu.getByTestId('nav-link-account')).toHaveText('Hesap ayarları')
    await expect(mainNav(page).getByTestId('nav-group-admin')).toHaveText('Yönetim')
    await menu.locator('[data-locale-option="en"]').click()

    await page.keyboard.press('Escape')
    await expect(page.locator('#user-menu')).toHaveCount(0)
    expect(await focusedTestId(page)).toBe('user-menu-button')

    await openUserMenu(page)
    await page.locator('#user-menu').getByTestId('nav-link-account').click()
    await expect(page).toHaveURL(/\/account$/)
    await expect(page.getByRole('heading', { name: 'Account settings' })).toBeVisible()
  })
})

test.describe('Main menu on a phone', () => {
  test.skip(({ e2eMode }) => e2eMode === 'stack', 'asserts the menus of the mocked owner')
  test.use({ viewport: { width: 390, height: 844 } })

  test('the menus collapse into a drawer with an accordion', async ({ page, auth }) => {
    await auth.signIn()
    await page.goto('/projects')
    await expect(mainNav(page)).toBeHidden()

    const button = page.getByTestId('mobile-nav-button')
    await expect(button).toHaveAttribute('aria-expanded', 'false')
    await button.click()
    const drawer = page.getByTestId('mobile-nav')
    await expect(drawer.getByRole('dialog')).toBeVisible()
    await expect(button).toHaveAttribute('aria-expanded', 'true')
    expect(await focusedTestId(page)).toBe('mobile-nav-close')

    // the groups are an accordion: one open at a time
    const admin = drawer.getByTestId('nav-group-admin')
    const build = drawer.getByTestId('nav-group-build')
    await expect(admin).toHaveAttribute('aria-expanded', 'false')
    await admin.click()
    await expect(admin).toHaveAttribute('aria-expanded', 'true')
    await expect(drawer.getByTestId('nav-link-tokens')).toBeVisible()
    await build.click()
    await expect(admin).toHaveAttribute('aria-expanded', 'false')
    await expect(drawer.getByTestId('nav-link-tokens')).toBeHidden()
    await expect(drawer.getByTestId('nav-link-models')).toBeVisible()

    await drawer.getByTestId('nav-link-models').click()
    await expect(page).toHaveURL(/\/models$/)
    await expect(drawer).toHaveCount(0)

    // the current group starts open; Esc closes the drawer, back to the menu button
    await button.click()
    await expect(page.getByTestId('mobile-nav').getByTestId('nav-group-build')).toHaveAttribute('aria-expanded', 'true')
    await page.keyboard.press('Escape')
    await expect(page.getByTestId('mobile-nav')).toHaveCount(0)
    expect(await focusedTestId(page)).toBe('mobile-nav-button')

    // the backdrop (right of the drawer) closes it too
    await button.click()
    await page.getByTestId('mobile-nav-backdrop').click({ position: { x: 375, y: 400 } })
    await expect(page.getByTestId('mobile-nav')).toHaveCount(0)
  })

  test('the header keeps only the brand, notifications and the user menu', async ({ page, auth }) => {
    await auth.signIn()
    await page.goto('/projects')
    await expect(page.getByTestId('notifications-button')).toBeVisible()
    await expect(page.getByTestId('user-menu-button')).toBeVisible()
    // the header fits the phone's width
    const overflow = await page.evaluate(() => {
      const header = document.querySelector('header')!
      return header.scrollWidth - header.clientWidth
    })
    expect(overflow).toBeLessThanOrEqual(0)

    const menu = await openUserMenu(page)
    const box = await menu.boundingBox()
    expect(box!.x).toBeGreaterThanOrEqual(0)
    expect(box!.x + box!.width).toBeLessThanOrEqual(390)
  })
})

// A 1024 px window is the narrowest that shows the main menu: in Turkish, with the tenant switcher
// in the header, the group labels do not fit — the groups show their icons, nothing overflows.
test.describe('Main menu at 1024 px', { tag: '@modules' }, () => {
  test.skip(({ e2eMode }) => e2eMode === 'stack', 'asserts the mocked tenants and the language')
  test.use({ viewport: { width: 1024, height: 768 } })

  test('Turkish with the tenant switcher: the groups fit as icons and keep their names', async ({ page, auth, mockApi }) => {
    const tenant = { id: 't-finance', name: 'Muhasebe ve Finans Departmanı', slug: 'finans', role: 'owner' }
    mockApi.db.tenants.push(tenant)
    mockApi.db.activeTenant = tenant
    await page.addInitScript(() => localStorage.setItem('locale', 'tr'))
    await auth.signIn()
    await page.goto('/projects')

    const nav = mainNav(page)
    const switcher = page.locator('header').getByRole('button', { name: /Muhasebe/ })
    await expect(switcher).toBeVisible()
    await expect(nav).toHaveAttribute('data-compact', 'true')
    await expect(nav.getByTestId('nav-group-icon')).toHaveCount(4)

    // nothing overflows the header; the menu ends before the switcher, everything inside the window
    const overflow = await page.evaluate(() => {
      const header = document.querySelector('header')!
      return header.scrollWidth - header.clientWidth
    })
    expect(overflow).toBeLessThanOrEqual(0)
    const items = nav.locator('[data-nav-item]')
    await expect(items).toHaveCount(5)
    const last = (await items.last().boundingBox())!
    expect(last.x + last.width).toBeLessThanOrEqual((await switcher.boundingBox())!.x)
    for (const item of await items.all()) await expect(item).toBeInViewport({ ratio: 1 })
    const user = (await page.getByTestId('user-menu-button').boundingBox())!
    expect(user.x + user.width).toBeLessThanOrEqual(1024)

    // the label stays the group's name (screen readers, tooltip) and the menu opens as before
    const admin = nav.getByTestId('nav-group-admin')
    await expect(admin).toHaveAccessibleName(/Yönetim/)
    await expect(admin).toHaveAttribute('title', 'Yönetim')
    const panel = await openNavMenu(page, 'admin')
    await expect(panel.getByTestId('nav-link-tokens')).toBeVisible()
  })

  test('English labels fit a wide window with the tenant switcher', async ({ page, auth, mockApi }) => {
    await page.setViewportSize({ width: 1440, height: 900 })
    mockApi.db.activeTenant = mockApi.db.tenants[0]
    await auth.signIn()
    await page.goto('/projects')

    await expect(mainNav(page)).toHaveAttribute('data-compact', 'false')
    await expect(mainNav(page).locator('[data-nav-item]')).toHaveText(['Projects', 'Build & Run', 'Data & Integrations', 'Observability', 'Administration'])
    await expect(mainNav(page).getByTestId('nav-group-icon')).toHaveCount(0)
  })
})

test.describe('Main menu in the multi-tenant edition', { tag: '@modules' }, () => {
  test.skip(({ e2eMode }) => e2eMode === 'stack', 'asserts the menus of the mocked platform administrator')

  test('platform administrator: tenants and the platform under Administration, own pages in the user menu', async ({ page, auth }) => {
    await auth.signIn()
    await page.goto('/projects')

    const admin = await openNavMenu(page, 'admin')
    await expect(admin.locator('section h3')).toHaveText(['Members', 'Access & security', 'Organization', 'Platform'])
    for (const link of ['users', 'tokens', 'auth-providers', 'audit-log', 'billing', 'platform-settings', 'tenants', 'resource-usage', 'branding', 'signup']) {
      await expect(admin.getByTestId(`nav-link-${link}`)).toBeVisible()
    }
    // the platform has no roles and groups of its own
    await expect(admin.getByTestId('nav-link-roles')).toHaveCount(0)

    const menu = await openUserMenu(page)
    await expect(menu.locator('[data-nav-link]')).toHaveText(['Account settings', 'My bills', 'Support Tickets'])
    await menu.getByTestId('nav-link-support-tickets').click()
    await expect(page).toHaveURL(/\/settings\?tab=support-tickets$/)
  })
})
