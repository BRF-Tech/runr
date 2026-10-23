import { defineAsyncComponent, markRaw } from 'vue'
import { Zap } from 'lucide-vue-next'
import { usePermissionsStore } from '@/stores/permissions'

/**
 * Durable workflows UI: the project "Workflows" tab and the tenant-level "Events" view
 * (Build & Run → Automation → Events). Loaded automatically (modules/index.js);
 * messages: locales/{en,tr}/workflows.js in the core catalog.
 */
export default function (registry) {
  registry.projectTabs.push({
    id:        'workflows',
    labelKey:  'workflows.tab',
    component: markRaw(defineAsyncComponent(() => import('./components/WorkflowsTab.vue'))),
    // Every deployable project type (the tab explains how to turn a project into a workflow
    // host) for users with workflows.view on the project (docs/permissions.md)
    visibleIf: (project) => ['xaas', 'faas', 'caas'].includes(project?.service_type)
      && usePermissionsStore().can('workflows.view', project.id),
    // Every environment is a workflow app of its own (docs/workflows.md#environments): the tab
    // follows the project page's environment switcher and gets the selected `environment`
    environments: true,
    order:     10,
  })

  registry.settingsTabs.push({
    key:       'events',
    labelKey:  'workflows.events.tab',
    descKey:   'workflows.events.navDesc',
    icon:      markRaw(Zap),
    group:     'build',
    section:   'automation',
    component: markRaw(defineAsyncComponent(() => import('./components/EventsView.vue'))),
    dataTour:  'settings-events-tab',
    visibleIf: () => usePermissionsStore().can('workflows.view'),
    order:     10,
  })
}
