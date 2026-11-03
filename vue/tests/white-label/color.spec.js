import { describe, expect, it } from 'vitest'
import {
  DEFAULT_ACCENT, DEFAULT_DARK, DEFAULT_LIGHT, DEFAULT_PRIMARY, RUNR_DARK, RUNR_LIGHT, SHADES, SURFACE_SHADES,
  contrastRatio, deriveSurfaceScale, editorSurfaceScale, expandPalette, hexToRgb, hexToTriplet, isHexColor, mix,
  normalizeHex, parseColor, readableTextOn, resolvePalette, resolveSurfaceScale, rgbToHex, toHex, toTriplet,
} from '@/modules/white-label/utils/color'
import { BRANDING_PRESETS, findPreset } from '@/modules/white-label/utils/presets'

const RUNR = {
  300: '253 186 116',
  400: '251 146 60',
  500: '249 115 22',
  600: '234 88 12',
  700: '194 65 12',
  800: '154 52 18',
  900: '124 45 18',
}

describe('color utils', () => {
  it('validates and normalises hex colours', () => {
    expect(isHexColor('#f97316')).toBe(true)
    expect(isHexColor('F97316')).toBe(true)
    expect(isHexColor('#0bc')).toBe(true)
    expect(isHexColor('#0bcd')).toBe(false)
    expect(isHexColor('rgb(0,0,0)')).toBe(false)
    expect(isHexColor(null)).toBe(false)

    expect(normalizeHex('#0BC')).toBe('#00bbcc')
    expect(normalizeHex(' F97316 ')).toBe('#f97316')
    expect(normalizeHex('nope')).toBeNull()
  })

  it('reads "#rrggbb" and "r g b" colours', () => {
    expect(hexToRgb('#f97316')).toEqual([249, 115, 22])
    expect(hexToTriplet('#e63946')).toBe('230 57 70')
    expect(parseColor('249 115 22')).toEqual([249, 115, 22])
    expect(parseColor('249,115,22')).toEqual([249, 115, 22])
    expect(parseColor('300 0 0')).toBeNull()
    expect(toTriplet('#009fe3')).toBe('0 159 227')
    expect(toTriplet('0 159 227')).toBe('0 159 227')
    expect(toHex('0 123 192')).toBe('#007bc0')
    expect(toHex('nope')).toBeNull()   // 'bad' would be the valid hex #bbaadd
    expect(rgbToHex([300, -5, 12.6])).toBe('#ff000d')
  })

  it('mixes colours linearly and clamps the weight', () => {
    expect(mix([0, 0, 0], [255, 255, 255], 0.5)).toEqual([128, 128, 128])
    expect(mix([10, 20, 30], [255, 255, 255], 0)).toEqual([10, 20, 30])
    expect(mix([10, 20, 30], [255, 255, 255], 5)).toEqual([255, 255, 255])
  })

  it('RunR defaults: base #f97316 expands to the RunR palette, accent #e63946', () => {
    expect(DEFAULT_PRIMARY).toBe('#f97316')
    expect(DEFAULT_ACCENT).toBe('#e63946')
    expect(expandPalette(DEFAULT_PRIMARY)).toEqual(RUNR)
    expect(expandPalette('nope')).toEqual(RUNR)
  })

  it('expands a custom base colour (500 = base, lighter above, darker below)', () => {
    const shades = expandPalette('#ff0000')
    expect(Object.keys(shades)).toEqual(SHADES)
    expect(shades[500]).toBe('255 0 0')
    expect(shades[300]).toBe('255 115 115')
    expect(shades[600]).toBe('224 0 0')
    expect(shades[900]).toBe('107 0 0')
  })

  // The API expands a single base colour the same way (BrandingService::expand), for mails and the
  // public status page: api/tests/Unit/WhiteLabel/BrandPaletteTest.php pins the same values. Before,
  // the API mixed with other weights (300 40 %, 600 18 % …) and one colour gave two palettes.
  it('gives the same scale as the API for the same base colour', () => {
    expect(expandPalette('#2563eb')).toEqual({ 300: '135 169 244', 400: '92 138 240', 500: '37 99 235', 600: '33 87 207', 700: '26 69 165', 800: '20 54 129', 900: '16 42 99' })
    expect(expandPalette('#009fe3')).toEqual({ 300: '115 202 240', 400: '64 183 234', 500: '0 159 227', 600: '0 140 200', 700: '0 111 159', 800: '0 87 125', 900: '0 67 95' })
    expect(expandPalette('#16a34a')).toEqual({ 300: '127 204 155', 400: '80 186 119', 500: '22 163 74', 600: '19 143 65', 700: '15 114 52', 800: '12 90 41', 900: '9 68 31' })
    expect(expandPalette('#7f7f7f')).toEqual({ 300: '185 185 185', 400: '159 159 159', 500: '127 127 127', 600: '112 112 112', 700: '89 89 89', 800: '70 70 70', 900: '53 53 53' })
  })

  it('an explicit palette wins shade by shade, the rest is expanded', () => {
    expect(resolvePalette({ 300: '1 2 3', 900: '#000010', 500: 'nope' }, '#ff0000')).toEqual({
      ...expandPalette('#ff0000'),
      300: '1 2 3',
      900: '0 0 16',
    })
    expect(resolvePalette(null, DEFAULT_PRIMARY)).toEqual(RUNR)
  })

  it('picks readable text colours', () => {
    expect(readableTextOn('#ffffff')).toBe('#000000')
    expect(readableTextOn('#7c2d12')).toBe('#ffffff')
  })
})

describe('branding presets', () => {
  it('offers RunR (default) and Ocean blue with full scales', () => {
    expect(BRANDING_PRESETS.map(p => p.key)).toEqual(['runr', 'ocean'])
    const runr = findPreset('runr')
    expect(runr.primary_color).toBe('#f97316')
    expect(runr.accent_color).toBe('#e63946')
    expect(Object.values(runr.primary_scale).map(toTriplet)).toEqual(Object.values(RUNR))

    const ocean = findPreset('ocean')
    expect(ocean.primary_color).toBe('#009fe3')
    expect(ocean.accent_color).toBe('#009fe3')
    expect(ocean.primary_scale).toEqual({
      300: '#66c5ed', 400: '#33b5e9', 500: '#009fe3', 600: '#007bc0', 700: '#0055b8', 800: '#004499', 900: '#002b80',
    })
    expect(findPreset('none')).toBeNull()
  })

  it('the RunR preset brings RunR\'s theme tones, the Ocean preset the navy dark theme', () => {
    const runr = findPreset('runr')
    expect(runr.dark_color).toBe('#0a0a0a')
    expect(runr.light_color).toBe('#fffcf8')
    expect(Object.fromEntries(Object.entries(runr.dark_scale).map(([s, hex]) => [s, toTriplet(hex)]))).toEqual(scaleOf(RUNR_DARK))
    expect(Object.fromEntries(Object.entries(runr.light_scale).map(([s, hex]) => [s, toTriplet(hex)]))).toEqual(scaleOf(RUNR_LIGHT))

    const ocean = findPreset('ocean')
    expect(ocean.dark_color).toBe('#091b4e')
    // the former navy theme, exactly
    expect(Object.fromEntries(Object.entries(ocean.dark_scale).map(([s, hex]) => [s, toTriplet(hex)]))).toEqual({
      950: '9 27 78', 900: '15 36 96', 800: '22 46 114', 700: '30 59 133', 600: '175 195 225', 500: '205 218 240',
      400: '222 232 248', 300: '235 240 250', 200: '242 245 252', 100: '248 250 254', 50: '252 253 255',
    })
    expect(ocean.light_color).toBe('#ffffff')
    expect(Object.keys(ocean.light_scale)).toEqual(SURFACE_SHADES)
  })

  it('preset keys are generic names', () => {
    for (const p of BRANDING_PRESETS) expect(p.key).toMatch(/^(runr|ocean)$/)
  })
})

const scaleOf = rgbByShade => Object.fromEntries(SURFACE_SHADES.map(s => [s, rgbByShade[s].join(' ')]))
const rgbOf = (triplet) => {
  // "r g b" → { r, g, b }
  const [r, g, b] = triplet.split(' ').map(Number)
  return { r, g, b }
}

describe('theme tones (--gray-50 … --gray-950)', () => {
  it('RunR\'s base colours give RunR\'s scales unchanged', () => {
    expect(deriveSurfaceScale(DEFAULT_DARK, 'dark')).toEqual(scaleOf(RUNR_DARK))
    expect(deriveSurfaceScale(DEFAULT_LIGHT, 'light')).toEqual(scaleOf(RUNR_LIGHT))
    // also as "r g b", and with a missing / invalid base
    expect(deriveSurfaceScale('10 10 10', 'dark')).toEqual(scaleOf(RUNR_DARK))
    expect(deriveSurfaceScale('nope', 'light')).toEqual(scaleOf(RUNR_LIGHT))
    expect(deriveSurfaceScale(null, 'dark')).toEqual(scaleOf(RUNR_DARK))
  })

  it('keeps the base colour as the page (950), all 11 shades as "r g b"', () => {
    const scale = deriveSurfaceScale('#091b4e', 'dark')
    expect(Object.keys(scale)).toEqual(SURFACE_SHADES)
    expect(scale['950']).toBe('9 27 78')
    for (const triplet of Object.values(scale)) expect(triplet).toMatch(/^\d{1,3} \d{1,3} \d{1,3}$/)
  })

  it('a gray base gives a gray scale', () => {
    for (const [base, theme] of [['#000000', 'dark'], ['#0a0a0a', 'dark'], ['#ffffff', 'light']]) {
      for (const triplet of Object.values(deriveSurfaceScale(base, theme))) {
        const { r, g, b } = rgbOf(triplet)
        expect(Math.max(r, g, b) - Math.min(r, g, b), `${base} ${triplet}`).toBeLessThanOrEqual(1)
      }
    }
  })

  it('a navy base: navy surfaces, bluish text, close to the former navy theme', () => {
    const scale = deriveSurfaceScale('#091b4e', 'dark')
    const NAVY = { 900: [15, 36, 96], 800: [22, 46, 114], 700: [30, 59, 133], 600: [175, 195, 225], 400: [222, 232, 248] }
    for (const [shade, rgb] of Object.entries(NAVY)) {
      const got = scale[shade].split(' ').map(Number)
      expect(Math.max(...got.map((v, i) => Math.abs(v - rgb[i]))), `shade ${shade}`).toBeLessThanOrEqual(24)
      expect(got[2]).toBeGreaterThan(got[0]) // blue over red
    }
  })

  it('text keeps its contrast on a derived scale (WCAG AA and more)', () => {
    for (const [base, theme] of [['#091b4e', 'dark'], ['#052e16', 'dark'], ['#000000', 'dark'], ['#f8fafc', 'light'], ['#ffe4e6', 'light'], ['#ffffff', 'light']]) {
      const s = deriveSurfaceScale(base, theme)
      expect(contrastRatio(s['100'], s['950']), `${base} text`).toBeGreaterThan(12)
      expect(contrastRatio(s['400'], s['900']), `${base} muted`).toBeGreaterThan(7)
      expect(contrastRatio(s['600'], s['900']), `${base} 600`).toBeGreaterThanOrEqual(4.5)
    }
  })

  it('an explicit scale wins shade by shade, the rest is derived; the base falls back to its 950', () => {
    const scale = resolveSurfaceScale({ 900: '#102030', 50: '1 2 3', 300: 'nope' }, '#091b4e', 'dark')
    expect(scale['900']).toBe('16 32 48')
    expect(scale['50']).toBe('1 2 3')
    expect(scale['300']).toBe(deriveSurfaceScale('#091b4e', 'dark')['300'])
    // no base colour: the scale's own 950
    expect(resolveSurfaceScale({ 950: '9 27 78' }, null, 'dark')['950']).toBe('9 27 78')
    expect(resolveSurfaceScale(null, null, 'light')).toEqual(scaleOf(RUNR_LIGHT))
  })

  it('the editor\'s scale: overrides as "#rrggbb" over the derived shades', () => {
    const scale = editorSurfaceScale({ 900: '#102030', 800: '' }, '#0a0a0a', 'dark')
    expect(scale['900']).toBe('#102030')
    expect(scale['800']).toBe('#262626')
    expect(scale['950']).toBe('#0a0a0a')
  })

  it('measures contrast like WCAG', () => {
    expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 5)
    expect(contrastRatio('255 255 255', '255 255 255')).toBeCloseTo(1, 5)
  })
})
