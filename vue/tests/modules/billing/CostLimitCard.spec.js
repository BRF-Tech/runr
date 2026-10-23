import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import client from '@/api/client'
import CostLimitCard from '@/modules/billing/components/CostLimitCard.vue'
import { useToast } from '@/composables/useToast'
import { costLimit, fail, flush, globalOptions, ok } from './helpers'

vi.mock('@/api/client', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const find = (w, id) => w.find(`[data-test="${id}"]`)

function mountCard(status = costLimit()) {
  return mount(CostLimitCard, { props: { projectId: 'p1', status }, global: globalOptions() })
}

describe('CostLimitCard', () => {
  beforeEach(() => {
    vi.mocked(client.put).mockReset()
    vi.mocked(client.delete).mockReset()
    vi.mocked(client.post).mockReset()
  })

  it('shows this month so far and the forecast, and saves a limit that notifies', async () => {
    const w = mountCard()
    expect(find(w, 'cost-limit-month-to-date').text()).toBe('This month so far: $42.00')
    expect(find(w, 'cost-limit-forecast').text()).toContain('Month-end forecast: $65.10')
    expect(find(w, 'cost-limit-forecast').text()).toContain('so far ÷ 20 days × 31 days')
    expect(find(w, 'cost-limit-progress').exists()).toBe(false)
    expect(find(w, 'cost-limit-remove').exists()).toBe(false)
    expect(find(w, 'cost-limit-save').attributes('disabled')).toBeDefined()

    vi.mocked(client.put).mockReturnValue(ok({ data: costLimit({ limit: { amount: 100, action: 'notify', updated_by: { id: 'u1', name: 'Ada' }, updated_at: '2026-10-20T09:00:00Z' }, percent: 42, state: 'ok' }) }))
    await find(w, 'cost-limit-amount').setValue('100')
    expect(find(w, 'cost-limit-stop-now').exists()).toBe(false)
    await w.find('form').trigger('submit')
    await flush()

    expect(client.put).toHaveBeenCalledWith('/projects/p1/billing/cost-limit', { amount: 100, action: 'notify' })
    expect(w.emitted('changed')[0][0]).toMatchObject({ limit: { amount: 100, action: 'notify' } })
    expect(useToast().toasts.value.at(-1).message).toBe('Cost limit saved.')
  })

  it('shows the limit with its progress and state, and warns that a stop limit already passed stops at once', async () => {
    const w = mountCard(costLimit({ limit: { amount: 50, action: 'notify', updated_by: null, updated_at: '2026-10-20T09:00:00Z' }, percent: 84, state: 'warning' }))
    expect(find(w, 'cost-limit-month-to-date').text()).toBe('This month so far: $42.00 of $50.00')
    expect(find(w, 'cost-limit-progress').attributes('aria-valuenow')).toBe('84')
    expect(find(w, 'cost-limit-state').text()).toBe('80 % of the limit reached')
    expect(find(w, 'cost-limit-forecast').text()).toContain('above the limit')
    // the saved limit is in the form; nothing to save until it changes
    expect(w.vm.form).toEqual({ amount: '50', action: 'notify' })
    expect(w.vm.canSave).toBe(false)

    await find(w, 'cost-limit-action-stop').trigger('click')
    expect(w.vm.canSave).toBe(true)
    // 42 so far is below 50: no immediate stop
    expect(find(w, 'cost-limit-stop-now').exists()).toBe(false)

    await find(w, 'cost-limit-amount').setValue('40')
    expect(find(w, 'cost-limit-stop-now').text()).toContain('saving stops the project right away')

    vi.mocked(client.put).mockReturnValue(ok({ data: costLimit({ limit: { amount: 40, action: 'stop' }, stopped: true, state: 'reached' }) }))
    await w.vm.save()
    expect(client.put).toHaveBeenCalledWith('/projects/p1/billing/cost-limit', { amount: 40, action: 'stop' })
  })

  it('refuses an amount that is not above 0 or has more than 3 decimals', async () => {
    const w = mountCard()
    for (const value of ['0', '-5', '1.2345']) {
      await find(w, 'cost-limit-amount').setValue(value)
      expect(find(w, 'cost-limit-invalid').exists(), value).toBe(true)
      expect(w.vm.amount).toBeNull()
      expect(find(w, 'cost-limit-save').attributes('disabled')).toBeDefined()
    }
    await w.vm.save()
    expect(client.put).not.toHaveBeenCalled()

    await find(w, 'cost-limit-amount').setValue('12.5')
    expect(find(w, 'cost-limit-invalid').exists()).toBe(false)
    expect(w.vm.amount).toBe(12.5)
  })

  it('removes the limit', async () => {
    const w = mountCard(costLimit({ limit: { amount: 100, action: 'stop' }, percent: 42, state: 'ok' }))
    vi.mocked(client.delete).mockReturnValue(ok({ data: costLimit() }))
    await find(w, 'cost-limit-remove').trigger('click')
    await flush()
    expect(client.delete).toHaveBeenCalledWith('/projects/p1/billing/cost-limit')
    expect(w.emitted('changed')[0][0].limit).toBeNull()
  })

  it('is read-only for members who may see but not change it', () => {
    const w = mountCard(costLimit({ can_manage: false, limit: { amount: 100, action: 'stop' }, percent: 42, state: 'ok' }))
    expect(w.find('form').exists()).toBe(false)
    expect(find(w, 'cost-limit-readonly').text()).toBe('Limit $100.00 per month; at 100 %: Stop the project.')

    const none = mountCard(costLimit({ can_manage: false }))
    expect(find(none, 'cost-limit-readonly').text()).toBe('No cost limit is set.')
  })

  it('a project its limit stopped is resumed here once the limit allows it', async () => {
    const stopped = costLimit({ limit: { amount: 40, action: 'stop' }, percent: 105, state: 'reached', stopped: true, can_resume: false })
    const w = mountCard(stopped)
    expect(find(w, 'cost-limit-stopped').text()).toBe('Stopped by its cost limit')
    expect(find(w, 'cost-limit-state').text()).toBe('Limit reached')
    expect(find(w, 'cost-limit-resume').exists()).toBe(false)
    expect(find(w, 'cost-limit-resume-hint').text()).toContain('raise or remove the limit')
    expect(find(w, 'cost-limit-progress').attributes('aria-valuenow')).toBe('100')

    await w.setProps({ status: { ...stopped, limit: { amount: 100, action: 'stop' }, percent: 42, state: 'ok', can_resume: true } })
    expect(find(w, 'cost-limit-resume-hint').exists()).toBe(false)
    vi.mocked(client.post).mockReturnValue(ok({ data: costLimit({ limit: { amount: 100, action: 'stop' } }), project: { id: 'p1', stopped: null }, deployment_id: 'd1', redeploy_error: null, redeploy_code: null }))
    await find(w, 'cost-limit-resume').trigger('click')
    await flush()
    expect(client.post).toHaveBeenCalledWith('/projects/p1/billing/cost-limit/resume')
    expect(w.emitted('resumed')[0][0]).toMatchObject({ deployment_id: 'd1', project: { id: 'p1', stopped: null } })
    expect(useToast().toasts.value.at(-1).message).toBe('Project resumed; the version that ran is being deployed again.')

    // the version is built again for the region's registry first
    vi.mocked(client.post).mockReturnValue(ok({ data: costLimit(), project: null, deployment_id: null, redeploy_error: 'v1 is built again…', redeploy_code: 'version_rebuilding' }))
    await w.vm.resume()
    expect(useToast().toasts.value.at(-1).type).toBe('success')
  })

  it('shows why the API refused', async () => {
    const w = mountCard(costLimit({ limit: { amount: 40, action: 'stop' }, stopped: true, can_resume: true, state: 'reached', percent: 105 }))
    vi.mocked(client.post).mockImplementation(() => fail(409, { message: 'The cost limit still stops the project.', code: 'cost_limit_still_reached' }))
    await w.vm.resume()
    await flush()
    expect(find(w, 'cost-limit-error').text()).toBe('The cost limit still stops the project.')
    expect(w.emitted('resumed')).toBeUndefined()

    vi.mocked(client.put).mockImplementation(() => fail(422, { message: 'Invalid.', errors: { amount: ['The amount must be greater than 0.'] } }))
    await find(w, 'cost-limit-amount').setValue('60')
    await w.vm.save()
    await flush()
    expect(find(w, 'cost-limit-error').text()).toBe('The amount must be greater than 0.')
  })
})
