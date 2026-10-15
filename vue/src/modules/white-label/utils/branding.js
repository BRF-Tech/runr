// Pure branding helpers (no API / Pinia imports): normalising the GET /api/branding payload and
// writing it to the document. plugin.js uses these before the app mounts (cached branding →
// no colour flash), the Pinia store uses them after every load.
//
// Image fields (logos, favicon, mark, e-mail logo) are https URLs, /storage/… paths or
// data:image/…;base64 URIs and are used as they are; null → the bundled RunR assets.
import { DEFAULT_ACCENT, DEFAULT_PRIMARY, SHADES, normalizeHex, resolvePalette, toTriplet } from './color'

export const BRANDING_CACHE_KEY = 'branding'

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
  features:         Object.freeze({ plans: false, tenant_branding: false, multi_tenant: false }),
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

/** Fill gaps / drop invalid values of an API branding payload. */
export function normalizeBranding(raw) {
  const d = raw && typeof raw === 'object' ? raw : {}
  const productName = (typeof d.product_name === 'string' && d.product_name.trim()) || DEFAULT_PRODUCT_NAME
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
      plans:           !!d.features?.plans,
      tenant_branding: !!d.features?.tenant_branding,
      multi_tenant:    !!d.features?.multi_tenant,
    },
  }
}

/**
 * Write the branding to the document: CSS variables (--brand-300 … --brand-900 from the
 * payload's palette or expanded from primary_color, --brand-accent), the title and the favicon.
 */
export function applyBranding(branding, doc = typeof document !== 'undefined' ? document : null) {
  if (!doc) return
  const b    = normalizeBranding(branding)
  const root = doc.documentElement

  for (const [shade, triplet] of Object.entries(resolvePalette(b.palette, b.primary_color))) {
    root.style.setProperty(`--brand-${shade}`, triplet)
  }
  root.style.setProperty('--brand-accent', toTriplet(b.accent_color))

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
