import { afterEach, describe, expect, it } from 'vitest'
import { brandColor, cssHex, surfaceColor } from '@/utils/brandColor'

// Colours for libraries that cannot read CSS variables (xterm, Monaco): the brand scale and the
// theme tones of the white-label branding, read at call time.

const root = document.documentElement

afterEach(() => root.removeAttribute('style'))

describe('brandColor / surfaceColor / cssHex', () => {
  it('read the variables the branding sets', () => {
    root.style.setProperty('--brand-500', '0 159 227')
    root.style.setProperty('--surface-dark-950', '9 27 78')
    expect(brandColor(500)).toBe('rgb(0, 159, 227)')
    expect(brandColor(500, 0.5)).toBe('rgba(0, 159, 227, 0.5)')
    expect(surfaceColor('dark', 950)).toBe('rgb(9, 27, 78)')
    expect(cssHex('brand-500')).toBe('#009fe3')
    expect(cssHex('surface-dark-950', 0.5)).toBe('#091b4e80')
  })

  it("fall back to RunR's colours without a stylesheet", () => {
    expect(brandColor(500)).toBe('rgb(249, 115, 22)')
    expect(surfaceColor('dark', 950)).toBe('rgb(10, 10, 10)')
    expect(surfaceColor('light', 950)).toBe('rgb(255, 252, 248)')
    expect(cssHex('brand-400', 1, '251 146 60')).toBe('#fb923c')
  })
})
