import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { usePermissionsStore } from '@/stores/permissions'
import { makeI18n } from './fixtures'

const replace = vi.fn(() => Promise.resolve())
vi.mock('vue-router', async (orig) => ({
  ...(await orig()),
  useRoute: () => ({ query: {}, hash: '#workflows' }),
  useRouter: () => ({ replace, push: vi.fn() }),
}))
vi.mock('@/modules/workflows/api/workflows', () => ({
  workflowsApi: {
    list:      vi.fn(),
    setActive: vi.fn(),
    startRun:  vi.fn(),
    runs:      vi.fn(() => Promise.resolve({ data: { data: [], meta: { current_page: 1, last_page: 1 } } })),
    run:       vi.fn(() => Promise.resolve({ data: { data: null } })),
    sync:      vi.fn(),
  },
}))

import { workflowsApi } from '@/modules/workflows/api/workflows'
import WorkflowsTab from '@/modules/workflows/components/WorkflowsTab.vue'

const app = (overrides = {}) => ({
  project_id: 'p1', trigger_type: 'workflow', is_workflow_project: true,
  endpoint_url: 'https://wf.example.com', endpoint_override: null, key_fingerprint: 'abc123def456',
  sync_status: 'ok', sync_error: null, sdk: { name: 'runr-workflows', version: '0.1.0' }, last_synced_at: null,
  queue_bridge: { enabled: true, stream: 'orders', event: 'queue/orders', has_redis: true },
  ...overrides,
})

const definitions = [
  { id: 'w1', name: 'orders', description: 'Order flow', triggers: [{ event: 'order.created' }, { cron: '0 9 * * *' }], retries: 3, timeout: '1d', concurrency: [{ limit: 2, key: 'event.data.customer' }], is_active: true, archived: false, run_counts: { completed: 3, failed: 1 } },
  { id: 'w2', name: 'cleanup', triggers: [], retries: 0, timeout: null, concurrency: [], is_active: false, archived: false, run_counts: {} },
]

let pinia
function mountTab(project = { id: 'p1', service_type: 'caas', config: { trigger_type: 'workflow' } }) {
  return mount(WorkflowsTab, { props: { project }, global: { plugins: [makeI18n(), pinia] } })
}

describe('WorkflowsTab', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    pinia = createPinia()
    setActivePinia(pinia)
  })

  it('lists definitions with triggers, limits and run counts', async () => {
    workflowsApi.list.mockResolvedValue({ data: { data: definitions, app: app() } })
    const w = mountTab()
    await flushPromises()

    expect(workflowsApi.list).toHaveBeenCalledWith('p1')
    const cards = w.findAll('[data-workflow]')
    expect(cards.map((c) => c.attributes('data-workflow'))).toEqual(['orders', 'cleanup'])
    expect(cards[0].text()).toContain('order.created')
    expect(cards[0].text()).toContain('0 9 * * *')
    expect(cards[0].text()).toContain('3 retries')
    expect(cards[0].text()).toContain('max 2 per event.data.customer')
    expect(cards[0].text()).toContain('Runs: 4')
    expect(cards[1].text()).toContain('Paused')
    expect(cards[1].text()).toContain('API only')
    expect(w.find('[data-test="endpoint-url"]').text()).toBe('https://wf.example.com')
    expect(w.text()).toContain('queue/orders')
    expect(w.find('[data-test="not-workflow-project"]').exists()).toBe(false)
    expect(w.find('[data-test="runs-table"]').exists()).toBe(true)
  })

  it('explains how to host workflows on other projects', async () => {
    workflowsApi.list.mockResolvedValue({ data: { data: [], app: app({ trigger_type: 'http', is_workflow_project: false }) } })
    const w = mountTab({ id: 'p1', service_type: 'faas', config: { trigger_type: 'http' } })
    await flushPromises()

    expect(w.find('[data-test="not-workflow-project"]').exists()).toBe(true)
    expect(w.find('[data-test="workflows-empty"]').exists()).toBe(true)
  })

  it('pauses and resumes a workflow', async () => {
    workflowsApi.list.mockResolvedValue({ data: { data: definitions, app: app() } })
    workflowsApi.setActive.mockResolvedValue({ data: { data: { ...definitions[0], is_active: false } } })
    const w = mountTab()
    await flushPromises()

    await w.find('[data-test="toggle-orders"]').trigger('click')
    await flushPromises()
    expect(workflowsApi.setActive).toHaveBeenCalledWith('p1', 'orders', false)
    expect(w.find('[data-workflow="orders"]').text()).toContain('Paused')
  })

  it('starts a run and opens it', async () => {
    workflowsApi.list.mockResolvedValue({ data: { data: definitions, app: app() } })
    workflowsApi.startRun.mockResolvedValue({ data: { data: { id: '01JNEWRUN' }, created: true } })
    const w = mountTab()
    await flushPromises()

    expect(w.find('[data-test="start-cleanup"]').attributes('disabled')).toBeDefined()
    await w.find('[data-test="start-orders"]').trigger('click')
    expect(w.find('[data-test="start-run-modal"]').exists()).toBe(true)

    await w.find('[data-test="run-data"]').setValue('{"order_id": 9}')
    await w.find('[data-test="start-run-modal"] form').trigger('submit')
    await flushPromises()

    expect(workflowsApi.startRun).toHaveBeenCalledWith('p1', 'orders', { data: { order_id: 9 }, test: true })
    expect(w.find('[data-test="start-run-modal"]').exists()).toBe(false)
    expect(w.find('[data-test="run-detail"]').exists()).toBe(true)
    expect(replace).toHaveBeenCalledWith(expect.objectContaining({ query: { run: '01JNEWRUN' } }))
  })

  it('rejects run data that is not a JSON object', async () => {
    workflowsApi.list.mockResolvedValue({ data: { data: definitions, app: app() } })
    const w = mountTab()
    await flushPromises()

    await w.find('[data-test="start-orders"]').trigger('click')
    await w.find('[data-test="run-data"]').setValue('[1, 2]')
    await w.find('[data-test="start-run-modal"] form').trigger('submit')
    await flushPromises()

    expect(workflowsApi.startRun).not.toHaveBeenCalled()
    expect(w.find('[data-test="start-error"]').text()).toContain('JSON object')
  })

  it('syncs definitions from the panel', async () => {
    workflowsApi.list.mockResolvedValue({ data: { data: [], app: app({ sync_status: 'never' }) } })
    workflowsApi.sync.mockResolvedValue({ data: { data: definitions, app: app() } })
    const w = mountTab()
    await flushPromises()

    expect(w.find('[data-test="sync-status"]').text()).toBe('Not synced')
    await w.find('[data-test="sync-button"]').trigger('click')
    await flushPromises()

    expect(workflowsApi.sync).toHaveBeenCalledWith('p1')
    expect(w.findAll('[data-workflow]')).toHaveLength(2)
    expect(w.find('[data-test="sync-status"]').text()).toBe('Synced')
  })

  it('hides run and manage actions without the permissions', async () => {
    usePermissionsStore().apply({ permissions: ['projects.view'], projects: { p1: ['projects.view', 'workflows.view'] } })
    workflowsApi.list.mockResolvedValue({ data: { data: definitions, app: app() } })
    const w = mountTab()
    await flushPromises()

    expect(w.findAll('[data-workflow]')).toHaveLength(2)
    expect(w.find('[data-test="start-orders"]').exists()).toBe(false)
    expect(w.find('[data-test="toggle-orders"]').exists()).toBe(false)
    expect(w.find('[data-test="sync-button"]').exists()).toBe(false)
    expect(w.find('[data-test="rotate-key"]').exists()).toBe(false)
    expect(w.find('[data-test="endpoint-override"]').exists()).toBe(false)
  })

  it('offers run actions with workflows.run on the project only', async () => {
    usePermissionsStore().apply({ permissions: [], projects: { p1: ['workflows.view', 'workflows.run'] } })
    workflowsApi.list.mockResolvedValue({ data: { data: definitions, app: app() } })
    const w = mountTab()
    await flushPromises()

    expect(w.find('[data-test="start-orders"]').exists()).toBe(true)
    expect(w.find('[data-test="toggle-orders"]').exists()).toBe(false)
  })

  it('shows sync errors', async () => {
    workflowsApi.list.mockResolvedValue({ data: { data: [], app: app({ sync_status: 'never' }) } })
    workflowsApi.sync.mockRejectedValue({ response: { data: { message: 'Function answered HTTP 401' } } })
    const w = mountTab()
    await flushPromises()

    await w.find('[data-test="sync-button"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-test="app-error"]').text()).toContain('HTTP 401')
  })
})
