import { defineAsyncComponent, markRaw } from 'vue'
import { usePermissionsStore } from '@/stores/permissions'
import PermissionsLoader from '@/components/rbac/PermissionsLoader.vue'

/**
 * Roles & permissions (core plugin, always loaded): loads the user's effective permissions and
 * adds the Roles and Groups settings tabs. Strings: src/locales/{en,tr}/rbac.js.
 */
export default function (registry) {
  registry.appTopSlots.push(markRaw(PermissionsLoader))

  // The organization's roles/groups — not the platform's central context (no organization there)
  const inOrganization = () => !registry.isCentralAdminFn?.()

  registry.settingsTabs.push({
    key:       'roles',
    labelKey:  'rbac.tabRoles',
    component: markRaw(defineAsyncComponent(() => import('@/components/rbac/RolesManager.vue'))),
    visibleIf: () => inOrganization() && (usePermissionsStore().can('roles.manage') || usePermissionsStore().can('members.view')),
    order:     50,
  })

  registry.settingsTabs.push({
    key:       'groups',
    labelKey:  'rbac.tabGroups',
    component: markRaw(defineAsyncComponent(() => import('@/components/rbac/GroupsManager.vue'))),
    visibleIf: () => inOrganization() && usePermissionsStore().can('members.view'),
    order:     51,
  })
}
