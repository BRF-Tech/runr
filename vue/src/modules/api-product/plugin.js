import { defineAsyncComponent, markRaw } from 'vue'
import { Waypoints } from 'lucide-vue-next'
import router from '@/router'
import { usePermissionsStore } from '@/stores/permissions'
import en from './locales/en.js'
import tr from './locales/tr.js'

/**
 * API product of the gateways (docs/api-product.md): the settings tab "API gateways" (routes and
 * their authentication, consumers and keys, plans, usage, custom domain, developer page) and the
 * public developer page. Loaded automatically (modules/index.js).
 */
const lazy = (loader) => markRaw(defineAsyncComponent(loader))

export default function (registry) {
  registry.settingsTabs.push({
    key:       'gateways',
    labelKey:  'apiProduct.tab',
    group:     'build',
    section:   'workloads',
    descKey:   'apiProduct.navDesc',
    icon:      markRaw(Waypoints),
    component: lazy(() => import('./components/GatewaysSettings.vue')),
    // whoever sees projects sees the gateways (GatewayPolicy); the owner manages its own
    visibleIf: () => usePermissionsStore().can('projects.view'),
    order:     55,
  })

  // Public developer page — added right away so a link opened directly resolves
  router.addRoute({
    path: '/developers/:key',
    name: 'public-developer-page',
    component: () => import('./components/DeveloperPage.vue'),
    meta: { public: true },
  })

  registry.localeMessages.push({ en, tr })
}
