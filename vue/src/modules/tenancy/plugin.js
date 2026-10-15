import { defineAsyncComponent, markRaw } from 'vue'
import { useSettingsStore } from '@/stores/settings'
import { usePermissionsStore } from '@/stores/permissions'
import { setBroadcastChannelPrefix, resubscribeBroadcast } from '@/composables/useRealtimeNotifications'
import en from './locales/en.js'
import tr from './locales/tr.js'

// Restore tenant channel prefix from localStorage before App.vue mounts
const _saved = localStorage.getItem('active_tenant')
if (_saved) {
  try {
    const _parsed = JSON.parse(_saved)
    if (_parsed?.id) setBroadcastChannelPrefix(`tenant.${_parsed.id}`)
  } catch { /* ignore */ }
}

export default function (registry) {
  // Header: TenantSwitcher (self-guards via tenancy_enabled check inside component)
  registry.headerSlots.push(
    markRaw(defineAsyncComponent(() => import('./components/TenantSwitcher.vue')))
  )

  // App top: ImpersonationBar (self-guards via isImpersonating check)
  registry.appTopSlots.push(
    markRaw(defineAsyncComponent(() => import('./components/ImpersonationBar.vue')))
  )

  // Lazy tenancy check — called at render time when settings are loaded
  const isTenancyEnabled = () => useSettingsStore().data?.tenancy_enabled === true

  // Hide registration toggle when inside a tenant (registration is a global/central concern)
  registry.hideRegistrationToggleFn = () => {
    if (!isTenancyEnabled()) return false
    try {
      const t = JSON.parse(localStorage.getItem('active_tenant') || 'null')
      return !!t?.id
    } catch { return false }
  }

  // Hide settings resource overview when tenancy is active
  registry.hideResourceOverviewFn = isTenancyEnabled

  // Queue stream suffix: append -tenantSlug for Redis isolation
  registry.queueStreamSuffixFn = () => {
    try {
      const t = JSON.parse(localStorage.getItem('active_tenant') || 'null')
      return t?.slug ? `-${t.slug}` : ''
    } catch { return '' }
  }

  // Central admin check (tenancy enabled + admin + no active tenant)
  const isCentralAdmin = () => {
    if (!isTenancyEnabled()) return false
    const user = JSON.parse(localStorage.getItem('user') || 'null')
    if (!user?.is_admin) return false
    const tenant = localStorage.getItem('active_tenant')
    if (!tenant) return true
    try { return !JSON.parse(tenant)?.id } catch { return true }
  }
  registry.isCentralAdminFn = isCentralAdmin

  // Settings tab — only visible for admin users in central mode (no active tenant)
  registry.settingsTabs.push({
    key: 'tenants',
    labelKey: 'tenancy.tabTenants',
    component: markRaw(defineAsyncComponent(() => import('./components/TenantSettings.vue'))),
    visibleIf: isCentralAdmin,
    dataTour: 'settings-tenants-tab',
  })

  // Users management tab — visible only when tenancy is enabled (members.view; platform admins)
  registry.settingsTabs.push({
    key: 'users',
    labelKey: 'tenancy.tabUsers',
    component: markRaw(defineAsyncComponent(() => import('./components/UsersManagement.vue'))),
    visibleIf: () => isTenancyEnabled() && (isCentralAdmin() || usePermissionsStore().can('members.view')),
    dataTour: 'settings-users-tab',
  })

  // Resource usage tab — central admin only
  registry.settingsTabs.push({
    key: 'resource-usage',
    labelKey: 'tenancy.tabResourceUsage',
    component: markRaw(defineAsyncComponent(() => import('./components/ResourceUsage.vue'))),
    visibleIf: isCentralAdmin,
    dataTour: 'settings-usage-tab',
  })

  // Billing & cost tab — central admin only
  registry.settingsTabs.push({
    key: 'billing',
    labelKey: 'tenancy.tabBilling',
    component: markRaw(defineAsyncComponent(() => import('./components/TenantBilling.vue'))),
    visibleIf: isCentralAdmin,
    dataTour: 'settings-billing-tab',
  })

  // Billing tab of a tenant — billing.view in the active tenant (its charges, credit, purchases)
  const canViewTenantBilling = () => {
    if (!isTenancyEnabled()) return false
    try {
      const t = JSON.parse(localStorage.getItem('active_tenant') || 'null')
      if (!t?.id) return false
      return usePermissionsStore().can('billing.view')
    } catch { return false }
  }
  registry.settingsTabs.push({
    key: 'charges',
    labelKey: 'tenancy.tabCharges',
    component: markRaw(defineAsyncComponent(() => import('./components/TenantOwnBilling.vue'))),
    visibleIf: canViewTenantBilling,
  })

  // Register form: inject workspace name field when tenancy is active
  registry.registerSlots.extraFields = markRaw(defineAsyncComponent(
    () => import('./components/RegisterTenantField.vue')
  ))

  // Login hook: store tenant data + scope broadcast channels
  registry.loginHooks.push(async (data) => {
    if (data.tenants) {
      const { useTenantStore } = await import('./stores/tenant')
      useTenantStore().setFromLogin(data)
    }
    if (data.active_tenant?.id) {
      setBroadcastChannelPrefix(`tenant.${data.active_tenant.id}`)
      resubscribeBroadcast()
    }
  })

  // Request interceptor: add X-Tenant-ID header
  registry.requestInterceptors.push((config) => {
    const tenant = localStorage.getItem('active_tenant')
    if (tenant) {
      try {
        const parsed = JSON.parse(tenant)
        if (parsed?.id) {
          config.headers['X-Tenant-ID'] = parsed.id
          config.headers['X-Context-ID'] = parsed.id
        }
      } catch { /* ignore */ }
    }
    return config
  })

  // Public route: invitation acceptance page
  registry.routes.push({
    path: '/invitations/:token/accept',
    name: 'accept-invitation',
    component: markRaw(defineAsyncComponent(() => import('./components/AcceptInvitationView.vue'))),
    meta: { public: true },
  })

  // Locale messages (merged into i18n after app init)
  registry.localeMessages.push({ en, tr })
}
