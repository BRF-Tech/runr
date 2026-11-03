import { createI18n } from 'vue-i18n'
import tr from '@/locales/tr'
import en from '@/locales/en'
import { currentLocale } from '@/utils/locale'

// The stored choice, else the browser's language: Turkish for a Turkish browser, English otherwise
const i18n = createI18n({
  legacy:        false,   // Composition API mode
  locale:        currentLocale(),
  fallbackLocale: 'en',
  messages:      { tr, en },
})

export default i18n
