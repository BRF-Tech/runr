import { defineAsyncComponent, markRaw } from 'vue'
import { Radio } from 'lucide-vue-next'
import { usePermissionsStore } from '@/stores/permissions'

/**
 * Triggers (docs/triggers.md): the project "Triggers" tab (the trigger list, the inbox of webhook
 * and platform event triggers) and the organization's topics (a settings tab next to Events,
 * reachable from the user menu). The Edit Project modal embeds the same list (TriggerConfig).
 * Loaded automatically (modules/index.js); messages: locales/{en,tr}/triggers.js in the core
 * catalog.
 */
const lazy = (loader) => markRaw(defineAsyncComponent(loader))
const CODE_TYPES = ['xaas', 'faas', 'caas']

export default function (registry) {
  registry.projectTabs.push({
    id:        'triggers',
    labelKey:  'triggers.tab',
    component: lazy(() => import('./components/TriggersTab.vue')),
    visibleIf: (project) => CODE_TYPES.includes(project?.service_type)
      && usePermissionsStore().can('projects.view', project.id),
    order:     4,
  })

  registry.settingsTabs.push({
    key:       'topics',
    labelKey:  'triggers.topics.tab',
    group:     'build',
    section:   'automation',
    descKey:   'triggers.topics.navDesc',
    icon:      markRaw(Radio),
    component: lazy(() => import('./components/TopicsView.vue')),
    // GET /topics: projects.view on any project
    visibleIf: () => usePermissionsStore().canAnywhere('projects.view'),
    order:     51,
  })
}
