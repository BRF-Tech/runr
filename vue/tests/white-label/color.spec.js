import { describe, expect, it } from 'vitest'
import {
  DEFAULT_ACCENT, DEFAULT_PRIMARY, SHADES, expandPalette, hexToRgb, hexToTriplet, isHexColor, mix,
  normalizeHex, parseColor, readableTextOn, resolvePalette, rgbToHex, toHex, toTriplet,
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
})
