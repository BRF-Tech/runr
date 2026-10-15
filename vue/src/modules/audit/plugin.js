import { defineAsyncComponent, markRaw } from 'vue'
import { usePermissionsStore } from '@/stores/permissions'

// Settings → Audit log (docs/security.md): shown to users with the audit.view permission
// (GET /auth/permissions); the API enforces it either way.
export function canViewAuditLog() {
  return usePermissionsStore().can('audit.view')
}

export default function (registry) {
  registry.settingsTabs.push({
    key: 'audit-log',
    labelKey: 'audit.tab',
    component: markRaw(defineAsyncComponent(() => import('./components/AuditLogSettings.vue'))),
    visibleIf: canViewAuditLog,
    order: 90,
    dataTour: 'settings-audit-tab',
  })
}
