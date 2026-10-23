import { reactive } from 'vue'

export const moduleRegistry = reactive({
  headerSlots: [],          // Components for AppHeader right side (context switchers: the tenant switcher)
  appTopSlots: [],          // Components rendered above RouterView
  // Settings-page tabs (/settings?tab=<key>), placed in the main menu by their group and section
  // (src/navigation/menu.js): { key, labelKey, component, group, section, descKey, icon, order, visibleIf(), dataTour }
  settingsTabs: [],
  // Module pages with a route of their own, in the main menu: { key, to, labelKey, group, section, descKey, icon, order, visibleIf() }
  navLinks: [],
  accountSections: [],      // Extra sections of Account settings: { key, component }
  projectTabs: [],          // Extra project detail tabs: { id, labelKey, component, visibleIf(project), order }
  regionTabs: [],           // Extra tabs of an own region's detail: { key, labelKey, component (prop region), visibleIf(region), order }
  regionCreateExtensions: [], // Fields of the region wizard's first step: { key, component (v-model: value), initial(), afterCreate(region, value) }
  projectsActions: [],      // Components next to "New project" (props hasReadyRegion, existingNames; emits created)
  loginHooks: [],           // async (loginData) => {} — called after successful login
  requestInterceptors: [],  // (config) => config — axios request interceptors
  localeMessages: [],       // { en: {...}, tr: {...} } — merged into i18n
  loginSlots: {},            // { beforeForm: Component } — rendered in LoginView
  registerSlots: {},         // { extraFields: Component } — rendered in RegisterForm
  registerExtensions: [],    // Components rendered in RegisterForm step 2 (v-model: extra payload fields)
  routes: [],               // { path, component, meta } — added to router
  viewKey: 0,               // Increment to force RouterView re-mount
  hideRegistrationToggleFn: null,  // Module can set a function that returns true to hide registration toggle
  hideResourceOverviewFn: null, // Module can set a function that returns true to hide settings resource card
  isCentralAdminFn: null,       // Module can set a function that returns true when in central admin context
  queueStreamSuffixFn: null,    // Module can set a function that returns a suffix string for queue stream names
})

// Auto-load core module plugins (tenancy + auth-provider excluded — loaded conditionally below)
const corePlugins = import.meta.glob(['./*/plugin.js', '!./tenancy/plugin.js', '!./auth-provider/plugin.js', '!./ticket/plugin.js', '!./billing/plugin.js'], { eager: true })
for (const mod of Object.values(corePlugins)) {
  if (typeof mod.default === 'function') mod.default(moduleRegistry)
}

// Tenancy module: only loaded when VITE_TENANCY_ENABLED=true (excluded from build otherwise)
// Exported so main.js can await it before merging locale messages into i18n
export const tenancyReady = import.meta.env.VITE_TENANCY_ENABLED === 'true'
  ? import('./tenancy/plugin.js').then(mod => {
      if (typeof mod.default === 'function') mod.default(moduleRegistry)
    })
  : Promise.resolve()

// AuthProvider module: only loaded when VITE_AUTH_PROVIDER_ENABLED=true
export const authProviderReady = import.meta.env.VITE_AUTH_PROVIDER_ENABLED === 'true'
  ? import('./auth-provider/plugin.js').then(mod => {
      if (typeof mod.default === 'function') mod.default(moduleRegistry)
    })
  : Promise.resolve()

// Ticket module: only loaded when VITE_TICKET_ENABLED=true
export const ticketReady = import.meta.env.VITE_TICKET_ENABLED === 'true'
  ? import('./ticket/plugin.js').then(mod => {
      if (typeof mod.default === 'function') mod.default(moduleRegistry)
    })
  : Promise.resolve()

// Billing module: only loaded when VITE_BILLING_ENABLED=true; its pages also stay hidden while the
// API reports billing off (GET /api/branding → features.billing)
export const billingReady = import.meta.env.VITE_BILLING_ENABLED === 'true'
  ? import('./billing/plugin.js').then(mod => {
      if (typeof mod.default === 'function') mod.default(moduleRegistry)
    })
  : Promise.resolve()
