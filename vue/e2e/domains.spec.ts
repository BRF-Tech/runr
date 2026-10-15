import { test, expect } from './fixtures/test'
import { confirmDialog } from './helpers/ui'

// Custom domains tab: add → DNS records → verify → delete. Verification and routing
// need DNS + a Knative region, so the flow runs against the mocked API.

test.skip(({ e2eMode }) => e2eMode === 'stack', 'domain verification needs DNS and a routed region; covered with the mocked API')

test.describe('Custom domains', () => {
  test('adds a domain, shows its DNS records and verifies it', async ({ page, auth, mockApi }) => {
    await auth.signIn()
    await page.goto('/projects/p-xaas#domains')
    await expect(page.getByText('No custom domains yet.')).toBeVisible()
    await expect(page.getByText('hello-snippet.eu-1.runr.test', { exact: true })).toBeVisible()

    const sent = mockApi.waitFor('POST', '/projects/p-xaas/domains')
    await page.getByPlaceholder('api.example.com').fill('Shop.Example.com')
    await page.getByRole('button', { name: 'Add domain' }).click()
    expect((await sent).body).toEqual({ domain: 'Shop.Example.com' })

    await expect(page.getByText('shop.example.com').first()).toBeVisible()
    await expect(page.getByText('Pending').first()).toBeVisible()
    await expect(page.getByText('_runr-challenge.shop.example.com')).toBeVisible()
    await expect(page.getByText('ingress.eu-1.runr.test')).toBeVisible()
    await expect(page.getByPlaceholder('api.example.com')).toHaveValue('')

    const verify = mockApi.waitFor('POST', '/projects/p-xaas/domains/:did/verify')
    await page.getByRole('button', { name: 'Verify', exact: true }).click()
    await verify
    await expect(page.getByText('Active').first()).toBeVisible()
    await expect(page.getByRole('button', { name: 'Check again' })).toBeVisible()
  })

  test('shows the validation error for an invalid host name', async ({ page, auth }) => {
    await auth.signIn()
    await page.goto('/projects/p-xaas#domains')
    await page.getByPlaceholder('api.example.com').fill('not a domain')
    await page.getByRole('button', { name: 'Add domain' }).click()
    await expect(page.getByText('The domain field must be a valid host name.')).toBeVisible()
  })

  test('removes a domain after confirmation', async ({ page, auth, mockApi }) => {
    mockApi.db.domains['p-xaas'].push({
      id: 'dom-1', domain: 'old.example.com', status: 'active',
      verification: null, dns_targets: [], tls_expires_at: null, created_at: new Date().toISOString(),
    })
    await auth.signIn()
    await page.goto('/projects/p-xaas#domains')
    await expect(page.getByText('old.example.com')).toBeVisible()

    await page.getByTitle('Delete', { exact: true }).click()
    await expect(page.getByText('Remove old.example.com from this project?', { exact: false })).toBeVisible()
    const sent = mockApi.waitFor('DELETE', '/projects/p-xaas/domains/:did')
    await confirmDialog(page, 'Delete')
    expect((await sent).path).toBe('/projects/p-xaas/domains/dom-1')
    await expect(page.getByText('No custom domains yet.')).toBeVisible()
  })
})
