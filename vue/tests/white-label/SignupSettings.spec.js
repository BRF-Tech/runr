import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import client from '@/api/client'
import SignupSettings from '@/modules/white-label/components/SignupSettings.vue'
import { parseDomains } from '@/modules/white-label/utils/signup'
import { flush, globalOptions, ok } from './helpers'

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const settings = (over = {}) => ({
  registration_enabled: true,
  email_verification: true,
  invite_only: false,
  allowed_domains: ['acme.test'],
  captcha_provider: 'turnstile',
  captcha_site_key: '0x4AAA',
  captcha_secret_set: true,
  terms_url: 'https://acme.test/terms',
  privacy_url: null,
  tenant_auto_create: true,
  default_cpu_quota_m: 2000,
  default_memory_quota_mb: 4096,
  self_service_region_ids: [],
  verification_ttl_hours: 48,
  multi_tenant: false,
  ...over,
})

async function mountSettings(data = settings()) {
  vi.mocked(client.get).mockImplementation((url) => url === '/admin/signup-settings' ? ok(data) : ok({}))
  vi.mocked(client.put).mockImplementation((url, body) => ok({ ...data, ...body, captcha_secret_set: body.captcha_secret_clear ? false : data.captcha_secret_set }))
  const wrapper = mount(SignupSettings, { global: globalOptions() })
  await flush()
  return wrapper
}

const lastPut = () => vi.mocked(client.put).mock.calls.at(-1)[1]

describe('SignupSettings', () => {
  beforeEach(() => {
    localStorage.clear()
    for (const fn of Object.values(client)) vi.mocked(fn).mockReset()
  })

  it('parses allowed domains from free text', () => {
    expect(parseDomains('Acme.test\n@corp.example, acme.test;  other.io ')).toEqual(['acme.test', 'corp.example', 'other.io'])
    expect(parseDomains('')).toEqual([])
  })

  it('never sends the stored secret back and keeps it by default', async () => {
    const wrapper = await mountSettings()
    expect(wrapper.find('[data-test="secret-state"]').text()).toContain('Stored')
    expect(wrapper.find('[data-test="field-captcha_secret"]').element.value).toBe('')

    await wrapper.find('form').trigger('submit')
    await flush()

    const body = lastPut()
    expect(vi.mocked(client.put).mock.calls.at(-1)[0]).toBe('/admin/signup-settings')
    expect(body).not.toHaveProperty('captcha_secret')
    expect(body).not.toHaveProperty('captcha_secret_clear')
    expect(body.allowed_domains).toEqual(['acme.test'])
    expect(body.captcha_site_key).toBe('0x4AAA')
    // single-tenant: no tenant fields
    expect(body).not.toHaveProperty('tenant_auto_create')
  })

  it('replaces the secret with a typed value', async () => {
    const wrapper = await mountSettings()
    await wrapper.find('[data-test="field-captcha_secret"]').setValue('  new-secret ')
    await wrapper.find('form').trigger('submit')
    await flush()

    expect(lastPut().captcha_secret).toBe('new-secret')
    // the input is emptied after saving: the secret is not kept in the page
    expect(wrapper.find('[data-test="field-captcha_secret"]').element.value).toBe('')
  })

  it('clears the secret with captcha_secret_clear', async () => {
    const wrapper = await mountSettings()
    await wrapper.find('[data-test="clear-captcha_secret"]').trigger('click')
    expect(wrapper.find('[data-test="secret-state"]').text()).toContain('Not set')
    await wrapper.find('form').trigger('submit')
    await flush()

    expect(lastPut().captcha_secret_clear).toBe(true)
    expect(lastPut()).not.toHaveProperty('captcha_secret')
  })

  it('multi-tenant: sends tenant creation, quota and regions', async () => {
    const wrapper = await mountSettings(settings({ multi_tenant: true, default_cpu_quota_m: null }))
    expect(wrapper.find('[data-test="signup-tenant-section"]').exists()).toBe(true)
    await wrapper.find('form').trigger('submit')
    await flush()

    const body = lastPut()
    expect(body.tenant_auto_create).toBe(true)
    expect(body.default_cpu_quota_m).toBeNull()
    expect(body.default_memory_quota_mb).toBe(4096)
    expect(body.self_service_region_ids).toEqual([])
  })
})
