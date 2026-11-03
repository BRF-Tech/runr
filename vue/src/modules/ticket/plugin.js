import { defineAsyncComponent, markRaw } from 'vue'
import { LifeBuoy } from 'lucide-vue-next'
import { useBrandingStore } from '@/modules/white-label/stores/branding'
import { usePermissionsStore } from '@/stores/permissions'
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

/**
 * The support page lists the user's own tickets (GET /support-tickets: tickets.view) or, for the
 * support staff, every ticket (GET /admin/support-tickets: tickets.manage); a role with neither
 * does not see the page (docs/permissions.md).
 */
export function canSeeSupport() {
  const perms = usePermissionsStore()
  return perms.can('tickets.view') || perms.can('tickets.manage')
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
    visibleIf: () => ticketsEnabled() && canSeeSupport(),
    dataTour:  'settings-support-tab',
    tutorial:  true,
    order:     30,
  })

  registry.localeMessages.push({ en, tr })
}
