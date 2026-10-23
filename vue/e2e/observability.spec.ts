import { test, expect } from './fixtures/test'
import type { MockApi } from './fixtures/mock-api'

// Observability v2 in the UI (docs/observability.md): the project tabs Traces / Replay / Uptime,
// Observability → Alerts (channels, send test, rules) and the public status page. Every observability
// endpoint is answered by the in-memory fake below (mocked API, no cluster).

type Json = Record<string, any>

const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736'
const CAPTURE_ID = '01JCAPTURE0000000000000001'

function trace(projectId: string): Json {
  const span = (o: Json): Json => ({
    trace_id: TRACE_ID, parent_span_id: null, kind: 'internal', service: 'orders', status: { code: 'unset', message: null },
    attributes: {}, events: [], resource: { 'service.name': 'orders', 'runr.project_id': projectId }, depth: 0,
    start_offset_ms: 0, duration_ms: 1, ...o,
  })
  return {
    trace_id: TRACE_ID, start: '2026-10-10T10:00:00.000Z', start_ms: 1760090400000, end_ms: 1760090400050, duration_ms: 50,
    span_count: 3, error_count: 1, services: ['activator', 'orders'],
    spans: [
      span({ span_id: 'aaaaaaaaaaaaaaaa', name: 'activator_proxy', service: 'activator', duration_ms: 50, resource: { 'service.name': 'activator' } }),
      span({
        span_id: 'bbbbbbbbbbbbbbbb', parent_span_id: 'aaaaaaaaaaaaaaaa', name: 'GET', kind: 'server', depth: 1, start_offset_ms: 5, duration_ms: 40,
        status: { code: 'error', message: 'HTTP 500' }, attributes: { 'http.response.status_code': 500, 'url.path': '/orders', 'runr.cold_start': true },
        events: [{ name: 'exception', offset_ms: 44, attributes: { 'exception.message': 'database unreachable' } }],
      }),
      span({ span_id: 'cccccccccccccccc', parent_span_id: 'bbbbbbbbbbbbbbbb', name: 'SELECT orders', kind: 'client', depth: 2, start_offset_ms: 10, duration_ms: 30 }),
    ],
  }
}

function settingsOf(projectId: string, o: Json = {}): Json {
  return {
    project_id: projectId, tracing_enabled: true, tracing_sample_ratio: 1, replay_enabled: false, replay_max_body_bytes: 65536,
    replay_retention_days: 7, replay_redact_headers: [], replay_redact_query: [],
    platform_redact_headers: ['authorization', 'cookie'], platform_redact_query: ['token'],
    region_tracing: { mode: 'managed', status: 'ready', queryable: true, accepts_spans: true },
    replay_available: true, replay_target: 'https://orders.eu-1.example.com', ...o,
  }
}

function installObservability(mockApi: MockApi, projectId: string): { settings: Json } {
  const state = { settings: settingsOf(projectId), checks: [] as Json[], channels: [] as Json[], rules: [] as Json[] }
  const base = `/projects/${projectId}`

  mockApi
    .on('GET', `${base}/observability`, () => ({ body: { data: state.settings } }))
    .on('PUT', `${base}/observability/tracing`, (req) => {
      state.settings = { ...state.settings, tracing_enabled: req.body.enabled, tracing_sample_ratio: req.body.sample_ratio }
      return { body: { data: state.settings, requires_redeploy: true } }
    })
    .on('PUT', `${base}/observability/replay`, (req) => {
      state.settings = { ...state.settings, replay_enabled: req.body.enabled, replay_redact_headers: req.body.redact_headers, replay_max_body_bytes: req.body.max_body_bytes }
      return { body: { data: state.settings, requires_redeploy: true } }
    })
    .on('GET', `${base}/traces`, (req) => {
      const errorsOnly = req.query.get('status') === 'error'
      const rows = [
        { trace_id: TRACE_ID, root_service: 'orders', root_name: 'GET', start: '2026-10-10T10:00:00.000Z', start_ms: 1, duration_ms: 50, status_code: 500, path: '/orders', cold_start: true, error: true, spans: [] },
        { trace_id: '1111111111111111aaaaaaaaaaaaaaaa', root_service: 'orders', root_name: 'GET', start: '2026-10-10T10:00:01.000Z', start_ms: 2, duration_ms: 3, status_code: 200, path: '/health', cold_start: false, error: false, spans: [] },
      ].filter((t) => !errorsOnly || t.error)
      return { body: { data: { traceql: '{}', from: 1, to: 2, traces: rows } } }
    })
    .on('GET', `${base}/traces/:id`, () => ({ body: { data: trace(projectId) } }))
    .on('GET', `${base}/traces/:id/logs`, () => ({ body: { data: [
      { ts_ms: 1760090400040, pod: 'orders-00001-deployment-x', container: 'handler', line: `[runr-wrapper] trace_id=${TRACE_ID} span_id=bbbbbbbbbbbbbbbb sampled=true GET /orders 500 40.0ms cold_start=true` },
    ] } }))
    .on('GET', `${base}/replays`, () => ({ body: { data: [{
      id: CAPTURE_ID, captured_at: '2026-10-10T10:00:00Z', trigger: 'http', method: 'POST', path: '/orders', query: 'page=2&token=REDACTED',
      status: 500, timed_out: false, duration_ms: 12.5, trace_id: TRACE_ID, replay_count: 0, last_replay_status: null,
    }], meta: { current_page: 1, last_page: 1, total: 1 } } }))
    .on('GET', `${base}/replays/:id`, () => ({ body: { data: {
      id: CAPTURE_ID, captured_at: '2026-10-10T10:00:00Z', method: 'POST', path: '/orders', query: 'page=2&token=REDACTED', status: 500,
      timed_out: false, duration_ms: 12.5, trace_id: TRACE_ID, replay_count: 0, headers: { 'content-type': 'application/json' },
      redacted_headers: ['authorization', 'cookie'], body: '{"item":42}', body_base64: false, body_truncated: false,
      response_headers: { 'content-type': 'application/json' }, response_body: '{"error":"database unreachable"}', response_body_base64: false,
    } } }))
    .on('POST', `${base}/replays/:id/replay`, () => ({ body: { data: {
      original: { status: 500 },
      replay: { status: 201, headers: { 'content-type': 'application/json' }, body: '{"id":1}', replayed_at: '2026-10-10T11:00:00Z', version: 4, error: null },
      diff: {
        changed: true, status: { before: 500, after: 201, changed: true },
        headers: { added: {}, removed: {}, changed: {} },
        body: { changed: true, truncated: false, lines: [{ op: '-', text: '{"error":"database unreachable"}' }, { op: '+', text: '{"id":1}' }] },
      },
    } } }))
    .on('GET', `${base}/uptime-checks`, () => ({ body: { data: state.checks } }))
    .on('POST', `${base}/uptime-checks`, (req) => {
      if (!req.body.name || req.body.url === 'https://intranet.example.com/') {
        return { status: 422, body: { message: 'The given data was invalid.', errors: { url: ['The URL points to a private, loopback or cluster-internal address.'] } } }
      }
      const check = { id: '01JCHECK000000000000000001', ...req.body, header_names: Object.keys(req.body.headers || {}), status: 'up', uptime_24h: 99.5, uptime_7d: 99.9, uptime_30d: 100, avg_response_ms_24h: 42, last_checked_at: '2026-10-10T10:00:00Z', last_error: null }
      state.checks.push(check)
      return { status: 201, body: { data: check } }
    })
    .on('GET', `${base}/uptime-checks/:id/results`, () => ({ body: { data: {
      results: [], locations: ['control-plane'], uptime: { '24h': 99.5 },
      days: Array.from({ length: 30 }, (_, i) => ({ date: `2026-09-${String(i + 1).padStart(2, '0')}`, total: 10, up: i === 29 ? 5 : 10, uptime: i === 29 ? 50 : 100 })),
      incidents: [],
    } } }))
    .on('GET', `${base}/uptime-incidents`, () => ({ body: { data: [] } }))
    .on('GET', '/observability/alert-channels', () => ({ body: { data: state.channels } }))
    .on('POST', '/observability/alert-channels', (req) => {
      const ch = { id: `01JCHANNEL00000000000000${String(state.channels.length).padStart(2, '0')}`, name: req.body.name, type: req.body.type, config: { webhook_host: 'hooks.slack.com', webhook_set: true }, is_enabled: true, last_test_at: null }
      state.channels.push(ch)
      return { status: 201, body: { data: ch } }
    })
    .on('POST', '/observability/alert-channels/:id/test', (req) => {
      const ch = state.channels.find((c) => c.id === req.params.id)!
      Object.assign(ch, { last_test_at: '2026-10-10T10:00:00Z', last_test_ok: true })
      return { body: { data: { ok: true, response_code: 200, error: null, channel: ch } } }
    })
    .on('GET', '/observability/alert-rules', () => ({ body: { data: state.rules } }))
    .on('POST', '/observability/alert-rules', (req) => {
      const rule = { id: '01JRULE0000000000000000001', state: 'ok', last_value: null, ...req.body }
      state.rules.push(rule)
      return { status: 201, body: { data: rule } }
    })
    .on('GET', '/observability/alert-events', () => ({ body: { data: [] } }))
    .on('GET', '/observability/alert-deliveries', () => ({ body: { data: [], meta: { total: 0 } } }))

  return state
}

test.describe('Observability', () => {
  test.beforeEach(({ e2eMode }) => {
    test.skip(e2eMode === 'stack', 'needs the mocked observability API (no Tempo in the dev stack)')
  })

  test('traces: filters, waterfall, span details and logs of a trace', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('xaas')
    installObservability(mockApi, project.id)
    await auth.signIn()
    await page.goto(`/projects/${project.id}#traces`)

    const tab = page.locator('[data-test="traces-tab"]')
    await expect(tab.locator('[data-trace]')).toHaveCount(2)

    const filtered = mockApi.waitFor('GET', `/projects/${project.id}/traces`)
    await tab.locator('[data-test="filter-status"]').selectOption('error')
    await tab.locator('[data-test="filter-min-duration"]').fill('100')
    await tab.locator('[data-test="filter-path"]').fill('/orders')
    await tab.locator('[data-test="filter-apply"]').click()
    const req = await filtered
    expect(req.query.get('status')).toBe('error')
    expect(req.query.get('min_duration_ms')).toBe('100')
    expect(req.query.get('path')).toBe('/orders')
    await expect(tab.locator('[data-trace]')).toHaveCount(1)

    await tab.locator(`[data-trace="${TRACE_ID}"]`).click()
    await expect(page).toHaveURL(new RegExp(`trace=${TRACE_ID}`))
    const waterfall = page.locator('[data-test="trace-waterfall"]')
    await expect(waterfall.locator('[data-span]')).toHaveCount(3)
    await expect(waterfall.locator('[data-span="bbbbbbbbbbbbbbbb"]')).toHaveAttribute('data-error', 'true')

    await waterfall.locator('[data-span="bbbbbbbbbbbbbbbb"]').click()
    const details = page.locator('[data-test="span-details"]')
    await expect(details).toContainText('url.path')
    await expect(details.locator('[data-test="span-events"]')).toContainText('database unreachable')

    await page.locator('[data-test="load-logs"]').click()
    await expect(page.locator('[data-test="log-line"]')).toContainText(`trace_id=${TRACE_ID}`)

    await page.locator('[data-test="trace-back"]').click()
    await expect(tab.locator('[data-test="trace-list"]')).toBeVisible()
  })

  test('traces: switching tracing applies with the next deployment', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('xaas')
    installObservability(mockApi, project.id)
    await auth.signIn()
    await page.goto(`/projects/${project.id}#traces`)

    const saved = mockApi.waitFor('PUT', `/projects/${project.id}/observability/tracing`)
    await page.locator('[data-test="sample-ratio"]').fill('0.25')
    await page.locator('[data-test="tracing-toggle"]').uncheck()
    expect((await saved).body).toEqual({ enabled: false, sample_ratio: 0.25 })
    await expect(page.getByText(/^Saved — applies with the next deployment/)).toBeVisible()
  })

  test('replay: capture settings, captured request, replay with a header and the diff', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('xaas')
    installObservability(mockApi, project.id)
    await auth.signIn()
    await page.goto(`/projects/${project.id}#replay`)

    const tab = page.locator('[data-test="replay-tab"]')
    await expect(tab.locator('[data-test="replay-privacy"]')).toContainText('personal data')

    const saved = mockApi.waitFor('PUT', `/projects/${project.id}/observability/replay`)
    await tab.locator('[data-test="replay-toggle"]').check()
    await tab.locator('[data-test="replay-redact-headers"]').fill('x-signature, x-tenant-key')
    await tab.locator('[data-test="replay-save"]').click()
    const body = (await saved).body
    expect(body.enabled).toBe(true)
    expect(body.redact_headers).toEqual(['x-signature', 'x-tenant-key'])

    await tab.locator(`[data-capture="${CAPTURE_ID}"]`).click()
    const detail = page.locator('[data-test="capture-detail"]')
    await expect(detail.locator('[data-test="capture-redacted"]')).toContainText('authorization, cookie')

    await detail.locator('[data-test="add-override"]').click()
    await detail.locator('[data-test="override-value-0"]').fill('Bearer fresh-token')
    const replayed = mockApi.waitFor('POST', `/projects/${project.id}/replays/${CAPTURE_ID}/replay`)
    await detail.locator('[data-test="replay-button"]').click()
    expect((await replayed).body).toEqual({ headers: { Authorization: 'Bearer fresh-token' } })

    const diff = page.locator('[data-test="replay-diff"]')
    await expect(diff.locator('[data-test="diff-after"]')).toHaveText('201')
    await expect(diff.locator('[data-test="diff-line"]')).toHaveCount(2)
  })

  test('uptime: a refused check shows the reason, a valid one is listed with its uptime', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('xaas')
    installObservability(mockApi, project.id)
    await auth.signIn()
    await page.goto(`/projects/${project.id}#uptime`)

    const tab = page.locator('[data-test="uptime-tab"]')
    await expect(tab.locator('[data-test="checks-empty"]')).toBeVisible()
    await tab.locator('[data-test="add-check"]').click()
    const form = tab.locator('[data-test="uptime-form"]')
    await form.locator('[data-test="check-name"]').fill('API')
    await form.locator('[data-test="check-url"]').fill('https://intranet.example.com/')
    await form.locator('[data-test="check-submit"]').click()
    await expect(form.locator('[data-test="check-url-error"]')).toContainText('private')

    const created = mockApi.waitFor('POST', `/projects/${project.id}/uptime-checks`)
    await form.locator('[data-test="check-url"]').fill('')
    await form.locator('[data-test="check-path"]').fill('/health')
    await form.locator('[data-test="check-regex"]').fill('ok')
    await form.locator('[data-test="check-status-page"]').check()
    await form.locator('[data-test="check-submit"]').click()
    const sent = (await created).body
    expect(sent).toMatchObject({ name: 'API', url: null, path: '/health', body_regex: 'ok', show_on_status_page: true, locations: 'control_plane' })

    const check = tab.locator('[data-check]')
    await expect(check).toContainText('99.5 %')
    await expect(check.locator('[data-status="up"]')).toBeVisible()
    await expect(check.locator('[data-test="uptime-bars"] [data-day]')).toHaveCount(30)
  })

  test('alerts: a Slack channel with send test, then a rule routed to it', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('xaas')
    installObservability(mockApi, project.id)
    await auth.signIn()
    await page.goto('/settings?tab=alerts')

    const settings = page.locator('[data-test="alerts-settings"]')
    await settings.locator('[data-test="add-channel"]').click()
    const form = settings.locator('[data-test="channel-form"]')
    await form.locator('[data-test="channel-name"]').fill('ops')
    await form.locator('[data-test="channel-type"]').selectOption('slack')
    const created = mockApi.waitFor('POST', '/observability/alert-channels')
    await form.locator('[data-test="channel-webhook"]').fill('https://hooks.slack.com/services/T0/B0/x')
    await form.locator('[data-test="channel-submit"]').click()
    expect((await created).body).toEqual({ name: 'ops', type: 'slack', is_enabled: true, config: { webhook_url: 'https://hooks.slack.com/services/T0/B0/x' } })

    const channel = settings.locator('[data-channel]')
    await expect(channel).toContainText('hooks.slack.com')
    await expect(channel).not.toContainText('services/T0')
    const tested = mockApi.waitFor('POST', '/observability/alert-channels/:id/test')
    await channel.getByRole('button', { name: 'Send test' }).click()
    await tested
    await expect(channel).toContainText('OK')

    await settings.locator('[data-test="add-rule"]').click()
    const editor = settings.locator('[data-test="rule-editor"]')
    await editor.locator('[data-test="rule-name"]').fill('errors')
    await editor.locator('[data-test="rule-type"]').selectOption('error_rate')
    await editor.locator('[data-test="param-threshold"]').fill('5')
    await editor.locator('[data-test^="rule-channel-"]').first().check()
    const rule = mockApi.waitFor('POST', '/observability/alert-rules')
    await editor.locator('[data-test="rule-submit"]').click()
    const ruleBody = (await rule).body
    expect(ruleBody).toMatchObject({ name: 'errors', type: 'error_rate', params: { threshold: 5, window_minutes: 5, min_requests: 20 } })
    expect(ruleBody.channel_ids).toHaveLength(1)
    await expect(settings.locator('[data-rule]')).toContainText('Error rate')
  })

  test('public status page without sign-in', async ({ page, mockApi }) => {
    mockApi.on('GET', '/public/status-pages/acme', () => ({ body: { data: {
      slug: 'acme', title: 'Acme status', description: 'Our services', logo_url: null, status: 'partial_outage', history_days: 30,
      generated_at: '2026-10-10T10:00:00Z', branding: { product_name: 'Acme Cloud', primary_color: '#0ea5e9', powered_by: true },
      components: [
        { id: 'c1', name: 'Public API', status: 'operational', uptime_90d: 99.95, days: Array.from({ length: 30 }, (_, i) => ({ date: `d${i}`, uptime: 100 })) },
        { id: 'c2', name: 'Web', status: 'outage', uptime_90d: 97.1, days: Array.from({ length: 30 }, (_, i) => ({ date: `w${i}`, uptime: i === 29 ? 60 : 100 })) },
      ],
      incidents: [{ id: 'i1', status: 'open', title: 'Web is down', message: 'Investigating', started_at: '2026-10-10T09:50:00Z', resolved_at: null, duration_seconds: 600, component: 'Web' }],
    } } }))
    mockApi.on('GET', '/public/status-pages/nobody', () => ({ status: 404, body: { message: 'Status page not found.' } }))

    await page.goto('/status/acme')
    const status = page.locator('[data-test="public-status-page"]')
    await expect(status.locator('[data-test="status-title"]')).toHaveText('Acme status')
    await expect(status.locator('[data-test="status-overall"]')).toHaveAttribute('data-status', 'partial_outage')
    await expect(status.locator('[data-component="Public API"]')).toContainText('99.95 %')
    await expect(status.locator('[data-test="status-incidents"]')).toContainText('Web is down')
    await expect(status).toContainText('Powered by Acme Cloud')
    await expect(page).toHaveURL(/\/status\/acme$/) // not redirected to the login

    await page.goto('/status/nobody')
    await expect(page.locator('[data-test="status-not-found"]')).toBeVisible()
  })
})
