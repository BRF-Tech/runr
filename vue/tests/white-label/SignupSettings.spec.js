import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, RouterLinkStub } from '@vue/test-utils'
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
  vi.mocked(client.put).mockImplementation((url, body) => ok({ ...data, ...body }))
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

  it('sends the signup settings without CAPTCHA fields', async () => {
    const wrapper = await mountSettings()
    await wrapper.find('form').trigger('submit')
    await flush()

    const body = lastPut()
    expect(vi.mocked(client.put).mock.calls.at(-1)[0]).toBe('/admin/signup-settings')
    expect(Object.keys(body).filter(k => k.startsWith('captcha'))).toEqual([])
    expect(body.allowed_domains).toEqual(['acme.test'])
    // single-tenant: no tenant fields
    expect(body).not.toHaveProperty('tenant_auto_create')
  })

  it('points at the bot protection for the CAPTCHA of the sign-up form', async () => {
    const wrapper = await mountSettings()
    expect(wrapper.find('[data-test="signup-captcha-moved"]').text()).toContain('bot protection')
    expect(wrapper.find('[data-test="field-captcha_secret"]').exists()).toBe(false)
    expect(wrapper.getComponent(RouterLinkStub).props('to')).toEqual({ path: '/settings', query: { tab: 'bot-protection' } })
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
