import { defineAsyncComponent, markRaw } from 'vue'
import { ArchiveRestore } from 'lucide-vue-next'
import { usePermissionsStore } from '@/stores/permissions'
import en from './locales/en.js'
import tr from './locales/tr.js'

/**
 * Organization export / import (core plugin, always loaded; docs/org-export.md): Administration
 * → Export / Import. Owners only — the API needs org.transfer and the Owner role, so the tab shows
 * for a member holding both (a platform administrator or an admin alone does not see it).
 */
export function canTransfer() {
  const perms = usePermissionsStore()
  if (!perms.loaded) return true // until loaded: show, the API decides
  return perms.can('org.transfer') && perms.role === 'owner'
}

export default function (registry) {
  // The organization's own data — not the platform's central context (no organization there)
  const inOrganization = () => !registry.isCentralAdminFn?.()

  registry.settingsTabs.push({
    key:       'org-transfer',
    labelKey:  'orgTransfer.tab',
    group:     'admin',
    section:   'organization',
    descKey:   'orgTransfer.navDesc',
    icon:      markRaw(ArchiveRestore),
    component: markRaw(defineAsyncComponent(() => import('./components/OrgTransferManager.vue'))),
    visibleIf: () => inOrganization() && canTransfer(),
    order:     92,
  })

  registry.localeMessages.push({ en, tr })
}
