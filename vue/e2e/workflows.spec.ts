import { test, expect } from './fixtures/test'
import type { MockApi } from './fixtures/mock-api'

// Durable workflows: the project "Workflows" tab (definitions, runs, run timeline, cancel /
// retry / replay, start run) and the tenant-level "Events" settings tab (list, detail, send
// test event). The workflow endpoints are answered by an in-memory engine below.

type Json = Record<string, any>

interface EngineState {
  runs: Json[]
  events: Json[]
}

const step = (o: Json): Json => ({
  id: o.step_key, name: o.step_key, type: 'run', status: 'completed', position: 1, attempt: 1, max_attempts: 4,
  input: null, output: null, error: null, attempts: [], wake_at: null, timeout_at: null, wait_event: null,
  wait_expression: null, child_run_id: null, started_at: '2026-10-10T10:00:00.000Z', ended_at: '2026-10-10T10:00:01.000Z',
  duration_ms: 25, ...o,
})

function baseRun(projectId: string, o: Json): Json {
  return {
    workflow: { id: 'w1', name: 'process-order' }, project_id: projectId, trigger_source: 'event', event_id: 'ev1',
    event_name: 'order.created', event: { id: 'ev1', name: 'order.created', data: { order_id: 7 }, ts: 1 },
    output: null, error: null, attempt: 0, retry_count: 0, idempotency_key: null, concurrency_key: null,
    parent_run_id: null, replay_of_run_id: null, replay_from_step: null, next_attempt_at: null,
    timeout_at: '2030-01-01T00:00:00.000Z', started_at: '2026-10-10T10:00:00.000Z', ended_at: null,
    duration_ms: 1200, steps_count: 2, created_at: '2026-10-10T10:00:00.000Z', children: [], ...o,
  }
}

const SLEEPING = '01JRUNSLEEPING000000000000'
const COMPLETED = '01JRUNCOMPLETED00000000000'

function workflowsOf(projectId: string): Json[] {
  return [
    {
      id: 'w1', project_id: projectId, name: 'process-order', description: 'Charge, wait, ship',
      triggers: [{ event: 'order.created' }], retries: 3, timeout: '7d', concurrency: [{ limit: 5, key: 'event.data.customer_id' }],
      idempotency: 'event.data.order_id', cancel_on: [], is_active: true, archived: false, source: 'sdk',
      synced_at: '2026-10-10T09:00:00Z', run_counts: { sleeping: 1, completed: 1 }, created_at: '2026-10-10T09:00:00Z',
    },
    {
      id: 'w2', project_id: projectId, name: 'nightly-report', description: null, triggers: [{ cron: '0 2 * * *' }],
      retries: 1, timeout: null, concurrency: [], idempotency: null, cancel_on: [], is_active: true, archived: false,
      source: 'sdk', synced_at: '2026-10-10T09:00:00Z', run_counts: {}, created_at: '2026-10-10T09:00:00Z',
    },
  ]
}

function appOf(projectId: string): Json {
  return {
    project_id: projectId, trigger_type: 'workflow', is_workflow_project: true, endpoint_url: 'https://wf-orders.fn.example.com',
    endpoint_override: null, key_fingerprint: '0a1b2c3d4e5f', previous_key_expires_at: null, sync_status: 'ok', sync_error: null,
    sdk: { name: 'runr-workflows', language: 'python', version: '0.1.0' }, last_synced_at: '2026-10-10T09:00:00Z',
    queue_bridge: { enabled: false, stream: null, event: null, has_redis: true },
  }
}

/** The workflow API of one project plus the tenant events API, on top of the shared fake. */
function installEngine(mockApi: MockApi, projectId: string): EngineState {
  const state: EngineState = {
    events: [
      { id: 'ev1', name: 'order.created', data: { order_id: 7 }, external_id: null, source: 'api', project_id: null, sent_by: '1', ts: 1, runs_started: 1, waits_resumed: 0, runs_cancelled: 0, created_at: '2026-10-10T10:00:00.000Z' },
    ],
    runs: [
      baseRun(projectId, {
        id: SLEEPING, status: 'sleeping',
        steps: [
          step({ step_key: 'charge-card', output: { charged: true } }),
          step({ step_key: 'cool-off', type: 'sleep', status: 'sleeping', position: 2, max_attempts: 1, wake_at: '2030-01-01T00:00:00.000Z', duration_ms: null }),
        ],
      }),
      baseRun(projectId, {
        id: COMPLETED, status: 'completed', output: { shipped: true }, ended_at: '2026-10-10T10:00:05.000Z',
        steps: [
          step({ step_key: 'charge-card', output: { charged: true } }),
          step({ step_key: 'ship', position: 2, output: { tracking: 'T1' }, attempt: 2, attempts: [
            { attempt: 1, status: 'failed', at: '2026-10-10T10:00:02.000Z', duration_ms: 30, error: { name: 'Error', message: 'carrier timeout' } },
            { attempt: 2, status: 'completed', at: '2026-10-10T10:00:04.000Z', duration_ms: 20 },
          ] }),
        ],
      }),
    ],
  }
  const summary = ({ steps, ...rest }: Json) => rest
  const base = `/projects/${projectId}`

  mockApi
    .on('GET', `${base}/workflows`, () => ({ body: { data: workflowsOf(projectId), app: appOf(projectId) } }))
    .on('POST', `${base}/workflows/sync`, () => ({ body: { message: 'Workflow definitions synced.', source: 'sdk', data: workflowsOf(projectId), app: appOf(projectId) } }))
    .on('GET', `${base}/workflow-runs`, (req) => {
      const status = req.query.get('status')
      const wf = req.query.get('workflow')
      const rows = state.runs
        .filter((r) => !status || status.split(',').includes(r.status))
        .filter((r) => !wf || r.workflow.name === wf)
        .map(summary)
      return { body: { data: rows, meta: { current_page: 1, last_page: 1, total: rows.length } } }
    })
    .on('GET', `${base}/workflow-runs/:run`, (req) => {
      const run = state.runs.find((r) => r.id === req.params.run)
      return run ? { body: { data: run } } : { status: 404, body: { message: 'Workflow run not found.' } }
    })
    .on('POST', `${base}/workflow-runs/:run/cancel`, (req) => {
      const run = state.runs.find((r) => r.id === req.params.run)!
      run.status = 'cancelled'
      run.error = { name: 'Cancelled', message: 'Cancelled by E2E User' }
      run.steps = run.steps.map((s: Json) => (s.status === 'sleeping' ? { ...s, status: 'cancelled' } : s))
      return { body: { data: run } }
    })
    .on('POST', `${base}/workflow-runs/:run/replay`, (req) => {
      const original = state.runs.find((r) => r.id === req.params.run)!
      const replay = baseRun(projectId, {
        id: '01JRUNREPLAY00000000000000', status: 'running', trigger_source: 'replay', replay_of_run_id: original.id,
        replay_from_step: req.body?.from_step ?? null, steps: original.steps.slice(0, 1),
      })
      state.runs.unshift(replay)
      return { status: 201, body: { data: replay } }
    })
    .on('POST', `${base}/workflows/:name/runs`, (req) => {
      const created = baseRun(projectId, {
        id: '01JRUNSTARTED0000000000000', status: 'queued', trigger_source: req.body?.test ? 'test' : 'api',
        workflow: { id: 'w1', name: decodeURIComponent(req.params.name) }, event: { id: 'x', name: 'runr/api.start', data: req.body?.data ?? {}, ts: 1 },
        event_name: 'runr/api.start', steps: [], steps_count: 0,
      })
      state.runs.unshift(created)
      return { status: 201, body: { data: created, created: true } }
    })
    .on('GET', '/events', (req) => {
      const prefix = req.query.get('name')
      const rows = state.events.filter((e) => !prefix || e.name.startsWith(prefix))
      return { body: { data: rows, meta: { current_page: 1, last_page: 1, total: rows.length } } }
    })
    .on('POST', '/events', (req) => {
      const event = { id: `ev${state.events.length + 1}`, name: req.body.name, data: req.body.data, external_id: req.body.id ?? null, source: 'test', project_id: null, sent_by: '1', ts: 1, runs_started: 1, waits_resumed: 0, runs_cancelled: 0, created_at: '2026-10-10T11:00:00.000Z' }
      state.events.unshift(event)
      return { body: { ids: [event.id], events: [{ id: event.id, name: event.name, duplicate: false, runs: ['r9'], resumed: 0, cancelled: 0 }] } }
    })
    .on('GET', '/events/:id', (req) => {
      const event = state.events.find((e) => e.id === req.params.id)
      return event
        ? { body: { data: event, runs: state.runs.filter((r) => r.event_id === event.id).map(summary) } }
        : { status: 404, body: { message: 'Event not found.' } }
    })
    .on('GET', '/workflow-usage', () => ({ body: { from: '2026-09-11', to: '2026-10-10', totals: { step_executions: 128, runs_started: 17 }, daily: [], by_workflow: [] } }))

  return state
}

test.describe('Workflows', () => {
  test.beforeEach(({ e2eMode }) => {
    test.skip(e2eMode === 'stack', 'needs the mocked workflow engine')
  })

  test('lists definitions and runs, opens a run timeline', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('faas', { config: { trigger_type: 'workflow' } })
    installEngine(mockApi, project.id)
    await auth.signIn()
    await page.goto(`/projects/${project.id}#workflows`)

    const tab = page.locator('[data-test="workflows-tab"]')
    await expect(tab.locator('[data-workflow="process-order"]')).toContainText('order.created')
    await expect(tab.locator('[data-workflow="nightly-report"]')).toContainText('0 2 * * *')
    await expect(tab.locator('[data-test="endpoint-url"]')).toHaveText('https://wf-orders.fn.example.com')

    await expect(tab.locator('[data-run]')).toHaveCount(2)
    await tab.locator(`[data-run="${COMPLETED}"]`).click()

    const detail = page.locator('[data-test="run-detail"]')
    await expect(detail).toContainText('process-order')
    await expect(detail.locator('[data-step]')).toHaveCount(2)
    await detail.locator('[data-step="ship"] button').first().click()
    await expect(detail.locator('[data-test="step-details"]')).toContainText('carrier timeout')
    await expect(page).toHaveURL(new RegExp(`run=${COMPLETED}`))

    await detail.getByRole('button', { name: 'All runs' }).click()
    await expect(page.locator('[data-test="runs-table"]')).toBeVisible()
  })

  test('filters runs by status', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('faas', { config: { trigger_type: 'workflow' } })
    installEngine(mockApi, project.id)
    await auth.signIn()
    await page.goto(`/projects/${project.id}#workflows`)

    // the unfiltered first load may still be on its way: look for the filtered request itself
    await page.locator('[data-test="status-filter"]').selectOption('sleeping')
    await expect(page.locator('[data-run]')).toHaveCount(1)
    const statuses = mockApi.calls('GET', `/projects/${project.id}/workflow-runs`).map(r => r.query.get('status'))
    expect(statuses).toContain('sleeping')
  })

  test('cancels a sleeping run', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('faas', { config: { trigger_type: 'workflow' } })
    installEngine(mockApi, project.id)
    await auth.signIn()
    await page.goto(`/projects/${project.id}?run=${SLEEPING}#workflows`)

    const detail = page.locator('[data-test="run-detail"]')
    await expect(detail.locator('[data-step="cool-off"]')).toContainText('Wakes up')
    const cancelled = mockApi.waitFor('POST', `/projects/${project.id}/workflow-runs/${SLEEPING}/cancel`)
    await detail.locator('[data-test="cancel-run"]').click()
    await page.locator('div[style*="z-index: 70"]').getByRole('button', { name: 'Cancel run' }).click()
    await cancelled

    await expect(detail.locator('[data-test="run-error"]')).toContainText('Cancelled by E2E User')
    await expect(detail.locator('[data-test="cancel-run"]')).toHaveCount(0)
  })

  test('replays a finished run from a step', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('faas', { config: { trigger_type: 'workflow' } })
    installEngine(mockApi, project.id)
    await auth.signIn()
    await page.goto(`/projects/${project.id}?run=${COMPLETED}#workflows`)

    const replay = mockApi.waitFor('POST', `/projects/${project.id}/workflow-runs/${COMPLETED}/replay`)
    await page.locator('[data-test="replay-from-ship"]').click()

    expect((await replay).body).toEqual({ from_step: 'ship' })
    await expect(page).toHaveURL(/run=01JRUNREPLAY00000000000000/)
    await expect(page.locator('[data-test="run-detail"]')).toContainText('from step ship')
  })

  test('starts a run with JSON data and refuses non-objects', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('faas', { config: { trigger_type: 'workflow' } })
    installEngine(mockApi, project.id)
    await auth.signIn()
    await page.goto(`/projects/${project.id}#workflows`)

    await page.locator('[data-test="start-process-order"]').click()
    const modal = page.locator('[data-test="start-run-modal"]')

    await modal.locator('[data-test="run-data"]').fill('[1, 2, 3]')
    await modal.locator('[data-test="start-submit"]').click()
    await expect(modal.locator('[data-test="start-error"]')).toContainText('JSON object')

    const started = mockApi.waitFor('POST', `/projects/${project.id}/workflows/process-order/runs`)
    await modal.locator('[data-test="run-data"]').fill('{"order_id": 42}')
    await modal.locator('[data-test="start-submit"]').click()

    expect((await started).body).toEqual({ data: { order_id: 42 }, test: true })
    await expect(modal).toHaveCount(0)
    await expect(page).toHaveURL(/run=01JRUNSTARTED0000000000000/)
  })

  test('events view: lists events, shows detail and sends a test event', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('faas', { config: { trigger_type: 'workflow' } })
    installEngine(mockApi, project.id)
    await auth.signIn()
    await page.goto('/settings?tab=events')

    const view = page.locator('[data-test="events-view"]')
    await expect(view.locator('[data-test="usage"]')).toContainText('128')
    await expect(view.locator('[data-event="ev1"]')).toContainText('order.created')

    await view.locator('[data-event="ev1"]').click()
    await expect(view.locator('[data-test="event-detail"]')).toContainText('"order_id": 7')

    await view.locator('[data-test="open-send-event"]').click()
    const modal = page.locator('[data-test="send-event-modal"]')
    await modal.locator('[data-test="event-name"]').fill('user.signup')
    await modal.locator('[data-test="event-data"]').fill('{"email": "new@example.com"}')
    const sent = mockApi.waitFor('POST', '/events')
    await modal.locator('[data-test="send-submit"]').click()

    const request = await sent
    expect(request.body).toEqual({ name: 'user.signup', data: { email: 'new@example.com' } })
    expect(request.query.get('test')).toBe('1')
    await expect(modal).toHaveCount(0)
    await expect(view.locator('[data-event]').first()).toContainText('user.signup')
  })
})
