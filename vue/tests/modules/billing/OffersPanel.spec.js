import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import client from '@/api/client'
import OffersPanel from '@/modules/billing/components/OffersPanel.vue'
import { fail, flush, globalOptions, grant, ok } from './helpers'

vi.mock('@/api/client', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const find = (w, id) => w.find(`[data-test="${id}"]`)

const offers = () => ({
  default_markup_percent: 10,
  data: [
    { region: { id: 'r-new', name: 'ank-1' }, status: 'pending', offered_at: '2026-10-18T08:00:00Z', accepted_at: null, billing_type: 'payg', currency: 'USD', markup_percent: null,
      prices: { current: { cpu_vcpu_hour: 0.04, memory_gb_hour: 0.005, gpu_hour: 1.2 }, scheduled: [] } },
    { region: { id: 'r-old', name: 'ist-1' }, status: 'active', offered_at: '2026-01-01T00:00:00Z', accepted_at: '2026-01-02T00:00:00Z', billing_type: 'fixed', currency: 'USD', markup_percent: 20,
      prices: { current: { cpu_vcpu_month: 25, memory_gb_month: 3, gpu_month: 700 }, scheduled: [] } },
  ],
})
const markups = () => ({
  default: { current: { id: 'm-d', region_id: null, percent: 10, effective_from: '2026-01-01' }, scheduled: [{ id: 'm-ds', region_id: null, percent: 12, effective_from: '2099-01-01' }], history: [] },
  regions: [{ region_id: 'r-old', region_name: 'ist-1', current: { id: 'm-r', percent: 20 }, scheduled: [{ id: 'm-rs', region_id: 'r-old', percent: 25, effective_from: '2099-02-01' }] }],
})

function routes() {
  vi.mocked(client.get).mockImplementation((url) => {
    if (url === '/billing/region-offers') return ok(offers())
    if (url === '/billing/markups') return ok(markups())
    return ok({})
  })
}

async function mountPanel(permissions = ['billing.view', 'billing.manage']) {
  const options = globalOptions()
  grant(permissions, { edition: 'multi' })
  const w = mount(OffersPanel, { global: options })
  await flush()
  return w
}

describe('OffersPanel (tenant)', () => {
  beforeEach(() => {
    vi.mocked(client.get).mockReset()
    vi.mocked(client.post).mockReset()
    vi.mocked(client.delete).mockReset()
    routes()
  })

  it('shows a pending offer with the platform price and the price with the default markup', async () => {
    const w = await mountPanel()
    expect(find(w, 'offer-r-new').text()).toContain('ank-1')
    expect(find(w, 'offer-r-new').text()).toContain('Pay as you go')
    expect(find(w, 'offer-markup-r-new').element.value).toBe('10')
    expect(find(w, 'offer-price-r-new-cpu_vcpu_hour').text()).toBe('$0.044')
    await find(w, 'offer-markup-r-new').setValue('50')
    expect(find(w, 'offer-price-r-new-cpu_vcpu_hour').text()).toBe('$0.06')
    expect(find(w, 'offer-active-r-old').text()).toContain('Markup 20 %')
    expect(find(w, 'offer-active-r-old').text()).toContain('25 % from 2099-02-01')
    expect(find(w, 'offers-default-markup').text()).toContain('12 % from 2099-01-01')
  })

  it('accepts an offer with the chosen markup; an invalid markup is refused', async () => {
    vi.mocked(client.post).mockReturnValue(ok({ data: { status: 'active' } }))
    const w = await mountPanel()
    await find(w, 'offer-markup-r-new').setValue('15')
    await find(w, 'offer-accept-r-new').trigger('click')
    await flush()
    expect(client.post).toHaveBeenCalledWith('/billing/region-offers/r-new/accept', { markup_percent: 15 })

    vi.mocked(client.post).mockClear()
    await find(w, 'offer-markup-r-new').setValue('5000')
    await find(w, 'offer-accept-r-new').trigger('click')
    await flush()
    expect(client.post).not.toHaveBeenCalled()
  })

  it('schedules markup changes for a region and the default, and removes a scheduled one', async () => {
    vi.mocked(client.post).mockReturnValue(ok({ data: { id: 'm-new' } }))
    vi.mocked(client.delete).mockReturnValue(ok(null, 204))
    const w = await mountPanel()

    await find(w, 'region-markup-percent-r-old').setValue('30')
    await find(w, 'region-markup-date-r-old').setValue('2099-05-01')
    await find(w, 'region-markup-save-r-old').trigger('click')
    await flush()
    expect(client.post).toHaveBeenCalledWith('/billing/markups', { region_id: 'r-old', percent: 30, effective_from: '2099-05-01' })

    await find(w, 'default-markup-percent').setValue('11')
    await find(w, 'default-markup-date').setValue('2099-06-01')
    await find(w, 'default-markup-save').trigger('click')
    await flush()
    expect(client.post).toHaveBeenLastCalledWith('/billing/markups', { region_id: null, percent: 11, effective_from: '2099-06-01' })

    const remove = find(w, 'offers-default-markup').findAll('button').find(b => b.text() === 'Remove')
    await remove.trigger('click')
    await flush()
    expect(client.delete).toHaveBeenCalledWith('/billing/markups/m-ds')
  })

  it('is read-only without billing.manage and shows errors', async () => {
    const w = await mountPanel(['billing.view'])
    expect(find(w, 'offer-accept-r-new').exists()).toBe(false)
    expect(find(w, 'default-markup-save').exists()).toBe(false)

    vi.mocked(client.get).mockImplementation(() => fail(404, { message: 'Only inside a tenant.' }))
    await w.vm.load()
    await flush()
    expect(w.text()).toContain('Only inside a tenant.')
  })

  it('without offers', async () => {
    vi.mocked(client.get).mockImplementation((url) => ok(url === '/billing/markups' ? { default: { current: null, scheduled: [], history: [] }, regions: [] } : { data: [] }))
    const w = await mountPanel()
    expect(find(w, 'offers-none-pending').exists()).toBe(true)
    expect(find(w, 'offers-default-markup-value').text()).toBe('0 %')
  })
})
