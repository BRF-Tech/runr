import { defineAsyncComponent, markRaw } from 'vue'
import { Package } from 'lucide-vue-next'
import { usePermissionsStore } from '@/stores/permissions'
import en from './locales/en.js'
import tr from './locales/tr.js'

/**
 * Uploaded images (core plugin, always loaded; docs/images.md): Build & Run → Images — upload with
 * progress and resume, the list, delete — and the messages of the CaaS image picker and the
 * runtime editor's "version from an uploaded image".
 */
export default function (registry) {
  registry.settingsTabs.push({
    key:       'images',
    labelKey:  'images.tab',
    descKey:   'images.navDesc',
    icon:      markRaw(Package),
    group:     'build',
    section:   'runtimes',
    component: markRaw(defineAsyncComponent(() => import('./components/ImagesManager.vue'))),
    visibleIf: () => usePermissionsStore().can('images.view'),
    order:     20,
  })

  registry.localeMessages.push({ en, tr })
}
