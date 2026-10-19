import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import coreEn from '@/locales/en'
import tenancyEn from '@/modules/tenancy/locales/en'
import ImpersonationBar from '@/modules/tenancy/components/ImpersonationBar.vue'
import { useTenantStore } from '@/modules/tenancy/stores/tenant'
import { tenantsApi } from '@/modules/tenancy/api/tenants'

vi.mock('@/api/client', () => ({ default: { get: vi.fn(), post: vi.fn() }, clearEtagCache: vi.fn() }))
vi.mock('@/modules/tenancy/api/tenants', () => ({ tenantsApi: { impersonate: vi.fn(), stopImpersonation: vi.fn() } }))

const mounted = []
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

function mountBar(activeTenant) {
  setActivePinia(createPinia())
  const store = useTenantStore()
  store.activeTenant = activeTenant
  const i18n = createI18n({
    legacy: false, locale: 'en', fallbackLocale: 'en', missingWarn: false, fallbackWarn: false,
    messages: { en: { ...coreEn, ...tenancyEn } },
  })
  const w = mount(ImpersonationBar, { global: { plugins: [i18n] } })
  mounted.push(w)
  return { w, store }
}

describe('ImpersonationBar', () => {
  const location = window.location
  beforeEach(() => {
    localStorage.clear()
    vi.clearAllMocks()
    delete window.location
    window.location = { pathname: '/settings', reload: vi.fn(), assign: vi.fn() }
  })
  afterEach(() => { window.location = location })

  it('is hidden outside an impersonation', () => {
    expect(mountBar(null).w.find('[data-testid="impersonation-bar"]').exists()).toBe(false)
    expect(mountBar({ id: 't-acme', name: 'Acme', impersonating: false }).w.find('[data-testid="impersonation-bar"]').exists()).toBe(false)
  })

  it('names the organization and stops back to the platform', async () => {
    tenantsApi.stopImpersonation.mockResolvedValue({ data: { tenant_id: 't-acme' } })
    const { w, store } = mountBar({ id: 't-acme', name: 'Acme', slug: 'acme', impersonating: true })

    const bar = w.find('[data-testid="impersonation-bar"]')
    expect(bar.text()).toContain('You are viewing Acme as platform administrator')

    await bar.find('button').trigger('click')
    await flushPromises()

    expect(tenantsApi.stopImpersonation).toHaveBeenCalledWith('t-acme')
    expect(store.activeTenant).toBeNull()
    expect(window.location.reload).toHaveBeenCalled()
  })

  it('leaves an organization page for the project list', async () => {
    tenantsApi.stopImpersonation.mockRejectedValue(new Error('offline'))
    window.location.pathname = '/projects/p-1'
    const { w, store } = mountBar({ id: 't-acme', name: 'Acme', slug: 'acme', impersonating: true })

    await w.find('[data-testid="impersonation-bar"] button').trigger('click')
    await flushPromises()

    expect(store.activeTenant).toBeNull()
    expect(window.location.assign).toHaveBeenCalledWith('/projects')
  })
})
