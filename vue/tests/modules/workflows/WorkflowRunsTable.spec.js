import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { makeI18n, run } from './fixtures'

vi.mock('@/modules/workflows/api/workflows', () => ({ workflowsApi: { runs: vi.fn() } }))

import { workflowsApi } from '@/modules/workflows/api/workflows'
import WorkflowRunsTable from '@/modules/workflows/components/WorkflowRunsTable.vue'

const page = (rows, meta = { current_page: 1, last_page: 1, total: rows.length }) => ({ data: { data: rows, meta } })

function mountTable(props = {}) {
  return mount(WorkflowRunsTable, {
    props: { projectId: 'p1', workflows: ['orders', 'nightly'], pollInterval: 0, ...props },
    global: { plugins: [makeI18n()] },
  })
}

describe('WorkflowRunsTable', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useRealTimers()
  })

  it('lists runs and opens one on click', async () => {
    workflowsApi.runs.mockResolvedValue(page([run(), run({ id: '01JRUN0000000000000000000B', status: 'failed', error: { message: 'boom' } })]))
    const w = mountTable()
    await flushPromises()

    expect(workflowsApi.runs).toHaveBeenCalledWith('p1', { per_page: 25 })
    const rows = w.findAll('[data-run]')
    expect(rows).toHaveLength(2)
    expect(rows[1].text()).toContain('boom')

    await rows[1].trigger('click')
    expect(w.emitted('open')).toEqual([['01JRUN0000000000000000000B']])
  })

  it('shows the empty state', async () => {
    workflowsApi.runs.mockResolvedValue(page([]))
    const w = mountTable()
    await flushPromises()
    expect(w.find('[data-test="runs-empty"]').exists()).toBe(true)
  })

  it('filters by status and workflow', async () => {
    workflowsApi.runs.mockResolvedValue(page([]))
    const w = mountTable()
    await flushPromises()

    await w.find('[data-test="status-filter"]').setValue('failed')
    await flushPromises()
    expect(workflowsApi.runs).toHaveBeenLastCalledWith('p1', { status: 'failed', per_page: 25 })

    await w.find('[data-test="workflow-filter"]').setValue('nightly')
    await flushPromises()
    expect(workflowsApi.runs).toHaveBeenLastCalledWith('p1', { status: 'failed', workflow: 'nightly', per_page: 25 })
    expect(w.emitted('update:workflow')).toEqual([['nightly']])
  })

  it('debounces the search', async () => {
    workflowsApi.runs.mockResolvedValue(page([]))
    const w = mountTable()
    await flushPromises()

    await w.find('[data-test="run-search"]').setValue('01J')
    await w.find('[data-test="run-search"]').setValue('01JRUN')
    expect(workflowsApi.runs).toHaveBeenCalledTimes(1)
    await new Promise((resolve) => setTimeout(resolve, 350))
    await flushPromises()
    expect(workflowsApi.runs).toHaveBeenCalledTimes(2)
    expect(workflowsApi.runs).toHaveBeenLastCalledWith('p1', { q: '01JRUN', per_page: 25 })
  })

  it('paginates', async () => {
    workflowsApi.runs.mockResolvedValue(page([run()], { current_page: 1, last_page: 3, total: 60 }))
    const w = mountTable()
    await flushPromises()

    expect(w.text()).toContain('Page 1 of 3')
    await w.findAll('button').find((b) => b.text() === 'Next').trigger('click')
    await flushPromises()
    expect(workflowsApi.runs).toHaveBeenLastCalledWith('p1', { page: 2, per_page: 25 })
  })

  it('preselects the workflow filter from the prop', async () => {
    workflowsApi.runs.mockResolvedValue(page([]))
    mountTable({ workflow: 'orders' })
    await flushPromises()
    expect(workflowsApi.runs).toHaveBeenCalledWith('p1', { workflow: 'orders', per_page: 25 })
  })
})
