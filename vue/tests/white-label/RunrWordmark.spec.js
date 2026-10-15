import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import RunrWordmark from '@/components/RunrWordmark.vue'
import { useBrandingStore } from '@/modules/white-label/stores/branding'
import { globalOptions, makePinia } from './helpers'

const theme = vi.hoisted(() => ({ value: 'dark' }))
vi.mock('@/composables/useTheme', async () => {
  const { ref } = await import('vue')
  const current = ref(theme.value)
  return { useTheme: () => ({ current, cycle: () => {}, set: (v) => { current.value = v } }) }
})
vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const SVG_DATA_URI = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciLz4='

function mountWordmark(branding, props = {}) {
  const pinia = makePinia()
  useBrandingStore().set(branding)
  return mount(RunrWordmark, { props, global: globalOptions({ pinia }) })
}

describe('RunrWordmark', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('RunR brand: "Run" + "R" wordmark with the serverless tagline', () => {
    const wrapper = mountWordmark({})
    const mark = wrapper.find('[data-test="wordmark-runr"]')
    expect(mark.exists()).toBe(true)
    expect(mark.text()).toContain('RunR')
    expect(mark.text().toLowerCase()).toContain('serverless')
    expect(mark.find('.runr-r').text()).toBe('R')
  })

  it('custom product name: the name as text', () => {
    const wrapper = mountWordmark({ product_name: 'Acme Cloud' })
    expect(wrapper.find('[data-test="wordmark-runr"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="wordmark-name"]').text()).toBe('Acme Cloud')
  })

  it('custom logo (data: URI): the image, dark surfaces prefer the dark logo', () => {
    const wrapper = mountWordmark({ product_name: 'Acme Cloud', logo_light_url: '/storage/branding/light.png', logo_dark_url: SVG_DATA_URI }, { forceWhite: true })
    const img = wrapper.find('[data-test="wordmark-logo"]')
    expect(img.attributes('src')).toBe(SVG_DATA_URI)
    expect(img.attributes('alt')).toBe('Acme Cloud')
  })

  it('showLogo=false keeps the text even with a custom logo', () => {
    const wrapper = mountWordmark({ product_name: 'Acme Cloud', logo_light_url: SVG_DATA_URI }, { showLogo: false })
    expect(wrapper.find('[data-test="wordmark-logo"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="wordmark-name"]').text()).toBe('Acme Cloud')
  })
})
