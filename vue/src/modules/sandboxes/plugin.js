import { defineAsyncComponent, markRaw } from 'vue'
import { usePermissionsStore } from '@/stores/permissions'
import en from './locales/en.js'
import tr from './locales/tr.js'

/**
 * Sandbox API UI: the /sandboxes page (route in src/router/index.js, so deep links work before
 * the module registry's routes are added), a header link to it and Settings → Sandboxes.
 */
export default function (registry) {
  registry.localeMessages.push({ en, tr })

  registry.headerSlots.push(
    markRaw(defineAsyncComponent(() => import('./components/SandboxesNavLink.vue'))),
  )

  registry.settingsTabs.push({
    key:       'sandboxes',
    labelKey:  'sandboxes.settingsTab',
    component: markRaw(defineAsyncComponent(() => import('./components/SandboxSettingsTab.vue'))),
    dataTour:  'settings-sandboxes-tab',
    // sandboxes.view (docs/permissions.md); the API enforces every sandbox permission
    visibleIf: () => usePermissionsStore().can('sandboxes.view'),
    order:     50,
  })
}
