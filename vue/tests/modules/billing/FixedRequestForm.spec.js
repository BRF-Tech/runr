import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import client from '@/api/client'
import FixedRequestForm from '@/modules/billing/components/FixedRequestForm.vue'
import { fail, fixedRequest, flush, globalOptions, ok } from './helpers'

vi.mock('@/api/client', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const find = (w, id) => w.find(`[data-test="${id}"]`)

/** GET …/billing/quote: 25 per vCPU, 3 per GB, 700 per GPU and month */
function quoteFor(params) {
  const cpu = params.cpu_m / 1000 * 25
  const memory = params.memory_mb / 1024 * 3
  const gpu = params.gpu_count * 700
  const monthly = cpu + memory + gpu
  return ok({
    currency: 'USD', monthly_price: monthly, breakdown: { cpu, memory, gpu }, markup_percent: 10,
    prices: { cpu_vcpu_month: 25, memory_gb_month: 3, gpu_month: 700 },
    first_month: { from: '2026-10-19', to: '2026-10-31', days: 13, amount: Math.round(monthly * 13 / 31 * 100) / 100 },
  })
}

function mountForm(props = {}) {
  return mount(FixedRequestForm, { props: { projectId: 'p1', ...props }, global: globalOptions() })
}

describe('FixedRequestForm', () => {
  beforeEach(() => {
    vi.mocked(client.get).mockReset().mockImplementation((url, { params }) => quoteFor(params))
    vi.mocked(client.post).mockReset()
  })

  it('shows the monthly price before the request can be sent', async () => {
    const w = mountForm()
    // nothing can be sent before the price is known
    expect(find(w, 'fixed-request-submit').attributes('disabled')).toBeDefined()

    await find(w, 'fixed-request-vcpu').setValue('2')
    await find(w, 'fixed-request-memory').setValue('4')
    await w.vm.fetchQuote()
    await flush()

    expect(client.get).toHaveBeenLastCalledWith('/projects/p1/billing/quote', { params: { cpu_m: 2000, memory_mb: 4096, gpu_count: 0 } })
    expect(find(w, 'fixed-request-monthly').text()).toBe('$62.00')
    expect(find(w, 'fixed-request-quote').text()).toContain('Includes 10 % markup')
    expect(find(w, 'fixed-request-first-month').text()).toContain('13 days')
    expect(find(w, 'fixed-request-submit').attributes('disabled')).toBeUndefined()

    vi.mocked(client.post).mockReturnValue(ok({ data: fixedRequest() }))
    await find(w, 'fixed-request-note').setValue('launch')
    await find(w, 'fixed-request-submit').trigger('submit')
    await flush()
    expect(client.post).toHaveBeenCalledWith('/projects/p1/billing/fixed-requests', { cpu_m: 2000, memory_mb: 4096, gpu_count: 0, note: 'launch' })
    expect(w.emitted('submitted')[0][0]).toMatchObject({ id: 'fr-1' })
    w.unmount()
  })

  it('refuses values outside the limits and an unchanged allocation', async () => {
    const w = mountForm({ allocation: { cpu_m: 1000, memory_mb: 2048, gpu_count: 0 } })
    await w.vm.fetchQuote()
    await flush()
    expect(w.text()).toContain('These are the approved resources.')
    expect(w.vm.canSubmit).toBe(false)

    await find(w, 'fixed-request-vcpu').setValue('0.01')
    await flush()
    expect(find(w, 'fixed-request-invalid').exists()).toBe(true)
    expect(w.vm.amounts).toBeNull()
    await w.vm.fetchQuote()
    expect(w.vm.quote).toBeNull()

    await find(w, 'fixed-request-vcpu').setValue('2')
    await find(w, 'fixed-request-gpu').setValue('1.5')
    await flush()
    expect(w.vm.amounts).toBeNull()
    w.unmount()
  })

  it('shows why a quote or a request failed', async () => {
    vi.mocked(client.get).mockImplementation(() => fail(422, { message: 'This region sells pay-as-you-go only.' }))
    const w = mountForm()
    await w.vm.fetchQuote()
    await flush()
    expect(find(w, 'fixed-request-quote-error').text()).toBe('This region sells pay-as-you-go only.')

    vi.mocked(client.get).mockImplementation((url, { params }) => quoteFor(params))
    await w.vm.fetchQuote()
    await flush()
    vi.mocked(client.post).mockImplementation(() => fail(422, { errors: { gpu_count: ['This region meters no GPUs.'] } }))
    await w.vm.submit()
    await flush()
    expect(find(w, 'fixed-request-error').text()).toBe('This region meters no GPUs.')
    w.unmount()
  })
})
