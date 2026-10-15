import { defineAsyncComponent, markRaw } from 'vue'
import { BellRing, Globe, Network } from 'lucide-vue-next'
import router from '@/router'
import { usePermissionsStore } from '@/stores/permissions'
import en from './locales/en.js'
import tr from './locales/tr.js'

/**
 * Observability (docs/observability.md): project tabs Traces / Replay / Uptime, the settings
 * tabs Alerts / Status page / Tracing (regions) and the public status page. Loaded
 * automatically (modules/index.js).
 */
const lazy = (loader) => markRaw(defineAsyncComponent(loader))
const CODE_TYPES = ['xaas', 'faas', 'caas']

export default function (registry) {
  registry.projectTabs.push(
    {
      id:        'traces',
      labelKey:  'observability.tabs.traces',
      component: lazy(() => import('./components/TracesTab.vue')),
      visibleIf: (project) => CODE_TYPES.includes(project?.service_type) && usePermissionsStore().can('traces.view', project.id),
      order:     20,
    },
    {
      id:        'replay',
      labelKey:  'observability.tabs.replay',
      component: lazy(() => import('./components/ReplayTab.vue')),
      visibleIf: (project) => CODE_TYPES.includes(project?.service_type) && usePermissionsStore().can('replay.manage', project.id),
      order:     21,
    },
    {
      id:        'uptime',
      labelKey:  'observability.tabs.uptime',
      component: lazy(() => import('./components/UptimeTab.vue')),
      visibleIf: (project) => CODE_TYPES.includes(project?.service_type) && usePermissionsStore().can('metrics.view', project.id),
      order:     22,
    },
  )

  registry.settingsTabs.push(
    {
      key:       'alerts',
      labelKey:  'observability.settingsTabs.alerts',
      icon:      markRaw(BellRing),
      component: lazy(() => import('./components/AlertsSettings.vue')),
      visibleIf: () => usePermissionsStore().can('alerts.manage'),
      order:     60,
    },
    {
      key:       'status-page',
      labelKey:  'observability.settingsTabs.statusPage',
      icon:      markRaw(Globe),
      component: lazy(() => import('./components/StatusPageSettings.vue')),
      visibleIf: () => usePermissionsStore().can('status_page.manage'),
      order:     61,
    },
    {
      key:       'tracing',
      labelKey:  'observability.settingsTabs.tracing',
      icon:      markRaw(Network),
      component: lazy(() => import('./components/RegionTracingSettings.vue')),
      visibleIf: () => usePermissionsStore().can('regions.view'),
      order:     62,
    },
  )

  // Public status page — added to the router right away (not through registry.routes, which
  // main.js adds after mount) so a link opened directly resolves on the first navigation
  const page = () => import('./components/PublicStatusPage.vue')
  router.addRoute({ path: '/status/:slug', name: 'public-status-page', component: page, meta: { public: true } })
  router.addRoute({ path: '/status', name: 'public-status-domain', component: page, meta: { public: true } })

  registry.localeMessages.push({ en, tr })
}
