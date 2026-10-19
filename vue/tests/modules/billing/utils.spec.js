import { beforeEach, describe, expect, it, vi } from 'vitest'
import client from '@/api/client'
import { billingApi } from '@/modules/billing/api/billing'
import {
  PRICE_FIELDS, apiMessage, billingContext, currentPeriod, formatDate, formatDateTime, formatMoney, formatNumber,
  formatPeriod, formatUnitPrice, gb, invalidPriceFields, priceForm, pricePayload, recentPeriods, today, vcpu,
} from '@/modules/billing/utils/billing'
import { PRICES, makePinia, signIn } from './helpers'

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(() => Promise.resolve({ data: {} })), put: vi.fn(() => Promise.resolve({ data: {} })), delete: vi.fn(() => Promise.resolve({ data: {} })) },
}))

describe('billing utils', () => {
  beforeEach(() => {
    localStorage.clear()
    makePinia()
    vi.unstubAllEnvs()
  })

  it('names the organization the console works in', () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'false')
    expect(billingContext()).toBe('install')
    vi.stubEnv('VITE_TENANCY_ENABLED', 'true')
    signIn({ admin: true })
    expect(billingContext()).toBe('platform')
    signIn({ tenant: { id: 't1', slug: 'acme' } })
    expect(billingContext()).toBe('tenant')
  })

  it('builds complete price versions: every field a number of 0 or more', () => {
    const form = priceForm(PRICES)
    expect(form.effective_from).toBe(today())
    expect(form.cpu_vcpu_hour).toBe('0.04')
    expect(invalidPriceFields(form)).toEqual([])

    form.region_id = 'r1'
    form.cpu_vcpu_hour = '0.05'
    const body = pricePayload(form)
    expect(body).toMatchObject({ region_id: 'r1', cpu_vcpu_hour: 0.05, gpu_month: 700 })
    expect(Object.keys(body)).toEqual(['effective_from', 'region_id', ...PRICE_FIELDS])

    form.memory_gb_hour = ''
    form.gpu_hour = '-1'
    form.kv_gb_hour = 'abc'
    expect(invalidPriceFields(form)).toEqual(['memory_gb_hour', 'gpu_hour', 'kv_gb_hour'])
    // a fresh form without prices is invalid (nothing is ever left unpriced)
    expect(invalidPriceFields(priceForm())).toEqual(PRICE_FIELDS)
  })

  it('computes periods and dates', () => {
    const d = new Date(2026, 0, 15)
    expect(today(d)).toBe('2026-01-15')
    expect(currentPeriod(d)).toBe('2026-01')
    expect(recentPeriods(3, d)).toEqual(['2026-01', '2025-12', '2025-11'])
    expect(formatPeriod('2026-10', 'en')).toBe('October 2026')
    expect(formatPeriod('nope')).toBe('nope')
    expect(formatPeriod(null)).toBe('—')
    expect(formatDate(null)).toBe('—')
    expect(formatDate('garbage')).toBe('garbage')
    expect(formatDate('2026-10-05T00:00:00Z', 'en')).toMatch(/2026/)
    expect(formatDateTime(null)).toBe('—')
    expect(formatDateTime('x')).toBe('x')
    expect(formatDateTime('2026-10-05T10:00:00Z', 'tr')).toMatch(/2026/)
  })

  it('formats money, unit prices and resources', () => {
    expect(formatMoney(12.5, 'USD', 'en')).toBe('$12.50')
    expect(formatMoney(null)).toBe('—')
    expect(formatMoney(3, 'NOPE')).toBe('3.00 NOPE')
    expect(formatUnitPrice(0.000123, 'USD', 'en')).toBe('$0.000123')
    expect(formatUnitPrice(undefined)).toBe('—')
    expect(formatUnitPrice(1, 'BADX')).toBe('1 BADX')
    expect(formatNumber(1234.567, 'en')).toBe('1,234.57')
    expect(formatNumber('')).toBe('—')
    expect(vcpu(2500)).toBe(2.5)
    expect(gb(3072)).toBe(3)
    expect(vcpu(null)).toBe(0)
  })

  it('reads the API message of a failed request', () => {
    expect(apiMessage({ response: { data: { errors: { cpu_m: ['Too small.'] }, message: 'Invalid.' } } }, 'x')).toBe('Too small.')
    expect(apiMessage({ response: { data: { message: 'Refused.' } } }, 'x')).toBe('Refused.')
    expect(apiMessage(new Error('net'), 'fallback')).toBe('fallback')
  })
})

describe('billing api client', () => {
  beforeEach(() => { vi.clearAllMocks() })

  it('calls the documented endpoints', async () => {
    await billingApi.settings()
    await billingApi.updateSettings({ webhook_url: null })
    await billingApi.events({ order: 'desc' })
    await billingApi.testEvent()
    await billingApi.redeliver('e1')
    await billingApi.prices()
    await billingApi.createPrice({ a: 1 })
    await billingApi.deletePrice('pr1')
    await billingApi.regions()
    await billingApi.setRegionType('r1', 'fixed')
    await billingApi.markups()
    await billingApi.createMarkup({ percent: 5 })
    await billingApi.deleteMarkup('m1')
    await billingApi.offers()
    await billingApi.acceptOffer('r2', { markup_percent: 10 })
    await billingApi.bills({ period: '2026-09' })
    await billingApi.bill('b1')
    await billingApi.setPaymentUrl('b1', 'https://pay')
    await billingApi.setPaid('b1', { paid: true })
    await billingApi.usage({ project: 'p1' })
    await billingApi.rollup({ period: '2026-09' })
    await billingApi.myBills()
    await billingApi.fixedRequests({ status: 'pending' })
    await billingApi.approve('fr1')
    await billingApi.approve('fr1', 'ok')
    await billingApi.reject('fr1', 'full')
    await billingApi.platformBills()
    await billingApi.platformBill('b2')
    await billingApi.tenantBills({ tenant: 'acme' })
    await billingApi.tenantBill('b3')
    await billingApi.setTenantPaymentUrl('b3', null)
    await billingApi.setTenantPaid('b3', { paid: false })
    await billingApi.project('p1')
    await billingApi.projectBill('p1', 'b1')
    await billingApi.quote('p1', { cpu_m: 1000 })
    await billingApi.requestFixed('p1', { cpu_m: 1000 })
    await billingApi.cancelRequest('p1', 'fr1')

    expect(client.get).toHaveBeenCalledWith('/billing/settings')
    expect(client.put).toHaveBeenCalledWith('/billing/regions/r1', { billing_type: 'fixed' })
    expect(client.post).toHaveBeenCalledWith('/billing/region-offers/r2/accept', { markup_percent: 10 })
    expect(client.put).toHaveBeenCalledWith('/billing/bills/b1/payment-url', { payment_url: 'https://pay' })
    expect(client.put).toHaveBeenCalledWith('/billing/tenant-bills/b3/payment-url', { payment_url: null })
    expect(client.post).toHaveBeenCalledWith('/billing/fixed-requests/fr1/approve', {})
    expect(client.post).toHaveBeenCalledWith('/billing/fixed-requests/fr1/approve', { note: 'ok' })
    expect(client.post).toHaveBeenCalledWith('/billing/fixed-requests/fr1/reject', { reason: 'full' })
    expect(client.get).toHaveBeenCalledWith('/projects/p1/billing/quote', { params: { cpu_m: 1000 } })
    expect(client.post).toHaveBeenCalledWith('/projects/p1/billing/fixed-requests/fr1/cancel')
    expect(client.delete).toHaveBeenCalledWith('/billing/prices/pr1')
    expect(client.get).toHaveBeenCalledWith('/billing/me', { params: {} })
  })
})
