import { test, expect } from './fixtures/test'
import { DEPLOY_STRATEGY_DEFAULTS, type MockApi } from './fixtures/mock-api'
import { openDeployModal } from './helpers/deploy'
import { openEditStep } from './helpers/edit'
import { confirmDialog } from './helpers/ui'

// Deploy strategies per function (direct, blue-green, canary): the settings (Deployments tab →
// "Deploy strategy", also step 4 of the edit modal; their own endpoint, no version), the deploy
// modal's one-off override and the live rollout panel with promote / abort / roll back. A rollout
// needs a Kubernetes region with Knative, so every endpoint is answered by the in-memory fakes
// below (mocked API only).

test.skip(({ e2eMode }) => e2eMode === 'stack', 'rollouts need a Kubernetes region with Knative; covered with the mocked API')

type Json = Record<string, any>

const at = (minutes: number) => new Date(Date.now() + minutes * 60_000).toISOString()
const now = () => new Date().toISOString()
const ELIGIBLE = ['http', 'workflow']

// ── Fakes ─────────────────────────────────────────────────────────────────────

/** GET / PUT /projects/:id/deploy-strategy of one project, validated like the API. */
function installStrategy(mockApi: MockApi, projectId: string, { tracing = true, settings = {} as Json } = {}): { settings: Json } {
  const state = { settings: { ...structuredClone(DEPLOY_STRATEGY_DEFAULTS), ...settings } }
  const describe = (db: MockApi['db']) => {
    const trigger = db.projects.find(p => p.id === projectId)?.config?.trigger_type ?? 'http'
    return {
      ...structuredClone(state.settings), eligible: ELIGIBLE.includes(trigger), trigger_type: trigger, tracing,
      defaults: structuredClone(DEPLOY_STRATEGY_DEFAULTS),
    }
  }

  mockApi
    .on('GET', `/projects/${projectId}/deploy-strategy`, (_, db) => ({ body: { data: describe(db) } }))
    .on('PUT', `/projects/${projectId}/deploy-strategy`, (req, db) => {
      const body = req.body ?? {}
      const strategy = String(body.strategy ?? state.settings.strategy).replace('-', '_')
      const errors: Record<string, string[]> = {}
      if (strategy !== 'direct' && !describe(db).eligible) errors.strategy = ['Queue and schedule functions always deploy directly.']
      const steps = body.canary?.steps
      if (Array.isArray(steps) && (steps.length < 1 || steps.length > 10 || steps.some((s: number, i: number) => s < 1 || s > 100 || (i > 0 && s <= steps[i - 1])))) {
        errors['canary.steps'] = ['The canary steps must be 1 to 10 strictly increasing percentages.']
      }
      if (Object.keys(errors).length) return { status: 422, body: { message: Object.values(errors)[0][0], errors } }

      const next: Json = { ...state.settings, strategy }
      for (const group of ['blue_green', 'canary']) if (body[group]) next[group] = { ...state.settings[group], ...body[group] }
      state.settings = next
      const project = db.projects.find(p => p.id === projectId)!
      project.deploy_strategy = strategy
      return { body: { data: describe(db) } }
    })
  return state
}

/**
 * A rollout under way: the project's v1 deployment serves ("stable"), a v2 deployment is the new
 * one ("candidate") that carries the rollout. Returns both rows of mockApi.db.deployments.
 */
function seedRollout(mockApi: MockApi, project: Json, over: Json = {}): { candidate: Json; stable: Json } {
  const db = mockApi.db
  const v1 = db.versions[project.id][0]
  const v2 = { id: mockApi.nextId('v'), label: 'v2', commit_message: 'New checkout', build_status: 'ready', created_at: at(-10) }
  db.versions[project.id].unshift(v2)
  const row = (id: string, version: Json, extra: Json) => ({
    id, version: { id: version.id, label: version.label }, version_id: version.id, k8s_job_name: project.slug,
    deployed_by: db.user.name, ...extra,
  })
  const stable = row(mockApi.nextId('d'), v1, { status: 'active', created_at: at(-60), rollout: null, rollout_role: 'stable' })
  const candidate = row(mockApi.nextId('d'), v2, { status: 'running', created_at: at(-5), rollout_role: 'candidate' })
  candidate.rollout = {
    id: '01JRE2E0000000000000000001', project_id: project.id, deployment_id: candidate.id, stable_deployment_id: stable.id,
    strategy: 'canary', status: 'progressing', open: true, traffic: { candidate: 10, stable: 90 },
    revision: `${project.slug}-v2-x7k2pq`, stable_revision: `${project.slug}-00001`, tag: 'canary',
    test_url: `https://canary-${project.slug}.eu-1.runr.test`, previous_url: null,
    options: { steps: [10, 50, 100], interval_minutes: 5, error_rate_threshold: 5, p95_threshold_ms: 800 },
    step: { index: 0, count: 3, percent: 10, next_percent: 50, started_at: at(-1), ends_at: at(4) },
    health: null,
    metrics: { tracing: true, error_rate: 0.4, p95_ms: 120.5, volume: 312, window_minutes: 3, measured_at: at(0), error_rate_threshold: 5, p95_threshold_ms: 800 },
    keep_until: null, promoted_at: null, finished_at: null, released_at: null, next_check_at: at(1), reason: null,
    events: [
      { at: at(-5), type: 'created', message: 'Rollout started', traffic: 0, actor: db.user.username },
      { at: at(-1), type: 'ready', message: 'New revision ready', traffic: 10, actor: 'system' },
    ],
    can: { promote: true, abort: true, rollback: false },
    created_by: db.user.username, created_at: at(-5), updated_at: at(-1),
    ...over,
  }
  db.deployments[project.id] = [candidate, stable]
  return { candidate, stable }
}

/** A blue-green rollout of v2: ready, no traffic yet, waiting for a manual promotion. */
const BLUE_GREEN_WAITING = (slug: string): Json => ({
  strategy: 'blue_green', status: 'waiting_promotion', traffic: { candidate: 0, stable: 100 }, tag: 'green',
  test_url: `https://green-${slug}.eu-1.runr.test`, step: null, metrics: null,
  options: { auto_promote: false, health_path: null, health_checks: 3, keep_previous_minutes: 15 },
})

/** The rollout endpoints of the project, moving the seeded rollout and deployments like the API. */
function installRollout(mockApi: MockApi, project: Json, candidate: Json, stable: Json): void {
  const base = `/projects/${project.id}`
  const actor = mockApi.db.user.username
  const update = (changes: Json, event: Json) => {
    candidate.rollout = { ...candidate.rollout, ...changes, updated_at: now(), events: [...candidate.rollout.events, { at: now(), actor, ...event }] }
    return { body: { data: candidate.rollout } }
  }
  const refused = (code: string) => ({ status: 409, body: { message: 'The rollout cannot do that any more.', code } })

  mockApi
    .on('GET', `${base}/rollout`, () => ({ body: { data: candidate.rollout } }))
    .on('GET', `${base}/deployments/:did/rollout`, (req) => (req.params.did === candidate.id
      ? { body: { data: candidate.rollout } }
      : { status: 404, body: { message: 'Not found.' } }))
    .on('POST', `${base}/deployments/:did/rollout/promote`, () => {
      const r = candidate.rollout
      if (!r.can.promote) return refused('rollout_not_promotable')
      const keep = r.strategy === 'blue_green' ? Number(r.options.keep_previous_minutes ?? 0) : 0
      candidate.status = 'active'
      candidate.rollout_role = keep ? 'candidate' : null
      stable.status = keep ? 'running' : 'shutting_down'
      stable.rollout_role = keep ? 'previous' : null
      return update({
        status: 'promoted', open: false, traffic: { candidate: 100, stable: 0 }, step: null, test_url: null, promoted_at: now(),
        keep_until: keep ? at(keep) : null, released_at: keep ? null : now(),
        previous_url: keep ? `https://previous-${project.slug}.eu-1.runr.test` : null,
        can: { promote: false, abort: false, rollback: keep > 0 },
      }, { type: 'promoted', message: `Promoted by ${actor}`, traffic: 100 })
    })
    .on('POST', `${base}/deployments/:did/rollout/abort`, (req) => {
      if (!candidate.rollout.can.abort) return refused('rollout_not_abortable')
      candidate.status = 'shutting_down'
      candidate.rollout_role = null
      stable.rollout_role = null
      return update({
        status: 'aborted', open: false, traffic: { candidate: 0, stable: 100 }, step: null, test_url: null, finished_at: now(),
        reason: req.body?.reason ?? `Aborted by ${actor}`, can: { promote: false, abort: false, rollback: false },
      }, { type: 'aborted', message: `Aborted by ${actor}`, traffic: 0 })
    })
    .on('POST', `${base}/deployments/:did/rollout/rollback`, () => {
      if (!candidate.rollout.can.rollback) return refused('rollout_not_rollbackable')
      candidate.status = 'shutting_down'
      candidate.rollout_role = null
      stable.status = 'active'
      stable.rollout_role = null
      return update({
        status: 'rolled_back', traffic: { candidate: 0, stable: 100 }, finished_at: now(), previous_url: null,
        reason: `Rolled back by ${actor}`, can: { promote: false, abort: false, rollback: false },
      }, { type: 'rolled_back', message: `Rolled back by ${actor}`, traffic: 0 })
    })
}

// ── Specs ─────────────────────────────────────────────────────────────────────

test.describe('Deploy strategies', () => {
  test('saves blue-green with its options; they are shown again, in the edit modal too', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('xaas')
    installStrategy(mockApi, project.id)
    await auth.signIn()
    await page.goto(`/projects/${project.id}#deployments`)

    const open = page.locator('[data-test="deploy-strategy-open"]')
    await expect(open).toHaveText('Deploy strategy: Direct')
    await open.click()
    const modal = page.locator('[data-test="deploy-strategy-modal"]')
    await expect(modal.locator('[data-test="deploy-strategy-direct"]')).toHaveAttribute('aria-checked', 'true')

    await modal.locator('[data-test="deploy-strategy-blue_green"]').click()
    await modal.getByRole('switch', { name: 'Promote automatically' }).click()
    await modal.locator('[data-test="blue-green-health-path"]').fill('/healthz')
    await modal.locator('[data-test="blue-green-health-checks"]').fill('5')
    await modal.locator('[data-test="blue-green-keep-previous"]').fill('30')

    const sent = mockApi.waitFor('PUT', `/projects/${project.id}/deploy-strategy`)
    await modal.getByRole('button', { name: 'Save strategy' }).click()
    expect((await sent).body).toEqual({
      strategy: 'blue_green',
      blue_green: { auto_promote: true, health_path: '/healthz', health_checks: 5, keep_previous_minutes: 30 },
    })
    await expect(page.getByText('Deploy strategy saved: Blue-green. It applies from the next deploy.')).toBeVisible()
    // its own endpoint: the project config (and its versions) stay as they are
    expect(mockApi.calls('PUT', `/projects/${project.id}`)).toHaveLength(0)

    await modal.getByTitle('Close').click()
    await expect(modal).toBeHidden()
    await expect(open).toHaveText('Deploy strategy: Blue-green')
    await expect(page.locator('[data-test="project-strategy-badge"]')).toHaveText('Blue-green')

    await page.reload()
    await page.locator('[data-test="deploy-strategy-open"]').click()
    await expect(modal.locator('[data-test="deploy-strategy-blue_green"]')).toHaveAttribute('aria-checked', 'true')
    await expect(modal.getByRole('switch', { name: 'Promote automatically' })).toHaveAttribute('aria-checked', 'true')
    await expect(modal.locator('[data-test="blue-green-health-path"]')).toHaveValue('/healthz')
    await expect(modal.locator('[data-test="blue-green-health-checks"]')).toHaveValue('5')
    await expect(modal.locator('[data-test="blue-green-keep-previous"]')).toHaveValue('30')
    await modal.getByTitle('Close').click()

    const edit = await openEditStep(page, 4)
    const section = edit.locator('[data-test="deploy-strategy-settings"]')
    await expect(section.locator('[data-test="deploy-strategy-blue_green"]')).toHaveAttribute('aria-checked', 'true')
    await expect(section.locator('[data-test="blue-green-health-path"]')).toHaveValue('/healthz')
  })

  test('refuses an invalid canary step list: before sending, and with the API\'s 422', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('xaas')
    installStrategy(mockApi, project.id)
    await auth.signIn()
    await page.goto(`/projects/${project.id}#deployments`)
    await page.locator('[data-test="deploy-strategy-open"]').click()
    const modal = page.locator('[data-test="deploy-strategy-modal"]')
    await modal.locator('[data-test="deploy-strategy-canary"]').click()
    const save = modal.getByRole('button', { name: 'Save strategy' })

    const step = modal.locator('[data-test="canary-step-1"]')
    await step.fill('5')
    await expect(modal.locator('[data-test="error-canary.steps"]')).toHaveText('Each step must be larger than the one before.')
    await expect(save).toBeDisabled()
    expect(mockApi.calls('PUT', `/projects/${project.id}/deploy-strategy`)).toHaveLength(0)

    // a rule only the API knows (e.g. a newer API's limit): its 422 lands next to the steps
    await step.fill('50')
    await modal.locator('[data-test="canary-add-step"]').click()
    await expect(save).toBeEnabled()
    mockApi.on('PUT', `/projects/${project.id}/deploy-strategy`, () => ({
      status: 422,
      body: {
        message: 'The canary.steps field must not have more than 3 items.',
        errors: { 'canary.steps': ['The canary.steps field must not have more than 3 items.'] },
      },
    }))
    const sent = mockApi.waitFor('PUT', `/projects/${project.id}/deploy-strategy`)
    await save.click()
    expect((await sent).body.canary.steps).toEqual([10, 50, 75, 100])
    await expect(modal.locator('[data-test="error-canary.steps"]')).toHaveText('The canary.steps field must not have more than 3 items.')
    await expect(page.locator('[data-test="deploy-strategy-open"]')).toHaveText('Deploy strategy: Direct')

    // the next change clears the API's message
    await modal.locator('[data-test="canary-remove-step-2"]').click()
    await expect(modal.locator('[data-test="error-canary.steps"]')).toHaveCount(0)
  })

  test('a queue function cannot use a strategy', async ({ page, auth, seed }) => {
    const project = await seed.project('xaas', { endpoint_url: null, config: { trigger_type: 'queue', queue_stream: 'orders-events' } })
    await auth.signIn()
    await page.goto(`/projects/${project.id}#deployments`)
    const open = page.locator('[data-test="deploy-strategy-open"]')
    await expect(open).toHaveText('Deploy strategy: Direct')
    await open.click()

    const modal = page.locator('[data-test="deploy-strategy-modal"]')
    await expect(modal.locator('[data-test="deploy-strategy-ineligible"]')).toContainText('This function has the Queue trigger, so it always deploys directly.')
    await expect(modal.locator('[data-test="deploy-strategy-blue_green"]')).toBeDisabled()
    await expect(modal.locator('[data-test="deploy-strategy-canary"]')).toBeDisabled()
    await expect(modal.getByRole('button', { name: 'Save strategy' })).toHaveCount(0)
  })

  test('deploys one version directly although the project uses canary', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('xaas', { deploy_strategy: 'canary' })
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)
    await expect(page.locator('[data-test="project-strategy-badge"]')).toHaveText('Canary')

    const modal = await openDeployModal(page)
    const choice = modal.locator('[data-test="deploy-strategy-choice"]')
    await expect(choice).toContainText('Rolls out as: Canary')
    await modal.locator('[data-test="deploy-strategy-override"]').selectOption('direct')
    await expect(choice).toContainText('Rolls out as: Direct')

    const sent = mockApi.waitFor('POST', `/projects/${project.id}/deploy`)
    await modal.getByRole('button', { name: 'Deploy', exact: true }).click()
    expect((await sent).body).toEqual({ version_id: mockApi.db.versions[project.id][0].id, strategy: 'direct' })
  })
})

test.describe('Rollouts', () => {
  test('a blue-green rollout waits for its promotion with a test URL; promoting it', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('xaas', { deploy_strategy: 'blue_green' })
    const { candidate, stable } = seedRollout(mockApi, project, BLUE_GREEN_WAITING(project.slug))
    installRollout(mockApi, project, candidate, stable)
    await auth.signIn()
    await page.goto(`/projects/${project.id}#deployments`)

    const panel = page.locator('[data-test="current-rollout"]')
    await expect(panel).toContainText('Blue-green rollout')
    await expect(panel.locator('[data-test="rollout-status"]')).toHaveText('Waiting for promotion')
    await expect(panel.locator('[data-test="rollout-waiting"]')).toContainText('Try it on the test URL')
    await expect(panel.locator('[data-test="rollout-test-url"]')).toHaveAttribute('href', `https://green-${project.slug}.eu-1.runr.test`)
    await expect(panel.locator('[data-test="rollout-traffic-candidate"]')).toHaveText('New 0 %')
    const rows = page.locator('tbody tr')
    await expect(rows.nth(0).locator('[data-test="rollout-chip"]')).toHaveText('New · waiting for promotion')
    await expect(rows.nth(1).locator('[data-test="rollout-chip"]')).toHaveText('Current · 100 %')

    await panel.locator('[data-test="rollout-promote"]').click()
    await expect(page.getByText('The previous revision stays warm for 15 min for an instant rollback.')).toBeVisible()
    const sent = mockApi.waitFor('POST', `/projects/${project.id}/deployments/${candidate.id}/rollout/promote`)
    await confirmDialog(page, 'Promote')
    await sent

    await expect(page.getByText('Promoted: the new revision takes all the traffic.')).toBeVisible()
    await expect(panel.locator('[data-test="rollout-status"]')).toHaveText('Promoted')
    await expect(panel.locator('[data-test="rollout-traffic-candidate"]')).toHaveText('New 100 %')
    await expect(panel.locator('[data-test="rollout-previous-url"]')).toHaveAttribute('href', `https://previous-${project.slug}.eu-1.runr.test`)
    await expect(panel.locator('[data-test="rollout-keep-until"]')).toContainText('kept warm until')
    await expect(panel.locator('[data-test="rollout-promote"]')).toHaveCount(0)
    await expect(rows.nth(0)).toContainText('Active')
    await expect(rows.nth(0).locator('[data-test="rollout-chip"]')).toHaveText('New · promoted')
    await expect(rows.nth(1)).toContainText('Running')
    await expect(rows.nth(1).locator('[data-test="rollout-chip"]')).toContainText('Previous · warm until')

    // the deployment's log panel shows its rollout too (the version cell: the endpoint link opens the URL)
    await rows.nth(0).locator('td').nth(1).click()
    await expect(page.locator('[data-test="rollout-panel"]').locator('[data-test="rollout-status"]')).toHaveText('Promoted')
  })

  test('a canary shows its steps and metrics; without tracing it progresses on time only', async ({ page, auth, seed, mockApi, realtime }) => {
    const project = await seed.project('xaas', { deploy_strategy: 'canary' })
    const { candidate, stable } = seedRollout(mockApi, project)
    installRollout(mockApi, project, candidate, stable)
    await auth.signIn()
    await page.goto(`/projects/${project.id}#deployments`)

    const panel = page.locator('[data-test="current-rollout"]')
    await expect(panel.locator('[data-test="rollout-status"]')).toHaveText('In progress')
    await expect(panel.locator('[data-test="rollout-step"]')).toContainText('Step 1 of 3: 10 % → 50 % in')
    await expect(panel.locator('[data-test="rollout-metric-error-rate"]')).toContainText('0.4 %')
    await expect(panel.locator('[data-test="rollout-metric-error-rate"]')).toContainText('limit: 5 %')
    await expect(panel.locator('[data-test="rollout-metric-p95"]')).toContainText('121 ms')
    await expect(panel.locator('[data-test="rollout-metric-p95"]')).toContainText('limit: 800 ms')
    await expect(panel.locator('[data-test="rollout-metric-volume"]')).toContainText('312')
    const rows = page.locator('tbody tr')
    await expect(rows.nth(0).locator('[data-test="rollout-chip"]')).toHaveText('New · canary 10 %')
    await expect(rows.nth(1).locator('[data-test="rollout-chip"]')).toHaveText('Current · 90 %')

    // the next step arrives over the websocket; the project has no tracing (any more)
    candidate.rollout = {
      ...candidate.rollout,
      traffic: { candidate: 50, stable: 50 },
      step: { index: 1, count: 3, percent: 50, next_percent: 100, started_at: at(0), ends_at: at(5) },
      metrics: { tracing: false, source: null, revision: candidate.rollout.revision, error_rate: null, p95_ms: null, volume: 0, window_minutes: 5, measured_at: at(0), error_rate_threshold: 5, p95_threshold_ms: 800 },
      events: [...candidate.rollout.events, { at: at(0), type: 'step', message: 'Step 2 of 3', traffic: 50, actor: 'system' }],
    }
    await realtime.platform('deployment.rollout', { project_id: project.id, deployment_id: candidate.id, status: 'running', rollout: candidate.rollout })
    await expect(panel.locator('[data-test="rollout-step"]')).toContainText('Step 2 of 3: 50 % → 100 % in')
    await expect(panel.locator('[data-test="rollout-time-only"]')).toContainText('the step moves on time only')
    await expect(panel.locator('[data-test="rollout-metrics"]')).toHaveCount(0)
    await expect(rows.nth(0).locator('[data-test="rollout-chip"]')).toHaveText('New · canary 50 %')
    await expect(rows.nth(1).locator('[data-test="rollout-chip"]')).toHaveText('Current · 50 %')

    // the strategy settings say so too and lead to the Traces tab, where tracing is turned on
    installStrategy(mockApi, project.id, { tracing: false, settings: { strategy: 'canary' } })
    mockApi
      .on('GET', `/projects/${project.id}/observability`, () => ({ body: { data: {
        project_id: project.id, tracing_enabled: false, tracing_sample_ratio: 1,
        region_tracing: { mode: 'managed', status: 'ready', queryable: true, accepts_spans: true },
      } } }))
      .on('GET', `/projects/${project.id}/traces`, () => ({ body: { data: { traces: [] } } }))
    await page.locator('[data-test="deploy-strategy-open"]').click()
    const modal = page.locator('[data-test="deploy-strategy-modal"]')
    await expect(modal.locator('[data-test="canary-no-tracing"]')).toContainText('Turn tracing on for the project (Traces tab)')
    await modal.locator('[data-test="canary-open-tracing"]').click()
    await expect(modal).toBeHidden()
    await expect(page).toHaveURL(/#traces$/)
    await expect(page.locator('[data-test="tracing-toggle"]')).not.toBeChecked()
  })

  test('aborts a canary rollout with a reason', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('xaas', { deploy_strategy: 'canary' })
    const { candidate, stable } = seedRollout(mockApi, project)
    installRollout(mockApi, project, candidate, stable)
    await auth.signIn()
    await page.goto(`/projects/${project.id}#deployments`)

    const panel = page.locator('[data-test="current-rollout"]')
    await panel.locator('[data-test="rollout-abort"]').click()
    await expect(page.getByText(`The traffic stays on ${project.slug}-00001 and the new deployment is shut down.`)).toBeVisible()
    await page.locator('[data-test="rollout-abort-reason"]').fill('Error spike in checkout')
    const sent = mockApi.waitFor('POST', `/projects/${project.id}/deployments/${candidate.id}/rollout/abort`)
    await confirmDialog(page, 'Abort')
    expect((await sent).body).toEqual({ reason: 'Error spike in checkout' })

    await expect(page.getByText('Rollout aborted.')).toBeVisible()
    await expect(panel.locator('[data-test="rollout-status"]')).toHaveText('Aborted')
    await expect(panel.locator('[data-test="rollout-reason"]')).toContainText('Aborted: the traffic stayed on the previous revision.')
    await expect(panel.locator('[data-test="rollout-reason"]')).toContainText('Reason: Error spike in checkout')
    await expect(panel.locator('[data-test="rollout-traffic-candidate"]')).toHaveText('New 0 %')
    await expect(panel.locator('[data-test="rollout-abort"]')).toHaveCount(0)

    const rows = page.locator('tbody tr')
    await expect(rows.nth(0)).toContainText('Shutting Down')
    await expect(rows.nth(0).locator('[data-test="rollout-chip"]')).toHaveText('Canary · Aborted')
    await expect(rows.nth(1)).toContainText('Active')
    await expect(rows.nth(1).locator('[data-test="rollout-chip"]')).toHaveCount(0)
  })

  test('rolls back a promoted blue-green rollout while the previous revision is kept', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('xaas', { deploy_strategy: 'blue_green' })
    const { candidate, stable } = seedRollout(mockApi, project, {
      ...BLUE_GREEN_WAITING(project.slug),
      status: 'promoted', open: false, traffic: { candidate: 100, stable: 0 }, test_url: null,
      previous_url: `https://previous-${project.slug}.eu-1.runr.test`, promoted_at: at(-3), keep_until: at(12),
      can: { promote: false, abort: false, rollback: true },
    })
    Object.assign(candidate, { status: 'active', rollout_role: 'candidate' })
    Object.assign(stable, { status: 'running', rollout_role: 'previous' })
    installRollout(mockApi, project, candidate, stable)
    await auth.signIn()
    await page.goto(`/projects/${project.id}#deployments`)

    const panel = page.locator('[data-test="current-rollout"]')
    await expect(panel.locator('[data-test="rollout-status"]')).toHaveText('Promoted')
    await expect(panel.locator('[data-test="rollout-keep-until"]')).toContainText('kept warm until')
    await expect(panel.locator('[data-test="rollout-traffic-stable"]')).toHaveText('Previous 0 %')
    const rows = page.locator('tbody tr')
    await expect(rows.nth(1).locator('[data-test="rollout-chip"]')).toContainText('Previous · warm until')

    await panel.locator('[data-test="rollout-rollback"]').click()
    await expect(page.getByText(`${project.slug}-00001 takes all the traffic again at once and the new deployment is shut down.`)).toBeVisible()
    const sent = mockApi.waitFor('POST', `/projects/${project.id}/deployments/${candidate.id}/rollout/rollback`)
    await confirmDialog(page, 'Roll back')
    await sent

    await expect(page.getByText('Rolled back to the previous revision.')).toBeVisible()
    await expect(panel.locator('[data-test="rollout-status"]')).toHaveText('Rolled back')
    await expect(panel.locator('[data-test="rollout-traffic-stable"]')).toHaveText('Previous 100 %')
    await expect(panel.locator('[data-test="rollout-rollback"]')).toHaveCount(0)
    await expect(rows.nth(0)).toContainText('Shutting Down')
    await expect(rows.nth(0).locator('[data-test="rollout-chip"]')).toHaveText('Blue-green · Rolled back')
    await expect(rows.nth(1)).toContainText('Active')
  })
})
