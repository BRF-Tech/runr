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

/**
 * Merges the modules' messages (moduleRegistry.localeMessages: { en: {...}, tr: {...} } each)
 * from the entry `from` on into `target`, and returns how many entries there are: a later call
 * with that number merges only the ones registered since.
 */
export function mergeModuleMessages(entries, from = 0, target = i18n) {
  for (const msgs of entries.slice(from)) {
    for (const [locale, data] of Object.entries(msgs)) {
      target.global.mergeLocaleMessage(locale, data)
    }
  }
  return entries.length
}

export default i18n
