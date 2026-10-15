import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { makeI18n, run } from './fixtures'

vi.mock('@/modules/workflows/api/workflows', () => ({
  workflowsApi: {
    run:       vi.fn(),
    cancelRun: vi.fn(),
    retryRun:  vi.fn(),
    replayRun: vi.fn(),
  },
}))
vi.mock('@/composables/useConfirm', () => ({
  useConfirm: () => ({ confirm: vi.fn(() => Promise.resolve(true)) }),
}))

import { workflowsApi } from '@/modules/workflows/api/workflows'
import WorkflowRunDetail from '@/modules/workflows/components/WorkflowRunDetail.vue'

function mountDetail(props = {}) {
  return mount(WorkflowRunDetail, {
    props: { projectId: 'p1', runId: '01JRUN0000000000000000000A', pollInterval: 0, ...props },
    global: { plugins: [makeI18n()] },
  })
}

describe('WorkflowRunDetail', () => {
  beforeEach(() => vi.clearAllMocks())

  it('loads and shows the run, its error and the step timeline', async () => {
    workflowsApi.run.mockResolvedValue({ data: { data: run({ status: 'failed', error: { name: 'StepError', message: 'charge failed' } }) } })
    const w = mountDetail()
    await flushPromises()

    expect(workflowsApi.run).toHaveBeenCalledWith('p1', '01JRUN0000000000000000000A')
    expect(w.text()).toContain('orders')
    expect(w.find('[data-test="run-error"]').text()).toContain('charge failed')
    expect(w.findAll('[data-step]')).toHaveLength(2)
    expect(w.find('[data-test="cancel-run"]').exists()).toBe(false)
    expect(w.find('[data-test="retry-run"]').exists()).toBe(true)
    expect(w.find('[data-test="replay-run"]').exists()).toBe(true)
  })

  it('cancels an active run after confirmation', async () => {
    workflowsApi.run.mockResolvedValue({ data: { data: run({ status: 'sleeping', output: null }) } })
    workflowsApi.cancelRun.mockResolvedValue({ data: { data: { status: 'cancelled' } } })
    const w = mountDetail()
    await flushPromises()

    expect(w.find('[data-test="retry-run"]').exists()).toBe(false)
    await w.find('[data-test="cancel-run"]').trigger('click')
    await flushPromises()

    expect(workflowsApi.cancelRun).toHaveBeenCalledWith('p1', '01JRUN0000000000000000000A')
    expect(w.emitted('changed')).toBeTruthy()
  })

  it('retries a failed run', async () => {
    workflowsApi.run.mockResolvedValue({ data: { data: run({ status: 'failed' }) } })
    workflowsApi.retryRun.mockResolvedValue({ data: { data: { status: 'queued' } } })
    const w = mountDetail()
    await flushPromises()

    await w.find('[data-test="retry-run"]').trigger('click')
    await flushPromises()
    expect(workflowsApi.retryRun).toHaveBeenCalledWith('p1', '01JRUN0000000000000000000A')
  })

  it('replays from the start or from a step and opens the new run', async () => {
    workflowsApi.run.mockResolvedValue({ data: { data: run() } })
    workflowsApi.replayRun.mockResolvedValue({ data: { data: { id: '01JREPLAY' } } })
    const w = mountDetail()
    await flushPromises()

    await w.find('[data-test="replay-run"]').trigger('click')
    await flushPromises()
    expect(workflowsApi.replayRun).toHaveBeenLastCalledWith('p1', '01JRUN0000000000000000000A', null)
    expect(w.emitted('open-run')).toEqual([['01JREPLAY']])

    await w.find('[data-test="replay-from-nap"]').trigger('click')
    await flushPromises()
    expect(workflowsApi.replayRun).toHaveBeenLastCalledWith('p1', '01JRUN0000000000000000000A', 'nap')
  })

  it('links replays and parents', async () => {
    workflowsApi.run.mockResolvedValue({ data: { data: run({ replay_of_run_id: '01JORIG', replay_from_step: 'nap', parent_run_id: '01JPARENT' }) } })
    const w = mountDetail()
    await flushPromises()

    expect(w.text()).toContain('from step nap')
    await w.findAll('button').find((b) => b.text() === '01JPARENT').trigger('click')
    expect(w.emitted('open-run')).toEqual([['01JPARENT']])
  })

  it('hides cancel, retry and replay without workflows.run', async () => {
    workflowsApi.run.mockResolvedValue({ data: { data: run({ status: 'failed' }) } })
    const w = mountDetail({ canRun: false })
    await flushPromises()

    expect(w.find('[data-test="retry-run"]').exists()).toBe(false)
    expect(w.find('[data-test="replay-run"]').exists()).toBe(false)
    expect(w.find('[data-test="replay-from-charge"]').exists()).toBe(false)
  })

  it('shows the load error', async () => {
    workflowsApi.run.mockRejectedValue({ response: { data: { message: 'Workflow run not found.' } } })
    const w = mountDetail()
    await flushPromises()
    expect(w.text()).toContain('Workflow run not found.')
  })

  it('emits back', async () => {
    workflowsApi.run.mockResolvedValue({ data: { data: run() } })
    const w = mountDetail()
    await flushPromises()
    await w.find('button').trigger('click')
    expect(w.emitted('back')).toBeTruthy()
  })
})
