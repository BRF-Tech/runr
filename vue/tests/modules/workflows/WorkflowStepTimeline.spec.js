import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import WorkflowStepTimeline from '@/modules/workflows/components/WorkflowStepTimeline.vue'
import WorkflowStatusBadge from '@/modules/workflows/components/WorkflowStatusBadge.vue'
import { makeI18n, step } from './fixtures'

const mountTimeline = (props) => mount(WorkflowStepTimeline, { props, global: { plugins: [makeI18n()] } })

describe('WorkflowStepTimeline', () => {
  it('shows the empty state', () => {
    const w = mountTimeline({ steps: [] })
    expect(w.find('[data-test="no-steps"]').exists()).toBe(true)
  })

  it('renders every step with type, status, attempts and duration', () => {
    const w = mountTimeline({
      steps: [
        step({ id: 'a', step_key: 'charge', attempt: 3, max_attempts: 4, duration_ms: 1500 }),
        step({ id: 'b', step_key: 'nap', type: 'sleep', status: 'sleeping', wake_at: '2030-01-01T00:00:00.000Z', duration_ms: null }),
        step({ id: 'c', step_key: 'paid', type: 'wait_for_event', status: 'waiting', wait_event: 'order.paid', timeout_at: '2030-01-02T00:00:00.000Z' }),
        step({ id: 'd', step_key: 'retry-me', status: 'retrying', wake_at: '2030-01-03T00:00:00.000Z', error: { name: 'Error', message: 'timeout' } }),
      ],
    })

    const items = w.findAll('[data-step]')
    expect(items.map((i) => i.attributes('data-step'))).toEqual(['charge', 'nap', 'paid', 'retry-me'])
    expect(items[0].text()).toContain('3 of 4 attempts')
    expect(items[0].text()).toContain('1.5s')
    expect(items[1].text()).toContain('Sleep')
    expect(items[1].text()).toContain('Wakes up')
    expect(items[2].text()).toContain('Waiting for order.paid')
    expect(items[3].text()).toContain('Next attempt')
    expect(items[3].text()).toContain('Error: timeout')
    expect(w.findAllComponents(WorkflowStatusBadge).map((b) => b.props('status'))).toEqual(['completed', 'sleeping', 'waiting', 'retrying'])
  })

  it('expands input, output, error and the attempt history', async () => {
    const w = mountTimeline({
      steps: [step({
        id: 'a', step_key: 'charge', input: { amount: 5 }, output: { ok: true },
        attempts: [
          { attempt: 1, status: 'failed', at: '2026-10-10T10:00:00.000Z', duration_ms: 10, error: { name: 'Error', message: 'boom' } },
          { attempt: 2, status: 'completed', at: '2026-10-10T10:00:02.000Z', duration_ms: 12 },
        ],
      })],
    })

    expect(w.find('[data-test="step-details"]').exists()).toBe(false)
    await w.find('[data-step="charge"] button').trigger('click')

    const details = w.find('[data-test="step-details"]')
    expect(details.exists()).toBe(true)
    expect(details.text()).toContain('"amount": 5')
    expect(details.text()).toContain('"ok": true')
    expect(details.text()).toContain('Attempt 1')
    expect(details.text()).toContain('Error: boom')
  })

  it('emits replay-from and open-run', async () => {
    const w = mountTimeline({
      canReplay: true,
      steps: [step({ id: 'a', step_key: 'call-child', type: 'invoke', child_run_id: '01JCHILD' })],
    })

    await w.find('[data-test="replay-from-call-child"]').trigger('click')
    expect(w.emitted('replay-from')).toEqual([['call-child']])

    const open = w.findAll('button').find((b) => b.text().includes('Child run'))
    await open.trigger('click')
    expect(w.emitted('open-run')).toEqual([['01JCHILD']])
  })

  it('hides replay buttons when the run cannot be replayed', () => {
    const w = mountTimeline({ canReplay: false, steps: [step({ step_key: 'x' })] })
    expect(w.find('[data-test="replay-from-x"]').exists()).toBe(false)
  })
})
