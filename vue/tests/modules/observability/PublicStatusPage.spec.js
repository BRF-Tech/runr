import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PublicStatusPage from '@/modules/observability/components/PublicStatusPage.vue'
import { observabilityApi } from '@/modules/observability/api/observability'
import { setBrandingTheme, useTheme } from '@/composables/useTheme'
import { BRANDING_STYLE_ID } from '@/modules/white-label/utils/branding'
import { deriveSurfaceScale, expandPalette } from '@/modules/white-label/utils/color'
import { withPlugins } from '../../helpers'

vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRoute: () => ({ params: { slug: 'acme' } }) }))
vi.mock('@/modules/observability/api/observability', () => ({
  observabilityApi: { publicStatusPage: vi.fn(), publicStatusByDomain: vi.fn() },
}))

// The public status page showed the organization's brand colour on RunR's dark theme: the API
// left out the theme tones and the default theme (StatusPageService::branding). The page now
// applies the organization's whole branding while it shows, and gives the console's back after.

const branding = {
  product_name: 'Acme Cloud', primary_color: '#2563eb', accent_color: '#e11d48', palette: null,
  dark_color: '#101418', dark_scale: null, light_color: '#f8fafc', light_scale: { 600: '71 85 105' },
  default_theme: 'light', logo_light_url: 'https://acme.example/logo-light.svg', logo_dark_url: 'https://acme.example/logo-dark.svg',
  powered_by: true,
}
const page = {
  title: 'Acme status', description: '', status: 'operational', generated_at: '2026-11-03T10:00:00Z',
  components: [], incidents: [], branding,
}

const root = () => document.documentElement
const style = () => document.getElementById(BRANDING_STYLE_ID)?.textContent ?? ''

let wrapper
beforeEach(() => {
  localStorage.clear()
  setBrandingTheme('dark')
  root().style.removeProperty('--brand-500')
  document.getElementById(BRANDING_STYLE_ID)?.remove()
  observabilityApi.publicStatusPage.mockResolvedValue({ data: { data: page } })
})
afterEach(() => wrapper?.unmount())

async function mountPage() {
  wrapper = mount(PublicStatusPage, withPlugins('en'))
  await flushPromises()
  return wrapper
}

describe('the public status page in the organization\'s branding', () => {
  it('applies its brand scale, both themes\' tones and its default theme', async () => {
    await mountPage()
    expect(root().style.getPropertyValue('--brand-500')).toBe(expandPalette('#2563eb')['500'])
    // the dark tones from dark_color, the light ones from light_color with the explicit 600
    expect(style()).toContain(`--gray-950: ${deriveSurfaceScale('#101418', 'dark')['950']};`)
    expect(style()).toContain('--gray-600: 71 85 105;')
    // a visitor who never picked a theme sees the organization's default
    expect(useTheme().current.value).toBe('light')
    expect(root().dataset.theme).toBe('light')
    expect(document.title).toBe('Acme status')
    expect(wrapper.get('[data-test="status-logo"]').attributes('src')).toBe('https://acme.example/logo-light.svg')
  })

  it('keeps a theme the visitor picked', async () => {
    useTheme().set('fulldark')
    await mountPage()
    expect(useTheme().current.value).toBe('fulldark')
    expect(wrapper.get('[data-test="status-logo"]').attributes('src')).toBe('https://acme.example/logo-dark.svg')
  })

  it('gives the console its own branding back when it closes', async () => {
    await mountPage()
    wrapper.unmount()
    wrapper = null
    // the console's (default RunR) branding again
    expect(root().style.getPropertyValue('--brand-500')).toBe('249 115 22')
    expect(useTheme().current.value).toBe('fulldark')
  })
})
