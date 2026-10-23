// The console's navigation: one definition that every menu renders from — the main menu and
// its mega menus (AppHeader → MainNav), the mobile drawer (MobileNavDrawer), the user menu
// (UserMenu) and the section tabs of the settings page (SettingsView).
//
//   Projects   the first main-menu item, always on its own
//   groups     the other main-menu items; each opens a mega menu of labelled sections
//   account    the user menu (avatar): the user's own pages
//
// Links come from three places:
//   CORE_LINKS                    the core pages below
//   moduleRegistry.settingsTabs   module tabs of the settings page (/settings?tab=<key>)
//   moduleRegistry.navLinks       module pages with a route of their own
//
// A module entry names its place with `group` and `section` (keys of NAV_GROUPS, or
// ACCOUNT_GROUP) and a one-line description with `descKey`. An entry without a known group
// lands in Administration → More, one without a known section in its group's "More".
// `visibleIf(ctx)` decides who sees an entry; sections and groups left empty disappear.
import { markRaw } from 'vue'
import {
  Activity, Boxes, Database, Gauge, KeyRound, MapPin, Rocket, Server, ShieldAlert, ShieldCheck, Sparkles, Stamp, UserCog, Users,
} from 'lucide-vue-next'

/** Main-menu groups (after Projects), in order, with their sections in order. */
export const NAV_GROUPS = [
  { key: 'build',         icon: markRaw(Rocket),      sections: ['workloads', 'runtimes', 'automation'] },
  { key: 'data',          icon: markRaw(Database),    sections: ['data', 'integrations'] },
  { key: 'observability', icon: markRaw(Activity),    sections: ['monitoring'] },
  { key: 'admin',         icon: markRaw(ShieldCheck), sections: ['members', 'access', 'organization', 'platform'] },
].map(group => ({ ...group, labelKey: `nav.groups.${group.key}`, descKey: `nav.groups.${group.key}Desc` }))

/** The user menu's links (group key of the account pages). */
export const ACCOUNT_GROUP = 'account'

/** Where an entry without a known group or section goes. */
export const FALLBACK_GROUP = 'admin'
export const FALLBACK_SECTION = 'more'

/** Order of an entry that does not give one. */
const DEFAULT_ORDER = 50

const can = (permission) => (ctx) => !!ctx.can?.(permission)

/** The core pages. Settings-page tabs name their `tab`, other pages their route (`to`). */
export const CORE_LINKS = [
  {
    key: 'models', group: 'build', section: 'workloads', to: '/models', order: 30,
    labelKey: 'ai.nav.models', descKey: 'nav.links.modelsDesc', icon: markRaw(Sparkles),
    visibleIf: can('models.view'),
  },
  {
    // Promotions between environments waiting for an approver (docs/environments.md#approvals)
    key: 'approvals', group: 'build', section: 'workloads', to: '/approvals', order: 40,
    labelKey: 'environments.approvals.nav', descKey: 'nav.links.approvalsDesc', icon: markRaw(Stamp),
    visibleIf: (ctx) => !!ctx.canAnywhere?.('environments.approve') || !!ctx.canAnywhere?.('environments.promote'),
  },
  {
    // The organization's rightsizing recommendations (docs/monitoring.md#rightsizing)
    key: 'rightsizing', group: 'observability', section: 'monitoring', to: '/rightsizing', order: 60,
    labelKey: 'rightsizing.title', descKey: 'nav.links.rightsizingDesc', icon: markRaw(Gauge),
    visibleIf: (ctx) => !!ctx.canAnywhere?.('metrics.view'),
  },
  {
    key: 'runtimes', group: 'build', section: 'runtimes', tab: 'runtimes', order: 10,
    labelKey: 'runtimes.tab', descKey: 'nav.links.runtimesDesc', icon: markRaw(Boxes),
    // runtimes.manage, or runtimes.use_custom while the platform allows custom runtimes
    visibleIf: (ctx) => !!ctx.canUseRuntimes,
  },
  {
    // Runtime patches: the platform's patch feed, policy and deadlines (platform administrators,
    // outside any organization)
    key: 'patches', group: 'build', section: 'runtimes', tab: 'patches', order: 15,
    labelKey: 'runtimePatches.admin.tab', descKey: 'nav.links.patchesDesc', icon: markRaw(ShieldAlert),
    visibleIf: (ctx) => !!ctx.platformAdmin && !ctx.tenantContext,
  },
  {
    key: 'users', group: 'admin', section: 'members', tab: 'users', order: 10,
    labelKey: 'users.tab', descKey: 'nav.links.usersDesc', icon: markRaw(Users),
    visibleIf: can('members.view'),
  },
  {
    key: 'tokens', group: 'admin', section: 'access', tab: 'tokens', order: 10,
    labelKey: 'tokens.tab', descKey: 'nav.links.tokensDesc', icon: markRaw(KeyRound),
    visibleIf: can('tokens.manage'),
  },
  {
    // The settings page inside an organization of the multi-tenant edition: its regions and domain
    key: 'org-settings', group: 'admin', section: 'organization', tab: 'settings', order: 10,
    labelKey: 'nav.links.orgSettings', descKey: 'nav.links.orgSettingsDesc', icon: markRaw(MapPin),
    visibleIf: (ctx) => !!ctx.tenantContext && (ctx.can('regions.view') || ctx.can('settings.manage')),
  },
  {
    // The settings page of the install (single-tenant) or of the platform (central context)
    key: 'platform-settings', group: 'admin', section: 'platform', tab: 'settings', order: 10,
    labelKey: 'nav.links.platformSettings', descKey: 'nav.links.platformSettingsDesc', icon: markRaw(Server),
    visibleIf: (ctx) => !ctx.tenantContext && (ctx.can('regions.view') || ctx.can('settings.manage')),
  },
  {
    key: 'account', group: ACCOUNT_GROUP, to: '/account', order: 10,
    labelKey: 'nav.userSettings', descKey: 'nav.links.accountDesc', icon: markRaw(UserCog),
  },
]

const groupByKey = new Map(NAV_GROUPS.map(group => [group.key, group]))

function isVisible(entry, ctx) {
  if (typeof entry.visibleIf !== 'function') return true
  try {
    return !!entry.visibleIf(ctx)
  } catch {
    return false
  }
}

/** A link with its place resolved (known group and section) and its source. */
function normalize(entry, source, index) {
  let group = entry.group
  let section = entry.section
  if (group !== ACCOUNT_GROUP && !groupByKey.has(group)) {
    group = FALLBACK_GROUP
    section = FALLBACK_SECTION
  } else if (group !== ACCOUNT_GROUP && !groupByKey.get(group).sections.includes(section)) {
    section = FALLBACK_SECTION
  }
  return {
    key:       entry.key,
    source,
    group,
    section:   group === ACCOUNT_GROUP ? null : section,
    labelKey:  entry.labelKey,
    descKey:   entry.descKey ?? null,
    icon:      entry.icon ?? null,
    tab:       entry.tab ?? null,
    to:        entry.to ?? null,
    component: entry.component ?? null,
    dataTour:  entry.dataTour ?? null,
    order:     entry.order ?? DEFAULT_ORDER,
    index,
  }
}

const byOrder = (a, b) => a.order - b.order || a.index - b.index

/**
 * The navigation the signed-in user sees.
 *
 *   core   core links (CORE_LINKS)
 *   tabs   module settings tabs: { key, labelKey, component, group, section, descKey, icon, order, visibleIf }
 *   pages  module pages: { key, to, labelKey, group, section, descKey, icon, order, visibleIf }
 *   ctx    what visibleIf(ctx) of the core links reads: can(permission), tenantContext,
 *          canUseRuntimes (module entries read their own stores)
 *
 * A visible module tab replaces the core link of the same tab (the tenancy module's Users).
 * Returns { groups: [{ key, labelKey, descKey, icon, sections: [{ key, labelKey, links }], links }],
 * account: [links], links: [every visible link] }.
 */
export function buildNavigation({ core = CORE_LINKS, tabs = [], pages = [], ctx = {} } = {}) {
  let index = 0
  const moduleTabs = tabs
    .filter(tab => isVisible(tab, ctx))
    .map(tab => normalize({ ...tab, tab: tab.key }, 'module', index++))
  const takenTabs = new Set(moduleTabs.map(link => link.tab))
  const coreLinks = core
    .filter(link => !(link.tab && takenTabs.has(link.tab)) && isVisible(link, ctx))
    .map(link => normalize(link, 'core', index++))
  const modulePages = pages
    .filter(page => isVisible(page, ctx))
    .map(page => normalize(page, 'module', index++))

  const links = [...coreLinks, ...moduleTabs, ...modulePages].sort(byOrder)

  const groups = NAV_GROUPS.map((group) => {
    const sections = [...group.sections, FALLBACK_SECTION]
      .map(key => ({
        key,
        labelKey: `nav.sections.${key}`,
        links: links.filter(link => link.group === group.key && link.section === key),
      }))
      .filter(section => section.links.length > 0)
    return { ...group, sections, links: sections.flatMap(section => section.links) }
  }).filter(group => group.sections.length > 0)

  return {
    groups,
    account: links.filter(link => link.group === ACCOUNT_GROUP),
    links,
  }
}

/** The route location of a link: settings tabs → /settings?tab=<tab>, pages → their path. */
export function linkLocation(link) {
  if (link.tab) return link.tab === 'settings' ? { path: '/settings' } : { path: '/settings', query: { tab: link.tab } }
  return link.to
}

/** The settings tab a route shows ('settings' without ?tab). */
export function routeTab(route) {
  const tab = route?.query?.tab
  return (Array.isArray(tab) ? tab[0] : tab) || 'settings'
}

/** The route shows the link's page. */
export function isLinkActive(link, route) {
  const path = route?.path ?? ''
  if (link.tab) return path === '/settings' && routeTab(route) === link.tab
  const to = typeof link.to === 'string' ? link.to : link.to?.path
  return !!to && (path === to || path.startsWith(`${to}/`))
}

/** The visible link of a settings tab, with its group and section (null when not visible). */
export function findTabLink(nav, tab) {
  for (const group of nav.groups) {
    for (const section of group.sections) {
      const link = section.links.find(l => l.tab === tab)
      if (link) return { link, group, section }
    }
  }
  const link = nav.account.find(l => l.tab === tab)
  return link ? { link, group: null, section: null } : null
}
