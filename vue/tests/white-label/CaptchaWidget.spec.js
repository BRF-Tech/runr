import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import CaptchaWidget from '@/modules/white-label/components/CaptchaWidget.vue'
import { resetCaptchaLoaders } from '@/modules/white-label/utils/captcha'
import { flush, globalOptions } from './helpers'

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

describe('CaptchaWidget', () => {
  beforeEach(() => {
    resetCaptchaLoaders()
    document.head.innerHTML = ''
    delete window.turnstile
    delete window.hcaptcha
  })
  afterEach(() => {
    delete window.turnstile
    delete window.hcaptcha
  })

  it('renders nothing and loads no script for provider "none"', async () => {
    const wrapper = mount(CaptchaWidget, { props: { provider: 'none', siteKey: 'k' }, global: globalOptions() })
    await flush()
    expect(wrapper.find('[data-test="captcha"]').exists()).toBe(false)
    expect(document.querySelector('script[data-captcha]')).toBeNull()
  })

  it('renders nothing without a site key', async () => {
    const wrapper = mount(CaptchaWidget, { props: { provider: 'turnstile', siteKey: '' }, global: globalOptions() })
    await flush()
    expect(wrapper.find('[data-test="captcha"]').exists()).toBe(false)
  })

  it('loads the Turnstile script explicitly and emits the token', async () => {
    const wrapper = mount(CaptchaWidget, { props: { provider: 'turnstile', siteKey: '0x4AAA' }, global: globalOptions() })
    await flush(1)

    const script = document.querySelector('script[data-captcha="turnstile"]')
    expect(script).not.toBeNull()
    expect(script.src).toBe('https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit')

    const render = vi.fn((el, opts) => { opts.callback('tok-123'); return 'w1' })
    window.turnstile = { render, reset: vi.fn(), remove: vi.fn() }
    script.dispatchEvent(new Event('load'))
    await flush()

    expect(render).toHaveBeenCalledTimes(1)
    expect(render.mock.calls[0][1].sitekey).toBe('0x4AAA')
    expect(wrapper.emitted('token')).toEqual([['tok-123']])

    // a fresh challenge after a rejected submit
    wrapper.vm.reset()
    expect(window.turnstile.reset).toHaveBeenCalledWith('w1')
    expect(wrapper.emitted('token').at(-1)).toEqual([''])

    wrapper.unmount()
    expect(window.turnstile.remove).toHaveBeenCalledWith('w1')
  })

  it('uses the hCaptcha API when it is already on the page', async () => {
    const render = vi.fn(() => 'h1')
    window.hcaptcha = { render, reset: vi.fn(), remove: vi.fn() }
    mount(CaptchaWidget, { props: { provider: 'hcaptcha', siteKey: 'site-hc' }, global: globalOptions() })
    await flush()

    expect(render).toHaveBeenCalledTimes(1)
    expect(render.mock.calls[0][1].sitekey).toBe('site-hc')
  })
})
