import { test, expect } from './fixtures/test'
import type { Locator, Page } from '@playwright/test'
import type { Json } from './fixtures/data'
import type { MockApi } from './fixtures/mock-api'
import { openEditStep, saveChangesButton } from './helpers/edit'
import { uniqueName } from './helpers/seed'
import { continueButton } from './helpers/ui'

// Triggers (docs/triggers.md):
//  - the single trigger selector of the project forms (a project without a trigger list)
//  - the trigger list: project "Triggers" tab and step 3 of the Edit Project modal (webhook inbox
//    next to HTTP with its URL, platform events, a topic subscription)
//  - the inbox of a webhook trigger: stored events, detail, replay one / every failed one
//  - Build & Run → Topics: create, subscriptions, the refused delete
// In mock mode an in-memory trigger engine below answers the triggers and topics API. Stack mode
// runs the scenarios that need no failed delivery against the real API.

// ── Mocked trigger engine ─────────────────────────────────────────────────────────────────

const TYPES: Json[] = [
  ['http', 'HTTP', true, false, false, false, false, [], 1],
  ['schedule', 'Schedule', false, true, false, false, false, [], null],
  ['queue', 'Queue', false, true, true, false, false, [], null],
  ['workflow', 'Workflow', true, false, false, true, false, [], 1],
  ['webhook', 'Webhook inbox', false, true, false, false, true, ['signing_secret'], null],
  ['topic', 'Topic', false, true, true, false, false, [], null],
  ['platform_event', 'Platform events', false, true, false, false, true, [], null],
].map(([type, label, serves_http, gateway, requires_redis, exclusive, stores_events, secrets, max_per_project]) => ({
  type, label, serves_http, gateway, requires_redis, exclusive, stores_events, secrets, max_per_project, fields: [],
}))
const PLATFORM_EVENTS = ['deployment.finished', 'deployment.failed', 'alert.fired', 'alert.resolved', 'runtime.patch_available', 'cost.limit_reached']
const DELIVERY = { concurrency: 4, max_retries: 5, retry_backoff_seconds: 2, retry_backoff_max_seconds: 300 }
const NOW = '2026-10-21T10:00:00.000Z'

interface Row {
  id: string
  name: string
  type: string
  enabled: boolean
  position: number
  config: Json
  secret?: string
  key?: string
}

interface EngineState {
  triggers: Row[]
  events: Record<string, Json[]>
  topics: Json[]
  seq: number
}

const meta = (type: string) => TYPES.find(x => x.type === type)!

function present(state: EngineState): { data: Json[]; plan: Json } {
  const rows = [...state.triggers].sort((a, b) => a.position - b.position)
  const enabled = rows.filter(r => r.enabled)
  const cronJob = enabled.length === 1 && enabled[0].type === 'schedule'
  const data = rows.map(r => ({
    id: r.id, name: r.name, type: r.type, label: meta(r.type).label, enabled: r.enabled, position: r.position,
    primary: r === enabled[0],
    runs_on: !r.enabled ? 'disabled' : (r.type === 'schedule' && cronJob ? 'cronjob' : (meta(r.type).serves_http ? 'service' : 'gateway')),
    config: r.config, secrets: r.secret ? ['signing_secret'] : [],
    delivery: meta(r.type).gateway ? { ...DELIVERY, ...(r.config.delivery ?? {}) } : null,
    retention_days: meta(r.type).stores_events ? (r.config.retention_days ?? 7) : null,
    created_at: NOW, updated_at: NOW,
    ...(r.type === 'webhook' ? { url: `https://api.runr.test/api/hooks/central/${r.key}`, has_signing_secret: !!r.secret } : {}),
  }))
  const gateway = enabled.some(r => meta(r.type).gateway) && !cronJob
  const isPublic = enabled.some(r => meta(r.type).serves_http)
  return {
    data,
    plan: {
      workload: cronJob ? 'cronjob' : 'service', public: isPublic, gateway,
      runtime_trigger: cronJob ? 'schedule' : (isPublic ? 'http' : (gateway ? 'queue' : 'http')),
      requires_redis: enabled.some(r => meta(r.type).requires_redis),
    },
  }
}

const invalid = (field: string, message: string) => ({ status: 422, body: { message, errors: { [field]: [message] } } })

/** The triggers API of one project and the topics API on top of the shared fake. */
function installTriggers(mockApi: MockApi, project: Json, rows: Partial<Row>[] = [{ name: 'http', type: 'http' }]): EngineState {
  const state: EngineState = { triggers: [], events: {}, topics: [], seq: 0 }
  // 26 characters like the API's ULIDs
  const nextId = (prefix: string) => `${prefix}${String(++state.seq).padStart(26 - prefix.length, '0')}`
  rows.forEach((r, i) => state.triggers.push({ id: nextId('01JTRG'), enabled: true, position: i, config: {}, ...r } as Row))
  const base = `/projects/${project.id}`
  const find = (key: string) => state.triggers.find(r => r.id === key || r.name === key)
  const renumber = (moved?: Row) => {
    state.triggers.sort((a, b) => a.position - b.position || (a === moved ? -1 : b === moved ? 1 : 0))
    state.triggers.forEach((r, i) => { r.position = i })
  }
  const one = (row: Row) => present(state).data.find(x => x.id === row.id)

  function check(type: string, config: Json, secrets: Json, existing?: Row) {
    if (type === 'webhook' && config.verify !== 'none' && !secrets?.signing_secret && !existing?.secret) {
      return invalid('secrets.signing_secret', 'Signature verification needs a signing secret.')
    }
    if (type === 'topic' && !state.topics.some(tp => tp.name === config.topic)) {
      return invalid('config.topic', `There is no topic "${config.topic}".`)
    }
    if (type === 'platform_event' && !config.events?.length) {
      return invalid('config.events', 'The config.events field is required.')
    }
    return null
  }

  mockApi
    .on('GET', '/trigger-types', () => ({ body: { data: TYPES, platform_events: PLATFORM_EVENTS, webhook_presets: ['none', 'stripe', 'github', 'slack', 'hmac'] } }))
    .on('GET', base, (_, db) => ({ body: { data: { ...db.projects.find(p => p.id === project.id), triggers: present(state).data } } }))
    .on('GET', `${base}/triggers`, () => ({ body: present(state) }))
    .on('POST', `${base}/triggers`, (req) => {
      const b = req.body ?? {}
      // unnamed: named after the type, like the API (TriggerService::uniqueName)
      const name = b.name || b.type
      if (find(name)) return invalid('name', `The project already has a trigger named "${name}".`)
      const refused = check(b.type, b.config ?? {}, b.secrets ?? {})
      if (refused) return refused
      const row: Row = {
        id: nextId('01JTRG'), name, type: b.type, enabled: b.enabled !== false, position: state.triggers.length,
        config: b.config ?? {}, secret: b.secrets?.signing_secret, key: b.type === 'webhook' ? 'k'.repeat(40) : undefined,
      }
      state.triggers.push(row)
      return { status: 201, body: { data: one(row), requires_deploy: true } }
    })
    .on('PATCH', `${base}/triggers/:trigger`, (req) => {
      const row = find(req.params.trigger)
      if (!row) return { status: 404, body: { message: 'Not found.' } }
      const b = req.body ?? {}
      if (b.config) {
        const refused = check(row.type, b.config, b.secrets ?? {}, row)
        if (refused) return refused
        row.config = b.config
      }
      if (b.secrets?.signing_secret) row.secret = b.secrets.signing_secret
      if (typeof b.enabled === 'boolean') row.enabled = b.enabled
      if (b.name) row.name = b.name
      if (typeof b.position === 'number') { row.position = b.position - 0.5; renumber(row) }
      return { body: { data: one(row), requires_deploy: true } }
    })
    .on('DELETE', `${base}/triggers/:trigger`, (req) => {
      if (state.triggers.length === 1) return { status: 422, body: { message: 'A project needs at least one trigger; disable it instead of removing it.' } }
      state.triggers = state.triggers.filter(r => r !== find(req.params.trigger))
      renumber()
      return { body: { requires_deploy: true } }
    })
    .on('GET', `${base}/triggers/:trigger/events`, (req) => {
      const row = find(req.params.trigger)!
      const status = req.query.get('status')
      const list = (state.events[row.id] ?? []).filter(e => !status || e.status === status)
      return { body: { data: list.map(({ headers, body_base64, body_text, ...summary }) => summary), meta: { current_page: 1, last_page: 1, per_page: 25, total: list.length } } }
    })
    .on('POST', `${base}/triggers/:trigger/events/replay`, (req) => {
      const row = find(req.params.trigger)!
      const failed = (state.events[row.id] ?? []).filter(e => e.status === (req.body?.status ?? 'failed'))
      failed.forEach(e => Object.assign(e, { status: 'pending', attempts: 0, replays: e.replays + 1, last_error: null }))
      return { body: { replayed: failed.length } }
    })
    .on('GET', `${base}/triggers/:trigger/events/:event`, (req) => {
      const event = (state.events[find(req.params.trigger)!.id] ?? []).find(e => e.id === req.params.event)
      return event ? { body: { data: event } } : { status: 404, body: { message: 'Not found.' } }
    })
    .on('POST', `${base}/triggers/:trigger/events/:event/replay`, (req) => {
      const event = (state.events[find(req.params.trigger)!.id] ?? []).find(e => e.id === req.params.event)!
      Object.assign(event, { status: 'pending', attempts: 0, replays: event.replays + 1, last_error: null, delivered_at: null })
      const { headers, body_base64, body_text, ...summary } = event
      return { body: { data: summary } }
    })
    // Topics of the organization
    .on('GET', '/topics', () => ({ body: { data: state.topics.map(tp => ({ ...tp, subscriptions: subscriptionsOf(tp).length })) } }))
    .on('POST', '/topics', (req, db) => {
      const region = db.regions.find(r => r.id === req.body?.knative_region_id)
      if (!region?.has_redis) return { status: 422, body: { message: 'Topics need Redis on the region.' } }
      const topic = {
        id: nextId('01JTOP'), name: req.body.name, description: req.body.description ?? null, knative_region_id: region.id,
        region_name: region.name, stream: `runr.topic.${req.body.name}`, created_by: '1', created_at: NOW,
      }
      state.topics.push(topic)
      return { status: 201, body: { data: { ...topic, subscriptions: 0 } } }
    })
    .on('GET', '/topics/:topic', (req) => {
      const topic = state.topics.find(tp => tp.id === req.params.topic || tp.name === req.params.topic)
      return topic ? { body: { data: { ...topic, subscriptions: subscriptionsOf(topic) } } } : { status: 404, body: { message: 'Not found.' } }
    })
    .on('DELETE', '/topics/:topic', (req) => {
      const topic = state.topics.find(tp => tp.id === req.params.topic || tp.name === req.params.topic)!
      const subs = subscriptionsOf(topic)
      if (subs.length) {
        return { status: 409, body: { message: `${subs.length} trigger(s) subscribe to this topic; remove them first.`, subscriptions: subs.map(s => ({ project_id: s.project_id, trigger: s.trigger })) } }
      }
      state.topics = state.topics.filter(tp => tp !== topic)
      return { status: 204 }
    })
    .on('POST', '/topics/:topic/publish', () => ({ status: 202, body: { data: { id: 'evt-1', stream_id: '1-0' } } }))

  function subscriptionsOf(topic: Json): Json[] {
    return state.triggers.filter(r => r.type === 'topic' && r.config.topic === topic.name).map(r => ({
      trigger_id: r.id, trigger: r.name, enabled: r.enabled, project_id: project.id, project_name: project.name, start_from: r.config.start_from ?? 'latest',
    }))
  }

  return state
}

/** A stored webhook delivery (TriggerEvent detail). */
function webhookEvent(id: string, status: string, extra: Json = {}): Json {
  const body = JSON.stringify({ action: 'opened', number: 42 })
  return {
    id, trigger_id: 't', kind: 'webhook', type: 'dev.runr.webhook.received', subject: 'pull_request', delivery_id: `gh-${id}`,
    content_type: 'application/json', size: body.length, status, attempts: status === 'failed' ? 6 : 1, replays: 0,
    last_error: status === 'failed' ? 'HTTP 500 from the function' : null, received_at: NOW,
    delivered_at: status === 'delivered' ? NOW : null, expires_at: '2026-10-28T10:00:00.000Z',
    headers: { 'Content-Type': 'application/json', 'X-GitHub-Event': 'pull_request' },
    body_base64: Buffer.from(body).toString('base64'), body_text: body, ...extra,
  }
}

const triggersTab = (page: Page) => page.locator('[data-test="triggers-tab"]')
const editor = (page: Page) => page.locator('[data-test="trigger-editor"]')
const confirmButton = (page: Page, name: string) => page.locator('div[style*="z-index: 70"]').getByRole('button', { name })

async function addTrigger(page: Page, scope: Locator, type: string): Promise<void> {
  await scope.locator('[data-action="add"]').click()
  await scope.locator(`[data-test="type-picker"] [data-type="${type}"]`).click()
  await expect(editor(page)).toBeVisible()
}

// ── Single trigger selector (projects without a trigger list) ───────────────────────────────

test.describe('Triggers: single trigger selector', () => {
  test.beforeEach(async ({ seed, isStack }) => {
    test.skip(isStack, 'the API lists the triggers of every project: the stack edits the trigger list')
    test.skip(!(await seed.readyRegion()), 'the stack has no ready region to seed a project in')
  })

  test('switches an HTTP project to a cron schedule', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('xaas')
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)
    const modal = await openEditStep(page, 3)
    const trigger = modal.locator('[data-tour="edit-trigger"]')

    await trigger.getByRole('button', { name: 'Schedule', exact: true }).click()
    await expect(modal.getByText('Cron expression is required')).toBeVisible()
    await expect(continueButton(modal)).toBeDisabled()

    const cron = modal.getByPlaceholder('0 * * * *')
    await cron.fill('0 0 * * * *')
    await expect(modal.getByText(/^Use 5 fields/)).toBeVisible()
    await expect(continueButton(modal)).toBeDisabled()

    await cron.fill('*/15 * * * *')
    await expect(continueButton(modal)).toBeEnabled()
    await continueButton(modal).click()

    // Scheduled projects run one pod: the scale editor is gone
    await expect(modal.locator('[data-tour="edit-resources"]')).toBeVisible()
    await expect(modal.getByText('Zero Scale')).toBeHidden()

    const sent = mockApi.waitFor('PUT', `/projects/${project.id}`)
    await saveChangesButton(modal).click()
    expect((await sent).body.config).toMatchObject({
      trigger_type: 'schedule', schedule: '*/15 * * * *', scale_to_zero: true, min_scale: 0, max_scale: 1,
    })
    await expect(modal).toBeHidden()
  })

  test('configures a queue trigger with its stream name', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('xaas')
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)
    const modal = await openEditStep(page, 3)

    await modal.locator('[data-tour="edit-trigger"]').getByRole('button', { name: 'Queue', exact: true }).click()
    const stream = modal.getByPlaceholder('my-events-stream')
    await stream.fill('')
    await expect(modal.getByText('Queue/Stream name is required')).toBeVisible()
    await stream.fill('orders-events')
    await continueButton(modal).click()

    const sent = mockApi.waitFor('PUT', `/projects/${project.id}`)
    await saveChangesButton(modal).click()
    expect((await sent).body.config).toMatchObject({ trigger_type: 'queue', queue_stream: 'orders-events' })
    await expect(modal).toBeHidden()
  })

  test('offers only the schedule trigger on a region without Knative Serving', async ({ page, auth, seed }) => {
    const project = await seed.project('xaas', {
      knative_region_id: 'r-cron',
      endpoint_url: null,
      config: { trigger_type: 'schedule', schedule: '0 * * * *' },
    })
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)
    const modal = await openEditStep(page, 3)
    const trigger = modal.locator('[data-tour="edit-trigger"]')

    await expect(trigger.getByRole('button', { name: 'Endpoint', exact: true })).toBeDisabled()
    await expect(trigger.getByRole('button', { name: 'Queue', exact: true })).toBeDisabled()
    await expect(modal.getByPlaceholder('0 * * * *')).toHaveValue('0 * * * *')
  })
})

// ── Trigger list ─────────────────────────────────────────────────────────────────────────

test.describe('Triggers: trigger list', () => {
  test.beforeEach(async ({ seed }) => {
    test.skip(!(await seed.readyRegion()), 'the stack has no ready region to seed a project in')
  })

  test('adds a webhook inbox next to HTTP and shows its URL', async ({ page, auth, seed, mockApi, isStack }) => {
    const project = await seed.project('xaas')
    if (!isStack) installTriggers(mockApi, project)
    await auth.signIn()
    await page.goto(`/projects/${project.id}#triggers`)

    const tab = triggersTab(page)
    await expect(tab.locator('[data-trigger="http"] [data-test="primary-badge"]')).toBeVisible()

    await addTrigger(page, tab, 'webhook')
    const form = editor(page)
    await form.locator('[data-field="name"]').fill('hooks')
    await form.locator('[data-field="verify"]').selectOption('github')
    // verification without a secret is refused before the request
    await form.locator('[data-test="trigger-save"]').click()
    await expect(form).toContainText('Signature verification needs a signing secret.')

    await form.locator('[data-field="signing_secret"]').fill('e2e-webhook-secret')
    const sent = isStack ? null : mockApi.waitFor('POST', `/projects/${project.id}/triggers`)
    await form.locator('[data-test="trigger-save"]').click()
    if (sent) {
      expect((await sent).body).toEqual({
        type: 'webhook', name: 'hooks', enabled: true, config: { verify: 'github' }, secrets: { signing_secret: 'e2e-webhook-secret' },
      })
    }
    await expect(form).toBeHidden()

    const row = tab.locator('[data-trigger="hooks"]')
    await expect(row).toContainText('Webhook inbox')
    await expect(row.locator('[data-test="runs-on"]')).toHaveText('Event gateway')
    await expect(row.locator('[data-test="trigger-url"]')).toHaveText(/\/api\/hooks\/[^/]+\/[a-z0-9]{40}$/)
    await expect(tab.locator('[data-test="requires-deploy"]')).toBeVisible()
    // HTTP stays the primary trigger
    await expect(tab.locator('[data-trigger="http"] [data-test="primary-badge"]')).toBeVisible()

    // the stored secret is never shown, only that it is set
    await row.locator('[data-action="edit"]').click()
    await expect(editor(page).locator('[data-test="secret-state"]')).toHaveText('set')
    await expect(editor(page).locator('[data-field="signing_secret"]')).toHaveValue('')
    await expect(editor(page).locator('[data-test="webhook-url"]')).toHaveText(/\/api\/hooks\//)
  })

  test('opens the inbox of a webhook trigger and replays failed events', async ({ page, auth, seed, mockApi, isStack }) => {
    test.skip(isStack, 'needs stored failed deliveries: the mocked trigger engine has them')
    const project = await seed.project('xaas')
    const state = installTriggers(mockApi, project, [
      { name: 'http', type: 'http' },
      { name: 'hooks', type: 'webhook', config: { verify: 'github' }, secret: 's3cret-value', key: 'a'.repeat(40) },
    ])
    const hooks = state.triggers[1]
    state.events[hooks.id] = [
      webhookEvent('01JEVT0000000000000000000A', 'delivered'),
      webhookEvent('01JEVT0000000000000000000B', 'failed'),
      webhookEvent('01JEVT0000000000000000000C', 'failed', { body_text: null, content_type: 'application/octet-stream' }),
    ]
    await auth.signIn()
    await page.goto(`/projects/${project.id}#triggers`)

    await triggersTab(page).locator('[data-trigger="hooks"] [data-action="inbox"]').click()
    const inbox = page.locator('[data-test="trigger-inbox"]')
    await expect(inbox).toContainText('Inbox of "hooks"')
    await expect(inbox.locator('[data-test="inbox-url"]')).toHaveText(/\/api\/hooks\//)
    await expect(inbox.locator('[data-event]')).toHaveCount(3)

    await inbox.locator('[data-field="status"]').selectOption('failed')
    await expect(inbox.locator('[data-event]')).toHaveCount(2)
    expect(mockApi.calls('GET', `/projects/${project.id}/triggers/${hooks.id}/events`).map(r => r.query.get('status'))).toContain('failed')

    // detail: headers, the JSON body, the last error
    await inbox.locator('[data-event="01JEVT0000000000000000000B"]').click()
    const detail = inbox.locator('[data-test="event-detail"]')
    await expect(detail.locator('[data-test="detail-headers"]')).toContainText('X-GitHub-Event: pull_request')
    await expect(detail.locator('[data-test="detail-body"]')).toContainText('"number": 42')
    await expect(detail.locator('[data-test="detail-error"]')).toHaveText('HTTP 500 from the function')
    await detail.locator('[data-action="body-base64"]').click()
    await expect(detail.locator('[data-test="detail-body"]')).toHaveText(Buffer.from(JSON.stringify({ action: 'opened', number: 42 })).toString('base64'))

    const replayed = mockApi.waitFor('POST', `/projects/${project.id}/triggers/${hooks.id}/events/01JEVT0000000000000000000B/replay`)
    await detail.locator('[data-action="replay"]').click()
    await replayed
    await expect(detail.locator('[data-test="detail-status"]')).toHaveText('Pending')
    await expect(page.getByText('The event is queued for delivery again.')).toBeVisible()

    // a binary body is shown as base64
    await inbox.locator('[data-event="01JEVT0000000000000000000C"]').click()
    await expect(detail).toContainText('shown as base64')

    // every remaining failed event
    const many = mockApi.waitFor('POST', `/projects/${project.id}/triggers/${hooks.id}/events/replay`)
    await inbox.locator('[data-action="replay-failed"]').click()
    await confirmButton(page, 'Replay failed').click()
    expect((await many).body).toEqual({ status: 'failed' })
    await expect(page.getByText('1 events queued for delivery again.')).toBeVisible()

    await inbox.locator('[data-action="back"]').click()
    await expect(triggersTab(page).locator('[data-trigger="hooks"]')).toBeVisible()
  })

  test('configures a platform event trigger', async ({ page, auth, seed, mockApi, isStack }) => {
    const project = await seed.project('xaas')
    if (!isStack) installTriggers(mockApi, project)
    await auth.signIn()
    await page.goto(`/projects/${project.id}#triggers`)
    const tab = triggersTab(page)

    await addTrigger(page, tab, 'platform_event')
    const form = editor(page)
    const fields = form.locator('[data-test="platform-event-fields"]')

    // "All events" covers every event
    await fields.locator('[data-field="events.all"]').check()
    await expect(fields.locator('[data-event="deployment.failed"]')).toBeDisabled()
    await fields.locator('[data-field="events.all"]').uncheck()

    await form.locator('[data-test="trigger-save"]').click()
    await expect(form).toContainText('Choose at least one event.')

    await fields.locator('[data-event="deployment.failed"]').check()
    await fields.locator('[data-event="alert.fired"]').check()
    await fields.locator(`[data-project="${project.id}"]`).check()
    await fields.locator('[data-field="retention_days"]').fill('14')
    // delivery settings of the event gateway
    await form.locator('[data-test="trigger-delivery"] button').first().click()
    await form.locator('[data-field="delivery.max_retries"]').fill('3')

    const sent = isStack ? null : mockApi.waitFor('POST', `/projects/${project.id}/triggers`)
    await form.locator('[data-test="trigger-save"]').click()
    if (sent) {
      expect((await sent).body).toEqual({
        type: 'platform_event', enabled: true,
        config: { events: ['deployment.failed', 'alert.fired'], projects: [project.id], retention_days: 14, delivery: { max_retries: 3 } },
      })
    }
    await expect(form).toBeHidden()

    const row = tab.locator('[data-trigger-type="platform_event"]')
    await expect(row.locator('[data-test="trigger-summary"]')).toContainText('deployment.failed, alert.fired')
    await expect(row.locator('[data-test="trigger-summary"]')).toContainText('1 projects')
  })

  test('edits the trigger list in the Edit Project modal', async ({ page, auth, seed, mockApi, isStack }) => {
    const project = await seed.project('xaas')
    if (!isStack) installTriggers(mockApi, project)
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)
    const modal = await openEditStep(page, 3)
    const list = modal.locator('[data-test="trigger-list-config"]')
    await expect(list.locator('[data-trigger="http"]')).toBeVisible()

    await addTrigger(page, list, 'schedule')
    await editor(page).locator('[data-field="name"]').fill('nightly')
    await editor(page).locator('[data-field="cron"]').fill('0 3 * * *')
    await editor(page).locator('[data-test="trigger-save"]').click()
    await expect(list.locator('[data-trigger="nightly"]')).toContainText('0 3 * * *')
    // next to HTTP the schedule is a source of the event gateway, not the CronJob
    await expect(list.locator('[data-trigger="nightly"] [data-test="runs-on"]')).toHaveText('Event gateway')
    await expect(list.locator('[data-test="requires-deploy"]')).toBeVisible()

    await continueButton(modal).click()
    // still a service: the scale editor stays
    await expect(modal.getByText('Zero Scale')).toBeVisible()
    // the list is saved already; a version note makes the rest of the form worth saving
    await modal.getByPlaceholder('Image updated…').fill('e2e: triggers')
    const sent = isStack ? null : mockApi.waitFor('PUT', `/projects/${project.id}`)
    // stack: the save is answered before the test ends (the cleanup deletes the project)
    const saved = isStack
      ? page.waitForResponse(r => r.request().method() === 'PUT' && new URL(r.url()).pathname === `/api/projects/${project.id}`)
      : null
    await saveChangesButton(modal).click()
    if (sent) {
      const config = (await sent).body.config
      expect(config).not.toHaveProperty('trigger_type')
      expect(config).not.toHaveProperty('schedule')
    }
    if (saved) expect((await saved).status()).toBe(200)
    await expect(modal).toBeHidden()
  })

  test('creates a topic and subscribes a project to it', async ({ page, auth, seed, mockApi, isStack }) => {
    const region = await seed.readyRegion()
    test.skip(!(region?.has_redis || region?.is_demo), 'topics need a region with Redis')
    const name = uniqueName('e2e-orders').replace(/-/g, '.')
    // stack: registered before the project, so the project (and its subscription) goes first
    if (isStack) seed.onCleanup(() => seed.call('delete', `/api/topics/${encodeURIComponent(name)}`).catch(() => {}))
    const project = await seed.project('xaas')
    if (!isStack) installTriggers(mockApi, project)
    await auth.signIn()

    // Build & Run → Topics: create
    await page.goto('/settings?tab=topics')
    const view = page.locator('[data-test="topics-view"]')
    await view.locator('[data-action="create-topic"]').click()
    const create = page.locator('[data-test="create-topic-modal"]')
    await create.locator('[data-field="name"]').fill('bad name')
    await expect(create).toContainText('starting with a letter or digit')
    await create.locator('[data-field="name"]').fill(name)
    await create.locator('[data-field="region"]').selectOption(project.knative_region_id)
    await create.locator('[data-test="create-topic-submit"]').click()
    await expect(create).toBeHidden()
    const detail = view.locator('[data-test="topic-detail"]')
    await expect(detail).toContainText(name)
    await expect(detail.locator('[data-test="no-subscriptions"]')).toBeVisible()

    // publish a test event
    await detail.locator('[data-action="publish"]').click()
    const publish = page.locator('[data-test="publish-topic-modal"]')
    await publish.locator('[data-field="data"]').fill('{"order_id": 7')
    await expect(publish.locator('[data-test="publish-submit"]')).toBeDisabled()
    await publish.locator('[data-field="data"]').fill('{"order_id": 7}')
    await publish.locator('[data-field="type"]').fill('order.created')
    const published = isStack ? null : mockApi.waitFor('POST', `/topics/${name}/publish`)
    await publish.locator('[data-test="publish-submit"]').click()
    if (published) expect((await published).body).toEqual({ data: { order_id: 7 }, type: 'order.created' })
    await expect(publish).toBeHidden()

    // the project subscribes with a topic trigger
    await page.goto(`/projects/${project.id}#triggers`)
    const tab = triggersTab(page)
    await addTrigger(page, tab, 'topic')
    await editor(page).locator('[data-field="topic"]').selectOption(name)
    await editor(page).locator('[data-test="trigger-save"]').click()
    await expect(tab.locator('[data-trigger-type="topic"]')).toContainText(name)

    // the topic lists the subscription and refuses to be deleted
    await page.goto('/settings?tab=topics')
    await view.locator(`[data-topic="${name}"]`).click()
    await expect(detail.locator(`[data-subscription="${project.name}"]`)).toBeVisible()
    await detail.locator('[data-action="delete-topic"]').click()
    await confirmButton(page, 'Delete').click()
    await expect(detail.locator('[data-test="topic-in-use"]')).toContainText('remove them first')
  })
})
