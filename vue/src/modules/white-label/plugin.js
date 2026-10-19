// White-label module (both editions): branding, self-service signup extras, signup e-mail
// verification page, onboarding wizard (docs/white-label.md). Billing is the Billing module's.
// Auto-loaded by modules/index.js (core plugin glob).
import { defineAsyncComponent, markRaw } from 'vue'
import { Palette, UserPlus, Brush } from 'lucide-vue-next'
import router from '@/router'
import { applyBranding, readCachedBranding } from './utils/branding'
import { canInTenant, canManageInstall, isMultiTenantBuild } from './utils/context'
import { useBrandingStore } from './stores/branding'
import en from './locales/en.js'
import tr from './locales/tr.js'

// Last known branding before the first paint (no colour / title flash on reload)
const cached = readCachedBranding()
if (cached) applyBranding(cached)

const lazy = (loader) => markRaw(defineAsyncComponent(loader))

export default function (registry) {
  const multiTenant = isMultiTenantBuild()

  // Loads the branding (platform / active tenant), renders nothing
  registry.appTopSlots.push(lazy(() => import('./components/BrandingRoot.vue')))

  if (multiTenant) {
    registry.appTopSlots.push(lazy(() => import('./components/OnboardingWizard.vue')))
  }

  // Register form (step 2): CAPTCHA, terms acceptance
  registry.registerExtensions.push(lazy(() => import('./components/SignupExtras.vue')))

  // Settings tabs
  registry.settingsTabs.push({
    key: 'branding',
    labelKey: 'whiteLabel.tabBranding',
    icon: markRaw(Palette),
    component: lazy(() => import('./components/BrandingSettings.vue')),
    visibleIf: () => canManageInstall('branding.manage', multiTenant),
    order: 50,
  })
  registry.settingsTabs.push({
    key: 'signup',
    labelKey: 'whiteLabel.tabSignup',
    icon: markRaw(UserPlus),
    component: lazy(() => import('./components/SignupSettings.vue')),
    visibleIf: () => canManageInstall('signup.manage', multiTenant),
    order: 51,
  })

  if (multiTenant) {
    const features = () => {
      try { return useBrandingStore().features } catch { return {} }
    }

    registry.settingsTabs.push({
      key: 'org-branding',
      labelKey: 'whiteLabel.tabOrgBranding',
      icon: markRaw(Brush),
      component: lazy(() => import('./components/TenantBranding.vue')),
      visibleIf: () => canInTenant('branding.manage', multiTenant) && !!features().tenant_branding,
      order: 54,
    })
  }

  // After login: branding of the tenant the user lands in (or the platform one)
  registry.loginHooks.push(async (data) => {
    useBrandingStore().load(data?.active_tenant?.slug || null)
  })

  // Signup e-mail verification link target. Added to the router right away (not through
  // registry.routes, which main.js adds after mount) so the link opened from the e-mail
  // resolves on the first navigation instead of the catch-all redirect.
  router.addRoute({
    path: '/signup/verify',
    name: 'signup-verify',
    component: () => import('./components/VerifyEmailView.vue'),
    meta: { public: true },
  })

  registry.localeMessages.push({ en, tr })
}
