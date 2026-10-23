import { test, expect } from './fixtures/test'
import { confirmDialog } from './helpers/ui'

// Network tab (docs/internal-network.md): the access mode and the projects allowed to call the
// project on the region's internal network. The authz and the routes need a region; the flow
// runs against the mocked API (the Kind suite test-internal-network covers the cluster).

test.skip(({ e2eMode }) => e2eMode === 'stack', 'the internal network needs a region; covered with the mocked API')

test.describe('Network', () => {
  test('shows the internal URL and makes the project internal only after confirmation', async ({ page, auth, mockApi }) => {
    await auth.signIn()
    await page.goto('/projects/p-xaas#network')
    await expect(page.getByTestId('network-internal-url')).toHaveText('http://hello-snippet.platform.runr.internal')
    await expect(page.getByTestId('network-curl')).toContainText('X-Runr-Internal-Token: $RUNR_INTERNAL_TOKEN')
    await expect(page.getByTestId('network-mode-public')).toBeChecked()

    await page.getByTestId('network-mode-internal').check()
    const sent = mockApi.waitFor('PUT', '/projects/p-xaas/network')
    await page.getByTestId('network-save').click()
    await expect(page.getByText('Make the project internal only?')).toBeVisible()
    await confirmDialog(page, 'Make internal')
    expect((await sent).body).toEqual({ access_mode: 'internal', allowed_cidrs: null })
    await expect(page.getByText('Network settings saved.')).toBeVisible()
    await expect(page.getByTestId('network-save')).toBeDisabled()
  })

  test('sets an IP allow list and shows the validation error without ranges', async ({ page, auth, mockApi }) => {
    await auth.signIn()
    await page.goto('/projects/p-xaas#network')
    await page.getByTestId('network-mode-allowlist').check()
    await page.getByTestId('network-save').click()
    await expect(page.getByTestId('network-save-error')).toContainText('required when access mode is allowlist')

    await page.getByTestId('network-cidrs').fill('203.0.113.0/24\n198.51.100.7')
    const sent = mockApi.waitFor('PUT', '/projects/p-xaas/network')
    await page.getByTestId('network-save').click()
    expect((await sent).body).toEqual({ access_mode: 'allowlist', allowed_cidrs: ['203.0.113.0/24', '198.51.100.7'] })
  })

  test('allows callers, marks a grant to another organization and removes one', async ({ page, auth, mockApi }) => {
    await auth.signIn()
    await page.goto('/projects/p-xaas#network')
    await expect(page.getByTestId('network-callers-empty')).toBeVisible()

    let sent = mockApi.waitFor('POST', '/projects/p-xaas/network/callers')
    await page.getByTestId('network-caller-input').fill('billing')
    await page.getByTestId('network-caller-add').click()
    expect((await sent).body).toEqual({ caller: 'billing' })
    await expect(page.locator('[data-caller="billing.platform"]')).toBeVisible()

    sent = mockApi.waitFor('POST', '/projects/p-xaas/network/callers')
    await page.getByTestId('network-caller-input').fill('checkout.acme')
    await page.getByTestId('network-caller-add').click()
    await sent
    await expect(page.locator('[data-caller="checkout.acme"]')).toContainText('grant to acme')

    const removed = mockApi.waitFor('DELETE', '/projects/p-xaas/network/callers/:caller')
    await page.locator('[data-caller="billing.platform"]').getByTestId('network-caller-remove').click()
    await confirmDialog(page, 'Remove')
    expect((await removed).path).toBe('/projects/p-xaas/network/callers/billing.platform')
    await expect(page.locator('[data-caller="billing.platform"]')).toHaveCount(0)
    await expect(page.locator('[data-caller="checkout.acme"]')).toBeVisible()
  })

  test('says why the platform refuses a grant to another organization', async ({ page, auth, mockApi }) => {
    mockApi.db.settings.internal_cross_tenant_grants = false
    await auth.signIn()
    await page.goto('/projects/p-xaas#network')
    await expect(page.getByTestId('network-cross-off')).toBeVisible()

    await page.getByTestId('network-caller-input').fill('checkout.acme')
    await page.getByTestId('network-caller-add').click()
    await expect(page.getByTestId('network-caller-error')).toHaveText('The platform does not allow grants to projects of other organizations.')
  })
})
