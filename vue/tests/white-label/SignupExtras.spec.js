import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import client from '@/api/client'
import SignupExtras from '@/modules/white-label/components/SignupExtras.vue'
import { flush, globalOptions, ok } from './helpers'

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const status = (over = {}) => ({
  enabled: true,
  invite_only: false,
  email_verification: false,
  allowed_domains: [],
  captcha: { provider: 'none', site_key: null },
  terms_url: null,
  privacy_url: null,
  tenant_auto_create: true,
  ...over,
})

async function mountExtras(data, modelValue = {}) {
  vi.mocked(client.get).mockReturnValue(ok(data))
  let model = modelValue
  const wrapper = mount(SignupExtras, {
    props: {
      modelValue: model,
      'onUpdate:modelValue': (v) => { model = v; wrapper.setProps({ modelValue: v }) },
    },
    global: globalOptions(),
  })
  await flush()
  return { wrapper, model: () => model }
}

describe('SignupExtras', () => {
  beforeEach(() => {
    vi.mocked(client.get).mockReset()
  })

  it('reads the registration status and renders nothing extra by default', async () => {
    const { wrapper } = await mountExtras(status())
    expect(client.get).toHaveBeenCalledWith('/auth/registration-status')
    expect(wrapper.find('[data-test="terms"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="plan-picker"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="captcha"]').exists()).toBe(false)
  })

  it('asks to accept the terms when a terms or privacy URL is set', async () => {
    const { wrapper, model } = await mountExtras(status({ terms_url: 'https://acme.test/terms', privacy_url: 'https://acme.test/privacy' }))
    const terms = wrapper.find('[data-test="terms"]')
    expect(terms.exists()).toBe(true)
    expect(terms.findAll('a').map(a => a.attributes('href'))).toEqual(['https://acme.test/terms', 'https://acme.test/privacy'])

    await wrapper.find('[data-test="terms-checkbox"]').setValue(true)
    expect(model().accept_terms).toBe(true)
  })

  it('never asks for a plan: new organizations start on the default plan', async () => {
    const { wrapper, model } = await mountExtras(status({ terms_url: 'https://acme.test/terms' }))
    expect(wrapper.find('[data-test="plan-picker"]').exists()).toBe(false)
    await wrapper.find('[data-test="terms-checkbox"]').setValue(true)
    expect(model()).toEqual({ accept_terms: true })
  })

  it('renders nothing when the registration status cannot be read', async () => {
    vi.mocked(client.get).mockRejectedValue(new Error('offline'))
    const wrapper = mount(SignupExtras, { props: { modelValue: {} }, global: globalOptions() })
    await flush()
    expect(wrapper.find('[data-test="signup-extras"]').exists()).toBe(false)
  })

  it('shows the allowed domains hint', async () => {
    const { wrapper } = await mountExtras(status({ allowed_domains: ['acme.test', 'corp.example'] }))
    expect(wrapper.find('[data-test="allowed-domains"]').text()).toContain('acme.test, corp.example')
  })

  it('renders the CAPTCHA when a provider is configured', async () => {
    window.turnstile = { render: vi.fn(() => 'w1'), reset: vi.fn(), remove: vi.fn() }
    const { wrapper } = await mountExtras(status({ captcha: { provider: 'turnstile', site_key: '0x4AAA' } }))
    expect(wrapper.find('[data-test="captcha"]').exists()).toBe(true)
    expect(window.turnstile.render).toHaveBeenCalled()
    delete window.turnstile
  })
})
