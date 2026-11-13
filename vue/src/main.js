import './assets/main.css'
import * as Sentry from '@sentry/vue'
import 'flag-icons/css/flag-icons.min.css'
import './monaco-env.js'
import * as monaco from 'monaco-editor'
import { loader } from '@guolao/vue-monaco-editor'
loader.config({ monaco })
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import i18n, { mergeModuleMessages } from './i18n'
import { reloadOnStaleChunks } from './utils/staleChunks'

import { moduleRegistry, tenancyReady, authProviderReady, ticketReady, billingReady } from '@/modules/index'

// A page's chunk of the previous build (opened before an upgrade) answers 404: load the new build
reloadOnStaleChunks()

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.use(i18n)

if (import.meta.env.VITE_SENTRY_DSN) {
  Sentry.init({
    app,
    dsn:              import.meta.env.VITE_SENTRY_DSN,
    tunnel:           '/sentry-tunnel',
    environment:      import.meta.env.MODE,
    integrations: [
      Sentry.browserTracingIntegration({ router }),
      Sentry.replayIntegration(),
    ],
    tracesSampleRate:   0.1,
    replaysSessionSampleRate: 0.1,
    replaysOnErrorSampleRate: 1.0,
    // What SDK 10 sent (no sendDefaultPii): SDK 11 collects the user, cookies, headers and bodies
    // by default, and streams spans instead of sending transactions
    traceLifecycle: 'static',
    dataCollection: {
      userInfo:       false,
      cookies:        false,
      httpHeaders:    {
        request:  { deny: ['forwarded', '-ip', 'remote-', 'via', '-user'] },
        response: { deny: ['forwarded', '-ip', 'remote-', 'via', '-user'] },
      },
      httpBodies:     [],
      urlQueryParams: { deny: ['forwarded', '-ip', 'remote-', 'via', '-user'] },
    },
  })
  window.Sentry = Sentry
}

app.directive('click-outside', {
  mounted(el, binding) {
    el._clickOutside = (e) => { if (!el.contains(e.target)) binding.value(e) }
    document.addEventListener('click', el._clickOutside)
  },
  unmounted(el) { document.removeEventListener('click', el._clickOutside) },
})

// The core modules' messages are registered by now (modules/index.js loads their plugins
// eagerly): merged before the first render, so a screen of theirs that opens with the app (the
// onboarding wizard, the sign-in page's bot protection) never shows a message key while the
// conditional modules below are still loading.
const coreMessages = mergeModuleMessages(moduleRegistry.localeMessages)

// Mount immediately, then merge async module plugins (routes, i18n) when ready.
// Top-level await + dynamic import creates a circular dependency deadlock in production
// builds (plugin chunk statically imports main bundle which is blocked by await).
app.mount('#app')

Promise.all([tenancyReady, authProviderReady, ticketReady, billingReady]).then(() => {
  for (const route of moduleRegistry.routes) {
    router.addRoute(route)
  }

  // the conditional modules' messages (the core ones are in)
  mergeModuleMessages(moduleRegistry.localeMessages, coreMessages)
})
