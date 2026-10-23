import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import client from '@/api/client'
import ProjectBilling from '@/modules/billing/components/ProjectBilling.vue'
import { LINES, bill, costLimit, fail, fixedRequest, flush, globalOptions, ok } from './helpers'

vi.mock('@/api/client', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const find = (w, id) => w.find(`[data-test="${id}"]`)
const project = { id: 'p1', name: 'orders-api', service_type: 'faas' }

function info(over = {}) {
  return {
    currency: 'USD', billing_type: 'payg', region: { id: 'r1', name: 'eu-1' }, can_request: false, can_view_costs: false,
    allocation: null, pending_request: null,
    current: bill({ id: 'b-now', status: 'open', estimate: true, period: '2026-10', line_items: LINES }),
    bills: [bill({ id: 'b-9' })],
    ...over,
  }
}

async function mountTab() {
  const w = mount(ProjectBilling, { props: { project }, global: globalOptions() })
  await flush()
  return w
}

describe('ProjectBilling', () => {
  beforeEach(() => {
    vi.mocked(client.get).mockReset()
    vi.mocked(client.post).mockReset()
    vi.mocked(client.put).mockReset()
  })

  it('shows a pay-as-you-go project\'s estimate and its past bills', async () => {
    vi.mocked(client.get).mockImplementation((url) => {
      if (url === '/projects/p1/billing') return ok(info())
      if (url === '/projects/p1/billing/bills/b-9') return ok({ data: bill({ id: 'b-9', line_items: LINES }) })
      return ok({})
    })
    const w = await mountTab()
    expect(find(w, 'project-billing-type').text()).toBe('Pay as you go')
    expect(find(w, 'bill-lines-b-now').text()).toContain('vCPU hours')
    expect(find(w, 'fixed-request-form').exists()).toBe(false)

    await find(w, 'bill-toggle-b-9').trigger('click')
    await flush()
    expect(client.get).toHaveBeenCalledWith('/projects/p1/billing/bills/b-9')
    expect(find(w, 'bill-lines-b-9').exists()).toBe(true)
  })

  it('in a fixed region: no allocation yet, the request form and a pending request that can be cancelled', async () => {
    let pending = fixedRequest()
    vi.mocked(client.get).mockImplementation((url) => {
      if (url === '/projects/p1/billing') return ok(info({ billing_type: 'fixed', can_request: true, pending_request: pending, bills: [] }))
      return ok({ currency: 'USD', monthly_price: 31, breakdown: { cpu: 25, memory: 6, gpu: 0 }, markup_percent: 0 })
    })
    vi.mocked(client.post).mockImplementation(() => { pending = null; return ok({ data: fixedRequest({ status: 'cancelled' }) }) })
    const w = await mountTab()

    expect(find(w, 'project-no-allocation').exists()).toBe(true)
    expect(find(w, 'project-pending-request').text()).toContain('2 vCPU')
    expect(find(w, 'fixed-request-form').exists()).toBe(true)
    expect(find(w, 'project-no-past-bills').exists()).toBe(true)

    await find(w, 'project-cancel-request').trigger('click')
    await flush()
    expect(client.post).toHaveBeenCalledWith('/projects/p1/billing/fixed-requests/fr-1/cancel')
    expect(find(w, 'project-pending-request').exists()).toBe(false)
    w.unmount()
  })

  it('shows the approved allocation and its price', async () => {
    vi.mocked(client.get).mockImplementation((url) => (url === '/projects/p1/billing'
      ? ok(info({ billing_type: 'fixed', allocation: fixedRequest({ status: 'approved', active_from: '2026-10-10' }) }))
      : ok({})))
    const w = await mountTab()
    expect(find(w, 'project-allocation').text()).toContain('2 vCPU · 4 GB')
    expect(find(w, 'project-allocation').text()).toContain('$62.00 per month')
  })

  it('a pay-as-you-go project has a monthly cost limit; a fixed-price project shows none', async () => {
    vi.mocked(client.get).mockImplementation((url) => (url === '/projects/p1/billing' ? ok(info({ cost_limit: costLimit() })) : ok({})))
    const w = await mountTab()
    expect(find(w, 'cost-limit').exists()).toBe(true)
    expect(find(w, 'cost-limit-month-to-date').text()).toBe('This month so far: $42.00')

    // the saved limit replaces the tab's status
    vi.mocked(client.put).mockReturnValue(ok({ data: costLimit({ limit: { amount: 100, action: 'notify' }, percent: 42, state: 'ok' }) }))
    await find(w, 'cost-limit-amount').setValue('100')
    await w.find('[data-test="cost-limit"] form').trigger('submit')
    await flush()
    expect(client.put).toHaveBeenCalledWith('/projects/p1/billing/cost-limit', { amount: 100, action: 'notify' })
    expect(find(w, 'cost-limit-month-to-date').text()).toBe('This month so far: $42.00 of $100.00')
    w.unmount()

    vi.mocked(client.get).mockImplementation((url) => (url === '/projects/p1/billing'
      ? ok(info({ billing_type: 'fixed', cost_limit: costLimit({ available: false }) }))
      : ok({ currency: 'USD', monthly_price: 31, breakdown: { cpu: 25, memory: 6, gpu: 0 }, markup_percent: 0 })))
    const fixed = await mountTab()
    expect(find(fixed, 'cost-limit').exists()).toBe(false)
    fixed.unmount()

    // no pay-as-you-go prices: nothing to limit
    vi.mocked(client.get).mockImplementation(() => ok(info({ cost_limit: costLimit({ available: false }) })))
    const free = await mountTab()
    expect(find(free, 'cost-limit').exists()).toBe(false)
  })

  it('a resume from the cost limit hands the project back to the page', async () => {
    vi.mocked(client.get).mockImplementation(() => ok(info({
      cost_limit: costLimit({ limit: { amount: 100, action: 'stop' }, percent: 42, state: 'ok', stopped: true, can_resume: true }),
    })))
    vi.mocked(client.post).mockReturnValue(ok({ data: costLimit({ limit: { amount: 100, action: 'stop' }, percent: 42, state: 'ok' }), project: { id: 'p1', stopped: null }, deployment_id: 'd1' }))
    const w = await mountTab()
    await find(w, 'cost-limit-resume').trigger('click')
    await flush()
    expect(client.post).toHaveBeenCalledWith('/projects/p1/billing/cost-limit/resume')
    expect(w.emitted('project-changed')[0][0]).toEqual({ id: 'p1', stopped: null })
    expect(find(w, 'cost-limit-stopped').exists()).toBe(false)
  })

  it('tells members without billing access who sees the bills', async () => {
    vi.mocked(client.get).mockImplementation(() => fail(403, { message: 'Forbidden.' }))
    const w = await mountTab()
    expect(find(w, 'project-billing-forbidden').exists()).toBe(true)

    vi.mocked(client.get).mockImplementation(() => fail(500, { message: 'Down.' }))
    await w.vm.load()
    await flush()
    expect(w.text()).toContain('Down.')
  })
})
