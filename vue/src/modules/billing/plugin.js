// Billing module (docs/billing.md): RunR calculates what every project owes per month — the
// project's owner pays — and external invoicing systems read the bills through the API. Loaded
// only when the console is built with VITE_BILLING_ENABLED=true (modules/index.js); every page also
// stays hidden while the API reports billing off (GET /api/branding → features.billing), so one
// official image serves installs with and without billing.
import { defineAsyncComponent, markRaw } from 'vue'
import { CreditCard, Receipt } from 'lucide-vue-next'
import { billingApi } from './api/billing'
import { billingEnabled, can, canSeeOwnBills, canViewBilling } from './utils/billing'
import { currentUser } from '@/modules/white-label/utils/context'
import en from './locales/en.js'
import tr from './locales/tr.js'

const lazy = (loader) => markRaw(defineAsyncComponent(loader))

/** Deployable project types (the retired AI types are not billed as projects). */
const BILLED_TYPES = ['xaas', 'faas', 'caas']

/** The project's Billing tab: its owner, projects.billing on it, or billing.view. */
export function canSeeProjectBilling(project) {
  if (!billingEnabled() || !BILLED_TYPES.includes(project?.service_type)) return false
  const me = currentUser()?.id
  if (me !== undefined && me !== null && project?.owner_id !== undefined && project?.owner_id !== null
    && String(project.owner_id) === String(me)) return true
  return can('projects.billing', project?.id) || can('billing.view')
}

export default function (registry) {
  registry.settingsTabs.push({
    key: 'billing',
    labelKey: 'billing.tab',
    icon: markRaw(CreditCard),
    component: lazy(() => import('./components/BillingAdmin.vue')),
    visibleIf: canViewBilling,
    dataTour: 'settings-billing-tab',
    order: 40,
  })

  registry.settingsTabs.push({
    key: 'my-bills',
    labelKey: 'billing.myBills.tab',
    icon: markRaw(Receipt),
    component: lazy(() => import('./components/MyBills.vue')),
    visibleIf: canSeeOwnBills,
    order: 41,
  })

  registry.projectTabs.push({
    id: 'billing',
    labelKey: 'billing.project.tab',
    component: lazy(() => import('./components/ProjectBilling.vue')),
    visibleIf: canSeeProjectBilling,
    order: 30,
  })

  // Region settings (own regions): billing type and prices
  registry.regionTabs?.push({
    key: 'billing',
    labelKey: 'billing.region.tab',
    component: lazy(() => import('./components/RegionBilling.vue')),
    visibleIf: () => canViewBilling(),
    order: 50,
  })

  // Region wizard: the billing type is chosen when the region is opened
  registry.regionCreateExtensions?.push({
    key: 'billing',
    component: lazy(() => import('./components/RegionBillingTypeField.vue')),
    initial: () => ({ billing_type: 'payg' }),
    afterCreate: async (region, value) => {
      if (!billingEnabled() || !can('billing.manage') || !region?.id) return
      await billingApi.setRegionType(region.id, value?.billing_type === 'fixed' ? 'fixed' : 'payg')
    },
  })

  registry.localeMessages.push({ en, tr })
}
