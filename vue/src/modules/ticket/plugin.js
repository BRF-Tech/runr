import { defineAsyncComponent, markRaw } from 'vue'
import { useBrandingStore } from '@/modules/white-label/stores/branding'
import en from './locales/en.js'
import tr from './locales/tr.js'

/**
 * The Ticket module is on in the API (GET /api/branding → features.tickets). A console image built
 * with VITE_TICKET_ENABLED=true hides the support pages of an API that runs without the module;
 * an API that does not report the flag (before 0.5.8) always had them.
 */
export function ticketsEnabled() {
  try {
    return useBrandingStore().features?.tickets !== false
  } catch {
    return true
  }
}

export default function (registry) {
  registry.settingsTabs.push({
    key:       'support-tickets',
    labelKey:  'account.ticketTitle',
    component: markRaw(defineAsyncComponent(() => import('./components/AccountSupportTab.vue'))),
    visibleIf: ticketsEnabled,
    dataTour:  'settings-support-tab',
    order:     999,
  })

  registry.localeMessages.push({ en, tr })
}
