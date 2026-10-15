import { defineAsyncComponent, markRaw } from 'vue'
import en from './locales/en.js'
import tr from './locales/tr.js'

export default function (registry) {
  registry.settingsTabs.push({
    key:       'support-tickets',
    labelKey:  'account.ticketTitle',
    component: markRaw(defineAsyncComponent(() => import('./components/AccountSupportTab.vue'))),
    dataTour:  'settings-support-tab',
    order:     999,
  })

  registry.localeMessages.push({ en, tr })
}
