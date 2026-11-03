import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { RUNR_DARK, RUNR_LIGHT, contrastRatio, mix } from '@/modules/white-label/utils/color'

// Input placeholders read almost like typed values (gray-400: 12:1 against the typed value's
// 14.5:1 on the dark theme), and the login page's were nearly invisible (the light border tone,
// 1.4:1). Placeholders here carry information (formats, examples: "{slug}.{region}.{domain}",
// cron, image names), so WCAG 1.4.3's 4.5:1 applies — WCAG has no lower bar for placeholder or
// hint text. The tone: the weakest text tone mixed toward the input surface, clearly weaker than
// a typed value and still 4.5:1 on both themes.

const mainCss = readFileSync(resolve(import.meta.dirname, '../../src/assets/main.css'), 'utf8')
const compatCss = readFileSync(resolve(import.meta.dirname, '../../src/assets/tailwind-v3-compat.css'), 'utf8')
const loginView = readFileSync(resolve(import.meta.dirname, '../../src/views/LoginView.vue'), 'utf8')
const sources = import.meta.glob(['/src/**/*.vue', '/src/**/*.js', '/src/**/*.css'], { query: '?raw', import: 'default', eager: true })

/** color-mix(in srgb, rgb(var(--<text>)) N%, rgb(var(--<surface>))) → { text, surface, share } */
function parseMix(css) {
  const m = css.match(/color-mix\(in srgb, rgb\(var\(--([\w-]+)\)\) (\d+)%, rgb\(var\(--([\w-]+)\)\)\)/)
  expect(m, 'a color-mix of a text tone and a surface').toBeTruthy()
  return { text: m[1], share: Number(m[2]) / 100, surface: m[3] }
}

const SCALES = { dark: RUNR_DARK, light: RUNR_LIGHT }
const shade = (name) => name.replace(/^.*-(\d+)$/, '$1')
const hex = (rgb) => '#' + rgb.map(v => v.toString(16).padStart(2, '0')).join('')

describe('placeholder contrast', () => {
  const weak = parseMix(mainCss.match(/--placeholder-color-weak:\s*([^;]+);/)[1])

  it('the console\'s placeholders: 4.5:1 or more on the input surface, well under a typed value', () => {
    expect(weak).toEqual({ text: 'gray-600', share: 0.82, surface: 'gray-800' })
    for (const [theme, scale] of Object.entries(SCALES)) {
      const surface = scale[shade(weak.surface)]
      const placeholder = mix(surface, scale[shade(weak.text)], weak.share)
      const typed = contrastRatio(hex(scale[100]), hex(surface))
      const ratio = contrastRatio(hex(placeholder), hex(surface))
      expect(ratio, theme).toBeGreaterThanOrEqual(4.5)
      // clearly weaker: at most two thirds of the typed value's contrast
      expect(ratio / typed, theme).toBeLessThan(0.67)
      // and still 4.5:1 on the darker / lighter surfaces inputs also sit on (900, 950)
      for (const s of ['900', '950']) expect(contrastRatio(hex(placeholder), hex(scale[s])), `${theme} on ${s}`).toBeGreaterThanOrEqual(4.5)
    }
  })

  it('applies to .input, to bare inputs and textareas, and to every placeholder class', () => {
    expect(mainCss).toMatch(/@utility input \{[^}]*placeholder-weak/)
    expect(compatCss).toMatch(/textarea::placeholder \{\s*color: var\(--placeholder-color-weak\);/)
    for (const [file, source] of Object.entries(sources)) {
      expect(source, file).not.toMatch(/(?<![\w-])placeholder-gray-\d+/)
    }
  })

  it('the login and sign-up card\'s placeholders: 4.5:1 on its light inputs', () => {
    const login = parseMix(loginView.match(/\.login-input\)::placeholder \{ color: ([^;]+);/)[1])
    expect(login).toEqual({ text: 'surface-light-600', share: 0.82, surface: 'surface-light-900' })
    const surface = RUNR_LIGHT[900]
    const ratio = contrastRatio(hex(mix(surface, RUNR_LIGHT[600], login.share)), hex(surface))
    expect(ratio).toBeGreaterThanOrEqual(4.5)
    expect(ratio / contrastRatio(hex(RUNR_LIGHT[100]), hex(surface))).toBeLessThan(0.67)
  })
})
