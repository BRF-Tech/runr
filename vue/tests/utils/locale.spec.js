import { afterEach, describe, expect, it, vi } from 'vitest'
import { DEFAULT_LOCALE, browserLocale, currentLocale, storedLocale } from '@/utils/locale'

// The console's language: the user's own choice (the language switch), else the first of the
// browser's languages the console has (tr or en), else English. An installation has no default
// language of its own: nothing but these two picks Turkish.

const nav = (languages, language = languages[0]) => ({ languages, language })
const storage = (value) => ({ getItem: (key) => (key === 'locale' ? value : null) })

describe('browserLocale', () => {
  it('is the first of the browser\'s languages the console has', () => {
    expect(browserLocale(nav(['tr-TR', 'en-US']))).toBe('tr')
    expect(browserLocale(nav(['tr']))).toBe('tr')
    expect(browserLocale(nav(['TR-tr']))).toBe('tr')
    expect(browserLocale(nav(['en-US', 'tr-TR']))).toBe('en')
    expect(browserLocale(nav(['en-GB']))).toBe('en')
    // a language the console lacks is passed over for the next one
    expect(browserLocale(nav(['de-DE', 'tr-TR', 'en']))).toBe('tr')
    expect(browserLocale(nav(['fr-FR', 'en-US', 'tr']))).toBe('en')
    expect(browserLocale(nav(['tr_TR']))).toBe('tr')
  })

  it('is null when none of them is one the console has', () => {
    expect(browserLocale(nav(['de-DE', 'fr']))).toBeNull()
    // a language whose code only starts with tr (trv, Taroko) or en (enm) is neither
    expect(browserLocale(nav(['trv', 'enm']))).toBeNull()
  })

  it('reads navigator.language when there is no list', () => {
    expect(browserLocale({ language: 'tr-TR' })).toBe('tr')
    expect(browserLocale({ languages: [], language: 'en-US' })).toBe('en')
    expect(browserLocale({ languages: [], language: 'fr' })).toBeNull()
    expect(browserLocale({})).toBeNull()
    expect(browserLocale(null)).toBeNull()
  })
})

describe('currentLocale', () => {
  it('keeps the user\'s own choice over the browser\'s languages', () => {
    expect(currentLocale(storage('tr'), nav(['en-US']))).toBe('tr')
    expect(currentLocale(storage('en'), nav(['tr-TR']))).toBe('en')
  })

  it('follows a Turkish browser without a stored choice', () => {
    expect(currentLocale(storage(null), nav(['tr-TR']))).toBe('tr')
    expect(currentLocale(storage(null), nav(['de-DE', 'tr']))).toBe('tr')
  })

  it('follows an English browser without a stored choice, or with one that is no locale', () => {
    expect(currentLocale(storage(null), nav(['en-US']))).toBe('en')
    expect(currentLocale(storage('de'), nav(['en-GB']))).toBe('en')
  })

  it('is English for a browser in a language the console lacks: the default is never Turkish', () => {
    expect(DEFAULT_LOCALE).toBe('en')
    expect(currentLocale(storage(null), nav(['de-DE', 'fr-FR']))).toBe('en')
    expect(currentLocale(storage(null), {})).toBe('en')
    expect(currentLocale(null, null)).toBe('en')
  })

  it('survives a storage that throws (private window, blocked site data)', () => {
    const blocked = { getItem: () => { throw new Error('SecurityError') } }
    expect(storedLocale(blocked)).toBeNull()
    expect(currentLocale(blocked, nav(['tr']))).toBe('tr')
    expect(currentLocale(blocked, nav(['de']))).toBe('en')
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

  it('in English for an English browser, on a first visit', async () => {
    vi.spyOn(navigator, 'languages', 'get').mockReturnValue(['en-US', 'en'])
    expect(await startLocale()).toBe('en')
  })

  it('in Turkish for a Turkish browser, on a first visit', async () => {
    vi.spyOn(navigator, 'languages', 'get').mockReturnValue(['tr-TR'])
    expect(await startLocale()).toBe('tr')
  })

  it('in the first of the browser\'s languages it has', async () => {
    vi.spyOn(navigator, 'languages', 'get').mockReturnValue(['de-DE', 'tr-TR', 'en'])
    expect(await startLocale()).toBe('tr')
  })

  it('in English for a browser whose languages it lacks', async () => {
    vi.spyOn(navigator, 'languages', 'get').mockReturnValue(['de-DE', 'fr-FR'])
    vi.spyOn(navigator, 'language', 'get').mockReturnValue('de-DE')
    expect(await startLocale()).toBe('en')
  })

  it('in the stored language once the user chose one', async () => {
    vi.spyOn(navigator, 'languages', 'get').mockReturnValue(['tr-TR'])
    localStorage.setItem('locale', 'en')
    expect(await startLocale()).toBe('en')
  })
})
