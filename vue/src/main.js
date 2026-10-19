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
import i18n from './i18n'

import { moduleRegistry, tenancyReady, authProviderReady, ticketReady, billingReady } from '@/modules/index'

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

// Mount immediately, then merge async module plugins (routes, i18n) when ready.
// Top-level await + dynamic import creates a circular dependency deadlock in production
// builds (plugin chunk statically imports main bundle which is blocked by await).
app.mount('#app')

Promise.all([tenancyReady, authProviderReady, ticketReady, billingReady]).then(() => {
  for (const route of moduleRegistry.routes) {
    router.addRoute(route)
  }

  for (const msgs of moduleRegistry.localeMessages) {
    for (const [locale, data] of Object.entries(msgs)) {
      i18n.global.mergeLocaleMessage(locale, data)
    }
  }
})
