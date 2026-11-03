import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import CaptchaField from '@/modules/white-label/components/CaptchaField.vue'
import client from '@/api/client'
import { useCaptcha } from '@/modules/white-label/composables/useCaptcha'
import { resetCaptchaLoaders } from '@/modules/white-label/utils/captcha'
import { hashOf } from '@/modules/white-label/utils/captchaPow'
import { flush, globalOptions } from './helpers'

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

// CaptchaField renders what useCaptcha says: a stand-in state object keeps the cases apart
function state(over = {}) {
  return reactive({
    required: true, showWidget: true, provider: 'turnstile', siteKey: '1x00000000000000000000AA',
    state: 'idle', progress: 0, solveMs: null, problem: null, resets: 0,
    setToken: vi.fn(), widgetUnavailable: vi.fn(), reset: vi.fn(), load: vi.fn(),
    ...over,
  })
}

describe('CaptchaField', () => {
  beforeEach(() => {
    resetCaptchaLoaders()
    window.turnstile = { render: vi.fn((el, opts) => { opts.callback('tok-9'); return 'w1' }), reset: vi.fn(), remove: vi.fn() }
  })
  afterEach(() => { delete window.turnstile })

  it('renders nothing while the form is not guarded', () => {
    const wrapper = mount(CaptchaField, { props: { captcha: state({ required: false }) }, global: globalOptions() })
    expect(wrapper.find('[data-test="captcha-field"]').exists()).toBe(false)
  })

  it('renders the widget and passes its token on', async () => {
    const captcha = state()
    const wrapper = mount(CaptchaField, { props: { captcha }, global: globalOptions() })
    await flush()

    expect(wrapper.find('[data-test="captcha"]').exists()).toBe(true)
    expect(window.turnstile.render.mock.calls[0][1].sitekey).toBe('1x00000000000000000000AA')
    expect(captcha.setToken).toHaveBeenCalledWith('tok-9')

    // useCaptcha asks for a fresh challenge
    captcha.resets++
    await flush()
    expect(window.turnstile.reset).toHaveBeenCalledWith('w1')
  })

  it('shows the browser check with its progress, readable by screen readers', async () => {
    const captcha = state({ showWidget: false, state: 'solving', progress: 0.42 })
    const wrapper = mount(CaptchaField, { props: { captcha }, global: globalOptions() })

    const check = wrapper.find('[data-test="captcha-local"]')
    expect(check.attributes('role')).toBe('status')
    expect(check.attributes('aria-live')).toBe('polite')
    expect(check.text()).toContain('cannot be reached')
    expect(wrapper.find('[role="progressbar"]').attributes('aria-valuenow')).toBe('42')
    expect(check.attributes('data-captcha-solve-ms')).toBeUndefined()

    captcha.state = 'solved'
    captcha.solveMs = 640
    await flush()
    expect(wrapper.find('[data-test="captcha-local"]').attributes('data-captcha-solve-ms')).toBe('640')
    expect(wrapper.find('[role="progressbar"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Browser check done')
  })

  it('says when the check could not load and starts over on "Try again"', async () => {
    const captcha = state({ showWidget: false, problem: 'loadFailed' })
    const wrapper = mount(CaptchaField, { props: { captcha }, global: globalOptions() })

    expect(wrapper.find('[data-test="captcha-problem"]').attributes('role')).toBe('alert')
    expect(wrapper.text()).toContain('could not load')
    // the API reaches the provider: the browser blocks the widget (shared contract §3.5)
    expect(wrapper.text()).toContain('Turn off your ad blocker')
    await wrapper.find('[data-test="captcha-retry"]').trigger('click')
    expect(captcha.reset).toHaveBeenCalled()
    expect(captcha.load).toHaveBeenCalled()
  })

  it('hands a widget that cannot load to useCaptcha', async () => {
    window.turnstile.render = vi.fn((el, opts) => { opts['error-callback'](); return 'w1' })
    const captcha = state()
    mount(CaptchaField, { props: { captcha }, global: globalOptions() })
    await flush()
    expect(captcha.widgetUnavailable).toHaveBeenCalled()
  })

  it('a wrong configuration: no widget, the local check right away (useCaptcha)', async () => {
    const SALT = '0123456789abcdef0123456789abcdef?expires=1762300600'
    vi.mocked(client.get).mockResolvedValue({ data: { provider: 'turnstile', site_key: null, fallback: 'local', forms: ['login'], misconfigured: true } })
    vi.mocked(client.post).mockResolvedValue({ data: { challenge: { algorithm: 'SHA-256', salt: SALT, hash: hashOf(SALT, 42), signature: 'ab'.repeat(32), maxnumber: 1000 } } })
    const captcha = useCaptcha('login', { solve: vi.fn().mockResolvedValue('c29sdmVk') })
    const wrapper = mount(CaptchaField, { props: { captcha }, global: globalOptions() })
    await flush()

    expect(wrapper.find('[data-test="captcha"]').exists()).toBe(false)
    expect(window.turnstile.render).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="captcha-local"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="captcha-field"]').attributes('data-captcha-state')).toBe('solved')
    expect(wrapper.find('[data-test="captcha-problem"]').exists()).toBe(false)
  })
})
