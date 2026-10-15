// Software supply chain + data residency (docs/supply-chain.md): the project Security tab and the
// locale messages. The region's Supply chain tab and the tenant's Residency tab are mounted by
// their views; the policy block modal by the project view.
import { defineAsyncComponent, markRaw } from 'vue'
import { usePermissionsStore } from '@/stores/permissions'
import en from './locales/en.js'
import tr from './locales/tr.js'

export default function (registry) {
  registry.projectTabs.push({
    id:        'security',
    labelKey:  'project.tabs.security',
    component: markRaw(defineAsyncComponent(() => import('./components/ProjectSecurity.vue'))),
    // Deployable project types, for users with security.view on the project (docs/permissions.md)
    visibleIf: (project) => ['xaas', 'faas', 'caas'].includes(project?.service_type)
      && usePermissionsStore().can('security.view', project.id),
    order:     20,
  })

  registry.localeMessages.push({ en, tr })
}
