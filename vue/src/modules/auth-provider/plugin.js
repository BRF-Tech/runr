import { markRaw } from 'vue'
import OAuthCallback from './components/OAuthCallback.vue'
import AuthProviderSettings from './components/AuthProviderSettings.vue'
import LinkedAccountsCard from './components/LinkedAccountsCard.vue'
import { usePermissionsStore } from '@/stores/permissions'
import en from './locales/en'
import tr from './locales/tr'

export default function (registry) {
  // Settings tab
  registry.settingsTabs.push({
    key: 'auth-providers',
    labelKey: 'authProvider.tab',
    component: markRaw(AuthProviderSettings),
    dataTour: 'auth-providers-tab',
    // Sign-in providers and SSO group mapping: sso.manage (docs/permissions.md)
    visibleIf: () => usePermissionsStore().can('sso.manage'),
  })

  // Account settings: the user's linked external accounts (link / unlink)
  registry.accountSections.push({
    key: 'linked-accounts',
    component: markRaw(LinkedAccountsCard),
  })

  // OAuth callback route
  registry.routes.push({
    path: '/oauth/callback',
    component: markRaw(OAuthCallback),
    meta: { public: true },
  })

  // i18n messages
  registry.localeMessages.push({ en, tr })
}
