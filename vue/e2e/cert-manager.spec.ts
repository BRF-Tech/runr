import { test, expect } from './fixtures/test'

// cert-manager of a region and what custom domains see of it (docs/regions.md#cert-manager), with
// the mocked API: the region's cert-manager card (install, status, the issuer, the region CA) and
// the custom domains of a project on that region (the TLS mode offered or why not, the CA).

test.skip(({ e2eMode }) => e2eMode === 'stack', 'cert-manager needs a region cluster; covered with the mocked API')

test.describe('cert-manager', () => {
  test('the region card installs it and shows the issuer and the region CA', async ({ page, auth, mockApi }) => {
    // a region of this installation (a synced copy has no Edit button)
    Object.assign(mockApi.db.regions[0], { is_synced: false })
    await auth.signIn()
    await page.goto('/settings')
    await page.locator('[data-region-row="eu-1"]').getByRole('button', { name: 'Edit', exact: true }).click()

    const card = page.getByTestId('cert-manager')
    await expect(card.getByTestId('cert-manager-status')).toHaveText('Not installed')
    await expect(card.getByTestId('cert-manager-not-installed')).toBeVisible()

    const install = mockApi.waitFor('POST', '/knative-regions/:id/cert-manager')
    await card.getByTestId('cert-manager-install').click()
    expect((await install).path).toBe('/knative-regions/r-ready/cert-manager')
    await expect(card.getByTestId('cert-manager-status')).toHaveText('Installing')
    await expect(card.getByTestId('cert-manager-log')).toContainText('Installation queued')

    // the install finishes: the card polls while it installs
    Object.assign(mockApi.db.regions[0], {
      cert_manager_status: 'ready', cert_manager_managed: true, cert_manager_version: 'v1.21.2', cert_manager_issuer: 'runr-ca',
      cert_manager_status_message: '[10:00:40] cert-manager is ready; custom domains in TLS mode cert-manager are issued by ClusterIssuer runr-ca.\n',
    })
    await expect(card.getByTestId('cert-manager-status')).toHaveText('Ready', { timeout: 15_000 })
    await expect(card.getByTestId('cert-manager-owner')).toContainText('installed by RunR')
    await expect(card.getByTestId('cert-manager-issuer')).toContainText('runr-ca')
    await expect(card.getByTestId('cert-manager-why')).toContainText('not reachable from the internet')

    const ca = mockApi.waitFor('GET', '/knative-regions/:id/cert-manager/ca-certificate')
    const download = page.waitForEvent('download')
    await card.getByTestId('cert-manager-ca').click()
    await ca
    expect((await download).suggestedFilename()).toBe('eu-1-runr-ca.crt')

    // RunR's default issuer: Let's Encrypt with the region's own e-mail
    await card.getByTestId('cert-manager-mode').selectOption('acme')
    await card.getByTestId('cert-manager-email').fill('ops@example.org')
    const settings = mockApi.waitFor('PUT', '/knative-regions/:id/settings')
    await card.getByTestId('cert-manager-save-issuer').click()
    expect((await settings).body).toEqual({ cert_manager_issuer_mode: 'acme', cert_manager_acme_email: 'ops@example.org' })
  })

  test('custom domains say why TLS mode cert-manager is not offered yet', async ({ page, auth, mockApi }) => {
    Object.assign(mockApi.db.regions[0], { cert_manager_status: 'installing' })
    mockApi.db.domains['p-xaas'].push({
      id: 'dom-1', domain: 'shop.example.org', status: 'active', tls_mode: 'none', tls_note: 'Served over HTTP.',
      verification: null, dns_targets: [], tls_expires_at: null, created_at: new Date().toISOString(),
    })
    await auth.signIn()
    await page.goto('/projects/p-xaas#domains')

    await expect(page.getByTestId('domains-cert-manager')).toContainText('cert-manager is being installed in this region')

    await page.getByRole('button', { name: 'HTTPS' }).click()
    const option = page.locator('label').filter({ hasText: 'cert-manager' }).filter({ has: page.locator('input[type="radio"]') })
    await expect(option).toContainText('offered as soon as it runs')
    await expect(option.locator('input')).toBeDisabled()
  })

  test('new domains get a certificate from the region CA, which the page offers', async ({ page, auth, mockApi }) => {
    Object.assign(mockApi.db.regions[0], { cert_manager_status: 'ready', cert_manager_issuer: 'runr-ca' })
    await auth.signIn()
    await page.goto('/projects/p-xaas#domains')

    await expect(page.getByTestId('domains-cert-manager')).toContainText('from the region\'s own CA')

    const added = mockApi.waitFor('POST', '/projects/p-xaas/domains')
    await page.getByPlaceholder('api.example.com').fill('shop.example.org')
    await page.getByRole('button', { name: 'Add domain' }).click()
    await added
    expect(mockApi.db.domains['p-xaas'][0].tls_mode).toBe('cert-manager')

    const ca = mockApi.waitFor('GET', '/projects/p-xaas/domains/ca-certificate')
    const download = page.waitForEvent('download')
    await page.getByTestId('domains-download-ca').click()
    await ca
    expect((await download).suggestedFilename()).toBe('eu-1-runr-ca.crt')
  })
})
