import { test, expect } from './fixtures/test'
import type { Page } from '@playwright/test'
import type { MockApi } from './fixtures/mock-api'
import { ALL_PERMISSIONS, effectivePermissions, type Json } from './fixtures/data'
import { openNavMenu, openProjectTab, openUserMenu } from './helpers/ui'

// Billing module (docs/billing.md), mocked API: the operator's bills with payment link and paid
// status, fixed resource approvals, region billing types and price versions, the project owner's
// request form with its monthly price before the request is sent, a tenant accepting a shared
// region with its markup, project stop / resume, and the pages hidden while the API reports billing
// off. Built into the modules server (VITE_BILLING_ENABLED=true).

const byTest = (page: Page, id: string) => page.locator(`[data-test="${id}"]`)
const PERIOD = '2026-09'

function bill(over: Json = {}): Json {
  return {
    id: 'b-1', kind: 'project', period: PERIOD, period_start: '2026-09-01', period_end: '2026-09-30',
    status: 'closed', estimate: false, currency: 'USD', source: 'runr',
    project: { id: 'p-faas', name: 'Orders API' }, owner: { id: 1, name: 'Ada Admin', email: 'admin@runr.test' }, tenant: null,
    billing_type: 'fixed', line_items: null, fixed_total: 50, usage_total: 2.5, total: 52.5, metered_cost: 31.2, difference: 21.3,
    payment_url: null, payment_url_set_at: null, paid: false, paid_at: null, payment_reference: null,
    closed_at: '2026-10-01T02:30:00Z', created_at: '2026-09-01T02:30:00Z', updated_at: '2026-10-01T02:30:00Z',
    ...over,
  }
}

const lines = [
  { type: 'fixed', metric: 'fixed_resources', description: 'Fixed resources 2 vCPU · 4 GB (eu-1)', quantity: 30, unit: 'days', unit_price: 1.666667, multiplier: 1, markup_percent: 0, total: 50, from: '2026-09-01', to: '2026-09-30' },
  { type: 'usage', metric: 'binding_storage_gb_hours', description: 'Binding storage (eu-1)', quantity: 125, unit: 'GB-hours', unit_price: 0.02, multiplier: 1, markup_percent: 0, total: 2.5, from: '2026-09-01', to: '2026-09-30' },
]

function request(over: Json = {}): Json {
  return {
    id: 'fr-1', project: { id: 'p-faas', name: 'Orders API' }, region: { id: 'r-ready', name: 'eu-1' },
    cpu_m: 2000, memory_mb: 4096, gpu_count: 0, vcpu: 2, memory_gb: 4, monthly_price: 62, currency: 'USD',
    status: 'pending', note: 'Black Friday', requested_by: { id: 2, name: 'Bob Builder' }, requested_at: '2026-10-18T09:00:00Z',
    decided_by: null, decided_at: null, decision_reason: null, active_from: null, active_until: null,
    ...over,
  }
}

function tenantMember(mockApi: MockApi, permissions: string[] = [...ALL_PERMISSIONS]) {
  mockApi.db.user = { ...mockApi.db.user, is_admin: false }
  mockApi.db.activeTenant = { ...mockApi.db.tenants[0] }
  mockApi.db.permissions = { ...effectivePermissions('owner', permissions), edition: 'multi' }
}

test.describe('Billing', { tag: '@modules' }, () => {
  test.skip(({ e2eMode }) => e2eMode === 'stack', 'relies on the mocked billing API')

  test('the operator sets a payment link and marks a closed bill paid', async ({ page, auth, mockApi }) => {
    mockApi.on('GET', '/billing/bills', () => ({ body: { data: [bill()], meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 } } }))
    mockApi.on('GET', '/billing/bills/:id', () => ({ body: { data: bill({ line_items: lines }) } }))
    mockApi.on('PUT', '/billing/bills/:id/payment-url', req => ({ body: { data: bill({ line_items: lines, payment_url: req.body.payment_url }) } }))
    mockApi.on('PUT', '/billing/bills/:id/paid', req => ({ body: { data: bill({ line_items: lines, payment_url: 'https://pay.example.com/i/42', paid: req.body.paid, paid_at: `${req.body.paid_at}T00:00:00Z`, payment_reference: req.body.payment_reference }) } }))
    await auth.signIn()
    await page.goto('/settings?tab=billing')

    await expect(byTest(page, 'bill-status-b-1')).toHaveText('Closed')
    await expect(byTest(page, 'bill-paid-b-1')).toHaveText('Unpaid')
    await expect(byTest(page, 'bill-total-b-1')).toContainText('52.50')
    await byTest(page, 'bill-toggle-b-1').click()
    await expect(byTest(page, 'bill-lines-b-1')).toContainText('Fixed resources 2 vCPU')
    // fixed price next to the real usage cost, for billing.view holders
    await expect(byTest(page, 'bill-metered-b-1')).toContainText('31.20')
    await expect(byTest(page, 'bill-difference-b-1')).toContainText('21.30')

    const urlSaved = mockApi.waitFor('PUT', '/billing/bills/:id/payment-url')
    await byTest(page, 'bill-payment-url-b-1').fill('https://pay.example.com/i/42')
    await byTest(page, 'bill-payment-url-save-b-1').click()
    expect((await urlSaved).body).toEqual({ payment_url: 'https://pay.example.com/i/42' })
    await expect(byTest(page, 'bill-payment-link-b-1')).toHaveAttribute('href', 'https://pay.example.com/i/42')

    const paidSaved = mockApi.waitFor('PUT', '/billing/bills/:id/paid')
    await byTest(page, 'bill-paid-toggle-b-1').check()
    await byTest(page, 'bill-paid-at-b-1').fill('2026-10-05')
    await byTest(page, 'bill-reference-b-1').fill('INV-42')
    await byTest(page, 'bill-paid-save-b-1').click()
    expect((await paidSaved).body).toEqual({ paid: true, paid_at: '2026-10-05', payment_reference: 'INV-42' })
    await expect(byTest(page, 'bill-paid-b-1')).toHaveText('Paid')
  })

  test('a fixed resource request is approved, another one rejected with a reason', async ({ page, auth, mockApi }) => {
    mockApi.on('GET', '/billing/fixed-requests', () => ({ body: { data: [request(), request({ id: 'fr-2', project: { id: 'p-xaas', name: 'Hello Snippet' } })], meta: { total: 2 } } }))
    mockApi.on('POST', '/billing/fixed-requests/:id/approve', req => ({ body: { data: request({ id: req.params.id, status: 'approved', active_from: '2026-10-19' }) } }))
    mockApi.on('POST', '/billing/fixed-requests/:id/reject', req => ({ body: { data: request({ id: req.params.id, status: 'rejected', decision_reason: req.body.reason }) } }))
    await auth.signIn()
    await page.goto('/settings?tab=billing')
    await byTest(page, 'billing-section-approvals').click()

    await expect(byTest(page, 'request-resources-fr-1')).toContainText('2 vCPU')
    const approved = mockApi.waitFor('POST', '/billing/fixed-requests/:id/approve')
    await byTest(page, 'request-approve-fr-1').click()
    await page.getByRole('button', { name: 'Approve' }).last().click()
    expect((await approved).path).toBe('/billing/fixed-requests/fr-1/approve')
    await expect(byTest(page, 'request-fr-1')).toHaveCount(0)

    await byTest(page, 'request-reject-fr-2').click()
    await byTest(page, 'request-reject-reason-fr-2').fill('Region is full this month')
    const rejected = mockApi.waitFor('POST', '/billing/fixed-requests/:id/reject')
    await byTest(page, 'request-reject-confirm-fr-2').click()
    expect((await rejected).body).toEqual({ reason: 'Region is full this month' })
  })

  test('the admin changes a region to fixed resources and schedules new default prices', async ({ page, auth, mockApi }) => {
    mockApi.on('PUT', '/billing/regions/:id', req => ({ body: { data: { id: req.params.id, billing_type: req.body.billing_type } } }))
    mockApi.on('POST', '/billing/prices', req => ({ status: 201, body: { data: { id: 'price-2', ...req.body } } }))
    await auth.signIn()
    await page.goto('/settings?tab=billing')
    await byTest(page, 'billing-section-prices').click()

    const typed = mockApi.waitFor('PUT', '/billing/regions/:id')
    await byTest(page, 'region-type-r-ready').selectOption('fixed')
    await page.getByRole('button', { name: 'Change type' }).click()
    expect((await typed).body).toEqual({ billing_type: 'fixed' })

    await byTest(page, 'price-new-default').click()
    await byTest(page, 'price-cpu_vcpu_hour').fill('0.05')
    await byTest(page, 'price-effective-from').fill('2099-01-01')
    const saved = mockApi.waitFor('POST', '/billing/prices')
    await byTest(page, 'price-save').click()
    const body = (await saved).body
    expect(body).toMatchObject({ region_id: null, effective_from: '2099-01-01', cpu_vcpu_hour: 0.05, memory_gb_hour: 0.005, gpu_month: 700, multiplier_http: 1 })
  })

  test('the project owner sees the monthly price before requesting fixed resources', async ({ page, auth, mockApi }) => {
    mockApi.on('GET', '/projects/:id/billing', () => ({
      body: {
        currency: 'USD', billing_type: 'fixed', region: { id: 'r-ready', name: 'eu-1' }, can_request: true, can_view_costs: false,
        allocation: null, pending_request: null, current: bill({ status: 'open', estimate: true, total: 0, fixed_total: 0, usage_total: 0, metered_cost: undefined, difference: undefined, line_items: [] }), bills: [],
      },
    }))
    mockApi.on('GET', '/projects/:id/billing/quote', req => {
      const cpu = Number(req.query.get('cpu_m')) / 1000
      const gb = Number(req.query.get('memory_mb')) / 1024
      const monthly = cpu * 25 + gb * 3
      return { body: { currency: 'USD', monthly_price: monthly, breakdown: { cpu: cpu * 25, memory: gb * 3, gpu: 0 }, markup_percent: 0,
        prices: { cpu_vcpu_month: 25, memory_gb_month: 3, gpu_month: 700 }, first_month: { from: '2026-10-19', to: '2026-10-31', days: 13, amount: Math.round(monthly * 13 / 31 * 100) / 100 } } }
    })
    mockApi.on('POST', '/projects/:id/billing/fixed-requests', req => ({ status: 201, body: { data: request({ ...req.body }) } }))
    await auth.signIn()
    await page.goto('/projects/p-faas')
    // the tab shows up once the branding (features.billing) is loaded
    await openProjectTab(page, 'Billing')

    await expect(byTest(page, 'project-no-allocation')).toBeVisible()
    await byTest(page, 'fixed-request-vcpu').fill('2')
    await byTest(page, 'fixed-request-memory').fill('4')
    // the price is known before the request is sent: 2 × 25 + 4 × 3
    await expect(byTest(page, 'fixed-request-monthly')).toContainText('62.00')
    await expect(byTest(page, 'fixed-request-first-month')).toContainText('13 days')

    const sent = mockApi.waitFor('POST', '/projects/:id/billing/fixed-requests')
    await byTest(page, 'fixed-request-note').fill('Launch week')
    await byTest(page, 'fixed-request-submit').click()
    expect((await sent).body).toEqual({ cpu_m: 2000, memory_mb: 4096, gpu_count: 0, note: 'Launch week' })
  })

  test('a tenant administrator accepts a shared region with a markup', async ({ page, auth, mockApi }) => {
    tenantMember(mockApi)
    mockApi.on('GET', '/billing/region-offers', () => ({
      body: {
        default_markup_percent: 10,
        data: [{
          region: { id: 'r-ank', name: 'ank-1' }, status: 'pending', offered_at: '2026-10-18T08:00:00Z', accepted_at: null,
          billing_type: 'payg', currency: 'USD', markup_percent: null,
          prices: { current: { cpu_vcpu_hour: 0.04, memory_gb_hour: 0.005, gpu_hour: 1.2 }, scheduled: [] },
        }],
      },
    }))
    mockApi.on('POST', '/billing/region-offers/:id/accept', req => ({ body: { data: { region: { id: req.params.id, name: 'ank-1' }, status: 'active', markup_percent: req.body.markup_percent } } }))
    await auth.signIn()
    await page.goto('/settings?tab=billing')
    await byTest(page, 'billing-section-offers').click()

    // prefilled with the default markup: 0.04 × 1.10
    await expect(byTest(page, 'offer-markup-r-ank')).toHaveValue('10')
    await expect(byTest(page, 'offer-price-r-ank-cpu_vcpu_hour')).toContainText('0.044')
    await byTest(page, 'offer-markup-r-ank').fill('15')
    const accepted = mockApi.waitFor('POST', '/billing/region-offers/:id/accept')
    await byTest(page, 'offer-accept-r-ank').click()
    expect((await accepted).body).toEqual({ markup_percent: 15 })
  })

  test('a project is stopped with a reason that its members see, and resumed', async ({ page, auth, mockApi }) => {
    // The signed-in user is a platform administrator: the API answers the platform's stop level
    const stopped = { at: '2026-10-19T10:00:00Z', by: { id: 1, name: 'Ada Admin' }, reason: 'Unpaid since August', level: 'platform' }
    mockApi.on('POST', '/projects/:id/stop', req => {
      const p = mockApi.db.projects.find(x => x.id === req.params.id)!
      Object.assign(p, { stopped: { ...stopped, reason: req.body.reason } })
      return { body: { data: p } }
    })
    mockApi.on('POST', '/projects/:id/resume', req => {
      const p = mockApi.db.projects.find(x => x.id === req.params.id)!
      p.stopped = null
      return { body: { data: p, deployment_id: 'd-new' } }
    })
    await auth.signIn()
    await page.goto('/projects/p-faas')

    await byTest(page, 'project-stop').click()
    await byTest(page, 'project-stop-reason').fill('Unpaid since August')
    const stop = mockApi.waitFor('POST', '/projects/:id/stop')
    await byTest(page, 'project-stop-submit').click()
    expect((await stop).body).toEqual({ reason: 'Unpaid since August' })
    await expect(byTest(page, 'project-stopped-banner')).toContainText('Unpaid since August')
    // the platform's own stop: nothing to take over
    await expect(byTest(page, 'project-stop')).toHaveCount(0)
    // the header's Deploy button (the deployments tab has one of its own)
    await expect(page.locator('[data-tour="action-buttons"]').getByRole('button', { name: 'Deploy' })).toBeDisabled()

    const resume = mockApi.waitFor('POST', '/projects/:id/resume')
    await byTest(page, 'project-resume').click()
    expect((await resume).path).toBe('/projects/p-faas/resume')
    await expect(byTest(page, 'project-stopped-banner')).toHaveCount(0)
  })

  test('billing pages stay hidden while the API reports billing off', async ({ page, auth, mockApi }) => {
    mockApi.on('GET', '/branding', () => ({ body: { product_name: 'RunR', is_default_brand: true, features: { multi_tenant: true, billing: false, tickets: true } } }))
    await auth.signIn()
    await page.goto('/settings')
    await expect(page.getByTestId('settings-title')).toHaveText('Administration')
    const admin = await openNavMenu(page, 'admin')
    await expect(admin.getByTestId('nav-link-tokens')).toBeVisible()
    await expect(admin.getByTestId('nav-link-billing')).toHaveCount(0)
    const menu = await openUserMenu(page)
    await expect(menu.getByTestId('nav-link-account')).toBeVisible()
    await expect(menu.getByTestId('nav-link-my-bills')).toHaveCount(0)
  })
})
