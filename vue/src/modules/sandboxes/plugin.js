import { defineAsyncComponent, markRaw } from 'vue'
import { Box, SlidersHorizontal } from 'lucide-vue-next'
import { usePermissionsStore } from '@/stores/permissions'
import en from './locales/en.js'
import tr from './locales/tr.js'

/**
 * Sandbox API UI: the /sandboxes page (route in src/router/index.js, so deep links work before
 * the module registry's routes are added) and the sandbox settings, both in Build & Run →
 * Workloads of the main menu.
 */
const canViewSandboxes = () => usePermissionsStore().can('sandboxes.view')

export default function (registry) {
  registry.localeMessages.push({ en, tr })

  registry.navLinks.push({
    key:       'sandboxes-page',
    to:        '/sandboxes',
    labelKey:  'sandboxes.nav',
    descKey:   'sandboxes.navDesc',
    icon:      markRaw(Box),
    group:     'build',
    section:   'workloads',
    order:     10,
    // sandboxes.view (docs/permissions.md); the API enforces every sandbox permission
    visibleIf: canViewSandboxes,
  })

  registry.settingsTabs.push({
    key:       'sandboxes',
    labelKey:  'sandboxes.settingsTab',
    descKey:   'sandboxes.settingsTabDesc',
    icon:      markRaw(SlidersHorizontal),
    group:     'build',
    section:   'workloads',
    component: markRaw(defineAsyncComponent(() => import('./components/SandboxSettingsTab.vue'))),
    dataTour:  'settings-sandboxes-tab',
    visibleIf: canViewSandboxes,
    order:     20,
  })
}
