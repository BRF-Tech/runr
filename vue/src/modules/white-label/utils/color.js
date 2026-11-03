// Colour helpers for the white-label theme. Brand colours arrive as "#rrggbb" (primary_color =
// the 500 base colour, accent_color) plus an optional `palette` ({"300": "r g b", … "900"})
// from GET /api/branding; they are written to CSS variables as space-separated RGB triplets
// ("249 115 22"), the format Tailwind's `rgb(var(--x) / <alpha-value>)` colours consume.

export const SHADES = ['300', '400', '500', '600', '700', '800', '900']

/** RunR's palette (brand-300 … brand-900) and accent. */
export const RUNR_PALETTE = Object.freeze({
  300: [253, 186, 116],
  400: [251, 146, 60],
  500: [249, 115, 22],
  600: [234, 88, 12],
  700: [194, 65, 12],
  800: [154, 52, 18],
  900: [124, 45, 18],
})

export const DEFAULT_PRIMARY = '#f97316'   // RunR 500
export const DEFAULT_ACCENT  = '#e63946'   // RunR accent

const HEX_RE = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i
const TRIPLET_RE = /^\s*(\d{1,3})[\s,]+(\d{1,3})[\s,]+(\d{1,3})\s*$/

/** true for "#rgb" / "#rrggbb" (with or without "#"). */
export function isHexColor(value) {
  return typeof value === 'string' && HEX_RE.test(value.trim())
}

/** "#rrggbb" in lower case, or null for anything that is not a hex colour. */
export function normalizeHex(value) {
  if (!isHexColor(value)) return null
  let hex = value.trim().replace(/^#/, '').toLowerCase()
  if (hex.length === 3) hex = hex.split('').map(c => c + c).join('')
  return `#${hex}`
}

/** [r, g, b] (0–255) or null. */
export function hexToRgb(value) {
  const hex = normalizeHex(value)
  if (!hex) return null
  return [0, 2, 4].map(i => parseInt(hex.slice(1 + i, 3 + i), 16))
}

/** "#rrggbb" from [r, g, b]. */
export function rgbToHex([r, g, b]) {
  return '#' + [r, g, b].map(v => clamp(Math.round(v)).toString(16).padStart(2, '0')).join('')
}

/** "r g b" triplet for CSS variables, or null for an invalid colour. */
export function hexToTriplet(value) {
  const rgb = hexToRgb(value)
  return rgb ? rgb.join(' ') : null
}

/** [r, g, b] from "#rrggbb" or "r g b" (also "r,g,b"), or null. */
export function parseColor(value) {
  if (typeof value !== 'string') return null
  if (isHexColor(value)) return hexToRgb(value)
  const m = value.match(TRIPLET_RE)
  if (!m) return null
  const rgb = [m[1], m[2], m[3]].map(Number)
  return rgb.every(v => v >= 0 && v <= 255) ? rgb : null
}

/** "r g b" from "#rrggbb" or "r g b", or null. */
export function toTriplet(value) {
  const rgb = parseColor(value)
  return rgb ? rgb.join(' ') : null
}

/** "#rrggbb" from "#rrggbb" or "r g b", or null. */
export function toHex(value) {
  const rgb = parseColor(value)
  return rgb ? rgbToHex(rgb) : null
}

/** Linear mix of two RGB colours; weight 0 → a, 1 → b. */
export function mix(a, b, weight) {
  const w = Math.min(1, Math.max(0, Number(weight) || 0))
  return [0, 1, 2].map(i => Math.round(a[i] * (1 - w) + b[i] * w))
}

function clamp(v) {
  return Math.min(255, Math.max(0, v))
}

const WHITE = [255, 255, 255]
const BLACK = [0, 0, 0]

/**
 * brand-300 … brand-900 from the 500 base colour: lighter shades mixed with white, darker ones
 * with black. RunR's base colour gives RunR's palette unchanged.
 *
 * @returns {Record<string, string>} shade → "r g b"
 */
export function expandPalette(baseColor) {
  const base = parseColor(baseColor) ?? hexToRgb(DEFAULT_PRIMARY)
  if (rgbToHex(base) === DEFAULT_PRIMARY) {
    return Object.fromEntries(SHADES.map(s => [s, RUNR_PALETTE[s].join(' ')]))
  }

  const shades = {
    300: mix(base, WHITE, 0.45),
    400: mix(base, WHITE, 0.25),
    500: base,
    600: mix(base, BLACK, 0.12),
    700: mix(base, BLACK, 0.3),
    800: mix(base, BLACK, 0.45),
    900: mix(base, BLACK, 0.58),
  }

  return Object.fromEntries(SHADES.map(s => [s, shades[s].join(' ')]))
}

/**
 * The palette to apply: an explicit scale from the API (`palette`, "r g b" or "#rrggbb" per
 * shade) wins shade by shade, the rest is expanded from the base colour.
 *
 * @returns {Record<string, string>} shade → "r g b"
 */
export function resolvePalette(palette, baseColor) {
  const expanded = expandPalette(baseColor)
  if (!palette || typeof palette !== 'object') return expanded
  return Object.fromEntries(SHADES.map(s => [s, toTriplet(palette[s]) ?? expanded[s]]))
}

// ── Theme tones (--gray-50 … --gray-950) ─────────────────────────────────────────────────────
// Each theme has its own tone scale: 950–700 are the surfaces (page, cards, inputs, borders),
// 600–50 the text tones. The branding sets one per theme (dark_color / dark_scale,
// light_color / light_scale): a base colour, which is the page background (shade 950), with the
// other shades derived from it, or the full scale.

export const SURFACE_SHADES = ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950']

const rgbScale = (triplets) => Object.freeze(Object.fromEntries(
  Object.entries(triplets).map(([shade, triplet]) => [shade, triplet.split(' ').map(Number)]),
))

/**
 * RunR's dark theme: neutral (R = G = B, no blue cast), a near-black page, high-contrast text.
 * The surfaces are Tailwind's neutral 950–700; the text tones keep the luminance of the former
 * cool-gray ones, so the contrast stays.
 */
export const RUNR_DARK = rgbScale({
  950: '10 10 10', 900: '23 23 23', 800: '38 38 38', 700: '64 64 64',
  600: '186 186 186', 500: '215 215 215', 400: '232 232 232', 300: '240 240 240',
  200: '246 246 246', 100: '250 250 250', 50: '253 253 253',
})

/** RunR's light theme: warm whites and warm gray-brown from the RunR orange hue, low saturation. */
export const RUNR_LIGHT = rgbScale({
  950: '255 252 248', 900: '254 245 239', 800: '245 232 223', 700: '223 206 193',
  600: '85 67 53', 500: '58 43 32', 400: '42 29 20', 300: '32 21 14',
  200: '23 15 9', 100: '16 10 6', 50: '10 7 4',
})

export const DEFAULT_DARK  = '#0a0a0a'   // RunR dark 950
export const DEFAULT_LIGHT = '#fffcf8'   // RunR light 950
export const THEME_KEYS = ['dark', 'light']
export const DEFAULT_THEME = 'dark'

const RUNR_SURFACES = { dark: RUNR_DARK, light: RUNR_LIGHT }

// OKLab (Björn Ottosson): lightness and chroma that follow the eye, so a derived scale keeps the
// contrast of RunR's own between its text and surface tones.
const toLinear = c => { const v = c / 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4 }
const fromLinear = c => 255 * (c <= 0.0031308 ? 12.92 * c : 1.055 * c ** (1 / 2.4) - 0.055)

function rgbToOklch([r, g, b]) {
  const [R, G, B] = [r, g, b].map(toLinear)
  const l = Math.cbrt(0.4122214708 * R + 0.5363325363 * G + 0.0514459929 * B)
  const m = Math.cbrt(0.2119034982 * R + 0.6806995451 * G + 0.1073969566 * B)
  const s = Math.cbrt(0.0883024619 * R + 0.2817188376 * G + 0.6299787005 * B)
  const L = 0.2104542553 * l + 0.7936177850 * m - 0.0040720468 * s
  const A = 1.9779984951 * l - 2.4285922050 * m + 0.4505937099 * s
  const Bb = 0.0259040371 * l + 0.7827717662 * m - 0.8086757660 * s
  return [L, Math.hypot(A, Bb), (Math.atan2(Bb, A) * 180 / Math.PI + 360) % 360]
}

function oklchToRgb([L, C, h]) {
  const A = C * Math.cos(h * Math.PI / 180)
  const B = C * Math.sin(h * Math.PI / 180)
  const l = (L + 0.3963377774 * A + 0.2158037573 * B) ** 3
  const m = (L - 0.1055613458 * A - 0.0638541728 * B) ** 3
  const s = (L - 0.0894841775 * A - 1.2914855480 * B) ** 3
  return [
    4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
    -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
    -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s,
  ].map(fromLinear)
}

const inGamut = rgb => rgb.every(v => v >= -0.5 && v <= 255.5)

/** The colour of lightness L and hue h with chroma C, or as much of it as sRGB can show. */
function oklchInGamut(L, C, h) {
  let rgb = oklchToRgb([L, C, h])
  if (!inGamut(rgb)) {
    let lo = 0
    let hi = C
    for (let i = 0; i < 24; i++) {
      const mid = (lo + hi) / 2
      if (inGamut(oklchToRgb([L, mid, h]))) lo = mid
      else hi = mid
    }
    rgb = oklchToRgb([L, lo, h])
  }
  return rgb.map(v => clamp(Math.round(v)))
}

// How far a surface follows the base colour's lightness (the page: all the way; the borders: a
// fifth). Text tones keep RunR's lightness, and with it their contrast.
const LIGHTNESS_FOLLOW = { 950: 1, 900: 0.6, 800: 0.4, 700: 0.2 }
// How much of the base colour's chroma each tone takes: the surfaces about as much, the text
// tones less the brighter (dark) or the darker (light) they are. (RunR's dark scale is neutral,
// so this profile is not read off it.)
const CHROMA_PROFILE = {
  dark: {
    950: 1, 900: 1.16, 800: 1.08, 700: 1.12,
    600: 0.76, 500: 0.54, 400: 0.43, 300: 0.26, 200: 0.1, 100: 0.1, 50: 0.05,
  },
  light: {
    950: 1, 900: 2.03, 800: 3.01, 700: 4.21,
    600: 5.43, 500: 4.73, 400: 4.23, 300: 3.62, 200: 2.91, 100: 2.29, 50: 1.75,
  },
}
// Text tones stay readable: no more colour than this (OKLCH chroma)
const TEXT_CHROMA_CAP = 0.06

/**
 * A theme's tone scale (--gray-50 … --gray-950) from its base colour, the page background
 * (shade 950). Each shade takes the base's hue and a share of its chroma (CHROMA_PROFILE: a gray
 * base → a gray scale, a navy one → navy surfaces and bluish text); the surfaces follow the
 * base's lightness, the text tones keep RunR's. RunR's own base colour gives RunR's scale
 * unchanged.
 *
 * @param {string} baseColor "#rrggbb" or "r g b"
 * @param {'dark'|'light'} theme
 * @returns {Record<string, string>} shade → "r g b"
 */
export function deriveSurfaceScale(baseColor, theme = 'dark') {
  const runr = RUNR_SURFACES[theme] ?? RUNR_DARK
  const base = parseColor(baseColor) ?? runr[950]
  if (rgbToHex(base) === rgbToHex(runr[950])) {
    return Object.fromEntries(SURFACE_SHADES.map(s => [s, runr[s].join(' ')]))
  }

  const [baseL, baseC, hue] = rgbToOklch(base)
  const [runrL] = rgbToOklch(runr[950])
  const profile = CHROMA_PROFILE[theme] ?? CHROMA_PROFILE.dark
  return Object.fromEntries(SURFACE_SHADES.map((shade) => {
    if (shade === '950') return [shade, base.join(' ')]
    const [L] = rgbToOklch(runr[shade])
    const follow = LIGHTNESS_FOLLOW[shade] ?? 0
    const lightness = Math.min(1, Math.max(0, L + (baseL - runrL) * follow))
    const chroma = Math.min(follow ? Infinity : TEXT_CHROMA_CAP, baseC * profile[shade])
    return [shade, oklchInGamut(lightness, chroma, hue).join(' ')]
  }))
}

/**
 * A theme's tone scale to apply: an explicit scale ("r g b" or "#rrggbb" per shade) wins shade
 * by shade, the rest is derived from the base colour (its 950 shade when no base is given).
 *
 * @returns {Record<string, string>} shade → "r g b"
 */
export function resolveSurfaceScale(scale, baseColor, theme = 'dark') {
  const explicit = scale && typeof scale === 'object' ? scale : {}
  const base = parseColor(baseColor) ? baseColor : (toTriplet(explicit['950']) ?? null)
  const derived = deriveSurfaceScale(base, theme)
  return Object.fromEntries(SURFACE_SHADES.map(s => [s, toTriplet(explicit[s]) ?? derived[s]]))
}

/**
 * The tone scale an editor shows: shade → "#rrggbb", the overrides of `scale` (shade → "#rrggbb"
 * or "") over the shades derived from `baseColor`.
 */
export function editorSurfaceScale(scale, baseColor, theme = 'dark') {
  const derived = deriveSurfaceScale(baseColor, theme)
  return Object.fromEntries(SURFACE_SHADES.map((s) => {
    const override = scale?.[s]
    return [s, (isHexColor(override) ? normalizeHex(override) : null) ?? toHex(derived[s])]
  }))
}

/** WCAG contrast ratio of two colours ("#rrggbb" or "r g b"), 1–21. */
export function contrastRatio(a, b) {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (hi + 0.05) / (lo + 0.05)
}

/** Relative luminance (WCAG) — used to pick readable text on a brand-coloured surface. */
export function luminance(value) {
  const rgb = parseColor(value)
  if (!rgb) return 0
  const [r, g, b] = rgb.map(v => {
    const c = v / 255
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

/** "#ffffff" or "#000000", whichever reads better on the given background. */
export function readableTextOn(value) {
  return luminance(value) > 0.45 ? '#000000' : '#ffffff'
}
