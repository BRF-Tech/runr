import { defineAsyncComponent, markRaw } from 'vue'
import { LifeBuoy } from 'lucide-vue-next'
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
    descKey:   'account.ticketNavDesc',
    icon:      markRaw(LifeBuoy),
    // the user menu (every user asks for help there; staff answer on the same page)
    group:     'account',
    component: markRaw(defineAsyncComponent(() => import('./components/AccountSupportTab.vue'))),
    visibleIf: ticketsEnabled,
    dataTour:  'settings-support-tab',
    order:     30,
  })

  registry.localeMessages.push({ en, tr })
}
