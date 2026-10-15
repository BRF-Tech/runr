import { test, expect } from './fixtures/test'
import type { Page } from '@playwright/test'
import { uniqueName } from './helpers/seed'
import { confirmDialog, field } from './helpers/ui'

// Settings → Users (single-tenant user management).

function userRow(page: Page, username: string) {
  return page.locator('div.flex.items-center.gap-3').filter({ hasText: `@${username}` })
}

test.describe('Users', () => {
  test('creates, edits and deletes a user', async ({ page, auth, seed, mockApi, isStack }) => {
    test.skip(isStack && (await seed.call('get', '/api/settings')).tenancy_enabled === true,
      'single-tenant user management: on a multi-tenant stack Settings → Users is the tenancy module\'s platform user list; runs against a stack with TENANCY_ENABLED=false')
    const username = uniqueName('e2e').replace(/-/g, '_')
    const email = `${username}@e2e.test`
    if (isStack) {
      seed.onCleanup(async () => {
        const { users = [] } = await seed.call('get', `/api/users?search=${encodeURIComponent(email)}`)
        for (const u of users) if (u.username === username) await seed.call('delete', `/api/users/${u.id}`)
      })
    }
    await auth.signIn()
    await page.goto('/settings?tab=users')
    await expect(page.getByRole('heading', { name: 'User Management' })).toBeVisible()

    // Create
    await page.locator('[data-tour="users-add-btn"]').click()
    const form = page.locator('[data-tour="users-add-form"]')
    await field(form, 'Name').fill('E2E Person')
    await field(form, 'Username').fill(username)
    await field(form, 'Email').fill(email)
    await field(form, 'Password').fill('Very-Secret-Pass-123')
    const created = isStack ? null : mockApi.waitFor('POST', '/users')
    await form.getByRole('button', { name: 'Save' }).click()
    if (created) expect((await created).body).toEqual({ name: 'E2E Person', username, email, password: 'Very-Secret-Pass-123' })
    await expect(page.getByText('User created.')).toBeVisible()
    await expect(userRow(page, username)).toBeVisible()

    // Edit
    await userRow(page, username).getByRole('button').filter({ has: page.locator('path[d^="M13.586"]') }).click()
    // Only the row being edited shows a form now (the add form closed after saving)
    await field(page, 'Name').fill('E2E Person Renamed')
    const updated = isStack ? null : mockApi.waitFor('PATCH', '/users/:id')
    await page.getByRole('button', { name: 'Save', exact: true }).click()
    if (updated) expect((await updated).body).toMatchObject({ name: 'E2E Person Renamed', email })
    await expect(page.getByText('User updated.')).toBeVisible()
    await expect(userRow(page, username)).toContainText('E2E Person Renamed')

    // Delete
    await userRow(page, username).getByRole('button').last().click()
    await expect(page.getByText(`Are you sure you want to delete "E2E Person Renamed"?`)).toBeVisible()
    await confirmDialog(page, 'Delete User')
    await expect(page.getByText('User deleted.')).toBeVisible()
    await expect(userRow(page, username)).toHaveCount(0)
  })

  test('filters the list by search', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'relies on the seeded mock users')
    await auth.signIn()
    await page.goto('/settings?tab=users')
    await expect(userRow(page, 'bob')).toBeVisible()

    const searched = mockApi.waitFor('GET', '/users')
    await page.locator('[data-tour="users-search"]').fill('ada')
    expect((await searched).query.get('search')).toBe('ada')
    await expect(userRow(page, 'bob')).toHaveCount(0)
    await expect(page.getByText('@admin')).toBeVisible()
  })

  test('resets the second factor of another user', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'needs a user with 2FA; the mocked API has one')
    await auth.signIn()
    await page.goto('/settings?tab=users')
    const row = userRow(page, 'bob')
    await expect(row.getByText('2FA')).toBeVisible()

    await row.getByTitle('Reset 2FA').click()
    const sent = mockApi.waitFor('POST', '/users/:id/reset-2fa')
    await confirmDialog(page, 'Reset Two-Factor Authentication')
    expect((await sent).path).toBe('/users/2/reset-2fa')
    await expect(page.getByText('2FA has been reset.')).toBeVisible()
    await expect(row.getByText('2FA', { exact: true })).toHaveCount(0)
  })
})
