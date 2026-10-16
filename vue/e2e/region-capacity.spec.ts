import { test, expect } from './fixtures/test'

// A region's capacity budgets and FaaS overcommit factors on its Workers tab
// (GET / PUT /knative-regions/{id}/capacity; docs/architecture.md → Region capacity and admission).

test.describe('Region capacity', () => {
  test.skip(({ e2eMode }) => e2eMode === 'stack', 'the mocked API has the budgets of a region with projects')

  test('shows both budgets and saves the overcommit factors', async ({ page, auth, mockApi }) => {
    // a region of this installation (a synced copy has no Edit button)
    Object.assign(mockApi.db.regions[0], { is_synced: false })
    const loaded = mockApi.waitFor('GET', '/knative-regions/:id/capacity')
    await auth.signIn()
    await page.goto('/settings')
    await page.locator('[data-region-row="eu-1"]').getByRole('button', { name: 'Edit', exact: true }).click()
    await page.getByRole('button', { name: 'Workers', exact: true }).click()
    await loaded

    // 8000m capacity, × 3 for scale-to-zero workloads, 700m of it used
    const cpu = page.getByTestId('capacity-cpu_m')
    await expect(cpu).toContainText('Always-on (no overcommit)')
    await expect(cpu).toContainText('0m / 8000m · 8000m free')
    await expect(cpu).toContainText('FaaS overcommit (× 3)')
    await expect(cpu).toContainText('700m / 24000m · 23300m free')
    await expect(page.getByTestId('capacity-memory_mb')).toContainText('FaaS overcommit (× 1.5)')

    await page.getByLabel('CPU overcommit factor').fill('4')
    const saved = mockApi.waitFor('PUT', '/knative-regions/:id/capacity')
    await page.getByRole('button', { name: 'Save factors' }).click()
    expect((await saved).body).toEqual({ faas_cpu_overcommit: 4, faas_memory_overcommit: 1.5 })
    await expect(cpu).toContainText('700m / 32000m · 31300m free')
    await expect(page.getByText('Capacity settings saved.')).toBeVisible()
  })

  test('the projects page shows both budgets, summed and per region', async ({ page, auth }) => {
    await auth.signIn()
    await page.goto('/projects')

    // eu-1 and region-b, 8000m each × 3 for scale-to-zero workloads, 700m of it used on eu-1
    const total = page.getByTestId('resource-total').getByTestId('capacity-cpu_m')
    await expect(total).toContainText('0m / 16000m · 16000m free')
    await expect(total).toContainText('FaaS overcommit (× 3)')
    await expect(total).toContainText('700m / 48000m · 47300m free')

    await page.getByTestId('resource-bars').click()
    await expect(page.getByTestId('region-budgets-r-ready').getByTestId('capacity-cpu_m')).toContainText('700m / 24000m · 23300m free')
    await expect(page.getByTestId('region-budgets-r-cron').getByTestId('capacity-memory_mb')).toContainText('FaaS overcommit (× 1.5)')
  })

  test('a refused factor shows the reason', async ({ page, auth, mockApi }) => {
    Object.assign(mockApi.db.regions[0], { is_synced: false })
    await auth.signIn()
    await page.goto('/settings')
    await page.locator('[data-region-row="eu-1"]').getByRole('button', { name: 'Edit', exact: true }).click()
    await page.getByRole('button', { name: 'Workers', exact: true }).click()

    await page.getByLabel('Memory overcommit factor').fill('12')
    await page.getByRole('button', { name: 'Save factors' }).click()
    await expect(page.getByText('The faas memory overcommit field must be between 1 and 10.')).toBeVisible()
  })
})
