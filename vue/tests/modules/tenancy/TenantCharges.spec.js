import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import client from '@/api/client'
import { useToast } from '@/composables/useToast'
import coreEn from '@/locales/en'
import tenancyEn from '@/modules/tenancy/locales/en'
import tenancyTr from '@/modules/tenancy/locales/tr'
import whiteLabelEn from '@/modules/white-label/locales/en'
import TenantCharges from '@/modules/tenancy/components/TenantCharges.vue'
import TenantOwnBilling from '@/modules/tenancy/components/TenantOwnBilling.vue'

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))
const confirm = vi.fn(() => Promise.resolve(true))
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm }) }))

const ok = (data) => Promise.resolve({ data, status: 200 })
const fail = (status, data = {}) => Promise.reject(Object.assign(new Error(`HTTP ${status}`), { response: { status, data } }))
const flush = async () => { for (let i = 0; i < 5; i++) await new Promise(r => setTimeout(r, 0)) }

function mountOptions() {
  setActivePinia(createPinia())
  const i18n = createI18n({
    legacy: false, locale: 'en', fallbackLocale: 'en', missingWarn: false, fallbackWarn: false,
    messages: { en: { ...coreEn, ...whiteLabelEn, ...tenancyEn } },
  })
  return { plugins: [i18n] }
}

const line = (description, total, quantity = 1, unit = 'item') => ({ description, quantity, unit, unit_price: total, total, metadata: {} })

const closed = {
  id: 'c-closed', tenant_id: 't1', kind: 'usage', description: 'Usage 2026-09', period: '2026-09',
  period_start: '2026-09-01', period_end: '2026-09-30', currency: 'USD', status: 'closed', final: true, amount: 0,
  line_items: [line('Project: api (HTTP, Scale-to-Zero)', 80, 15000, 'sessions')], total: 80, credit_applied: 30, amount_due: 50,
  external_ref: 'INV-2026-0042', closed_at: '2026-10-01T02:30:00Z', voided_at: null,
}
const openListed = {
  id: 'c-open', tenant_id: 't1', kind: 'plan', description: 'Plan Pro (month)', period: '2026-10',
  period_start: '2026-10-01', period_end: '2026-10-31', currency: 'USD', status: 'open', final: false, amount: 25,
  line_items: null, total: null, credit_applied: 0, amount_due: null, external_ref: null, closed_at: null, voided_at: null,
}
const openEstimated = { ...openListed, line_items: [line('Plan Pro (month)', 25, 1, 'period')], total: 25, amount_due: 25 }

describe('TenantCharges', () => {
  beforeEach(() => {
    for (const fn of Object.values(client)) vi.mocked(fn).mockReset()
    confirm.mockClear()
    useToast().toasts.value = []
  })

  describe('admin', () => {
    function routes({ open = [openEstimated] } = {}) {
      vi.mocked(client.get).mockImplementation((url) => {
        if (url === '/billing/charges') return ok({ data: [openListed, closed], meta: { total: 2 } })
        if (url === '/billing/tenants/t1') return ok({ credit_balances: { USD: 20, EUR: 0 }, open_charges: open })
        if (url === '/billing/purchases') return ok({ data: [
          { id: 'p1', kind: 'credit', external_ref: 'order-1', description: 'Credit pack', amount: 50, currency: 'USD', plan: null, purchased_at: '2026-09-10T00:00:00Z', voided_at: null },
          { id: 'p2', kind: 'plan', external_ref: 'shop-2', description: null, amount: 30, currency: null, plan: { name: 'Pro' }, purchased_at: '2026-09-12T00:00:00Z', voided_at: '2026-09-13T00:00:00Z' },
        ] })
        if (url === '/billing/charges/c-open') return ok({ charge: openEstimated })
        return ok({})
      })
    }

    async function mountAdmin() {
      const wrapper = mount(TenantCharges, { props: { tenantId: 't1', mode: 'admin' }, global: mountOptions() })
      await flush()
      return wrapper
    }

    it('lists the tenant charges with open amounts as of now, credit and purchases', async () => {
      routes()
      const wrapper = await mountAdmin()

      expect(client.get).toHaveBeenCalledWith('/billing/charges', { params: { tenant: 't1', per_page: 100 } })
      expect(wrapper.find('[data-test="charge-status-c-open"]').text()).toBe('Open')
      expect(wrapper.find('[data-test="charge-due-c-open"]').text()).toContain('25.00')
      expect(wrapper.find('[data-test="charge-status-c-closed"]').text()).toBe('Final')
      expect(wrapper.find('[data-test="charge-due-c-closed"]').text()).toContain('50.00')
      expect(wrapper.find('[data-test="credit-balances"]').text()).toContain('$20.00')
      expect(wrapper.find('[data-test="credit-balances"]').text()).not.toContain('€')
      expect(wrapper.find('[data-test="purchase-p1"]').text()).toContain('Credit pack')
      expect(wrapper.find('[data-test="purchase-p2"]').text()).toContain('Plan Pro')
      expect(wrapper.find('[data-test="purchase-p2"]').text()).toContain('30.00')
      expect(wrapper.find('[data-test="purchase-p2"]').text()).toContain('Reversed')
    })

    it('expands a closed charge with its line items, reference and credit', async () => {
      routes()
      const wrapper = await mountAdmin()

      await wrapper.find('[data-test="charge-c-closed"] > div').trigger('click')
      expect(wrapper.find('[data-test="charge-lines-c-closed"]').text()).toContain('Project: api (HTTP, Scale-to-Zero)')
      expect(wrapper.find('[data-test="charge-ref-c-closed"]').text()).toContain('INV-2026-0042')
      expect(wrapper.find('[data-test="charge-c-closed"]').text()).toContain('Credit applied')
      await wrapper.find('[data-test="charge-c-closed"] > div').trigger('click')
      expect(wrapper.find('[data-test="charge-lines-c-closed"]').exists()).toBe(false)
    })

    it('fetches an open charge without amounts when it is expanded', async () => {
      routes({ open: [] })
      const wrapper = await mountAdmin()
      expect(wrapper.find('[data-test="charge-due-c-open"]').text()).toBe('—')

      await wrapper.find('[data-test="charge-c-open"] > div').trigger('click')
      await flush()
      expect(client.get).toHaveBeenCalledWith('/billing/charges/c-open')
      expect(wrapper.find('[data-test="charge-lines-c-open"]').text()).toContain('Plan Pro (month)')
      expect(wrapper.text()).toContain('final when the month closes')
    })

    it('voids a charge after confirming', async () => {
      routes()
      vi.mocked(client.post).mockReturnValue(ok({ charge: { ...closed, status: 'void', amount_due: 0, credit_applied: 0 } }))
      const wrapper = await mountAdmin()

      await wrapper.find('[data-test="charge-c-closed"] > div').trigger('click')
      await wrapper.find('[data-test="charge-void-c-closed"]').trigger('click')
      await flush()

      expect(confirm).toHaveBeenCalled()
      expect(client.post).toHaveBeenCalledWith('/billing/charges/c-closed/void', {})
      expect(useToast().toasts.value.map(t => t.message)).toContain('Charge voided.')
    })

    it('adds a one-off charge', async () => {
      routes()
      vi.mocked(client.post).mockReturnValue(ok({ charge: openEstimated }))
      const wrapper = await mountAdmin()

      await wrapper.find('[data-test="charge-add"]').trigger('click')
      await wrapper.find('[data-test="charge-description"]').setValue('Onboarding workshop')
      await wrapper.find('[data-test="charge-amount"]').setValue('450')
      await wrapper.find('[data-test="charge-currency"]').setValue('eur')
      await wrapper.find('[data-test="charge-date"]').setValue('2026-10-14')
      await wrapper.find('[data-test="charge-add-form"]').trigger('submit')
      await flush()

      expect(client.post).toHaveBeenCalledWith('/billing/tenants/t1/charges', { description: 'Onboarding workshop', amount: 450, currency: 'EUR', period_start: '2026-10-14' })
      expect(wrapper.find('[data-test="charge-add-form"]').exists()).toBe(false)
    })

    it('shows a refused one-off charge and a load error', async () => {
      routes()
      vi.mocked(client.post).mockImplementation(() => fail(422, { errors: { amount: ['The amount field must be greater than 0.'] } }))
      const wrapper = await mountAdmin()
      await wrapper.find('[data-test="charge-add"]').trigger('click')
      await wrapper.find('[data-test="charge-description"]').setValue('x')
      await wrapper.find('[data-test="charge-amount"]').setValue('1')
      await wrapper.find('[data-test="charge-add-form"]').trigger('submit')
      await flush()
      expect(useToast().toasts.value.map(t => t.message)).toContain('The amount field must be greater than 0.')

      vi.mocked(client.get).mockImplementation(() => fail(500, { message: 'Server error' }))
      await wrapper.vm.load()
      await flush()
      expect(wrapper.text()).toContain('Server error')
    })
  })

  describe('tenant', () => {
    it('shows the own charges, credit, purchases and the store link', async () => {
      vi.mocked(client.get).mockReturnValue(ok({
        charges: [openEstimated, { ...closed, credit_applied: 0, amount_due: 80, line_items: [] }],
        credit_balances: { USD: 5 },
        purchases: [{ id: 'p1', kind: 'addon', external_ref: 'addon-7', description: '+10 projects', amount: null, currency: null, plan: null, purchased_at: '2026-09-10T00:00:00Z', voided_at: null }],
        store_url: 'https://shop.example.com',
      }))
      const wrapper = mount(TenantOwnBilling, { global: mountOptions() })
      await flush()

      expect(client.get).toHaveBeenCalledWith('/tenant/billing')
      expect(wrapper.text()).toContain('Charges')
      expect(wrapper.find('[data-test="charge-due-c-closed"]').text()).toContain('80.00')
      expect(wrapper.find('[data-test="purchase-p1"]').text()).toContain('+10 projects')
      expect(wrapper.find('[data-test="charges-store-link"]').attributes('href')).toBe('https://shop.example.com')
      // no administrator actions for the tenant
      expect(wrapper.find('[data-test="charge-add"]').exists()).toBe(false)
      await wrapper.find('[data-test="charge-c-closed"] > div').trigger('click')
      expect(wrapper.find('[data-test="charge-void-c-closed"]').exists()).toBe(false)
      expect(wrapper.text()).toContain('Nothing charged in this period.')
    })

    it('an organization without charges', async () => {
      vi.mocked(client.get).mockReturnValue(ok({ charges: [], credit_balances: {}, purchases: [], store_url: null }))
      const wrapper = mount(TenantCharges, { props: { mode: 'tenant' }, global: mountOptions() })
      await flush()
      expect(wrapper.find('[data-test="charges-empty"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="charges-store-link"]').exists()).toBe(false)
    })
  })

  it('has every charge string in Turkish too', () => {
    const missing = Object.keys(tenancyEn.charge).filter(k => !(k in tenancyTr.charge))
    expect(missing).toEqual([])
  })
})
