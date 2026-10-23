import { test, expect } from './fixtures/test'
import type { Page } from '@playwright/test'
import type { Json } from './fixtures/data'
import { openProjectTab } from './helpers/ui'

// Monthly cost limit of a pay-as-you-go project (Billing module, docs/billing.md), mocked API: the
// owner sets one amount, sees this month so far and the month-end forecast, chooses what happens at
// 100 % (notify or stop the project) and removes it again; a project its limit stopped says so in
// the banner and is resumed on the Billing tab once the limit is raised; a fixed-price project
// shows no limit at all. Built into the modules server (VITE_BILLING_ENABLED=true).

const byTest = (page: Page, id: string) => page.locator(`[data-test="${id}"]`)

/** CostLimitStatus: 42 so far on day 20 of a 31-day month, no limit yet. */
function status(over: Json = {}): Json {
  return {
    available: true, currency: 'USD', period: '2026-10', limit: null, month_to_date: 42, forecast: 65.1,
    days_elapsed: 20, days_in_month: 31, percent: null, warning_percent: 80, state: 'none',
    warned_at: null, reached_at: null, stopped: false, can_manage: true, can_resume: false, checked_at: '2026-10-20T09:00:00Z',
    ...over,
  }
}

/** The status the API answers for a saved limit (42 so far). */
function withLimit(amount: number, action: string, over: Json = {}): Json {
  const percent = Math.round((42 / amount) * 1000) / 10
  return status({
    limit: { amount, action, updated_by: { id: 1, name: 'Ada Admin' }, updated_at: '2026-10-20T09:05:00Z' },
    percent, state: percent >= 100 ? 'reached' : percent >= 80 ? 'warning' : 'ok',
    ...over,
  })
}

function billing(costLimit: Json, over: Json = {}): Json {
  return {
    currency: 'USD', billing_type: 'payg', region: { id: 'r-ready', name: 'eu-1' }, can_request: true, can_view_costs: false,
    allocation: null, pending_request: null, current: null, bills: [], cost_limit: costLimit,
    ...over,
  }
}

test.describe('Cost limit', { tag: '@modules' }, () => {
  test.skip(({ e2eMode }) => e2eMode === 'stack', 'relies on the mocked billing API')

  test('the owner sets a monthly limit, switches it to stop and removes it', async ({ page, auth, mockApi }) => {
    mockApi.on('GET', '/projects/:id/billing', () => ({ body: billing(status()) }))
    mockApi.on('PUT', '/projects/:id/billing/cost-limit', req => ({ body: { data: withLimit(req.body.amount, req.body.action) } }))
    mockApi.on('DELETE', '/projects/:id/billing/cost-limit', () => ({ body: { data: status() } }))
    await auth.signIn()
    await page.goto('/projects/p-faas')
    await openProjectTab(page, 'Billing')

    await expect(byTest(page, 'cost-limit-month-to-date')).toHaveText('This month so far: $42.00')
    // so far ÷ elapsed days × days of the month: 42 ÷ 20 × 31
    await expect(byTest(page, 'cost-limit-forecast')).toContainText('Month-end forecast: $65.10')
    await expect(byTest(page, 'cost-limit-forecast')).toContainText('20 days × 31 days')
    await expect(byTest(page, 'cost-limit-progress')).toHaveCount(0)

    // 100 per month, notify at 100 %
    await byTest(page, 'cost-limit-amount').fill('100')
    const saved = mockApi.waitFor('PUT', '/projects/:id/billing/cost-limit')
    await byTest(page, 'cost-limit-save').click()
    expect((await saved).body).toEqual({ amount: 100, action: 'notify' })
    await expect(byTest(page, 'cost-limit-month-to-date')).toHaveText('This month so far: $42.00 of $100.00')
    await expect(byTest(page, 'cost-limit-progress')).toHaveAttribute('aria-valuenow', '42')
    await expect(byTest(page, 'cost-limit-state')).toHaveCount(0)

    // a stop limit the month has already reached stops the project as soon as it is saved
    await byTest(page, 'cost-limit-action-stop').click()
    await expect(byTest(page, 'cost-limit-stop-now')).toHaveCount(0)
    await byTest(page, 'cost-limit-amount').fill('40')
    await expect(byTest(page, 'cost-limit-stop-now')).toContainText('saving stops the project right away')
    await byTest(page, 'cost-limit-amount').fill('50')
    await expect(byTest(page, 'cost-limit-stop-now')).toHaveCount(0)
    const stopLimit = mockApi.waitFor('PUT', '/projects/:id/billing/cost-limit')
    await byTest(page, 'cost-limit-save').click()
    expect((await stopLimit).body).toEqual({ amount: 50, action: 'stop' })
    // 42 of 50 is 84 %: past the 80 % warning
    await expect(byTest(page, 'cost-limit-state')).toHaveText('80 % of the limit reached')
    await expect(byTest(page, 'cost-limit-forecast')).toContainText('above the limit')

    const removed = mockApi.waitFor('DELETE', '/projects/:id/billing/cost-limit')
    await byTest(page, 'cost-limit-remove').click()
    expect((await removed).path).toBe('/projects/p-faas/billing/cost-limit')
    await expect(byTest(page, 'cost-limit-remove')).toHaveCount(0)
    await expect(byTest(page, 'cost-limit-month-to-date')).toHaveText('This month so far: $42.00')
  })

  test('a project its limit stopped is resumed on the Billing tab once the limit is raised', async ({ page, auth, mockApi }) => {
    const p = mockApi.db.projects.find(x => x.id === 'p-faas')!
    p.stopped = { at: '2026-10-20T09:00:00Z', by: null, reason: 'Monthly cost limit reached: $42.00 of $40.00 in 2026-10.', level: 'organization', system: 'cost_limit' }
    let current = withLimit(40, 'stop', { stopped: true, can_resume: false })
    mockApi.on('GET', '/projects/:id/billing', () => ({ body: billing(current) }))
    mockApi.on('PUT', '/projects/:id/billing/cost-limit', req => {
      current = withLimit(req.body.amount, req.body.action, { stopped: true, can_resume: req.body.amount > 42 })
      return { body: { data: current } }
    })
    mockApi.on('POST', '/projects/:id/billing/cost-limit/resume', () => {
      p.stopped = null
      current = withLimit(100, 'stop')
      return { body: { data: current, project: p, deployment_id: 'd-new', redeploy_error: null, redeploy_code: null } }
    })
    await auth.signIn()
    await page.goto('/projects/p-faas')

    await expect(byTest(page, 'project-stopped-title')).toHaveText('This project was stopped by its monthly cost limit')
    await expect(byTest(page, 'project-stopped-reason')).toContainText('Monthly cost limit reached')
    await expect(byTest(page, 'project-stopped-by')).toContainText('on the Billing tab')

    await openProjectTab(page, 'Billing')
    await expect(byTest(page, 'cost-limit-stopped')).toHaveText('Stopped by its cost limit')
    await expect(byTest(page, 'cost-limit-state')).toHaveText('Limit reached')
    // the limit still stops it: no resume until it is raised, removed or the month ends
    await expect(byTest(page, 'cost-limit-resume')).toHaveCount(0)
    await expect(byTest(page, 'cost-limit-resume-hint')).toBeVisible()

    await byTest(page, 'cost-limit-amount').fill('100')
    const raised = mockApi.waitFor('PUT', '/projects/:id/billing/cost-limit')
    await byTest(page, 'cost-limit-save').click()
    expect((await raised).body).toEqual({ amount: 100, action: 'stop' })
    await expect(byTest(page, 'cost-limit-resume-hint')).toHaveCount(0)

    const resumed = mockApi.waitFor('POST', '/projects/:id/billing/cost-limit/resume')
    await byTest(page, 'cost-limit-resume').click()
    expect((await resumed).path).toBe('/projects/p-faas/billing/cost-limit/resume')
    await expect(byTest(page, 'project-stopped-banner')).toHaveCount(0)
    await expect(byTest(page, 'cost-limit-stopped')).toHaveCount(0)
  })

  test('a fixed-price project has no cost limit', async ({ page, auth, mockApi }) => {
    mockApi.on('GET', '/projects/:id/billing', () => ({
      body: billing(status({ available: false, month_to_date: null, forecast: null }), { billing_type: 'fixed' }),
    }))
    mockApi.on('GET', '/projects/:id/billing/quote', () => ({
      body: { currency: 'USD', monthly_price: 31, breakdown: { cpu: 25, memory: 6, gpu: 0 }, markup_percent: 0, prices: { cpu_vcpu_month: 25, memory_gb_month: 3, gpu_month: 700 } },
    }))
    await auth.signIn()
    await page.goto('/projects/p-faas')
    await openProjectTab(page, 'Billing')

    await expect(byTest(page, 'project-no-allocation')).toBeVisible()
    await expect(byTest(page, 'cost-limit')).toHaveCount(0)
  })
})
