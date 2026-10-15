import { beforeEach, describe, expect, it, vi } from 'vitest'
import client from '@/api/client'
import { applyBranding, normalizeBranding, readCachedBranding } from '@/modules/white-label/utils/branding'
import { useBrandingStore } from '@/modules/white-label/stores/branding'
import { makePinia, ok } from './helpers'

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const css = (name) => document.documentElement.style.getPropertyValue(name)
const favicon = () => document.querySelector('link[rel~="icon"]').getAttribute('href')

const PNG_DATA_URI = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='

describe('branding: normalize + apply', () => {
  beforeEach(() => {
    localStorage.clear()
    document.head.innerHTML = '<link rel="icon" type="image/svg+xml" href="/favicon.svg">'
    document.documentElement.removeAttribute('style')
    document.title = 'before'
  })

  it('RunR defaults when nothing is customised', () => {
    const b = normalizeBranding({})
    expect(b.product_name).toBe('RunR')
    expect(b.company_name).toBe('BRF Teknoloji')
    expect(b.primary_color).toBe('#f97316')
    expect(b.accent_color).toBe('#e63946')
    expect(b.is_default_brand).toBe(true)
    expect(b.palette).toBeNull()
    expect(b.logo_mark_url).toBeNull()
    expect(b.features).toEqual({ plans: false, tenant_branding: false, multi_tenant: false })
  })

  it('fills gaps and drops invalid values', () => {
    const b = normalizeBranding({
      product_name: '  ', primary_color: 'red', company_name: 'Acme Ltd',
      palette: { 300: '1 2 3', 400: 'nope' },
      footer_links: [{ label: 'x' }, { label: 'Docs', url: 'https://d' }],
    })
    expect(b.product_name).toBe('RunR')
    expect(b.company_name).toBe('Acme Ltd')
    expect(b.primary_color).toBe('#f97316')
    expect(b.palette).toEqual({ 300: '1 2 3' })
    expect(b.footer_links).toEqual([{ label: 'Docs', url: 'https://d' }])
    expect(normalizeBranding({ product_name: 'Acme Cloud' }).is_default_brand).toBe(false)
    expect(normalizeBranding({ product_name: 'Acme Cloud', is_default_brand: true }).is_default_brand).toBe(true)
  })

  it('applies the RunR palette, accent and favicon by default', () => {
    applyBranding({})
    expect(css('--brand-300')).toBe('253 186 116')
    expect(css('--brand-500')).toBe('249 115 22')
    expect(css('--brand-900')).toBe('124 45 18')
    expect(css('--brand-accent')).toBe('230 57 70')
    expect(document.title).toBe('RunR')
    expect(favicon()).toBe('/favicon.svg')
  })

  it('applies the palette of the payload as --brand-300 … --brand-900', () => {
    applyBranding({
      product_name: 'Acme Cloud',
      primary_color: '#009fe3',
      accent_color: '#112233',
      palette: { 300: '102 197 237', 400: '51 181 233', 500: '0 159 227', 600: '0 123 192', 700: '0 85 184', 800: '0 68 153', 900: '0 43 128' },
      favicon_url: PNG_DATA_URI,
    })

    expect(css('--brand-300')).toBe('102 197 237')
    expect(css('--brand-600')).toBe('0 123 192')
    expect(css('--brand-900')).toBe('0 43 128')
    expect(css('--brand-accent')).toBe('17 34 51')
    expect(document.title).toBe('Acme Cloud')
    // data: URIs are used as they are
    expect(favicon()).toBe(PNG_DATA_URI)
  })

  it('expands the base colour when the payload has no palette and restores the RunR favicon', () => {
    applyBranding({ primary_color: '#ff0000', favicon_url: 'https://cdn.acme.test/f.ico' })
    expect(css('--brand-500')).toBe('255 0 0')
    expect(css('--brand-300')).toBe('255 115 115')
    expect(favicon()).toBe('https://cdn.acme.test/f.ico')

    applyBranding({})
    expect(favicon()).toBe('/favicon.svg')
  })
})

describe('branding store', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.mocked(client.get).mockReset()
    makePinia()
  })

  it('loads the platform branding, applies and caches it', async () => {
    vi.mocked(client.get).mockReturnValue(ok({ product_name: 'Acme', primary_color: '#ff0000', is_default_brand: false, features: { plans: true } }))
    const store = useBrandingStore()

    await store.load()

    expect(client.get).toHaveBeenCalledWith('/branding', { params: {} })
    expect(store.productName).toBe('Acme')
    expect(store.companyName).toBe('BRF Teknoloji')
    expect(store.features.plans).toBe(true)
    expect(store.isDefaultName).toBe(false)
    expect(css('--brand-500')).toBe('255 0 0')
    expect(readCachedBranding().product_name).toBe('Acme')
  })

  it('passes the tenant slug and lets the newest load win', async () => {
    let resolveFirst
    vi.mocked(client.get)
      .mockImplementationOnce(() => new Promise(r => { resolveFirst = r }))
      .mockImplementationOnce(() => ok({ product_name: 'Tenant B', tenant: { slug: 'b', name: 'B' } }))
    const store = useBrandingStore()

    const first = store.load('a')
    await store.load('b')
    resolveFirst({ data: { product_name: 'Tenant A' } })
    await first

    expect(client.get).toHaveBeenNthCalledWith(1, '/branding', { params: { tenant: 'a' } })
    expect(store.productName).toBe('Tenant B')
    expect(store.tenantSlug).toBe('b')
  })

  it('picks the logo by theme with a fallback; no logo → null (bundled RunR assets)', () => {
    const store = useBrandingStore()
    store.set({})
    expect(store.logoFor('light')).toBeNull()
    expect(store.markUrl).toBeNull()
    expect(store.isDefaultName).toBe(true)

    store.set({ logo_light_url: PNG_DATA_URI, logo_mark_url: '/storage/branding/mark.png' })
    expect(store.logoFor('light')).toBe(PNG_DATA_URI)
    expect(store.logoFor('dark')).toBe(PNG_DATA_URI)
    expect(store.markUrl).toBe('/storage/branding/mark.png')
    store.set({ logo_light_url: '/light.png', logo_dark_url: '/dark.png' })
    expect(store.logoFor('fulldark')).toBe('/dark.png')
    expect(store.hasCustomLogo).toBe(true)
  })

  it('keeps the current branding when the request fails', async () => {
    vi.mocked(client.get).mockRejectedValue(new Error('offline'))
    const store = useBrandingStore()
    store.set({ product_name: 'Cached' })
    await store.load()
    expect(store.productName).toBe('Cached')
  })
})
