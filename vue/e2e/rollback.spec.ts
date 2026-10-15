import { test, expect } from './fixtures/test'
import { broadcastDeploy, openDeployModal } from './helpers/deploy'

// Rolling back = deploying an earlier version again from the Deploy modal.

test.skip(({ e2eMode }) => e2eMode === 'stack', 'deploying needs a Kubernetes region; covered with the mocked API')

test.describe('Roll back', () => {
  test('redeploys the previous version and supersedes the current one', async ({ page, auth, mockApi, realtime }) => {
    await auth.signIn()
    await page.goto('/projects/p-xaas#deployments')
    const rows = page.locator('tbody tr')
    await expect(rows.first()).toContainText('v2')
    await expect(rows.first()).toContainText('Active')

    const modal = await openDeployModal(page)
    await modal.locator('label', { hasText: 'Initial version' }).getByRole('radio').check()
    const sent = mockApi.waitFor('POST', '/projects/p-xaas/deploy')
    await modal.getByRole('button', { name: 'Deploy' }).click()
    expect((await sent).body).toEqual({ version_id: 'v-xaas-1' })

    await broadcastDeploy(mockApi, realtime, 'p-xaas')
    await expect(rows).toHaveCount(2)
    await expect(rows.first()).toContainText('v1')
    await expect(rows.first()).toContainText('Active')
    await expect(rows.nth(1)).toContainText('v2')
    await expect(rows.nth(1)).toContainText('Shut Down')
  })

  test('shows the rolled-back code when the older version is opened', async ({ page, auth }) => {
    await auth.signIn()
    await page.goto('/projects/p-xaas')
    await page.locator('[data-tour="version-list"]').getByText('Initial version').click()
    await expect(page.locator('.monaco-editor').first()).toContainText('"hello"')
    await expect(page.locator('.monaco-editor').first()).not.toContainText('hello, world')
  })
})
