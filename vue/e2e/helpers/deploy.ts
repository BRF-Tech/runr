import { expect, type Locator, type Page } from '@playwright/test'
import type { MockApi } from '../fixtures/mock-api'
import type { FakeRealtime } from '../fixtures/realtime'

/** Opens the Deploy modal from the project header. */
export async function openDeployModal(page: Page): Promise<Locator> {
  await page.locator('[data-tour="action-buttons"]').getByRole('button', { name: 'Deploy' }).click()
  const modal = page.locator('.card').filter({ hasText: 'Which version do you want to deploy?' })
  await expect(modal).toBeVisible()
  return modal
}

/**
 * What the API broadcasts after POST /deploy: the new deployment, then the previous
 * active one shutting down. The deployments table only learns about both this way.
 */
export async function broadcastDeploy(mockApi: MockApi, realtime: FakeRealtime, projectId: string): Promise<void> {
  const [created, ...older] = mockApi.db.deployments[projectId]
  await realtime.platform('deployment.created', { project_id: projectId, deployment: created })
  for (const d of older.filter(d => d.status === 'shut_down')) {
    await realtime.platform('deployment.status', { project_id: projectId, deployment_id: d.id, status: d.status })
  }
}
