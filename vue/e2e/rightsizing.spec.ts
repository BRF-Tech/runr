import { test, expect } from './fixtures/test'
import { confirmDialog } from './helpers/ui'

// Rightsizing recommendations (docs/monitoring.md#rightsizing): the card on a project's page
// ("requests 512 MB but used at most 140 MB … → 256 MB is enough", savings, Apply / Snooze /
// Dismiss) and the organization's list (/rightsizing, largest saving first, linked from the
// projects page). The recommendations come from a night of Prometheus peaks, so the flows run
// against the mocked API.

test.skip(({ e2eMode }) => e2eMode === 'stack', 'recommendations need days of Prometheus peaks; covered with the mocked API')

type Json = Record<string, any>

/** Orders API (p-faas) at 512 MB / 500m used 140 MB / 80m at most in 14 days. */
function recommendation(over: Json = {}): Json {
  return {
    id: 'rs-1', project_id: 'p-faas', project: { id: 'p-faas', name: 'Orders API', slug: 'orders-api', service_type: 'faas' },
    region_id: 'r-ready', status: 'open', direction: 'down',
    current: { cpu_m: 500, memory_mb: 512 }, recommended: { cpu_m: 125, memory_mb: 256 },
    changes: { cpu: 'down', memory: 'down' }, reasons: ['memory_over_provisioned', 'cpu_over_provisioned'],
    evidence: {
      window_days: 14, from: '2026-10-07', to: '2026-10-20', days: 14, sessions: 560, always_on: true,
      memory_max_mb: 140, memory_p95_mb: 110, memory_basis: 'max', memory_margin: 1.3, memory_near_limit: false,
      cpu_max_m: 210, cpu_p95_m: 80, cpu_basis: 'p95', cpu_margin: 1.3, oom_kills: 0, throttled_ratio: 0,
    },
    freed: { cpu_m: 375, memory_mb: 256, pods: 1, always_on: true, peak_cpu_m: 375, peak_memory_mb: 256 },
    savings: { basis: 'reserved', monthly: 12.78, currency: 'USD', allocation: null, freed_allocation: null },
    blocked: null, computed_at: '2026-10-21T02:10:00+00:00', snoozed_until: null, decided_at: null, decided_by: null,
    applied_version_id: null,
    ...over,
  }
}

test.describe('Rightsizing', () => {
  test('the project page shows the recommendation and applies it as a new version', async ({ page, auth, mockApi }) => {
    let state: Json = { status: 'open', reason: null, data: recommendation(), collecting: null, pending_deploy: false }
    mockApi.on('GET', '/projects/p-faas/rightsizing', () => ({ body: state }))
    mockApi.on('POST', '/projects/p-faas/rightsizing/apply', () => {
      state = { status: 'applied', reason: null, data: recommendation({ status: 'applied' }), collecting: null, pending_deploy: true }
      return { body: { data: state.data, version: { id: 'v-9', number: 3, label: 'v3' }, deploy_required: true,
        message: 'The recommended size was saved as v3. Deploy the project to run it.' } }
    })

    await auth.signIn()
    await page.goto('/projects/p-faas')

    const card = page.locator('[data-test="rightsizing-card"]')
    await expect(card).toContainText('This project can run smaller')
    await expect(card).toContainText('Memory: requests 512 MB but used at most 140 MB in the last 14 days → 256 MB is enough.')
    await expect(card).toContainText('CPU: requests 500m but used 80m (95th percentile) in the last 14 days → 125m is enough.')
    await expect(card).toContainText('Frees 256 MB, 375m CPU')
    await expect(card).toContainText('about $12.78 less per month')

    await card.getByRole('button', { name: 'Evidence' }).click()
    await expect(page.locator('[data-test="rightsizing-evidence"]')).toContainText('2026-10-07 – 2026-10-20: 14 days with readings, 560 sessions')

    const applied = mockApi.waitFor('POST', '/projects/p-faas/rightsizing/apply')
    await card.getByRole('button', { name: 'Apply', exact: true }).click()
    await expect(page.getByText('Change Orders API from 512 MB / 500m to 256 MB / 125m?', { exact: false })).toBeVisible()
    const refreshed = mockApi.waitFor('GET', '/projects/p-faas')
    await confirmDialog(page, 'Apply')
    await applied
    await refreshed

    await expect(page.getByText('Saved as v3. Deploy the project to run the new size.')).toBeVisible()
    await expect(page.locator('[data-test="rightsizing-card"]')).toHaveCount(0)
    await expect(page.locator('[data-test="rightsizing-pending"]')).toContainText('256 MB / 125m')
  })

  test('dismissing and snoozing hide the card', async ({ page, auth, mockApi }) => {
    mockApi.on('GET', '/projects/p-faas/rightsizing', () => ({ body: { status: 'open', reason: null, data: recommendation(), collecting: null, pending_deploy: false } }))
    mockApi.on('POST', '/projects/p-faas/rightsizing/dismiss', (req) => ({
      body: { data: recommendation({ status: req.body?.snooze_days ? 'snoozed' : 'dismissed' }), message: 'ok' },
    }))

    await auth.signIn()
    await page.goto('/projects/p-faas')
    const snoozed = mockApi.waitFor('POST', '/projects/p-faas/rightsizing/dismiss')
    await page.locator('[data-test="rightsizing-card"]').getByRole('button', { name: 'Snooze 30 days' }).click()
    expect((await snoozed).body).toEqual({ snooze_days: 30 })
    await expect(page.getByText('Hidden for 30 days.')).toBeVisible()
    await expect(page.locator('[data-test="rightsizing-card"]')).toHaveCount(0)

    await page.reload()
    const dismissed = mockApi.waitFor('POST', '/projects/p-faas/rightsizing/dismiss')
    await page.locator('[data-test="rightsizing-card"]').getByRole('button', { name: 'Dismiss' }).click()
    expect((await dismissed).body).toEqual({})
    await expect(page.getByText('Dismissed. It comes back only when the recommended size changes.')).toBeVisible()
  })

  test('a larger size the region cannot hold is shown but not applied', async ({ page, auth, mockApi }) => {
    mockApi.on('GET', '/projects/p-faas/rightsizing', () => ({
      body: {
        status: 'open', reason: null, collecting: null, pending_deploy: false,
        data: recommendation({
          direction: 'up', changes: { cpu: 'keep', memory: 'up' }, reasons: ['memory_oom_killed'],
          recommended: { cpu_m: 500, memory_mb: 1024 }, freed: { cpu_m: 0, memory_mb: -512, pods: 1, always_on: true },
          savings: null, blocked: { code: 'resource_limit_exceeded', message: 'Not enough memory in eu-1.' },
          evidence: { ...recommendation().evidence, oom_kills: 3 },
        }),
      },
    }))

    await auth.signIn()
    await page.goto('/projects/p-faas')
    const card = page.locator('[data-test="rightsizing-card"]')
    await expect(card).toContainText('This project needs more resources')
    await expect(card).toContainText('Memory: 512 MB is too little (killed for memory) → 1024 MB.')
    await expect(card).toContainText('Takes 512 MB more')
    await expect(card).toContainText('Applying it would be refused now: Not enough memory in eu-1.')
    await expect(card.getByRole('button', { name: 'Apply', exact: true })).toBeDisabled()
  })

  test('a project without enough data shows no card', async ({ page, auth, mockApi }) => {
    const asked = mockApi.waitFor('GET', '/projects/p-xaas/rightsizing')
    await auth.signIn()
    await page.goto('/projects/p-xaas')
    await asked
    await expect(page.getByText('Hello Snippet').first()).toBeVisible()
    await expect(page.locator('[data-test="rightsizing-card"]')).toHaveCount(0)
  })

  test('the organization list: largest saving first, reached from the projects page', async ({ page, auth, mockApi }) => {
    let rows: Json[] = [
      recommendation({ id: 'rs-2', project_id: 'p-caas', project: { id: 'p-caas', name: 'Web Frontend', slug: 'web-frontend', service_type: 'caas' },
        savings: { basis: 'reserved', monthly: 30.5, currency: 'USD' } }),
      recommendation(),
    ]
    mockApi.on('GET', '/rightsizing', () => ({
      body: { data: rows, summary: { count: rows.length, monthly_savings: rows.length === 2 ? 43.28 : 12.78, currency: 'USD', freed_memory_mb: 512, freed_cpu_m: 750 } },
    }))
    mockApi.on('POST', '/projects/p-caas/rightsizing/dismiss', () => {
      rows = rows.filter(r => r.project_id !== 'p-caas')
      return { body: { data: recommendation({ status: 'snoozed' }), message: 'ok' } }
    })

    await auth.signIn()
    await page.goto('/projects')
    const link = page.getByTestId('rightsizing-link')
    await expect(link).toHaveText('2 size recommendation(s) · $43.28/month')
    await link.click()
    await expect(page).toHaveURL(/\/rightsizing$/)

    await expect(page.getByText('Rightsizing recommendations')).toBeVisible()
    await expect(page.locator('[data-test="rightsizing-summary"]')).toHaveText('2 recommendation(s) · about $43.28 per month in all')
    const names = page.locator('[data-rightsizing-row]')
    await expect(names).toHaveCount(2)
    await expect(names.nth(0)).toHaveAttribute('data-rightsizing-row', 'Web Frontend')
    await expect(names.nth(1)).toHaveAttribute('data-rightsizing-row', 'Orders API')
    await expect(names.nth(0)).toContainText('about $30.50 less per month')

    const snoozed = mockApi.waitFor('POST', '/projects/p-caas/rightsizing/dismiss')
    await names.nth(0).getByRole('button', { name: 'Snooze 30 days' }).click()
    expect((await snoozed).body).toEqual({ snooze_days: 30 })
    await expect(names).toHaveCount(1)

    await names.nth(0).getByRole('link', { name: 'Orders API' }).click()
    await expect(page).toHaveURL(/\/projects\/p-faas$/)
  })
})
