import { test, expect } from './fixtures/test'
import type { MockApi } from './fixtures/mock-api'
import { openDeployModal } from './helpers/deploy'
import { openProjectTab } from './helpers/ui'

// Environments and promotion (docs/environments.md) and the rollback with the config of a
// deployment (#626), on the mocked API: environments, promotions and their deployments are kept
// in this spec's own state.

test.skip(({ e2eMode }) => e2eMode === 'stack', 'environments deploy into a Kubernetes region; covered with the mocked API')

type Json = Record<string, any>

const now = () => new Date().toISOString()

/** p-xaas with production (v2 live) and, optionally, staging; the environment endpoints answer from `state`. */
function environmentsOf(mockApi: MockApi, withStaging = false) {
  const state: { environments: Json[]; deployments: Record<string, Json[]>; promotions: Json[] } = {
    environments: [
      { id: 'e-prod', name: 'production', is_primary: true, requires_approval: true, workload_name: 'hello-snippet', region: { id: 'r-ready', name: 'e2e-ready' }, url: 'https://hello-snippet.e2e.example', overrides: {} },
    ],
    deployments: { production: mockApi.db.deployments['p-xaas'], staging: [] },
    promotions: [],
  }
  if (withStaging) {
    state.environments.unshift({ id: 'e-staging', name: 'staging', is_primary: false, requires_approval: false, workload_name: 'hello-snippet-staging', git_branch: 'develop', region: { id: 'r-ready', name: 'e2e-ready' }, url: 'https://hello-snippet-staging.e2e.example', overrides: {} })
    state.deployments.staging = [{ id: 'd-st-1', status: 'active', environment: 'staging', version: { id: 'v-xaas-2', label: 'v2' }, deployed_by: 'Ada Admin', created_at: now() }]
  }
  const live = (name: string) => (state.deployments[name] ?? []).find(d => d.status === 'active') ?? null
  const present = (env: Json) => ({ ...env, deployment: live(env.name) })

  mockApi
    .on('GET', '/projects/p-xaas/environments', () => ({ status: 200, body: { data: state.environments.map(present), abilities: { manage: true, promote: true, approve: true } } }))
    .on('POST', '/projects/p-xaas/environments', (req) => {
      const env = { id: `e-${req.body.name}`, name: req.body.name, is_primary: false, requires_approval: !!req.body.requires_approval, workload_name: `hello-snippet-${req.body.name}`, git_branch: req.body.git_branch ?? null, region: { id: 'r-ready', name: 'e2e-ready' }, url: `https://hello-snippet-${req.body.name}.e2e.example`, overrides: req.body.overrides ?? {} }
      state.environments.splice(state.environments.length - 1, 0, env)
      state.deployments[env.name] = []
      return { status: 201, body: { data: present(env) } }
    })
    .on('GET', '/projects/p-xaas/deployments', (req) => ({ status: 200, body: { data: state.deployments[req.query.get('environment') ?? 'production'] ?? [] } }))
    .on('POST', '/projects/p-xaas/deploy', (req) => {
      const name = req.body.environment ?? 'production'
      const list = (state.deployments[name] ??= [])
      list.forEach(d => { if (d.status === 'active') d.status = 'shut_down' })
      const v = mockApi.db.versions['p-xaas'].find((x: Json) => x.id === req.body.version_id)
      const d = { id: `d-${name}-${list.length + 1}`, status: 'active', environment: name, version: { id: v.id, label: v.label }, deployed_by: 'Ada Admin', created_at: now() }
      list.unshift(d)
      return { status: 202, body: d }
    })
    .on('GET', '/projects/p-xaas/environments/staging/promote', () => ({ status: 200, body: { data: {
      source: { environment: 'staging' }, target: { environment: 'production' },
      code: { from: { label: 'v1' }, to: { label: 'v2', image: 'registry.e2e/hello-snippet:v2' }, changes: true },
      config: [{ field: 'env_vars', added: ['FEATURE_FLAG'], removed: [], changed: ['GREETING'] }, { field: 'min_scale', from: 0, to: 2 }],
      environment_differences: [], requires_approval: true, can_promote: true, problem: null, can_approve: true,
    } } }))
    .on('POST', '/projects/p-xaas/environments/staging/promote', (req) => {
      const p = { id: 'pr-1', project_id: 'p-xaas', project: { id: 'p-xaas', name: 'hello-snippet' }, source: 'staging', target: req.body.to ?? 'production', version: { id: 'v-xaas-2', label: 'v2' }, status: 'pending_approval', message: req.body.message ?? null, requested_by: 'Grace Hopper', created_at: now(), can_approve: true, can_cancel: false }
      state.promotions.unshift(p)
      return { status: 202, body: { data: p } }
    })
    .on('GET', '/projects/p-xaas/promotions', () => ({ status: 200, body: { data: state.promotions } }))
    .on('GET', '/environment-promotions', () => ({ status: 200, body: { data: state.promotions.filter(p => p.status === 'pending_approval') } }))
    .on('POST', '/projects/p-xaas/promotions/:id/approve', (req) => {
      const p = state.promotions.find(x => x.id === req.params.id)!
      Object.assign(p, { status: 'deploying', can_approve: false, decided_by: 'Ada Admin', decision_comment: req.body?.comment ?? null })
      return { status: 200, body: { data: p } }
    })
    .on('POST', '/projects/p-xaas/deployments/:did/rollback', (req) => {
      const list = state.deployments.production
      const from = list.find(d => d.id === req.params.did)!
      list.forEach(d => { if (d.status === 'active') d.status = 'shut_down' })
      const d = { id: `d-rb-${list.length + 1}`, status: 'pending', environment: 'production', version: from.version, config_source: req.body?.keep_current_config ? null : 'restored', deployed_by: 'Ada Admin', created_at: now() }
      list.unshift(d)
      return { status: 202, body: d }
    })

  return state
}

test.describe('Environments', () => {
  test('creates an environment, switches to it and deploys there', async ({ page, auth, mockApi }) => {
    const state = environmentsOf(mockApi)
    await auth.signIn()
    await page.goto('/projects/p-xaas#environments')

    await page.getByTestId('environment-create').click()
    const form = page.getByTestId('environment-form')
    await form.getByTestId('env-name').fill('staging')
    await form.getByTestId('env-branch').fill('develop')
    await form.getByTestId('env-min-scale').fill('0')
    const created = mockApi.waitFor('POST', '/projects/p-xaas/environments')
    await form.getByTestId('env-save').click()
    expect((await created).body).toMatchObject({ name: 'staging', git_branch: 'develop', requires_approval: false, overrides: { min_scale: 0 } })
    await expect(page.getByTestId('environment-card-staging')).toContainText('Deploys from develop')
    expect(state.environments.map(e => e.name)).toEqual(['staging', 'production'])

    // The deployments tab follows the environment chosen
    await openProjectTab(page, 'Deployments')
    await page.getByTestId('environment-staging').click()
    await expect(page.locator('tbody tr')).toHaveCount(0)
    await expect(page.getByTestId('environment-summary')).toContainText('https://hello-snippet-staging.e2e.example')

    const modal = await openDeployModal(page)
    await expect(modal.getByTestId('deploy-environment')).toHaveValue('staging')
    const sent = mockApi.waitFor('POST', '/projects/p-xaas/deploy')
    await modal.getByRole('button', { name: 'Deploy' }).click()
    expect((await sent).body).toEqual({ version_id: 'v-xaas-2', environment: 'staging' })
    await expect(page.locator('tbody tr')).toHaveCount(1)
    await expect(page.locator('tbody tr').first()).toContainText('v2')
  })

  test('promotes staging to production after the "what will change" preview, approved from the inbox', async ({ page, auth, mockApi }) => {
    environmentsOf(mockApi, true)
    await auth.signIn()
    await page.goto('/projects/p-xaas#environments')

    await page.getByTestId('environment-promote-staging').click()
    const dialog = page.getByTestId('promote-dialog')
    await expect(dialog.getByTestId('promote-code')).toContainText('v1')
    await expect(dialog.getByTestId('promote-code')).toContainText('registry.e2e/hello-snippet:v2')
    await expect(dialog.getByTestId('promote-change-env_vars')).toContainText('FEATURE_FLAG')
    await expect(dialog.getByTestId('promote-change-min_scale')).toContainText('0 → 2')
    await expect(dialog.getByTestId('promote-approval')).toContainText('need approval')

    await dialog.locator('textarea').fill('release v2')
    const requested = mockApi.waitFor('POST', '/projects/p-xaas/environments/staging/promote')
    await dialog.getByTestId('promote-submit').click()
    expect((await requested).body).toEqual({ to: 'production', message: 'release v2' })
    await expect(dialog).toBeHidden()

    // The approvals inbox
    await page.getByTestId('nav-approvals').click()
    await expect(page).toHaveURL(/\/approvals$/)
    const row = page.getByTestId('promotion-pr-1')
    await expect(row).toContainText('hello-snippet')
    await expect(row).toContainText('Waiting for approval')
    await row.getByTestId('promotion-comment-pr-1').fill('go')
    const approved = mockApi.waitFor('POST', '/projects/p-xaas/promotions/pr-1/approve')
    await row.getByTestId('promotion-approve-pr-1').click()
    expect((await approved).body).toEqual({ comment: 'go' })
    await expect(page.getByTestId('approvals-empty')).toBeVisible()
  })

  test('rolls back to an earlier deployment keeping the current configuration', async ({ page, auth, mockApi }) => {
    mockApi.db.deployments['p-xaas'].push({
      id: 'd-xaas-0', status: 'shut_down', version: { id: 'v-xaas-1', label: 'v1' }, version_id: 'v-xaas-1',
      k8s_job_name: 'hello-snippet', deployed_by: 'Ada Admin', created_at: '2026-03-01T11:00:00Z',
    })
    environmentsOf(mockApi)
    await auth.signIn()
    await page.goto('/projects/p-xaas#deployments')

    const rows = page.locator('tbody tr')
    await expect(rows).toHaveCount(2)
    await expect(rows.first().getByTestId('deployment-rollback')).toHaveCount(0)
    await rows.nth(1).hover()
    await rows.nth(1).getByTestId('deployment-rollback').click()

    const modal = page.getByTestId('rollback-modal')
    await expect(modal).toContainText('Roll back to v1')
    await modal.getByTestId('rollback-keep-config').check()
    const sent = mockApi.waitFor('POST', '/projects/p-xaas/deployments/d-xaas-0/rollback')
    await modal.getByTestId('rollback-confirm').click()
    expect((await sent).body).toEqual({ keep_current_config: true })
    await expect(modal).toBeHidden()
    await expect(rows).toHaveCount(3)
    await expect(rows.first()).toContainText('v1')
  })
})
