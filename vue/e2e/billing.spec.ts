import { test, expect } from './fixtures/test'

// Settings → Billing (central administrator, multi-tenant): per-tenant cost overview with the
// tenant's charges (what it owes per month, docs/billing.md) and the pricing configuration.

test.describe('Billing', { tag: '@modules' }, () => {
  test('lists tenants and expands one into its cost summary', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'relies on the seeded mock billing data')
    await auth.signIn()
    await page.goto('/settings?tab=billing')
    await expect(page.getByRole('heading', { name: 'Billing' })).toBeVisible()
    await expect(page.getByText('Tenant Cost Overview')).toBeVisible()

    const overview = page.locator('[data-tour="billing-overview"]')
    await expect(overview.getByText('Acme Corp')).toBeVisible()
    await expect(overview.getByText('Globex', { exact: true })).toBeVisible()

    const summary = mockApi.waitFor('GET', '/tenants/:id/billing/summary')
    await overview.getByText('Acme Corp').click()
    expect((await summary).path).toBe('/tenants/t-acme/billing/summary')
    await expect(overview.getByText('123.45 USD')).toBeVisible()
    await expect(overview.getByText('1500m')).toBeVisible()
  })

  test('shows a tenant\'s charges and voids one', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'relies on the mocked billing API')
    const charge = {
      id: 'c-9', tenant_id: 't-acme', kind: 'manual', description: 'Setup fee', period: '2026-09', period_start: '2026-09-01', period_end: '2026-09-01',
      currency: 'USD', status: 'closed', final: true, amount: 40, total: 40, credit_applied: 0, amount_due: 40, external_ref: null,
      line_items: [{ description: 'Setup fee', quantity: 1, unit: 'item', unit_price: 40, total: 40, metadata: {} }],
      closed_at: '2026-10-01T02:30:00Z', voided_at: null,
    }
    mockApi.on('GET', '/billing/charges', () => ({ body: { data: [charge], meta: { total: 1 } } }))
    mockApi.on('GET', '/billing/tenants/:id', () => ({ body: { credit_balances: { USD: 12.5 }, open_charges: [] } }))
    mockApi.on('POST', '/billing/charges/:id/void', () => ({ body: { charge: { ...charge, status: 'void', amount_due: 0 } } }))
    await auth.signIn()
    await page.goto('/settings?tab=billing')

    const charges = mockApi.waitFor('GET', '/billing/charges')
    await page.locator('[data-tour="billing-overview"]').getByText('Acme Corp').click()
    expect((await charges).query.get('tenant')).toBe('t-acme')
    await expect(page.locator('[data-test="charge-due-c-9"]')).toContainText('40.00')
    await expect(page.locator('[data-test="credit-balances"]')).toContainText('$12.50')

    await page.locator('[data-test="charge-c-9"]').getByText('Setup fee').first().click()
    await page.locator('[data-test="charge-void-c-9"]').click()
    const voided = mockApi.waitFor('POST', '/billing/charges/:id/void')
    await page.getByRole('button', { name: 'Void', exact: true }).last().click()
    expect((await voided).path).toBe('/billing/charges/c-9/void')
  })

  test('opens the pricing configuration', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'asserts the configuration request on the mocked API')
    await auth.signIn()
    await page.goto('/settings?tab=billing')
    const configs = mockApi.waitFor('GET', '/cost-configs')
    await page.getByRole('button', { name: 'Configuration' }).click()
    await configs
    await expect(page.getByText('Tenant Cost Overview')).toBeHidden()
  })

  test('is only offered to the central administrator', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'switches users on the mocked API')
    mockApi.db.user = { ...mockApi.db.user, is_admin: false }
    mockApi.db.activeTenant = { ...mockApi.db.tenants[1] }
    await auth.signIn()
    await page.goto('/settings')
    await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Billing' })).toHaveCount(0)
  })

  test('opens for the stack administrator', async ({ page, auth, isStack }) => {
    test.skip(!isStack, 'stack-mode smoke check')
    await auth.signIn()
    await page.goto('/settings?tab=billing')
    await expect(page.getByText('Tenant Cost Overview')).toBeVisible()
  })
})
