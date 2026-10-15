import { defineAsyncComponent, markRaw } from 'vue'
import { usePermissionsStore } from '@/stores/permissions'

// Bindings (docs/bindings.md): the project "Bindings" tab and Settings → Data (the
// organization's bindings). Core plugin — loaded by modules/index.js with every build;
// messages: locales/{en,tr}/bindings.js in the core catalog.
export default function (registry) {
  registry.projectTabs.push({
    id:        'bindings',
    labelKey:  'bindings.tab',
    component: markRaw(defineAsyncComponent(() => import('@/components/project/ProjectBindings.vue'))),
    // Deployable project types (retired AI types have no bindings), for users who see the project
    visibleIf: (project) => ['xaas', 'faas', 'caas'].includes(project?.service_type)
      && usePermissionsStore().can('projects.view', project.id),
    order:     5,
  })

  registry.settingsTabs.push({
    key: 'data',
    labelKey: 'bindings.data.tab',
    component: markRaw(defineAsyncComponent(() => import('@/components/bindings/DataPage.vue'))),
    visibleIf: () => usePermissionsStore().can('bindings.view'),
    order: 30,
  })
}
