import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { moduleRegistry } from '@/modules/index'
import { usePermissionsStore } from '@/stores/permissions'
import { useRuntimesStore } from '@/stores/runtimes'
import { buildNavigation, findTabLink, isLinkActive, linkLocation } from './menu'

/**
 * What the core links' visibleIf(ctx) reads: the user's permissions, whether the user is a
 * platform administrator, whether the console works inside an organization of the multi-tenant
 * edition and the runtime abilities (the catalog's answer once loaded; until then the
 * permissions).
 */
export function navigationContext({ permissions, runtimes, registry = moduleRegistry }) {
  const can = (permission) => permissions.can(permission)
  const canAnywhere = (permission) => (typeof permissions.canAnywhere === 'function' ? permissions.canAnywhere(permission) : permissions.can(permission))
  const tenantContext = typeof registry.isCentralAdminFn === 'function' ? !registry.isCentralAdminFn() : false
  const canUseRuntimes = runtimes?.loaded
    ? !!runtimes.canUseCustom
    : can('runtimes.manage') || can('runtimes.use_custom')
  return { can, canAnywhere, platformAdmin: !!permissions.platformAdmin, tenantContext, canUseRuntimes }
}

/** The navigation tree of the signed-in user, recomputed when permissions or modules change. */
export function useNavigation() {
  const route = useRoute()
  const permissions = usePermissionsStore()
  const runtimes = useRuntimesStore()

  const nav = computed(() => {
    // a tenant switch re-mounts the views (viewKey): read it so the tree follows
    void moduleRegistry.viewKey
    return buildNavigation({
      tabs:  moduleRegistry.settingsTabs,
      pages: moduleRegistry.navLinks ?? [],
      ctx:   navigationContext({ permissions, runtimes }),
    })
  })

  const groups  = computed(() => nav.value.groups)
  const account = computed(() => nav.value.account)

  const isActive = (link) => isLinkActive(link, route)
  const isGroupActive = (group) => group.links.some(isActive)
  const projectsActive = computed(() => route.path === '/projects' || route.path.startsWith('/projects/'))

  return {
    nav,
    groups,
    account,
    projectsActive,
    isActive,
    isGroupActive,
    linkLocation,
    findTab: (tab) => findTabLink(nav.value, tab),
  }
}
