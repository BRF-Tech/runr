import { test, expect } from './fixtures/test'
import type { MockApi } from './fixtures/mock-api'
import { effectivePermissions } from './fixtures/data'

// Settings → Audit log (docs/security.md): filters, detail drawer, export, retention.

const EVENTS = [
  {
    id: 'ae-2', occurred_at: '2026-10-10T09:05:00Z', tenant_id: null,
    actor: { type: 'user', id: '1', name: 'Admin', email: 'admin@example.com', auth_method: 'session', impersonator: null },
    action: 'project.update', result: 'success',
    target: { type: 'project', id: 'p-xaas', name: 'hello-python' },
    ip_address: '203.0.113.7', user_agent: 'Mozilla/5.0', request_id: '01JREQ0000000000000000002',
    http_method: 'PUT', route: 'api/projects/{project}', status_code: 200,
    metadata: { changes: [{ model: 'project_config', id: 'c1', event: 'updated', fields: ['env_vars'], env_vars: { added: ['LOG_LEVEL'] } }] },
  },
  {
    id: 'ae-1', occurred_at: '2026-10-10T09:00:00Z', tenant_id: null,
    actor: { type: 'anonymous', id: null, name: 'mallory', email: null, auth_method: null, impersonator: null },
    action: 'auth.login', result: 'failure', target: null,
    ip_address: '198.51.100.9', user_agent: 'curl/8', request_id: '01JREQ0000000000000000001',
    http_method: 'POST', route: 'api/auth/login', status_code: 401,
    metadata: { method: 'password', reason: 'invalid_credentials' },
  },
]

function mockAudit(mockApi: MockApi) {
  mockApi
    .on('GET', '/audit-events', req => {
      const action = req.query.get('action')
      const result = req.query.get('result')
      const data = EVENTS.filter(e => (!action || e.action === action) && (!result || e.result === result))
      return { body: { data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, actions: ['auth.login', 'project.update'], can_export: true, scope: 'platform' } } }
    })
    .on('GET', '/audit-settings', () => ({ body: { retention_days: 365, max_days: 3650, forward_enabled: false, can_update: true } }))
    .on('PUT', '/audit-settings', req => ({ body: { retention_days: req.body.retention_days, max_days: 3650, forward_enabled: false, can_update: true } }))
    .on('GET', '/audit-events/export', req => ({
      body: req.query.get('format') === 'json' ? JSON.stringify(EVENTS) : 'occurred_at,action\n2026-10-10T09:05:00Z,project.update\n',
      headers: { 'content-type': 'text/csv', 'content-disposition': 'attachment; filename="runr-audit-test.csv"' },
    }))
}

test.describe('Audit log', () => {
  test('lists events, filters them and shows the detail', async ({ page, auth, mockApi, isStack }) => {
    if (!isStack) mockAudit(mockApi)
    await auth.signIn()
    await page.goto('/settings?tab=audit-log')

    const log = page.locator('[data-testid="audit-log"]')
    await expect(log.getByRole('heading', { name: 'Audit log' })).toBeVisible()
    await expect(log.locator('[data-testid="audit-row"]').first()).toBeVisible()

    if (isStack) {
      // Signing in through the API is itself an audited event
      await log.locator('[data-testid="audit-filter-action"]').selectOption('auth.login')
      await log.locator('[data-testid="audit-filter-apply"]').click()
      await expect(log.locator('[data-testid="audit-row"]').first()).toContainText('auth.login')
      return
    }

    await expect(log.locator('[data-testid="audit-row"]')).toHaveCount(2)

    const filtered = mockApi.waitFor('GET', '/audit-events')
    await log.locator('[data-testid="audit-filter-result"]').selectOption('failure')
    await log.locator('[data-testid="audit-filter-apply"]').click()
    expect((await filtered).query.get('result')).toBe('failure')
    await expect(log.locator('[data-testid="audit-row"]')).toHaveCount(1)
    await expect(log.locator('[data-testid="audit-row"]')).toContainText('mallory')

    await log.locator('[data-testid="audit-filter-reset"]').click()
    await expect(log.locator('[data-testid="audit-row"]')).toHaveCount(2)

    await log.locator('[data-testid="audit-row"]').first().click()
    const drawer = page.locator('[data-testid="audit-drawer"]')
    await expect(drawer).toBeVisible()
    await expect(drawer).toContainText('01JREQ0000000000000000002')
    await expect(drawer).toContainText('LOG_LEVEL')
    await drawer.locator('[data-testid="audit-drawer-close"]').click()
    await expect(drawer).toBeHidden()
  })

  test('exports the filtered events as CSV', async ({ page, auth, mockApi, isStack }) => {
    if (!isStack) mockAudit(mockApi)
    await auth.signIn()
    await page.goto('/settings?tab=audit-log')

    const exported = isStack ? null : mockApi.waitFor('GET', '/audit-events/export')
    const download = page.waitForEvent('download')
    await page.locator('[data-testid="audit-export-csv"]').click()

    const file = await download
    expect(file.suggestedFilename()).toMatch(/^runr-audit.*\.csv$/)
    if (exported) expect((await exported).query.get('format')).toBe('csv')
  })

  test('changes the retention', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'changes a platform setting; asserted on the mocked API')
    mockAudit(mockApi)
    await auth.signIn()
    await page.goto('/settings?tab=audit-log')

    const card = page.locator('[data-testid="audit-retention"]')
    await expect(card).toContainText('Events are kept for 365 days.')
    await card.locator('[data-testid="audit-retention-days"]').fill('90')
    const saved = mockApi.waitFor('PUT', '/audit-settings')
    await card.locator('[data-testid="audit-retention-save"]').click()
    expect((await saved).body).toEqual({ retention_days: 90 })
    await expect(card).toContainText('Events are kept for 90 days.')
  })

  test('the tab follows the audit.view permission', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'needs a member without audit.view')
    mockApi.on('GET', '/auth/permissions', () => ({ body: effectivePermissions('developer', ['projects.view', 'env.view', 'members.view']) }))
    await auth.signIn({ user: { id: 9, name: 'Dev', email: 'dev@example.test', username: 'dev', is_admin: false } })
    await page.goto('/settings')
    await expect(page.getByRole('button', { name: 'Platform' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Audit log' })).toHaveCount(0)
  })
})
