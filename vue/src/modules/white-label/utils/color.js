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
