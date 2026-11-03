import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import client from '@/api/client'
import BotProtectionSettings from '@/modules/white-label/components/BotProtectionSettings.vue'
import { flush, globalOptions, ok, signIn } from './helpers'

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))
// The page's tour (driver.js) is not under test here
vi.mock('@/composables/useTutorial', () => ({ createTutorial: () => ({ start: vi.fn() }) }))

const ALL = ['login', 'register', 'password_reset', 'demo_login', 'public_forms']

const platform = (over = {}) => ({
  provider: 'turnstile', site_key: '1x00000000000000000000AA', secret_set: true, forms: ALL,
  fallback: 'local', verify_timeout_ms: 4000, source: 'platform',
  stored: { provider: 'turnstile', site_key: '1x00000000000000000000AA', secret_set: true, forms: null, fallback: null, verify_timeout_ms: null },
  defaults: { provider: 'none', site_key: null, secret_set: false, forms: ALL, fallback: 'local', verify_timeout_ms: 4000, legacy: false },
  providers: ['none', 'turnstile', 'recaptcha', 'hcaptcha'], form_keys: ALL, multi_tenant: false,
  fallback_active: true, fallback_total: 3, fallback_last_at: '2026-11-03T10:00:00+00:00',
  ...over,
})

const tenant = (over = {}) => ({
  provider: null, site_key: null, secret_set: false,
  effective: { provider: 'hcaptcha', source: 'platform', forms: ALL },
  platform: { provider: 'hcaptcha', forms: ALL },
  providers: ['turnstile', 'recaptcha', 'hcaptcha'],
  ...over,
})

async function mountPage(data, url) {
  vi.mocked(client.get).mockImplementation((u) => (u === url ? ok(data) : ok({})))
  vi.mocked(client.put).mockImplementation((u, body) => ok({ ...data, ...body, secret_set: body.secret_clear ? false : (body.secret ? true : data.secret_set) }))
  const wrapper = mount(BotProtectionSettings, { global: globalOptions() })
  await flush()
  return wrapper
}

const lastPut = () => vi.mocked(client.put).mock.calls.at(-1)

describe('BotProtectionSettings', () => {
  beforeEach(() => {
    localStorage.clear()
    for (const fn of Object.values(client)) vi.mocked(fn).mockReset()
  })
  afterEach(() => vi.unstubAllEnvs())

  it('platform: shows the provider, the forms and the fallback status, never the secret', async () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'false')
    signIn({ admin: true })
    const wrapper = await mountPage(platform(), '/admin/captcha')

    expect(wrapper.find('[data-test="bot-protection-platform"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="field-provider"]').element.value).toBe('turnstile')
    expect(wrapper.find('[data-test="field-site_key"]').element.value).toBe('1x00000000000000000000AA')
    expect(wrapper.find('[data-test="field-secret"]').element.value).toBe('')
    expect(wrapper.find('[data-test="field-secret-state"]').text()).toContain('Stored')
    expect(wrapper.find('[data-test="provider-hint"]').text()).toContain('Installation default: Off')
    expect(wrapper.find('[data-test="fallback-active"]').text()).toContain('active')
    expect(wrapper.find('[data-test="fallback-total"]').text()).toContain('3')
    // RunR has no password reset form: no checkbox for it
    expect(wrapper.find('[data-test="form-password_reset"]').exists()).toBe(false)
    for (const key of ['login', 'demo_login', 'register', 'public_forms']) {
      expect(wrapper.find(`[data-test="form-${key}"]`).element.checked).toBe(true)
    }
    // the tour's targets
    for (const target of ['provider', 'keys', 'forms', 'fallback', 'status', 'save']) {
      expect(wrapper.find(`[data-tour="bot-protection-${target}"]`).exists()).toBe(true)
    }
  })

  it('platform: saves the forms and keeps the secret unless one is typed', async () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'false')
    signIn({ admin: true })
    const wrapper = await mountPage(platform(), '/admin/captcha')

    await wrapper.find('[data-test="form-public_forms"]').setValue(false)
    await wrapper.find('[data-test="field-fallback"]').setValue('off')
    await wrapper.find('form').trigger('submit')
    await flush()

    const [url, body] = lastPut()
    expect(url).toBe('/admin/captcha')
    expect(body).toMatchObject({ provider: 'turnstile', site_key: '1x00000000000000000000AA', fallback: 'off', verify_timeout_ms: 4000 })
    expect(body.forms).toEqual(['login', 'register', 'password_reset', 'demo_login'])
    expect(body).not.toHaveProperty('secret')
    expect(body).not.toHaveProperty('secret_clear')

    await wrapper.find('[data-test="field-secret"]').setValue('  new-secret ')
    await wrapper.find('form').trigger('submit')
    await flush()
    expect(lastPut()[1].secret).toBe('new-secret')
    expect(wrapper.find('[data-test="field-secret"]').element.value).toBe('')

    await wrapper.find('[data-test="field-secret-clear"]').trigger('click')
    await wrapper.find('form').trigger('submit')
    await flush()
    expect(lastPut()[1].secret_clear).toBe(true)
  })

  it('platform: "Off" hides the keys, and Reset sends reset', async () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'false')
    signIn({ admin: true })
    const wrapper = await mountPage(platform(), '/admin/captcha')

    await wrapper.find('[data-test="field-provider"]').setValue('none')
    expect(wrapper.find('[data-tour="bot-protection-keys"]').exists()).toBe(false)

    await wrapper.find('[data-test="bot-protection-reset"]').trigger('click')
    await flush()
    expect(lastPut()[1]).toEqual({ reset: true })
  })

  it('shows the API\'s validation errors', async () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'false')
    signIn({ admin: true })
    const wrapper = await mountPage(platform({ provider: 'none', site_key: null, secret_set: false, stored: null }), '/admin/captcha')
    vi.mocked(client.put).mockRejectedValue({ response: { status: 422, data: { message: 'A provider needs its site key.', errors: { site_key: ['A provider needs its site key.'] } } } })

    await wrapper.find('[data-test="field-provider"]').setValue('recaptcha')
    await wrapper.find('form').trigger('submit')
    await flush()

    expect(wrapper.find('[data-test="bot-protection-error"]').text()).toContain('site key')
    expect(wrapper.text()).toContain('A provider needs its site key.')
    expect(wrapper.find('[data-test="bot-protection-reset"]').exists()).toBe(false)
  })

  it('organization: its own provider and keys, or the platform\'s', async () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'true')
    signIn({ tenant: { id: 't1', slug: 'acme', name: 'Acme', role: 'owner' } })
    const wrapper = await mountPage(tenant(), '/tenant/captcha')

    expect(wrapper.find('[data-test="bot-protection-tenant"]').exists()).toBe(true)
    const select = wrapper.find('[data-test="field-provider"]')
    expect(select.element.value).toBe('')
    expect(select.text()).toContain('The platform\'s protection (hCaptcha)')
    expect(select.findAll('option').map(o => o.element.value)).toEqual(['', 'turnstile', 'recaptcha', 'hcaptcha'])
    // the forms and the fallback are the platform's
    expect(wrapper.find('[data-tour="bot-protection-forms"]').exists()).toBe(false)
    expect(wrapper.find('[data-tour="bot-protection-fallback"]').exists()).toBe(false)

    await select.setValue('turnstile')
    await wrapper.find('[data-test="field-site_key"]').setValue('acme-site')
    await wrapper.find('[data-test="field-secret"]').setValue('acme-secret')
    await wrapper.find('form').trigger('submit')
    await flush()
    expect(lastPut()).toEqual(['/tenant/captcha', { provider: 'turnstile', site_key: 'acme-site', secret: 'acme-secret' }])

    // back to the platform's protection
    await wrapper.find('[data-test="field-provider"]').setValue('')
    await wrapper.find('form').trigger('submit')
    await flush()
    expect(lastPut()[1]).toEqual({ provider: null })
  })

  it('platform: a wrong configuration shows under the keys and in the status', async () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'false')
    signIn({ admin: true })
    const wrapper = await mountPage(platform({
      secret_set: false,
      problem: { reason: 'secret_unreadable', at: '2026-11-03T10:00:00+00:00' },
    }), '/admin/captcha')

    const problem = wrapper.find('[data-test="bot-protection-problem"]')
    expect(problem.attributes('role')).toBe('alert')
    expect(problem.attributes('data-problem')).toBe('secret_unreadable')
    expect(problem.text()).toContain('The stored secret key of Cloudflare Turnstile cannot be decrypted')
    expect(problem.text()).toContain('The forms run on the browser check until it is fixed.')
    // inside the keys step of the tour
    expect(wrapper.find('[data-tour="bot-protection-keys"] [data-test="bot-protection-problem"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="fallback-active"]').text()).toContain('The configuration is wrong')
  })

  it('platform: no problem, no red line', async () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'false')
    signIn({ admin: true })
    const wrapper = await mountPage(platform({ problem: null }), '/admin/captcha')

    expect(wrapper.find('[data-test="bot-protection-problem"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="fallback-active"]').text()).toContain('active')
  })

  it('organization: the provider refusing its own secret shows under its keys', async () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'true')
    signIn({ tenant: { id: 't1', slug: 'acme', name: 'Acme', role: 'owner' } })
    const wrapper = await mountPage(tenant({
      provider: 'turnstile', site_key: 'acme-site', secret_set: true,
      effective: { provider: 'turnstile', source: 'tenant', forms: ALL },
      problem: { reason: 'secret_rejected', at: null },
    }), '/tenant/captcha')

    const problem = wrapper.find('[data-tour="bot-protection-keys"] [data-test="bot-protection-problem"]')
    expect(problem.exists()).toBe(true)
    expect(problem.text()).toContain('Cloudflare Turnstile refuses the secret key')
    expect(problem.text()).not.toContain('Last:')
  })
})
