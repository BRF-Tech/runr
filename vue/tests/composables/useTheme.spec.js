import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

// useTheme reads localStorage when the module is first imported, so each case imports a fresh copy.
async function freshTheme() {
  vi.resetModules()
  const mod = await import('@/composables/useTheme')
  return { ...mod.useTheme(), setBrandingTheme: mod.setBrandingTheme, themeKeyOf: mod.themeKeyOf }
}

describe('useTheme', () => {
  beforeEach(() => {
    localStorage.clear()
    delete document.documentElement.dataset.theme
  })

  it('offers only the dark (fulldark) and light themes', async () => {
    const { themes } = await freshTheme()
    expect(themes).toEqual(['fulldark', 'light'])
  })

  it('starts dark when nothing is stored, and stores nothing', async () => {
    const { current, picked } = await freshTheme()
    expect(current.value).toBe('fulldark')
    expect(picked.value).toBe(false)
    expect(document.documentElement.dataset.theme).toBe('fulldark')
    expect(localStorage.getItem('theme')).toBeNull()
  })

  it('a stored former navy theme ("dark") or a "fulldark" earlier versions wrote is not a pick', async () => {
    for (const stored of ['dark', 'fulldark']) {
      await nextTick() // the previous copy's theme reaches the document first
      localStorage.clear()
      delete document.documentElement.dataset.theme
      localStorage.setItem('theme', stored)
      const { current, picked, setBrandingTheme } = await freshTheme()
      expect(current.value).toBe('fulldark')
      expect(picked.value).toBe(false)
      // so the branding's default theme reaches it
      setBrandingTheme('light')
      expect(current.value).toBe('light')
    }
  })

  it('keeps a stored light theme (only a pick could store it)', async () => {
    localStorage.setItem('theme', 'light')
    const { current, picked, setBrandingTheme } = await freshTheme()
    expect(current.value).toBe('light')
    expect(picked.value).toBe(true)
    setBrandingTheme('dark')
    expect(current.value).toBe('light')
  })

  it('follows the branding default theme until the user picks one', async () => {
    const { current, set, setBrandingTheme } = await freshTheme()
    setBrandingTheme('light')
    await nextTick()
    expect(current.value).toBe('light')
    expect(document.documentElement.dataset.theme).toBe('light')

    set('fulldark')
    await nextTick()
    expect(localStorage.getItem('theme')).toBe('fulldark')
    expect(localStorage.getItem('theme_chosen')).toBe('1')
    setBrandingTheme('light')
    expect(current.value).toBe('fulldark')
  })

  it('a picked dark theme survives a reload, the branding default does not override it', async () => {
    localStorage.setItem('theme', 'fulldark')
    localStorage.setItem('theme_chosen', '1')
    const { current, picked, setBrandingTheme } = await freshTheme()
    expect(picked.value).toBe(true)
    setBrandingTheme('light')
    expect(current.value).toBe('fulldark')
  })

  it('starts from the theme the pre-paint script chose (the cached branding default)', async () => {
    document.documentElement.dataset.theme = 'light'
    const { current } = await freshTheme()
    expect(current.value).toBe('light')
  })

  it('refuses a theme that does not exist', async () => {
    const { current, set } = await freshTheme()
    set('dark')
    expect(current.value).toBe('fulldark')
    set('light')
    expect(current.value).toBe('light')
  })

  it('maps the branding default_theme to the console key', async () => {
    const { themeKeyOf } = await freshTheme()
    expect(themeKeyOf('light')).toBe('light')
    expect(themeKeyOf('dark')).toBe('fulldark')
    expect(themeKeyOf(undefined)).toBe('fulldark')
  })
})
