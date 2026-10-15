import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import en from '@/locales/en'
import tr from '@/locales/tr'

// Real application messages, so assertions read the strings users see.
export function createTestI18n(locale = 'en') {
  return createI18n({
    legacy: false,
    locale,
    fallbackLocale: 'en',
    messages: { en, tr },
    missingWarn: false,
    fallbackWarn: false,
  })
}

// Global mount options: i18n + a fresh Pinia (also made active for direct store use).
export function withPlugins(locale = 'en') {
  const pinia = createPinia()
  setActivePinia(pinia)
  const i18n = createTestI18n(locale)
  return { pinia, i18n, global: { plugins: [i18n, pinia] } }
}

// A t() stand-in that makes the key and params visible in assertions.
export function fakeT(key, params) {
  return params ? `${key} ${JSON.stringify(params)}` : key
}
