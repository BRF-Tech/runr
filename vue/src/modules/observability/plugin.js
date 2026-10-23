import { defineAsyncComponent, markRaw } from 'vue'
import { BellRing, Globe, Network } from 'lucide-vue-next'
import router from '@/router'
import { usePermissionsStore } from '@/stores/permissions'
import en from './locales/en.js'
import tr from './locales/tr.js'

/**
 * Observability (docs/observability.md): project tabs Traces / Replay / Uptime / Load test, the
 * pages Alerts / Tracing (regions) / Status page of the Observability menu and the public status page. Loaded
 * automatically (modules/index.js).
 */
const lazy = (loader) => markRaw(defineAsyncComponent(loader))
const CODE_TYPES = ['xaas', 'faas', 'caas']
const isHttp = (project) => (project?.config?.trigger_type ?? 'http') === 'http'

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
    {
      // HTTP projects only: the test targets the project's own URL (docs/load-test.md)
      id:        'load-test',
      labelKey:  'observability.tabs.loadTest',
      component: lazy(() => import('./components/LoadTestTab.vue')),
      visibleIf: (project) => CODE_TYPES.includes(project?.service_type) && isHttp(project) && usePermissionsStore().can('metrics.view', project.id),
      order:     23,
    },
  )

  registry.settingsTabs.push(
    {
      key:       'alerts',
      labelKey:  'observability.settingsTabs.alerts',
      descKey:   'observability.settingsTabs.alertsDesc',
      icon:      markRaw(BellRing),
      group:     'observability',
      section:   'monitoring',
      component: lazy(() => import('./components/AlertsSettings.vue')),
      visibleIf: () => usePermissionsStore().can('alerts.manage'),
      order:     10,
    },
    {
      key:       'status-page',
      labelKey:  'observability.settingsTabs.statusPage',
      descKey:   'observability.settingsTabs.statusPageDesc',
      icon:      markRaw(Globe),
      group:     'observability',
      section:   'monitoring',
      component: lazy(() => import('./components/StatusPageSettings.vue')),
      visibleIf: () => usePermissionsStore().can('status_page.manage'),
      order:     30,
    },
    {
      key:       'tracing',
      labelKey:  'observability.settingsTabs.tracing',
      descKey:   'observability.settingsTabs.tracingDesc',
      icon:      markRaw(Network),
      group:     'observability',
      section:   'monitoring',
      component: lazy(() => import('./components/RegionTracingSettings.vue')),
      visibleIf: () => usePermissionsStore().can('regions.view'),
      order:     20,
    },
  )

  // Public status page — added to the router right away (not through registry.routes, which
  // main.js adds after mount) so a link opened directly resolves on the first navigation
  const page = () => import('./components/PublicStatusPage.vue')
  router.addRoute({ path: '/status/:slug', name: 'public-status-page', component: page, meta: { public: true } })
  router.addRoute({ path: '/status', name: 'public-status-domain', component: page, meta: { public: true } })

  registry.localeMessages.push({ en, tr })
}
