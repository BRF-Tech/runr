// Before the first paint: index.html inlines this module, bundled into a classic script by the
// runr-pre-paint plugin (vue/build/prePaintPlugin.js), so it runs before anything is drawn. It
// picks the theme and writes the colours a re-branded console starts in, instead of flashing
// RunR's dark theme until GET /api/branding answers:
//
//   1. the branding cached by the last visit (localStorage `branding`, written by the app),
//   2. else the platform's branding defaults, which the ui container writes at start into
//      /branding-defaults.js (window.__RUNR_BRANDING_DEFAULTS__: Helm branding.colors.* and
//      branding.defaultTheme, the API's BRANDING_* env; vue/docker-entrypoint.sh),
//   3. else RunR's own (main.css).
//
// The theme a user picked always wins (the same choice as src/composables/useTheme.js). The app
// takes over with applyBranding() (utils/branding.js): this writes the same CSS. Only pure
// helpers here (no Vue, no Pinia): the bundle stays small.
import { DEFAULT_THEME, SHADES, SURFACE_SHADES, normalizeHex, resolvePalette, resolveSurfaceScale, toTriplet } from './utils/color.js'
import { currentLocale } from '../../utils/locale.js'

export const BRANDING_STYLE_ID = 'runr-branding'
export const DEFAULTS_GLOBAL = '__RUNR_BRANDING_DEFAULTS__'

const TRIPLET = /^\d{1,3} \d{1,3} \d{1,3}$/

/** "r g b" values of the known shades only: nothing from storage or the defaults reaches CSS unchecked. */
function cleanScale(scale, shades) {
  const out = {}
  if (!scale || typeof scale !== 'object') return out
  for (const shade of shades) {
    // "rgb(r, g, b)" too: the Helm values allow it (values.schema.json)
    const value = typeof scale[shade] === 'string' ? scale[shade].replace(/^\s*rgb\(|\)\s*$/gi, '') : scale[shade]
    const triplet = toTriplet(value)
    if (triplet && TRIPLET.test(triplet)) out[shade] = triplet
  }
  return out
}

function declarations(prefix, scale) {
  return Object.entries(scale).map(([shade, triplet]) => `--${prefix}-${shade}: ${triplet}; `).join('')
}

/** The theme tones of both themes and the brand scale as CSS (the same rules as surfaceCss()). */
export function brandingCss({ surfaces = {}, palette = {}, accent = null }) {
  const dark = cleanScale(surfaces.dark, SURFACE_SHADES)
  const light = cleanScale(surfaces.light, SURFACE_SHADES)
  const brand = cleanScale(palette, SHADES)
  const accentTriplet = toTriplet(accent)
  return `html:not([data-theme="light"]) { ${declarations('gray', dark)}}\n`
    + `html[data-theme="light"] { ${declarations('gray', light)}}\n`
    + `html:root { ${declarations('surface-dark', dark)}${declarations('surface-light', light)}${declarations('brand', brand)}`
    + `${accentTriplet ? `--brand-accent: ${accentTriplet}; ` : ''}}`
}

/**
 * The platform defaults of /branding-defaults.js as colours to write: the brand scale (an
 * explicit scale over the one derived from the base colour), both themes' tones (likewise) and
 * the default theme. null for anything that is not such an object.
 */
export function platformBranding(raw) {
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return null
  const primary = normalizeHex(raw.primary_color)
  const palette = cleanScale(raw.primary_scale, SHADES)
  const dark = cleanScale(raw.dark_scale, SURFACE_SHADES)
  const light = cleanScale(raw.light_scale, SURFACE_SHADES)
  const darkColor = normalizeHex(raw.dark_color)
  const lightColor = normalizeHex(raw.light_color)
  const theme = raw.default_theme === 'light' || raw.default_theme === 'dark' ? raw.default_theme : null
  const accent = normalizeHex(raw.accent_color)
  // an install without platform branding: RunR's own (main.css), nothing to write
  if (!primary && !accent && !darkColor && !lightColor && !theme
    && ![palette, dark, light].some(scale => Object.keys(scale).length)) return null
  return {
    // nothing given: RunR's (main.css) stays, no rule is written for it
    palette: primary || Object.keys(palette).length ? resolvePalette(palette, primary ?? undefined) : {},
    accent,
    surfaces: {
      dark: darkColor || Object.keys(dark).length ? resolveSurfaceScale(dark, darkColor, 'dark') : {},
      light: lightColor || Object.keys(light).length ? resolveSurfaceScale(light, lightColor, 'light') : {},
    },
    default_theme: theme,
  }
}

function readCache(storage) {
  try {
    const b = JSON.parse(storage?.getItem('branding') || 'null')
    return b && typeof b === 'object' && !Array.isArray(b) ? b : null
  } catch {
    return null
  }
}

function pickedTheme(storage) {
  try {
    const picked = storage?.getItem('theme')
    if (picked === 'light' || (picked === 'fulldark' && storage.getItem('theme_chosen'))) return picked
  } catch { /* storage unavailable */ }
  return null
}

/**
 * Sets data-theme, the page language and the branding colours on the document. Returns where the
 * colours came from: 'cache' | 'platform' | null (RunR's).
 */
export function prePaint({
  doc = globalThis.document,
  storage = globalThis.localStorage,
  defaults = globalThis[DEFAULTS_GLOBAL],
  nav = globalThis.navigator,
} = {}) {
  const root = doc.documentElement
  const cached = readCache(storage)
  const platform = platformBranding(defaults)

  // the user's pick, else the cached branding's default, else the platform's, else RunR's (dark)
  const brandingTheme = cached ? (cached.default_theme === 'light' ? 'light' : DEFAULT_THEME) : (platform?.default_theme ?? DEFAULT_THEME)
  root.dataset.theme = pickedTheme(storage) ?? (brandingTheme === 'light' ? 'light' : 'fulldark')
  root.lang = currentLocale(storage, nav)

  const source = cached ? 'cache' : platform ? 'platform' : null
  if (!source) return null
  const colours = cached
    ? { surfaces: cached.surfaces || {}, palette: cached.palette || {}, accent: cached.accent_color }
    : platform

  const style = doc.createElement('style')
  style.id = BRANDING_STYLE_ID
  style.textContent = brandingCss(colours)
  doc.head.appendChild(style)
  return source
}
