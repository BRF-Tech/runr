import { test, expect } from './fixtures/test'
import { apiLogin, completeTutorial, type Session } from './helpers/auth'
import { uniqueName, type Seeder } from './helpers/seed'
import { field } from './helpers/ui'

// Settings → Support Tickets (ticket module).

/** Stack mode: a member account (not an administrator), in the first tenant on a multi-tenant stack. */
async function memberSession(seed: Seeder): Promise<Session> {
  const username = uniqueName('e2e_member').replace(/-/g, '_')
  const email = `${username}@e2e.test`
  const password = 'Very-Secret-Pass-123'
  const { user } = await seed.call('post', '/api/users', { name: 'E2E Member', username, email, password })
  seed.onCleanup(() => seed.call('delete', `/api/users/${user.id}`))
  if ((await seed.call('get', '/api/settings')).tenancy_enabled === true) {
    const tenant = ((await seed.call('get', '/api/tenant/current')).tenants ?? [])[0]
    if (!tenant) throw new Error('the stack has no tenant to add the member to')
    await seed.call('post', `/api/tenants/${tenant.id}/users`, { email, role: 'developer' })
  }
  return completeTutorial(seed.api(), await apiLogin(seed.api(), email, password))
}

test.describe('Support tickets', { tag: '@modules' }, () => {
  test('a user opens a ticket with category and priority', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'the stack administrator sees the admin queue; asserted on the mocked API')
    mockApi.db.user = { ...mockApi.db.user, id: 2, name: 'Bob Builder', email: 'bob@runr.test', is_admin: false }
    await auth.signIn()
    await page.goto('/settings?tab=support-tickets')
    await expect(page.getByText('Deployment stuck in pending')).toBeVisible()

    await page.locator('[data-tour="support-new-ticket"]').click()
    const modal = page.locator('div.fixed.inset-0').filter({ has: page.getByRole('heading', { name: 'Create Support Ticket' }) })
    await expect(modal).toBeVisible()
    await expect(modal.locator('[data-tour="ticket-submit"]')).toBeDisabled()

    await field(modal, 'Subject').fill('Cannot reach my function')
    await modal.getByRole('button', { name: 'Deployment', exact: true }).click()
    await modal.getByRole('button', { name: 'High', exact: true }).click()
    await modal.getByPlaceholder('Describe your issue in detail...').fill('The endpoint answers 502 since the last deploy.')

    const sent = mockApi.waitFor('POST', '/support-tickets')
    await modal.locator('[data-tour="ticket-submit"]').click()
    expect((await sent).body).toMatchObject({
      subject: 'Cannot reach my function',
      category: 'deployment',
      priority: 'high',
      description: 'The endpoint answers 502 since the last deploy.',
    })
    await expect(modal).toBeHidden()
    await expect(page.getByText('Cannot reach my function')).toBeVisible()
  })

  test('filters the list by status', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'relies on the seeded mock tickets')
    mockApi.db.user = { ...mockApi.db.user, is_admin: false }
    mockApi.db.tickets.push({ ...mockApi.db.tickets[0], id: 'tk-2', subject: 'Invoice question', status: 'resolved', category: 'account' })
    await auth.signIn()
    await page.goto('/settings?tab=support-tickets')
    await expect(page.getByText('Invoice question')).toBeVisible()

    const filtered = mockApi.waitFor('GET', '/support-tickets')
    await page.getByText('Open', { exact: true }).first().click()
    expect((await filtered).query.get('status')).toBe('open')
    await expect(page.getByText('Invoice question')).toBeHidden()
    await expect(page.getByText('Deployment stuck in pending')).toBeVisible()
  })

  test('administrators work the cross-tenant queue', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'asserts the admin endpoints on the mocked API')
    const queue = mockApi.waitFor('GET', '/admin/support-tickets')
    await auth.signIn()
    await page.goto('/settings?tab=support-tickets')
    await queue
    await expect(page.getByText('Deployment stuck in pending')).toBeVisible()
    await expect(page.locator('[data-tour="support-new-ticket"]')).toHaveCount(0)
  })

  test('the stack accepts a new ticket', async ({ page, auth, seed, isStack }) => {
    test.skip(!isStack, 'stack-mode variant of the first test')
    const subject = uniqueName('e2e-ticket')
    // An administrator works the admin queue (no "New Ticket" button): then a member the test
    // creates opens the ticket (removing the member afterwards removes its tickets as well)
    const member = (await seed.call('get', '/api/auth/me')).is_admin ? await memberSession(seed) : undefined
    await auth.signIn(member)
    await page.goto('/settings?tab=support-tickets')
    await page.locator('[data-tour="support-new-ticket"]').click()
    await field(page, 'Subject').fill(subject)
    await page.getByPlaceholder('Describe your issue in detail...').fill('Created by the e2e suite.')
    await page.locator('[data-tour="ticket-submit"]').click()
    await expect(page.getByText(subject)).toBeVisible()
  })
})
