// Pure branding helpers (no API / Pinia imports): normalising the GET /api/branding payload and
// writing it to the document. plugin.js uses these before the app mounts (cached branding →
// no colour flash), the Pinia store uses them after every load.
//
// Image fields (logos, favicon, mark, e-mail logo) are https URLs, /storage/… paths or
// data:image/…;base64 URIs and are used as they are; null → the bundled RunR assets.
//
// Colours: the brand scale (primary_color / palette → --brand-300 … --brand-900, accent) and the
// tone scales of the two themes (dark_color / dark_scale, light_color / light_scale →
// --gray-50 … --gray-950 per theme), plus the theme of users who never picked one
// (default_theme). The pre-paint script of index.html applies the cached copy's `surfaces`,
// `palette` and `default_theme` before the first paint: keep its shape in step.
import { setBrandingTheme } from '@/composables/useTheme'
import {
  DEFAULT_ACCENT, DEFAULT_DARK, DEFAULT_LIGHT, DEFAULT_PRIMARY, DEFAULT_THEME, SHADES, SURFACE_SHADES,
  normalizeHex, resolvePalette, resolveSurfaceScale, toHex, toTriplet,
} from './color'

export const BRANDING_CACHE_KEY = 'branding'

/** The <style> element with the theme tones (and, before the app starts, the brand scale). */
export const BRANDING_STYLE_ID = 'runr-branding'

/** Bundled favicon (RunR icon) — used when the branding has none. */
export const DEFAULT_FAVICON = '/favicon.svg'

export const DEFAULT_PRODUCT_NAME = 'RunR'
export const DEFAULT_COMPANY_NAME = 'BRF Teknoloji'

/** What GET /api/branding answers when nothing is customised. */
export const DEFAULT_BRANDING = Object.freeze({
  product_name:     DEFAULT_PRODUCT_NAME,
  company_name:     DEFAULT_COMPANY_NAME,
  logo_light_url:   null,
  logo_dark_url:    null,
  logo_mark_url:    null,
  favicon_url:      null,
  primary_color:    DEFAULT_PRIMARY,
  accent_color:     DEFAULT_ACCENT,
  palette:          null,
  dark_color:       DEFAULT_DARK,
  dark_scale:       null,
  light_color:      DEFAULT_LIGHT,
  light_scale:      null,
  default_theme:    DEFAULT_THEME,
  login_headline:   '',
  login_text:       '',
  footer_links:     [],
  support_email:    '',
  support_url:      '',
  email_from_name:  '',
  email_logo_url:   null,
  powered_by:       true,
  is_default_brand: true,
  tenant:           null,
  // billing / tickets: the Billing / Ticket module is on in the API (the console hides their pages otherwise)
  features:         Object.freeze({ billing: false, tickets: true, tenant_branding: false, multi_tenant: false }),
})

/** Explicit palette of the payload ("r g b" per shade) or null. */
function normalizePalette(palette) {
  if (!palette || typeof palette !== 'object') return null
  const out = {}
  for (const shade of SHADES) {
    const triplet = toTriplet(palette[shade])
    if (triplet) out[shade] = triplet
  }
  return Object.keys(out).length ? out : null
}

/** Explicit tone scale of a theme ("r g b" per shade, 50 … 950) or null. */
function normalizeSurfaceScale(scale) {
  if (!scale || typeof scale !== 'object') return null
  const out = {}
  for (const shade of SURFACE_SHADES) {
    const triplet = toTriplet(scale[shade])
    if (triplet) out[shade] = triplet
  }
  return Object.keys(out).length ? out : null
}

/** Fill gaps / drop invalid values of an API branding payload. */
export function normalizeBranding(raw) {
  const d = raw && typeof raw === 'object' ? raw : {}
  const productName = (typeof d.product_name === 'string' && d.product_name.trim()) || DEFAULT_PRODUCT_NAME
  const darkScale   = normalizeSurfaceScale(d.dark_scale)
  const lightScale  = normalizeSurfaceScale(d.light_scale)
  // A scale without its base colour: the base is its page shade (950)
  const darkColor   = normalizeHex(d.dark_color) || toHex(darkScale?.['950']) || DEFAULT_DARK
  const lightColor  = normalizeHex(d.light_color) || toHex(lightScale?.['950']) || DEFAULT_LIGHT
  return {
    product_name:     productName,
    company_name:     (typeof d.company_name === 'string' && d.company_name.trim()) || DEFAULT_COMPANY_NAME,
    logo_light_url:   d.logo_light_url || null,
    logo_dark_url:    d.logo_dark_url || null,
    logo_mark_url:    d.logo_mark_url || null,
    favicon_url:      d.favicon_url || null,
    primary_color:    normalizeHex(d.primary_color) || DEFAULT_PRIMARY,
    accent_color:     normalizeHex(d.accent_color) || DEFAULT_ACCENT,
    palette:          normalizePalette(d.palette),
    dark_color:       darkColor,
    dark_scale:       darkScale,
    light_color:      lightColor,
    light_scale:      lightScale,
    default_theme:    d.default_theme === 'light' ? 'light' : DEFAULT_THEME,
    // The tone scales to apply, all 11 shades per theme (explicit shades over the derived ones)
    surfaces: {
      dark:  resolveSurfaceScale(darkScale, darkColor, 'dark'),
      light: resolveSurfaceScale(lightScale, lightColor, 'light'),
    },
    login_headline:   d.login_headline || '',
    login_text:       d.login_text || '',
    footer_links:     Array.isArray(d.footer_links)
      ? d.footer_links.filter(l => l && l.label && l.url).map(l => ({ label: String(l.label), url: String(l.url) }))
      : [],
    support_email:    d.support_email || '',
    support_url:      d.support_url || '',
    email_from_name:  d.email_from_name || '',
    email_logo_url:   d.email_logo_url || null,
    powered_by:       d.powered_by !== false,
    is_default_brand: typeof d.is_default_brand === 'boolean' ? d.is_default_brand : productName === DEFAULT_PRODUCT_NAME,
    tenant:           d.tenant && d.tenant.slug ? { slug: d.tenant.slug, name: d.tenant.name ?? d.tenant.slug } : null,
    features: {
      billing:         d.features?.billing === true,
      // an API without the flag (before 0.5.8) always had the Ticket module's pages
      tickets:         d.features?.tickets !== false,
      tenant_branding: !!d.features?.tenant_branding,
      multi_tenant:    !!d.features?.multi_tenant,
    },
  }
}

const declarations = (prefix, scale) => Object.entries(scale).map(([shade, triplet]) => `--${prefix}-${shade}: ${triplet};`).join(' ')

/**
 * The theme tones as CSS: each theme's --gray-* variables, and both themes' tones under their own
 * names (--surface-dark-*, --surface-light-*) for the pages that show both at once (the login
 * page: a dark panel beside a light card). The selectors outrank main.css's (:root /
 * [data-theme="…"]), whichever stylesheet comes last.
 */
export function surfaceCss(surfaces) {
  return [
    `html:not([data-theme="light"]) { ${declarations('gray', surfaces.dark)} }`,
    `html[data-theme="light"] { ${declarations('gray', surfaces.light)} }`,
    `html:root { ${declarations('surface-dark', surfaces.dark)} ${declarations('surface-light', surfaces.light)} }`,
  ].join('\n')
}

/**
 * Write the branding to the document: CSS variables (--brand-300 … --brand-900 from the
 * payload's palette or expanded from primary_color, --brand-accent; each theme's --gray-50 …
 * --gray-950 in the <style id="runr-branding"> element), the default theme of users who never
 * picked one, the title and the favicon.
 */
export function applyBranding(branding, doc = typeof document !== 'undefined' ? document : null) {
  if (!doc) return
  const b    = normalizeBranding(branding)
  const root = doc.documentElement

  for (const [shade, triplet] of Object.entries(resolvePalette(b.palette, b.primary_color))) {
    root.style.setProperty(`--brand-${shade}`, triplet)
  }
  root.style.setProperty('--brand-accent', toTriplet(b.accent_color))

  let style = doc.getElementById(BRANDING_STYLE_ID)
  if (!style) {
    style = doc.createElement('style')
    style.id = BRANDING_STYLE_ID
    doc.head.appendChild(style)
  }
  style.textContent = surfaceCss(b.surfaces)
  setBrandingTheme(b.default_theme)

  doc.title = b.product_name

  let link = doc.querySelector('link[rel~="icon"]')
  if (!link) {
    link = doc.createElement('link')
    link.rel = 'icon'
    doc.head.appendChild(link)
  }
  if (b.favicon_url) {
    link.setAttribute('href', b.favicon_url)
    link.removeAttribute('type')
  } else {
    link.setAttribute('href', DEFAULT_FAVICON)
    link.setAttribute('type', 'image/svg+xml')
  }
}

export function readCachedBranding() {
  try {
    const raw = localStorage.getItem(BRANDING_CACHE_KEY)
    return raw ? normalizeBranding(JSON.parse(raw)) : null
  } catch {
    return null
  }
}

export function writeCachedBranding(branding) {
  try { localStorage.setItem(BRANDING_CACHE_KEY, JSON.stringify(branding)) } catch { /* storage unavailable or full (data: URIs) */ }
}

/** Slug of the active tenant (localStorage active_tenant) — null in central / single-tenant mode. */
export function activeTenantSlug() {
  try {
    if (!localStorage.getItem('token')) return null
    const t = JSON.parse(localStorage.getItem('active_tenant') || 'null')
    return t?.slug || null
  } catch {
    return null
  }
}
