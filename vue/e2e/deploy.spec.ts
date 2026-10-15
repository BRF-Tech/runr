import { test, expect } from './fixtures/test'
import { broadcastDeploy, openDeployModal } from './helpers/deploy'

// Deploy modal → POST /projects/:id/deploy → Deployments tab, updated by the
// deployment.* platform events. A real deploy needs a Kubernetes region with
// Knative, so this flow runs against the mocked API only.

test.skip(({ e2eMode }) => e2eMode === 'stack', 'deploying needs a Kubernetes region; covered with the mocked API')

test.describe('Deploy', () => {
  test('deploys the latest version and shows it as the active deployment', async ({ page, auth, mockApi, realtime }) => {
    await auth.signIn()
    await page.goto('/projects/p-xaas#deployments')
    const rows = page.locator('tbody tr')
    await expect(rows).toHaveCount(1)

    const modal = await openDeployModal(page)
    await expect(modal.getByRole('radio').first()).toBeChecked()
    const sent = mockApi.waitFor('POST', '/projects/p-xaas/deploy')
    await modal.getByRole('button', { name: 'Deploy' }).click()
    expect((await sent).body).toEqual({ version_id: 'v-xaas-2' })
    await expect(modal).toBeHidden()

    await broadcastDeploy(mockApi, realtime, 'p-xaas')
    await expect(rows).toHaveCount(2)
    await expect(rows.first()).toContainText('Active')
    await expect(rows.first()).toContainText('v2')
    await expect(rows.nth(1)).toContainText('Shut Down')
  })

  test('switches to the Deployments tab after deploying from the editor', async ({ page, auth, mockApi }) => {
    await auth.signIn()
    await page.goto('/projects/p-faas')
    const modal = await openDeployModal(page)
    const sent = mockApi.waitFor('POST', '/projects/p-faas/deploy')
    await modal.getByRole('button', { name: 'Deploy' }).click()
    expect((await sent).body).toEqual({ version_id: 'v-faas-1' })
    await expect(page).toHaveURL(/#deployments$/)
  })

  test('cannot deploy a version whose build is not ready', async ({ page, auth, mockApi }) => {
    mockApi.db.versions['p-xaas'][0].build_status = 'building'
    await auth.signIn()
    await page.goto('/projects/p-xaas')
    const modal = await openDeployModal(page)

    const radios = modal.getByRole('radio')
    await expect(radios.first()).toBeDisabled()
    await expect(modal.getByText('Building')).toBeVisible()
    await radios.nth(1).check()
    const sent = mockApi.waitFor('POST', '/projects/p-xaas/deploy')
    await modal.getByRole('button', { name: 'Deploy' }).click()
    expect((await sent).body.version_id).toBe('v-xaas-1')
  })

  test('deletes a deployment after confirmation', async ({ page, auth, mockApi }) => {
    await auth.signIn()
    await page.goto('/projects/p-xaas#deployments')
    const row = page.locator('tbody tr').first()
    await expect(row).toContainText('v2')

    await row.getByTitle('Delete Deployment').click()
    await expect(page.getByText('This deployment is currently active.')).toBeVisible()
    const sent = mockApi.waitFor('DELETE', '/projects/p-xaas/deployments/:did')
    await page.getByRole('button', { name: 'Yes, Delete' }).click()
    expect((await sent).path).toBe('/projects/p-xaas/deployments/d-xaas-1')
    await expect(page.getByText('No deployments yet.')).toBeVisible()
  })
})
