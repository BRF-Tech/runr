/**
 * The console's language. A choice the user made (the language switch stores it as `locale`)
 * wins; until then the browser's language decides: a Turkish browser gets Turkish, every other
 * one English.
 */
export const LOCALES = ['tr', 'en']

/** 'tr' when the browser's preferred language is Turkish (tr, tr-TR, …), else 'en'. */
export function browserLocale(nav = globalThis.navigator) {
  const preferred = [nav?.languages?.[0], nav?.language].find(lang => typeof lang === 'string' && lang !== '')
  return /^tr(?:-|$)/i.test(preferred ?? '') ? 'tr' : 'en'
}

/** The stored choice, if it is one of LOCALES (storage can be unavailable: private windows). */
export function storedLocale(storage = globalThis.localStorage) {
  try {
    const saved = storage?.getItem('locale')
    return LOCALES.includes(saved) ? saved : null
  } catch {
    return null
  }
}

/** The language the console starts in and sends to the API (Accept-Language). */
export function currentLocale(storage = globalThis.localStorage, nav = globalThis.navigator) {
  return storedLocale(storage) ?? browserLocale(nav)
}
