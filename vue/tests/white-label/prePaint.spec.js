import { beforeEach, describe, expect, it } from 'vitest'
import { BRANDING_STYLE_ID, normalizeBranding, surfaceCss, writeCachedBranding } from '@/modules/white-label/utils/branding'
import { RUNR_LIGHT, SURFACE_SHADES, deriveSurfaceScale, expandPalette } from '@/modules/white-label/utils/color'
import { DEFAULTS_GLOBAL, platformBranding, prePaint } from '@/modules/white-label/prePaint'

// index.html's pre-paint runs before the first paint: it picks the theme and writes the branding's
// theme tones and brand scale, so a re-branded console does not flash RunR's palette before the app
// starts. A first visit has no cached branding yet: it showed RunR's dark theme until GET
// /api/branding answered. Now the platform's branding defaults (Helm branding.colors.*,
// branding.defaultTheme), which the ui container writes into /branding-defaults.js at start, come
// between the cache and RunR's own. src/modules/white-label/prePaint.js is that script; the Vite
// plugin inlines it into index.html as a classic script (prePaintBuild.spec.js runs the build).

const NAVY = {
  950: '9 27 78', 900: '15 36 96', 800: '22 46 114', 700: '30 59 133', 600: '175 195 225', 500: '205 218 240',
  400: '222 232 248', 300: '235 240 250', 200: '242 245 252', 100: '248 250 254', 50: '252 253 255',
}
const OCEAN = { 300: '102 197 237', 400: '51 181 233', 500: '0 159 227', 600: '0 123 192', 700: '0 85 184', 800: '0 68 153', 900: '0 43 128' }
const asTriplets = (scale) => Object.fromEntries(SURFACE_SHADES.map(s => [s, scale[s].join(' ')]))

/** The pre-paint as index.html runs it, with the platform defaults /branding-defaults.js set. */
function run(defaults = null) {
  window[DEFAULTS_GLOBAL] = defaults
  prePaint()
}

const style = () => document.getElementById(BRANDING_STYLE_ID)
const rule = (selector) => (style()?.textContent ?? '').split('\n').find(line => line.startsWith(`${selector} {`)) ?? ''
const vars = (selector, prefix) =>
  Object.fromEntries([...rule(selector).matchAll(new RegExp(`--${prefix}-(\\d+): ([\\d ]+);`, 'g'))].map(m => [m[1], m[2]]))

beforeEach(() => {
  localStorage.clear()
  document.head.innerHTML = ''
  delete document.documentElement.dataset.theme
  delete window[DEFAULTS_GLOBAL]
})

describe('pre-paint: the cached branding', () => {
  it('nothing cached, no platform defaults: the dark theme and no branding style', () => {
    run()
    expect(document.documentElement.dataset.theme).toBe('fulldark')
    expect(style()).toBeNull()
  })

  it('a cached branding: its tones and brand scale before the app starts, its default theme', () => {
    writeCachedBranding(normalizeBranding({ dark_scale: NAVY, palette: OCEAN, default_theme: 'light' }))
    run()
    expect(document.documentElement.dataset.theme).toBe('light')
    expect(vars('html:not([data-theme="light"])', 'gray')).toEqual(NAVY)
    expect(vars('html[data-theme="light"]', 'gray')).toEqual(asTriplets(RUNR_LIGHT))
    expect(vars('html:root', 'surface-dark')).toEqual(NAVY)
    expect(vars('html:root', 'brand')).toEqual(OCEAN)
  })

  it('writes the same theme tones as applyBranding() (no change of colour once the app starts)', () => {
    const branding = normalizeBranding({ dark_color: '#0a0a0a', light_color: '#f8fafc' })
    writeCachedBranding(branding)
    run()
    const app = surfaceCss(branding.surfaces).split('\n')
    expect(rule('html:not([data-theme="light"])').replace(/\s+/g, ' ')).toBe(app[0].replace(/\s+/g, ' '))
    expect(rule('html[data-theme="light"]').replace(/\s+/g, ' ')).toBe(app[1].replace(/\s+/g, ' '))
  })

  it("the user's pick wins over the branding's default theme", () => {
    writeCachedBranding(normalizeBranding({ default_theme: 'light' }))
    localStorage.setItem('theme', 'fulldark')
    localStorage.setItem('theme_chosen', '1')
    run()
    expect(document.documentElement.dataset.theme).toBe('fulldark')

    writeCachedBranding(normalizeBranding({ default_theme: 'dark' }))
    localStorage.setItem('theme', 'light')
    localStorage.removeItem('theme_chosen')
    run()
    expect(document.documentElement.dataset.theme).toBe('light')
  })

  it('a "fulldark" stored by an earlier version (no pick) follows the default theme', () => {
    writeCachedBranding(normalizeBranding({ default_theme: 'light' }))
    localStorage.setItem('theme', 'fulldark')
    run()
    expect(document.documentElement.dataset.theme).toBe('light')
  })

  it('writes only "r g b" values of numbered shades (a cache cannot inject CSS)', () => {
    localStorage.setItem('branding', JSON.stringify({
      surfaces: { dark: { 950: '1 2 3; } body { display: none', 900: '4 5 6', x: '7 8 9' }, light: null },
      palette: { 500: 'red' },
    }))
    run()
    expect(style().textContent).not.toContain('display')
    expect(vars('html:not([data-theme="light"])', 'gray')).toEqual({ 900: '4 5 6' })
    expect(vars('html:root', 'brand')).toEqual({})
  })

  it('a broken cache: the platform defaults or the dark theme, nothing written, no error', () => {
    localStorage.setItem('branding', '{nope')
    expect(() => run()).not.toThrow()
    expect(document.documentElement.dataset.theme).toBe('fulldark')
    expect(style()).toBeNull()
  })
})

describe('pre-paint: the platform defaults on a first visit', () => {
  const platform = { primary_color: '#2563eb', dark_color: '#101418', light_color: '#f8fafc', default_theme: 'light' }

  it('opens in the platform\'s default theme and colours, not RunR\'s dark theme', () => {
    run(platform)
    expect(document.documentElement.dataset.theme).toBe('light')
    expect(vars('html:root', 'brand')).toEqual(expandPalette('#2563eb'))
    expect(vars('html:not([data-theme="light"])', 'gray')).toEqual(deriveSurfaceScale('#101418', 'dark'))
    expect(vars('html[data-theme="light"]', 'gray')).toEqual(deriveSurfaceScale('#f8fafc', 'light'))
  })

  it('writes what the app will write once GET /api/branding answers with the same values', () => {
    run(platform)
    const app = normalizeBranding(platform)
    const css = surfaceCss(app.surfaces).split('\n')
    expect(rule('html:not([data-theme="light"])').replace(/\s+/g, ' ')).toBe(css[0].replace(/\s+/g, ' '))
    expect(rule('html[data-theme="light"]').replace(/\s+/g, ' ')).toBe(css[1].replace(/\s+/g, ' '))
  })

  it('takes full scales as given, with the shades left out derived', () => {
    run({ primary_scale: OCEAN, dark_scale: { 950: '9 27 78', 600: 'rgb(175, 195, 225)' } })
    expect(vars('html:root', 'brand')).toEqual(OCEAN)
    const dark = vars('html:not([data-theme="light"])', 'gray')
    expect(dark[950]).toBe('9 27 78')
    expect(dark[600]).toBe('175 195 225')
    expect(dark[900]).toBe(deriveSurfaceScale('#091b4e', 'dark')[900])
    // no light tones given: RunR's (main.css), no rule
    expect(vars('html[data-theme="light"]', 'gray')).toEqual({})
    expect(document.documentElement.dataset.theme).toBe('fulldark')
  })

  it('comes after the cached branding and after the user\'s pick', () => {
    writeCachedBranding(normalizeBranding({ palette: OCEAN, default_theme: 'dark' }))
    run(platform)
    expect(document.documentElement.dataset.theme).toBe('fulldark')
    expect(vars('html:root', 'brand')).toEqual(OCEAN)

    localStorage.clear()
    localStorage.setItem('theme', 'fulldark')
    localStorage.setItem('theme_chosen', '1')
    document.head.innerHTML = ''
    run(platform)
    expect(document.documentElement.dataset.theme).toBe('fulldark')
    expect(vars('html:root', 'brand')).toEqual(expandPalette('#2563eb'))
  })

  it('drops what is not a colour, a shade map or a theme (nothing reaches CSS unchecked)', () => {
    run({
      primary_color: 'red; } body { display: none', default_theme: 'sepia',
      dark_scale: { 950: '1 2 3; } body { display: none', x: '7 8 9', 900: '4 5 6' }, light_scale: 'nope',
    })
    expect(style().textContent).not.toContain('display')
    expect(vars('html:root', 'brand')).toEqual({})
    expect(vars('html:not([data-theme="light"])', 'gray')[900]).toBe('4 5 6')
    expect(document.documentElement.dataset.theme).toBe('fulldark')
    expect(platformBranding('nope')).toBeNull()
    expect(platformBranding([])).toBeNull()
    expect(platformBranding({})).toBeNull()
  })

  it('sets the page language: the stored choice, else the browser\'s, else English', () => {
    prePaint({ defaults: null, nav: { languages: ['tr-TR'] } })
    expect(document.documentElement.lang).toBe('tr')
    prePaint({ defaults: null, nav: { languages: ['en-US', 'en'] } })
    expect(document.documentElement.lang).toBe('en')
    prePaint({ defaults: null, nav: { languages: ['de-DE', 'tr'] } })
    expect(document.documentElement.lang).toBe('tr')
    // a browser in a language the console lacks, with the platform's branding defaults: English
    prePaint({ defaults: platform, nav: { languages: ['de-DE'], language: 'de-DE' } })
    expect(document.documentElement.lang).toBe('en')
    localStorage.setItem('locale', 'en')
    prePaint({ defaults: null, nav: { languages: ['tr-TR'] } })
    expect(document.documentElement.lang).toBe('en')
  })

  it('reports where the colours came from', () => {
    expect(prePaint({ defaults: null })).toBeNull()
    expect(prePaint({ defaults: platform })).toBe('platform')
    writeCachedBranding(normalizeBranding({}))
    expect(prePaint({ defaults: platform })).toBe('cache')
  })
})
