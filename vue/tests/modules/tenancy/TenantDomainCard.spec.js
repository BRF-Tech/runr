import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import coreEn from '@/locales/en'
import tenancyEn from '@/modules/tenancy/locales/en'
import TenantDomainCard from '@/modules/tenancy/components/TenantDomainCard.vue'
import { settingsApi } from '@/api/settings'
import { useSettingsStore } from '@/stores/settings'

vi.mock('@/api/settings', () => ({ settingsApi: { get: vi.fn(), update: vi.fn() } }))

const mounted = []
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

function mountCard(settingsData) {
  setActivePinia(createPinia())
  const settings = useSettingsStore()
  settings.update(settingsData)
  const i18n = createI18n({
    legacy: false, locale: 'en', fallbackLocale: 'en', missingWarn: false, fallbackWarn: false,
    messages: { en: { ...coreEn, ...tenancyEn } },
  })
  const w = mount(TenantDomainCard, { global: { plugins: [i18n] } })
  mounted.push(w)
  return { w, settings }
}

describe('Administration → Regions & domain', () => {
  beforeEach(() => vi.clearAllMocks())

  it('is not shown outside an organization', () => {
    const { w } = mountCard({ base_domain: 'platform.example' })
    expect(w.find('[data-testid="tenant-domain-card"]').exists()).toBe(false)
  })

  it('shows the platform default until the organization has its own domain', () => {
    const { w } = mountCard({ base_domain: 'platform.example', tenant_base_domain: null, platform_base_domain: 'platform.example' })

    const input = w.find('[data-testid="tenant-domain-input"]')
    expect(input.element.value).toBe('')
    expect(input.attributes('placeholder')).toBe('platform.example')
    expect(w.text()).toContain('Platform domain: platform.example')
    expect(w.text()).toContain('https://function.region.platform.example')
    expect(w.find('[data-testid="tenant-domain-clear"]').exists()).toBe(false)
    expect(w.find('[data-testid="tenant-domain-save"]').attributes('disabled')).toBeDefined()
  })

  it('saves the organization domain and keeps the answer in the settings store', async () => {
    settingsApi.update.mockResolvedValue({ data: { base_domain: 'apps.acme.example', tenant_base_domain: 'apps.acme.example', platform_base_domain: 'platform.example' } })
    const { w, settings } = mountCard({ base_domain: 'platform.example', tenant_base_domain: null, platform_base_domain: 'platform.example' })

    await w.find('[data-testid="tenant-domain-input"]').setValue(' apps.acme.example ')
    await w.find('[data-testid="tenant-domain-save"]').trigger('click')
    await flushPromises()

    expect(settingsApi.update).toHaveBeenCalledWith({ base_domain: 'apps.acme.example' })
    expect(settings.data.tenant_base_domain).toBe('apps.acme.example')
    expect(w.text()).toContain('https://function.region.apps.acme.example')
    expect(w.find('[data-testid="tenant-domain-clear"]').exists()).toBe(true)
  })

  it('goes back to the platform domain', async () => {
    settingsApi.update.mockResolvedValue({ data: { base_domain: 'platform.example', tenant_base_domain: null, platform_base_domain: 'platform.example' } })
    const { w } = mountCard({ base_domain: 'acme.example', tenant_base_domain: 'acme.example', platform_base_domain: 'platform.example' })

    await w.find('[data-testid="tenant-domain-clear"]').trigger('click')
    await flushPromises()

    expect(settingsApi.update).toHaveBeenCalledWith({ base_domain: null })
    expect(w.find('[data-testid="tenant-domain-input"]').element.value).toBe('')
  })

  it('shows a domain move in progress and completes it on request', async () => {
    const moving = { from: 'old.acme.example', to: 'new.acme.example', started_at: '2026-10-19T08:00:00Z', regions: ['eu-1', 'us-1'] }
    settingsApi.update.mockResolvedValue({ data: { base_domain: 'new.acme.example', tenant_base_domain: 'new.acme.example', platform_base_domain: 'platform.example', tenant_domain_migration: null } })
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const { w, settings } = mountCard({ base_domain: 'new.acme.example', tenant_base_domain: 'new.acme.example', platform_base_domain: 'platform.example', tenant_domain_migration: moving })

    const notice = w.find('[data-testid="tenant-domain-migration"]')
    expect(notice.text()).toContain('Moving from old.acme.example to new.acme.example')
    expect(notice.text()).toContain('Waiting for DNS in: eu-1, us-1')

    await w.find('[data-testid="tenant-domain-complete"]').trigger('click')
    await flushPromises()

    expect(confirm).toHaveBeenCalledWith(expect.stringContaining('new.acme.example'))
    expect(settingsApi.update).toHaveBeenCalledWith({ complete_domain_migration: true })
    expect(settings.data.tenant_domain_migration).toBeNull()
    expect(w.find('[data-testid="tenant-domain-migration"]').exists()).toBe(false)
    confirm.mockRestore()
  })

  it('names the platform domain for a first organization domain and keeps the move when completing is cancelled or fails', async () => {
    const moving = { from: null, to: 'apps.acme.example', started_at: '2026-10-19T08:00:00Z', regions: [] }
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true)
    settingsApi.update.mockRejectedValue({ response: { status: 500, data: { message: 'The region did not answer.' } } })
    const { w } = mountCard({ base_domain: 'apps.acme.example', tenant_base_domain: 'apps.acme.example', platform_base_domain: 'platform.example', tenant_domain_migration: moving })

    expect(w.text()).toContain('Moving to apps.acme.example: functions answer on the platform domain and on apps.acme.example')
    expect(w.text()).not.toContain('Waiting for DNS in')

    await w.find('[data-testid="tenant-domain-complete"]').trigger('click')
    await flushPromises()
    expect(settingsApi.update).not.toHaveBeenCalled()

    await w.find('[data-testid="tenant-domain-complete"]').trigger('click')
    await flushPromises()
    expect(settingsApi.update).toHaveBeenCalledWith({ complete_domain_migration: true })
    expect(w.text()).toContain('The region did not answer.')
    expect(w.find('[data-testid="tenant-domain-migration"]').exists()).toBe(true)
    confirm.mockRestore()
  })

  it('shows why a domain was refused', async () => {
    settingsApi.update.mockRejectedValue({ response: { status: 422, data: { message: 'invalid', errors: { base_domain: ['The base domain field format is invalid.'] } } } })
    const { w } = mountCard({ base_domain: 'platform.example', tenant_base_domain: null, platform_base_domain: 'platform.example' })

    await w.find('[data-testid="tenant-domain-input"]').setValue('not a domain')
    await w.find('[data-testid="tenant-domain-save"]').trigger('click')
    await flushPromises()

    expect(w.text()).toContain('The base domain field format is invalid.')
  })
})
