// White-label module (both editions): branding, self-service signup extras, signup e-mail
// verification page, onboarding wizard (docs/white-label.md), the bot protection of the anonymous
// forms (docs/security.md#bot-protection). Billing is the Billing module's.
// Auto-loaded by modules/index.js (core plugin glob).
import { defineAsyncComponent, markRaw } from 'vue'
import { Palette, UserPlus, Brush, Bot } from 'lucide-vue-next'
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

  // Register form (step 2): terms acceptance, allowed domains (the CAPTCHA is RegisterForm's own)
  registry.registerExtensions.push(lazy(() => import('./components/SignupExtras.vue')))

  // Administration → Platform (install) and → Organization (a tenant's own brand)
  registry.settingsTabs.push({
    key: 'branding',
    labelKey: 'whiteLabel.tabBranding',
    descKey: 'whiteLabel.navBrandingDesc',
    icon: markRaw(Palette),
    group: 'admin',
    section: 'platform',
    component: lazy(() => import('./components/BrandingSettings.vue')),
    visibleIf: () => canManageInstall('branding.manage', multiTenant),
    order: 40,
    dataTour: 'settings-branding-tab',
    // The page starts its own tour (brandingSteps: the colour and theme controls)
    tutorial: true,
  })
  registry.settingsTabs.push({
    key: 'signup',
    labelKey: 'whiteLabel.tabSignup',
    descKey: 'whiteLabel.navSignupDesc',
    icon: markRaw(UserPlus),
    group: 'admin',
    section: 'platform',
    component: lazy(() => import('./components/SignupSettings.vue')),
    visibleIf: () => canManageInstall('signup.manage', multiTenant),
    order: 51,
  })

  // Administration → Access & security → Bot protection: the install's (platform administrators;
  // single-tenant settings.manage) or, inside an organization, its own provider keys
  registry.settingsTabs.push({
    key: 'bot-protection',
    labelKey: 'whiteLabel.tabBotProtection',
    descKey: 'whiteLabel.navBotProtectionDesc',
    icon: markRaw(Bot),
    group: 'admin',
    section: 'access',
    component: lazy(() => import('./components/BotProtectionSettings.vue')),
    visibleIf: () => canManageInstall('settings.manage', multiTenant) || canInTenant('settings.manage', multiTenant),
    order: 40,
    dataTour: 'settings-bot-protection-tab',
    // The page starts its own tour (botProtectionSteps: provider, keys, forms, fallback)
    tutorial: true,
  })

  if (multiTenant) {
    const features = () => {
      try { return useBrandingStore().features } catch { return {} }
    }

    registry.settingsTabs.push({
      key: 'org-branding',
      labelKey: 'whiteLabel.tabOrgBranding',
      descKey: 'whiteLabel.navOrgBrandingDesc',
      icon: markRaw(Brush),
      group: 'admin',
      section: 'organization',
      component: lazy(() => import('./components/TenantBranding.vue')),
      visibleIf: () => canInTenant('branding.manage', multiTenant) && !!features().tenant_branding,
      order: 20,
      dataTour: 'settings-org-branding-tab',
      tutorial: true,
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
