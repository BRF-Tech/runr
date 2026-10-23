import { test, expect } from './fixtures/test'
import { uniqueName } from './helpers/seed'
import { confirmDialog } from './helpers/ui'

// Administration → API Tokens: create (plain token shown once), scoped abilities, delete.

test.describe('API tokens', () => {
  test('creates a token, shows the plain value once and deletes it', async ({ page, auth, seed, mockApi, isStack }) => {
    const name = uniqueName('e2e-token')
    if (isStack) {
      seed.onCleanup(async () => {
        const { tokens = [] } = await seed.call('get', '/api/tokens')
        for (const t of tokens) if (t.name === name) await seed.call('delete', `/api/tokens/${t.id}`)
      })
    }
    await auth.signIn()
    await page.goto('/settings?tab=tokens')
    await expect(page.getByRole('heading', { name: 'API Token Management' })).toBeVisible()

    await page.getByPlaceholder('e.g. CI/CD, Mobile App').fill(name)
    await page.getByRole('button', { name: '30 days' }).click()
    const sent = isStack ? null : mockApi.waitFor('POST', '/tokens')
    await page.getByRole('button', { name: 'Create Token' }).click()

    if (sent) {
      const { body } = await sent
      expect(body.name).toBe(name)
      expect(body.abilities).toEqual(['*'])
      expect(body.expires_at).toBeTruthy()
    }
    await expect(page.getByText("Token created! You won't be able to see this value again.")).toBeVisible()
    await page.getByRole('button', { name: 'Dismiss' }).click()
    await expect(page.getByText("Token created!")).toBeHidden()

    const row = page.locator('[data-tour="tokens-list"] > div').filter({ hasText: name })
    await expect(row).toBeVisible()
    await row.getByRole('button').click()
    await expect(page.getByText(`Are you sure you want to delete "${name}"?`)).toBeVisible()
    await confirmDialog(page, 'Delete Token')
    await expect(page.locator('[data-tour="tokens-list"]').getByText(name)).toHaveCount(0)
  })

  test('creates a token limited to chosen abilities without expiry', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'asserts the abilities payload on the mocked API')
    await auth.signIn()
    await page.goto('/settings?tab=tokens')
    await page.getByPlaceholder('e.g. CI/CD, Mobile App').fill('deploy-bot')
    await page.getByRole('button', { name: 'No expiration' }).click()
    await page.locator('[data-tour="tokens-custom-access"]').click()
    await page.locator('[data-tour="tokens-permissions"]').getByText('Deploy', { exact: true }).click()
    const sent = mockApi.waitFor('POST', '/tokens')
    await page.getByRole('button', { name: 'Create Token' }).click()

    const { body } = await sent
    // Abilities are catalog permissions (docs/permissions.md); "Deploy" is projects.deploy
    expect(body).toMatchObject({ name: 'deploy-bot', abilities: ['projects.deploy'] })
    expect(body).not.toHaveProperty('expires_at')
  })

  test('requires a name before creating', async ({ page, auth }) => {
    await auth.signIn()
    await page.goto('/settings?tab=tokens')
    await expect(page.getByRole('button', { name: 'Create Token' })).toBeDisabled()
  })
})
