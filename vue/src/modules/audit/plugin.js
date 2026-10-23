import { defineAsyncComponent, markRaw } from 'vue'
import { ScrollText } from 'lucide-vue-next'
import { usePermissionsStore } from '@/stores/permissions'

// Administration → Audit log (docs/security.md): shown to users with the audit.view permission
// (GET /auth/permissions); the API enforces it either way.
export function canViewAuditLog() {
  return usePermissionsStore().can('audit.view')
}

export default function (registry) {
  registry.settingsTabs.push({
    key: 'audit-log',
    labelKey: 'audit.tab',
    descKey: 'audit.navDesc',
    icon: markRaw(ScrollText),
    group: 'admin',
    section: 'access',
    component: markRaw(defineAsyncComponent(() => import('./components/AuditLogSettings.vue'))),
    visibleIf: canViewAuditLog,
    order: 30,
    dataTour: 'settings-audit-tab',
  })
}
