import { describe, expect, it } from 'vitest'
import {
  ACCOUNT_GROUP, CORE_LINKS, NAV_GROUPS, buildNavigation, findTabLink, isLinkActive, linkLocation, routeTab,
} from '@/navigation/menu'

// The navigation definition (src/navigation/menu.js): placement, ordering, permission
// filtering and the helpers the menus share.

const ALL = () => true
const only = (...permissions) => (permission) => permissions.includes(permission)
const ctx = (over = {}) => ({ can: ALL, tenantContext: false, canUseRuntimes: true, ...over })

/** { group: { section: [link keys] } } of a navigation. */
function tree(nav) {
  return Object.fromEntries(nav.groups.map(group => [
    group.key,
    Object.fromEntries(group.sections.map(section => [section.key, section.links.map(link => link.key)])),
  ]))
}

describe('navigation definition', () => {
  it('declares the main-menu groups in order, each with an icon, label and description', () => {
    expect(NAV_GROUPS.map(group => group.key)).toEqual(['build', 'data', 'observability', 'admin'])
    for (const group of NAV_GROUPS) {
      expect(group.icon).toBeTruthy()
      expect(group.labelKey).toBe(`nav.groups.${group.key}`)
      expect(group.descKey).toBe(`nav.groups.${group.key}Desc`)
      expect(group.sections.length).toBeGreaterThan(0)
    }
  })

  it('places every core link in a declared group and section, with a description', () => {
    for (const link of CORE_LINKS) {
      expect(link.labelKey).toBeTruthy()
      expect(link.descKey).toBeTruthy()
      expect(link.tab || link.to).toBeTruthy()
      if (link.group === ACCOUNT_GROUP) continue
      const group = NAV_GROUPS.find(g => g.key === link.group)
      expect(group, link.key).toBeTruthy()
      expect(group.sections).toContain(link.section)
    }
  })

  it('never puts Projects into a group: it is the main menu\'s own first item', () => {
    const nav = buildNavigation({ ctx: ctx() })
    expect(nav.links.some(link => link.to === '/projects')).toBe(false)
  })

  it('builds the core tree of a user with every permission', () => {
    const nav = buildNavigation({ ctx: ctx() })
    expect(tree(nav)).toEqual({
      build: { workloads: ['models'], runtimes: ['runtimes'] },
      admin: { members: ['users'], access: ['tokens'], platform: ['platform-settings'] },
    })
    expect(nav.account.map(link => link.key)).toEqual(['account'])
  })

  it('places module tabs and pages by their declared group and section, ordered by `order`', () => {
    const tabs = [
      { key: 'alerts', labelKey: 'a', group: 'observability', section: 'monitoring', order: 20 },
      { key: 'tracing', labelKey: 'b', group: 'observability', section: 'monitoring', order: 10 },
      { key: 'roles', labelKey: 'c', group: 'admin', section: 'members', order: 20 },
    ]
    const pages = [{ key: 'sandboxes-page', to: '/sandboxes', labelKey: 'd', group: 'build', section: 'workloads', order: 10 }]
    const nav = buildNavigation({ tabs, pages, ctx: ctx() })
    expect(tree(nav).observability).toEqual({ monitoring: ['tracing', 'alerts'] })
    expect(tree(nav).admin.members).toEqual(['users', 'roles'])
    expect(tree(nav).build.workloads).toEqual(['sandboxes-page', 'models'])
  })

  it('puts an entry without a known group into Administration → More, without a known section into its group\'s More', () => {
    const tabs = [
      { key: 'legacy', labelKey: 'x' },
      { key: 'odd', labelKey: 'y', group: 'nowhere', section: 'members' },
      { key: 'stray', labelKey: 'z', group: 'data', section: 'unknown' },
    ]
    const nav = buildNavigation({ tabs, ctx: ctx() })
    expect(tree(nav).admin.more).toEqual(['legacy', 'odd'])
    expect(tree(nav).data).toEqual({ more: ['stray'] })
    // "More" comes after the declared sections
    const admin = nav.groups.find(group => group.key === 'admin')
    expect(admin.sections.map(section => section.key)).toEqual(['members', 'access', 'platform', 'more'])
  })

  it('sends account entries to the user menu instead of a group', () => {
    const tabs = [{ key: 'my-bills', labelKey: 'x', group: ACCOUNT_GROUP, order: 20 }]
    const nav = buildNavigation({ tabs, ctx: ctx() })
    expect(nav.account.map(link => link.key)).toEqual(['account', 'my-bills'])
    expect(nav.groups.flatMap(group => group.links).some(link => link.key === 'my-bills')).toBe(false)
  })

  it('filters by permission: links, then empty sections and groups disappear', () => {
    const nav = buildNavigation({ ctx: ctx({ can: only('tokens.manage'), canUseRuntimes: false }) })
    expect(tree(nav)).toEqual({ admin: { access: ['tokens'] } })

    const none = buildNavigation({ ctx: ctx({ can: () => false, canUseRuntimes: false }) })
    expect(none.groups).toEqual([])
    expect(none.account.map(link => link.key)).toEqual(['account'])
  })

  it('hands module visibleIf the context and hides an entry whose visibleIf throws', () => {
    const seen = []
    const tabs = [
      { key: 'seen', labelKey: 'x', group: 'data', section: 'data', visibleIf: (c) => { seen.push(c); return true } },
      { key: 'broken', labelKey: 'y', group: 'data', section: 'data', visibleIf: () => { throw new Error('store not ready') } },
      { key: 'hidden', labelKey: 'z', group: 'data', section: 'data', visibleIf: () => false },
    ]
    const context = ctx()
    const nav = buildNavigation({ tabs, ctx: context })
    expect(tree(nav).data).toEqual({ data: ['seen'] })
    expect(seen[0]).toBe(context)
  })

  it('lets a visible module tab replace the core link of the same tab (tenancy Users)', () => {
    const users = { key: 'users', labelKey: 'tenancy.tabUsers', group: 'admin', section: 'members', component: {}, visibleIf: () => true }
    const nav = buildNavigation({ tabs: [users], ctx: ctx() })
    const link = nav.links.find(l => l.tab === 'users')
    expect(link.source).toBe('module')
    expect(link.labelKey).toBe('tenancy.tabUsers')
    expect(nav.links.filter(l => l.tab === 'users')).toHaveLength(1)

    // a hidden module tab leaves the core link
    const hidden = buildNavigation({ tabs: [{ ...users, visibleIf: () => false }], ctx: ctx() })
    expect(hidden.links.find(l => l.tab === 'users').source).toBe('core')
  })

  it('shows the settings page as Regions & settings outside an organization, Regions & domain inside one', () => {
    const outside = buildNavigation({ ctx: ctx() })
    expect(findTabLink(outside, 'settings').link.key).toBe('platform-settings')
    expect(findTabLink(outside, 'settings').section.key).toBe('platform')

    const inside = buildNavigation({ ctx: ctx({ tenantContext: true }) })
    expect(findTabLink(inside, 'settings').link.key).toBe('org-settings')
    expect(findTabLink(inside, 'settings').section.key).toBe('organization')
    expect(inside.links.some(link => link.key === 'platform-settings')).toBe(false)

    // neither regions.view nor settings.manage: no link (the page stays reachable by its URL)
    const none = buildNavigation({ ctx: ctx({ can: only('tokens.manage') }) })
    expect(findTabLink(none, 'settings')).toBeNull()
  })

  it('shows Runtimes with the runtime abilities and Models with models.view', () => {
    expect(buildNavigation({ ctx: ctx({ canUseRuntimes: false }) }).links.some(l => l.key === 'runtimes')).toBe(false)
    expect(buildNavigation({ ctx: ctx({ can: only('regions.view') }) }).links.some(l => l.key === 'models')).toBe(false)
    expect(buildNavigation({ ctx: ctx({ can: only('models.view') }) }).links.some(l => l.key === 'models')).toBe(true)
  })

  it('keeps each link\'s component, icon and data-tour for the menus and the settings page', () => {
    const component = { name: 'X' }
    const icon = { name: 'Icon' }
    const nav = buildNavigation({ tabs: [{ key: 'data', labelKey: 'x', group: 'data', section: 'data', component, icon, dataTour: 'settings-data-tab' }], ctx: ctx() })
    const link = findTabLink(nav, 'data').link
    expect(link).toMatchObject({ component, icon, dataTour: 'settings-data-tab', tab: 'data', source: 'module' })
  })
})

describe('navigation helpers', () => {
  const tab = (key) => ({ tab: key })

  it('links a settings tab to /settings?tab=<key> and the main settings page to /settings', () => {
    expect(linkLocation(tab('tokens'))).toEqual({ path: '/settings', query: { tab: 'tokens' } })
    expect(linkLocation(tab('settings'))).toEqual({ path: '/settings' })
    expect(linkLocation({ to: '/models' })).toBe('/models')
  })

  it('reads the tab of a route (settings without ?tab)', () => {
    expect(routeTab({ query: {} })).toBe('settings')
    expect(routeTab({ query: { tab: 'billing' } })).toBe('billing')
    expect(routeTab({ query: { tab: ['images', 'x'] } })).toBe('images')
    expect(routeTab(undefined)).toBe('settings')
  })

  it('marks the link of the page the route shows', () => {
    expect(isLinkActive(tab('tokens'), { path: '/settings', query: { tab: 'tokens' } })).toBe(true)
    expect(isLinkActive(tab('tokens'), { path: '/settings', query: {} })).toBe(false)
    expect(isLinkActive(tab('settings'), { path: '/settings', query: {} })).toBe(true)
    expect(isLinkActive({ to: '/sandboxes' }, { path: '/sandboxes', query: {} })).toBe(true)
    expect(isLinkActive({ to: '/models' }, { path: '/models/abc', query: {} })).toBe(true)
    expect(isLinkActive({ to: '/models' }, { path: '/modelsx', query: {} })).toBe(false)
  })

  it('finds a tab in the user menu as well (no group or section)', () => {
    const nav = buildNavigation({ tabs: [{ key: 'support-tickets', labelKey: 'x', group: ACCOUNT_GROUP }], ctx: ctx() })
    expect(findTabLink(nav, 'support-tickets')).toMatchObject({ group: null, section: null, link: { key: 'support-tickets' } })
    expect(findTabLink(nav, 'nope')).toBeNull()
  })
})
