import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import RunrWordmark from '@/components/RunrWordmark.vue'
import runrWordmarkDarkUrl from '@/assets/runr-wordmark-dark.svg'
import runrWordmarkLightUrl from '@/assets/runr-wordmark-light.svg'
import { useBrandingStore } from '@/modules/white-label/stores/branding'
import { globalOptions, makePinia } from './helpers'

const theme = vi.hoisted(() => ({ value: 'fulldark' }))
vi.mock('@/composables/useTheme', async () => {
  const { ref } = await import('vue')
  const current = ref(theme.value)
  return { useTheme: () => ({ current, cycle: () => {}, set: (v) => { current.value = v } }), setBrandingTheme: () => {} }
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

  it('RunR brand: the runr.sh wordmark SVG, the dark one on dark surfaces', () => {
    const wrapper = mountWordmark({})
    const mark = wrapper.find('[data-test="wordmark-runr"]')
    expect(mark.exists()).toBe(true)
    expect(mark.element.tagName).toBe('IMG')
    expect(mark.attributes('src')).toBe(runrWordmarkDarkUrl)
    expect(mark.attributes('alt')).toBe('RunR serverless')
  })

  it('RunR brand on the light theme: the light wordmark SVG; forceWhite keeps the dark one', async () => {
    const { useTheme } = await import('@/composables/useTheme')
    useTheme().set('light')
    try {
      expect(mountWordmark({}).find('[data-test="wordmark-runr"]').attributes('src')).toBe(runrWordmarkLightUrl)
      expect(mountWordmark({}, { forceWhite: true }).find('[data-test="wordmark-runr"]').attributes('src')).toBe(runrWordmarkDarkUrl)
      expect(runrWordmarkLightUrl).not.toBe(runrWordmarkDarkUrl)
    } finally {
      useTheme().set('fulldark')
    }
  })

  it('the console wordmark SVGs are the runr.sh wordmark', async () => {
    const fs = await import('node:fs')
    const read = (p) => fs.readFileSync(new URL(p, import.meta.url), 'utf8')
    const site = read('../../../landing/assets/runr-wordmark.svg')
    expect(read('../../src/assets/runr-wordmark-dark.svg')).toBe(site)
    const light = read('../../src/assets/runr-wordmark-light.svg')
    // same geometry and tagline; only the text colours differ
    const shape = (svg) => svg.replace(/fill="#[0-9a-fA-F]{6}"/g, '').replace(/id="[^"]+"|url\(#[^)]+\)/g, '')
    expect(shape(light)).toBe(shape(site))
    expect(read('../../src/assets/runr-icon.svg')).toBe(read('../../../landing/assets/runr-icon.svg'))
    expect(read('../../public/favicon.svg')).toBe(read('../../../landing/assets/favicon.svg'))
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
