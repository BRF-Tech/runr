/**
 * The console's language, in this order:
 *
 *   1. the user's own choice (the language switch of the sign-in page and the user menu stores
 *      it as `locale`),
 *   2. else the first of the browser's preferred languages (navigator.languages) the console has,
 *   3. else DEFAULT_LOCALE.
 *
 * An installation has no default language of its own: no platform or branding setting changes it.
 */
export const LOCALES = ['tr', 'en']

/** The language when neither the stored choice nor any of the browser's languages is one of LOCALES. */
export const DEFAULT_LOCALE = 'en'

/**
 * The first of the browser's preferred languages (navigator.languages, then navigator.language)
 * that is one of LOCALES (tr, tr-TR, en-GB, …), or null when none is.
 */
export function browserLocale(nav = globalThis.navigator) {
  const preferred = [...(Array.isArray(nav?.languages) ? nav.languages : []), nav?.language]
  for (const lang of preferred) {
    if (typeof lang !== 'string') continue
    const base = lang.trim().toLowerCase().split(/[-_]/)[0]
    if (LOCALES.includes(base)) return base
  }
  return null
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

/** The language the console starts in, sets as the page's `lang` and sends to the API (Accept-Language). */
export function currentLocale(storage = globalThis.localStorage, nav = globalThis.navigator) {
  return storedLocale(storage) ?? browserLocale(nav) ?? DEFAULT_LOCALE
}
