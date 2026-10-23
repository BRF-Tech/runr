import { test, expect } from './fixtures/test'
import type { MockApi } from './fixtures/mock-api'

// Load tests in the UI (docs/load-test.md): the project's "Load test" tab — the metered-traffic
// note, the form (target host + path, method and body, users, duration), the live status through
// the load_test.* broadcasts, the results and the last runs side by side, the API's refusals.
// Every load-test endpoint is answered by the in-memory fake below (mocked API, no cluster: the
// k6 Job itself is covered by the Kind e2e, test:e2e --suite=observability, obs-load-test).

type Json = Record<string, any>

const META = {
  enabled: true, users: [10, 50, 100], durations: [30, 60, 300], methods: ['GET', 'POST'],
  daily_limit: 20, used_today: 2, max_body_bytes: 4096, metered: true,
}

function run(projectId: string, id: string, p95: number, o: Json = {}): Json {
  return {
    id, project_id: projectId, status: 'completed', method: 'GET', host: 'orders.eu-1.runr.test', path: '/api', url: 'https://orders.eu-1.runr.test/api',
    has_body: false, users: 50, duration_seconds: 60, error: null, started_by: { id: 'u1', name: 'Admin' },
    started_at: '2026-10-21T10:00:00Z', finished_at: '2026-10-21T10:01:05Z', created_at: `2026-10-21T10:0${id.slice(-1)}:00Z`,
    results: {
      requests: 12000, failed_requests: 12, rps: 199.6, error_rate: 0.001,
      latency_ms: { avg: 21, min: 3, p50: 18, p95, p99: p95 * 2, max: 400 },
      pods_peak: 4, pods_source: 'prometheus', cold_starts: 2,
    },
    ...o,
  }
}

function installLoadTests(mockApi: MockApi, projectId: string, o: { tests?: Json[], meta?: Json } = {}): { tests: Json[] } {
  const state = { tests: o.tests ?? [] }
  const base = `/projects/${projectId}/load-tests`
  const meta = { ...META, targets: ['orders.eu-1.runr.test', 'shop.example.com'], ...(o.meta ?? {}) }

  mockApi
    .on('GET', base, () => ({ body: { data: state.tests, meta } }))
    .on('POST', base, (req) => {
      if (req.body.users === 100 && req.body.duration_seconds === 300) {
        return { status: 429, body: { message: 'Your organization started 20 load tests today, its daily limit. Try again tomorrow.', code: 'daily_limit_reached', daily_limit: 20 } }
      }
      const test = {
        id: '01JLOADTEST00000000000000NEW', project_id: projectId, status: 'pending', ...req.body,
        url: `https://${req.body.host}${req.body.path}`, has_body: !!req.body.body, results: null, error: null,
        started_at: null, finished_at: null, created_at: '2026-10-21T10:09:00Z',
      }
      state.tests.unshift(test)
      return { status: 202, body: { data: test } }
    })
    .on('POST', `${base}/:id/cancel`, (req) => {
      const test = state.tests.find((t) => t.id === req.params.id)!
      Object.assign(test, { status: 'cancelled', finished_at: '2026-10-21T10:09:30Z' })
      return { body: { data: test } }
    })

  return state
}

test.describe('Load test', () => {
  test.beforeEach(({ e2eMode }) => {
    test.skip(e2eMode === 'stack', 'needs the mocked load-test API (a k6 Job needs a region cluster)')
  })

  test('starts a test, follows it live and shows its results next to the earlier runs', async ({ page, auth, seed, mockApi, realtime }) => {
    const project = await seed.project('xaas')
    installLoadTests(mockApi, project.id, { tests: [run(project.id, '01JLOADTEST0000000000000001', 61)] })
    await auth.signIn()
    await page.goto(`/projects/${project.id}#load-test`)

    const tab = page.locator('[data-test="load-test-tab"]')
    await expect(tab.locator('[data-test="load-test-metered"]')).toContainText('metered and billed')
    await expect(tab.locator('[data-test="load-test-quota"]')).toHaveText('2 of 20 tests today')

    await tab.locator('[data-test="load-test-host"]').selectOption('shop.example.com')
    await tab.locator('[data-test="load-test-path"]').fill('/api/orders')
    await tab.locator('[data-test="load-test-method"]').selectOption('POST')
    await tab.locator('[data-test="load-test-body"]').fill('{"item":42}')
    await tab.locator('[data-test="load-test-users-50"]').click()
    await tab.locator('[data-test="load-test-duration-60"]').click()
    const started = mockApi.waitFor('POST', `/projects/${project.id}/load-tests`)
    await tab.locator('[data-test="load-test-start"]').click()
    expect((await started).body).toEqual({
      host: 'shop.example.com', path: '/api/orders', method: 'POST', users: 50, duration_seconds: 60,
      body: '{"item":42}', content_type: 'application/json',
    })

    const active = tab.locator('[data-test="load-test-active"]')
    await expect(active).toHaveAttribute('data-status', 'pending')
    await expect(tab.locator('[data-test="load-test-start"]')).toBeDisabled()

    const id = '01JLOADTEST00000000000000NEW'
    const base = { project_id: project.id, id, method: 'POST', host: 'shop.example.com', path: '/api/orders', url: 'https://shop.example.com/api/orders', users: 50, duration_seconds: 60, created_at: '2026-10-21T10:09:00Z' }
    await realtime.platform('load_test.updated', { project_id: project.id, load_test: { ...base, status: 'running', started_at: new Date().toISOString(), results: null } })
    await expect(active).toHaveAttribute('data-status', 'running')
    await expect(active).toContainText('50 users · 1 min')

    await realtime.platform('load_test.completed', { project_id: project.id, load_test: run(project.id, id, 48, { ...base, finished_at: '2026-10-21T10:10:05Z' }) })
    await expect(active).toHaveCount(0)
    const latest = tab.locator('[data-test="load-test-latest"]')
    await expect(latest).toHaveAttribute('data-status', 'completed')
    await expect(latest.locator('[data-metric="p95"]')).toContainText('48 ms')
    await expect(latest.locator('[data-metric="coldStarts"]')).toContainText('2')
    await expect(latest.locator('[data-metric="podsPeak"]')).toContainText('4')

    const compare = tab.locator('[data-test="load-test-compare"]')
    await expect(compare.locator('[data-run]')).toHaveCount(2)
    await expect(compare.locator('[data-row="p95"] [data-best="true"]')).toHaveText('48 ms')
  })

  test('cancels a running test and shows the daily limit the API answers with', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('xaas')
    installLoadTests(mockApi, project.id, { tests: [run(project.id, '01JLOADTEST0000000000000002', 30, { status: 'running', results: null, finished_at: null })] })
    await auth.signIn()
    await page.goto(`/projects/${project.id}#load-test`)

    const tab = page.locator('[data-test="load-test-tab"]')
    const cancelled = mockApi.waitFor('POST', `/projects/${project.id}/load-tests/:id/cancel`)
    await tab.locator('[data-test="load-test-cancel"]').click()
    // the confirmation's own button (the tab's button has the same label)
    await page.getByRole('button', { name: 'Stop test' }).last().click()
    await cancelled
    await expect(tab.locator('[data-test="load-test-active"]')).toHaveCount(0)
    await expect(tab.locator('[data-test="load-test-latest"]')).toHaveAttribute('data-status', 'cancelled')

    await tab.locator('[data-test="load-test-users-100"]').click()
    await tab.locator('[data-test="load-test-duration-300"]').click()
    await tab.locator('[data-test="load-test-start"]').click()
    await expect(page.getByText('its daily limit')).toBeVisible()
  })

  test('the tab is only on HTTP projects', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('xaas', { config: { trigger_type: 'queue' }, endpoint_url: null })
    installLoadTests(mockApi, project.id)
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)

    await expect(page.getByRole('button', { name: 'Uptime', exact: true })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Load test', exact: true })).toHaveCount(0)
  })
})
