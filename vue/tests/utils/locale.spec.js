import { afterEach, describe, expect, it, vi } from 'vitest'
import { browserLocale, currentLocale, storedLocale } from '@/utils/locale'

// A first visit opened the console in Turkish whatever the browser's language. Now the stored
// choice (the language switch) wins, and without one the browser decides: Turkish browsers get
// Turkish, every other one English.

const nav = (languages, language = languages[0]) => ({ languages, language })
const storage = (value) => ({ getItem: (key) => (key === 'locale' ? value : null) })

describe('browserLocale', () => {
  it('is tr for a Turkish browser, en for every other one', () => {
    expect(browserLocale(nav(['tr-TR', 'en-US']))).toBe('tr')
    expect(browserLocale(nav(['tr']))).toBe('tr')
    expect(browserLocale(nav(['TR-tr']))).toBe('tr')
    expect(browserLocale(nav(['en-US', 'tr-TR']))).toBe('en')
    expect(browserLocale(nav(['de-DE']))).toBe('en')
    // a language whose code only starts with tr (trv, Taroko) is not Turkish
    expect(browserLocale(nav(['trv']))).toBe('en')
  })

  it('reads navigator.language when there is no list, and falls back to en', () => {
    expect(browserLocale({ language: 'tr-TR' })).toBe('tr')
    expect(browserLocale({ languages: [], language: 'fr' })).toBe('en')
    expect(browserLocale({})).toBe('en')
    expect(browserLocale(undefined)).toBe('en')
  })
})

describe('currentLocale', () => {
  it('keeps a stored choice over the browser\'s language', () => {
    expect(currentLocale(storage('tr'), nav(['en-US']))).toBe('tr')
    expect(currentLocale(storage('en'), nav(['tr-TR']))).toBe('en')
  })

  it('follows the browser without a stored choice, or with one that is no locale', () => {
    expect(currentLocale(storage(null), nav(['tr-TR']))).toBe('tr')
    expect(currentLocale(storage(null), nav(['en-GB']))).toBe('en')
    expect(currentLocale(storage('de'), nav(['en-GB']))).toBe('en')
  })

  it('survives a storage that throws (private window, blocked site data)', () => {
    const blocked = { getItem: () => { throw new Error('SecurityError') } }
    expect(storedLocale(blocked)).toBeNull()
    expect(currentLocale(blocked, nav(['tr']))).toBe('tr')
  })
})

describe('the console starts in that language', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    vi.resetModules()
    localStorage.clear()
  })

  async function startLocale() {
    vi.resetModules()
    return (await import('@/i18n')).default.global.locale.value
  }

  it('in English for a browser that is not Turkish, on a first visit', async () => {
    vi.spyOn(navigator, 'languages', 'get').mockReturnValue(['en-US'])
    expect(await startLocale()).toBe('en')
  })

  it('in Turkish for a Turkish browser, on a first visit', async () => {
    vi.spyOn(navigator, 'languages', 'get').mockReturnValue(['tr-TR'])
    expect(await startLocale()).toBe('tr')
  })

  it('in the stored language once the user chose one', async () => {
    vi.spyOn(navigator, 'languages', 'get').mockReturnValue(['tr-TR'])
    localStorage.setItem('locale', 'en')
    expect(await startLocale()).toBe('en')
  })
})
