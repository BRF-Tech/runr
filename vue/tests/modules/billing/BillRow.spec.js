import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import BillRow from '@/modules/billing/components/BillRow.vue'
import { LINES, bill, fail, flush, globalOptions, ok } from './helpers'

vi.mock('@/api/client', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const find = (w, id) => w.find(`[data-test="${id}"]`)

function mountRow(props = {}) {
  return mount(BillRow, { props: { bill: bill(), ...props }, global: globalOptions() })
}

describe('BillRow', () => {
  beforeEach(() => localStorage.clear())

  it('shows the month, status, paid flag and total of a closed bill', () => {
    const w = mountRow()
    expect(w.text()).toContain('2026-09')
    expect(w.text()).toContain('orders-api')
    expect(find(w, 'bill-status-b-1').text()).toBe('Closed')
    expect(find(w, 'bill-paid-b-1').text()).toBe('Unpaid')
    expect(find(w, 'bill-total-b-1').text()).toBe('$12.50')
  })

  it('names account and tenant bills and marks imported 0.5.7 bills', () => {
    expect(mountRow({ bill: bill({ kind: 'account', project: null }) }).text()).toContain('Account resources of Ada')
    expect(mountRow({ bill: bill({ kind: 'account', project: null, owner: null }) }).text()).toContain('Unassigned')
    expect(mountRow({ bill: bill({ kind: 'tenant', project: null, tenant: { id: 't1', slug: 'acme', name: 'Acme' }, source: 'legacy' }) }).text())
      .toContain('Acme')
    expect(mountRow({ bill: bill({ source: 'legacy' }) }).text()).toContain('Imported from 0.5.7')
    // open bills are estimates without a paid flag
    const open = mountRow({ bill: bill({ status: 'open' }) })
    expect(find(open, 'bill-status-b-1').text()).toBe('Open (estimate)')
    expect(find(open, 'bill-paid-b-1').exists()).toBe(false)
  })

  it('loads the line items when expanded and shows the markup and multiplier', async () => {
    const fetchDetail = vi.fn(() => Promise.resolve(bill({ line_items: LINES })))
    const w = mountRow({ fetchDetail })
    await find(w, 'bill-toggle-b-1').trigger('click')
    await flush()
    expect(fetchDetail).toHaveBeenCalledWith('b-1')
    const lines = find(w, 'bill-lines-b-1')
    expect(lines.text()).toContain('vCPU hours (eu-1, HTTP)')
    expect(lines.text()).toContain('+10 % markup')
    expect(lines.text()).toContain('×1.5')
    // no cost comparison without metered_cost (owners see the bill only)
    expect(find(w, 'bill-metered-b-1').exists()).toBe(false)
    // a second toggle closes, a third does not fetch again
    await find(w, 'bill-toggle-b-1').trigger('click')
    await find(w, 'bill-toggle-b-1').trigger('click')
    await flush()
    expect(fetchDetail).toHaveBeenCalledTimes(1)
  })

  it('shows the real usage cost and the difference to billing.view holders', async () => {
    const w = mountRow({ bill: bill({ line_items: [], fixed_total: 50, total: 50, metered_cost: 30, difference: 20 }), expanded: true })
    await flush()
    expect(find(w, 'bill-metered-b-1').text()).toContain('$30.00')
    expect(find(w, 'bill-difference-b-1').text()).toContain('$20.00')
    expect(w.text()).toContain('Nothing to bill in this month.')
  })

  it('shows a fetch error', async () => {
    const w = mountRow({ fetchDetail: () => fail(500, { message: 'Down.' }), expanded: true })
    await flush()
    expect(w.text()).toContain('Down.')
  })

  it('links the payment page of a closed bill and lets an operator set link and paid status', async () => {
    const setUrl = vi.fn((id, url) => ok({ data: bill({ line_items: [], payment_url: url }) }))
    const setPaid = vi.fn((id, data) => ok({ data: bill({ line_items: [], payment_url: 'https://pay.example.com/42', ...data, paid_at: '2026-10-05T00:00:00Z' }) }))
    const w = mountRow({ bill: bill({ line_items: [] }), payment: { setUrl, setPaid }, expanded: true })
    await flush()

    await find(w, 'bill-payment-url-b-1').setValue('https://pay.example.com/42')
    await find(w, 'bill-payment-url-save-b-1').trigger('click')
    await flush()
    expect(setUrl).toHaveBeenCalledWith('b-1', 'https://pay.example.com/42')
    expect(find(w, 'bill-payment-link-b-1').attributes('href')).toBe('https://pay.example.com/42')
    expect(find(w, 'bill-payment-link-b-1').attributes('target')).toBe('_blank')
    expect(w.emitted('updated')).toHaveLength(1)

    await find(w, 'bill-paid-toggle-b-1').setValue(true)
    await find(w, 'bill-paid-at-b-1').setValue('2026-10-05')
    await find(w, 'bill-reference-b-1').setValue(' INV-42 ')
    await find(w, 'bill-paid-save-b-1').trigger('click')
    await flush()
    expect(setPaid).toHaveBeenCalledWith('b-1', { paid: true, paid_at: '2026-10-05', payment_reference: 'INV-42' })
    expect(find(w, 'bill-paid-b-1').text()).toBe('Paid')

    // the link is cleared with null
    await find(w, 'bill-payment-url-clear-b-1').trigger('click')
    await flush()
    expect(setUrl).toHaveBeenLastCalledWith('b-1', null)
  })

  it('keeps open bills read-only and reports a refused payment update', async () => {
    const open = mountRow({ bill: bill({ status: 'open', line_items: [] }), payment: { setUrl: vi.fn(), setPaid: vi.fn() }, expanded: true })
    await flush()
    expect(find(open, 'bill-payment-editor-b-1').exists()).toBe(false)

    const setPaid = vi.fn(() => fail(409, { message: 'The bill is still open.' }))
    const w = mountRow({ bill: bill({ line_items: [] }), payment: { setUrl: vi.fn(), setPaid }, expanded: true })
    await flush()
    await find(w, 'bill-paid-save-b-1').trigger('click')
    await flush()
    expect(setPaid).toHaveBeenCalledWith('b-1', { paid: false })
  })
})
