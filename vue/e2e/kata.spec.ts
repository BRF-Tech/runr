import { test, expect } from './fixtures/test'
import type { Page } from '@playwright/test'

// Kata Containers on a region's Isolation tab with the mocked API's default
// GET /knative-regions/{id}/kata-shims (docs/kata-hypervisors.md): worker nodes with /dev/kvm,
// every hypervisor RunR installs available, Firecracker after the devmapper snapshotter setup.

async function openIsolationTab(page: Page, regionName: string) {
  await page.goto('/settings')
  await page.locator(`[data-region-row="${regionName}"]`).getByRole('button', { name: 'Edit', exact: true }).click()
  await page.getByRole('button', { name: 'Isolation', exact: true }).click()
}

test.describe('Kata hypervisors', () => {
  test.skip(({ e2eMode }) => e2eMode === 'stack', 'needs the mocked API (a cluster with /dev/kvm on its nodes)')

  test('lists the hypervisors the nodes can run; Firecracker asks for the thin-pool size', async ({ page, auth, mockApi }) => {
    // a region of this installation (a synced copy has no Edit button)
    Object.assign(mockApi.db.regions[0], { is_synced: false })
    const shims = mockApi.waitFor('GET', '/knative-regions/:id/kata-shims')
    await auth.signIn()
    await openIsolationTab(page, 'eu-1')
    await shims

    for (const name of [/^Cloud Hypervisor/, /^QEMU/, /^Dragonball/, /^Firecracker/]) {
      await expect(page.getByRole('button', { name })).toBeEnabled()
    }
    await expect(page.getByRole('button', { name: /^Cloud Hypervisor/ })).toContainText('Recommended')
    await expect(page.getByRole('button', { name: /^Firecracker/ })).toContainText('RunR sets up the devmapper snapshotter')
    // Every node has /dev/kvm: no hint about nodes without it
    await expect(page.getByText(/hardware virtualization \(\/dev\/kvm\)/)).toHaveCount(0)

    await page.getByRole('button', { name: /^Firecracker/ }).click()
    await expect(page.locator('#kata-devmapper-pool')).toHaveValue('20')
  })
})
