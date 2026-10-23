import { test, expect } from './fixtures/test'
import { openEditStep } from './helpers/edit'
import { confirmDialog } from './helpers/ui'
import type { Page } from '@playwright/test'
import type { Json } from './fixtures/data'
import type { MockApi } from './fixtures/mock-api'

// Project secrets (docs/security.md, docs/secret-stores.md) with the mocked API: a value lives in the
// project's region (RunR keeps its name, version and fingerprint), a variable can instead refer to
// one of the organization's secret stores (synced by External Secrets Operator), and a store is
// registered with what it has to trust — never a credential. All values here are fake placeholders.

const STORE: Json = {
  id: 'ss-1', name: 'team-vault', provider: 'vault', auth: 'kubernetes',
  settings: { server: 'https://vault.example.test:8200', role: 'runr' },
  refresh_interval: 3600, regions: {}, audience: null,
}

function inRegion(name: string, extra: Json = {}): Json {
  return {
    name, source: 'region', version: 2, stored_in: 'region', fingerprint: '0a1b2c3d4e5f',
    updated_at: '2026-10-20T10:00:00Z', updated_by: { id: 1, name: 'Admin' }, ...extra,
  }
}

function reference(name: string, sync: Json): Json {
  return {
    name, source: 'external', version: 1,
    reference: { store_id: 'ss-1', store_name: 'team-vault', provider: 'vault', path: 'apps/orders', key: 'password' },
    sync, updated_at: '2026-10-20T10:00:00Z', updated_by: { id: 1, name: 'Admin' },
  }
}

function listSecrets(mockApi: MockApi, rows: Json[], canViewValues = true) {
  mockApi.on('GET', '/projects/p-xaas/secrets', () => ({ body: { data: rows, can_view_values: canViewValues, storage: 'region' } }))
}

async function openSecrets(page: Page) {
  await page.goto('/projects/p-xaas')
  const modal = await openEditStep(page, 4)
  return modal.locator('[data-testid="secret-env-editor"]')
}

test.describe('Project secrets: pass-through and secret store references', () => {
  test.skip(({ e2eMode }) => e2eMode === 'stack', 'needs the mocked API (values in a region, a secret store)')

  test('lists a value in the region and a reference with its sync status', async ({ page, auth, mockApi }) => {
    mockApi.db.secretStores.push(STORE)
    listSecrets(mockApi, [
      inRegion('API_TOKEN'),
      inRegion('LEGACY_KEY', { stored_in: 'database', fingerprint: null }),
      reference('DB_PASSWORD', { status: 'failed', message: 'permission denied on apps/orders', synced_at: null }),
    ])
    await auth.signIn()
    const editor = await openSecrets(page)

    const value = editor.locator('[data-testid="secret-row-API_TOKEN"]')
    await expect(value.locator('[data-testid="secret-value-API_TOKEN"]')).toHaveText('••••••••')
    await expect(value.locator('[data-testid="secret-source-API_TOKEN"]')).toHaveText('RunR (in-region)')
    await expect(value.locator('[data-testid="secret-fingerprint-API_TOKEN"]')).toContainText('0a1b2c3d4e5f')
    await expect(editor.locator('[data-testid="secret-in-database-LEGACY_KEY"]')).toHaveText('Still in the database')

    const ref = editor.locator('[data-testid="secret-row-DB_PASSWORD"]')
    await expect(ref.locator('[data-testid="secret-value-DB_PASSWORD"]')).toContainText('Secret store: team-vault · apps/orders#password')
    await expect(ref.locator('[data-testid="secret-sync-DB_PASSWORD"]')).toHaveText('Sync failed')
    await expect(ref.locator('[data-testid="secret-sync-message-DB_PASSWORD"]')).toContainText('permission denied')
    // A reference is read in its store, never through RunR
    await expect(ref.locator('[data-testid="secret-reveal-DB_PASSWORD"]')).toHaveCount(0)

    // Sync now asks the region again and shows what it reports
    mockApi.on('POST', '/projects/p-xaas/secrets/refresh', () => ({ body: {
      data: [inRegion('API_TOKEN'), reference('DB_PASSWORD', { status: 'synced', message: null, synced_at: '2026-10-21T08:00:00Z' })],
      can_view_values: true, storage: 'region',
    } }))
    const refreshed = mockApi.waitFor('POST', '/projects/p-xaas/secrets/refresh')
    await editor.locator('[data-testid="secret-sync-now"]').click()
    await refreshed
    await expect(ref.locator('[data-testid="secret-sync-DB_PASSWORD"]')).toHaveText('Synced')
  })

  test('adds a reference to a secret store', async ({ page, auth, mockApi }) => {
    mockApi.db.secretStores.push(STORE)
    await auth.signIn()
    const editor = await openSecrets(page)

    await editor.locator('[data-testid="secret-add-reference"]').click()
    const form = editor.locator('[data-testid="secret-reference-form"]')
    await form.locator('[data-testid="secret-ref-name"]').fill('DB_PASSWORD')
    await form.locator('[data-testid="secret-ref-store"]').selectOption('ss-1')
    await form.locator('[data-testid="secret-ref-path"]').fill('apps/orders')
    await form.locator('[data-testid="secret-ref-key"]').fill('password')

    const sent = mockApi.waitFor('PUT', '/projects/p-xaas/secrets/DB_PASSWORD')
    await form.locator('[data-testid="secret-ref-save"]').click()
    expect((await sent).body).toEqual({ reference: { store_id: 'ss-1', path: 'apps/orders', key: 'password' } })

    const row = editor.locator('[data-testid="secret-row-DB_PASSWORD"]')
    await expect(row.locator('[data-testid="secret-value-DB_PASSWORD"]')).toContainText('team-vault · apps/orders#password')
    await expect(row.locator('[data-testid="secret-sync-DB_PASSWORD"]')).toHaveText('Pending')
    await expect(editor.locator('[data-testid="secret-reference-form"]')).toHaveCount(0)
  })

  test('warns when the value in the region is not the one RunR wrote', async ({ page, auth, mockApi }) => {
    listSecrets(mockApi, [inRegion('API_TOKEN')])
    mockApi.on('GET', '/projects/p-xaas/secrets/API_TOKEN/value', () => ({
      body: { name: 'API_TOKEN', value: 'placeholder-changed-in-cluster', matches_fingerprint: false },
    }))
    await auth.signIn()
    const editor = await openSecrets(page)

    await editor.locator('[data-testid="secret-reveal-API_TOKEN"]').click()
    await expect(editor.locator('[data-testid="secret-value-API_TOKEN"]')).toHaveText('placeholder-changed-in-cluster')
    await expect(editor.locator('[data-testid="secret-mismatch-API_TOKEN"]')).toContainText('changed in the region')
  })

  test('says so when the region does not answer, and changes nothing', async ({ page, auth, mockApi }) => {
    listSecrets(mockApi, [])
    const message = 'The cluster of region "eu-1" does not answer, and secret values are kept there only. Nothing was changed; try again once it answers.'
    mockApi.on('PUT', '/projects/p-xaas/secrets/:name', () => ({ status: 503, body: { message, reason: 'region_unreachable' } }))
    await auth.signIn()
    const editor = await openSecrets(page)

    await editor.locator('[data-testid="secret-new-name"]').fill('NEW_TOKEN')
    await editor.locator('[data-testid="secret-new-value"]').fill('placeholder-value')
    await editor.locator('[data-testid="secret-add"]').click()
    await expect(editor.locator('[data-testid="secret-error"]')).toHaveText(message)
    await expect(editor.locator('[data-testid="secret-row-NEW_TOKEN"]')).toHaveCount(0)
    // The typed value stays in the (password) field for a retry, never shown as text
    await expect(page.getByText('placeholder-value')).toHaveCount(0)
  })

  test('registers a secret store without a credential and shows what it has to trust', async ({ page, auth, mockApi }) => {
    await auth.signIn()
    await page.goto('/settings?tab=secret-stores')
    const pageRoot = page.locator('[data-testid="secret-stores"]')
    await expect(pageRoot.locator('[data-testid="secret-stores-empty"]')).toBeVisible()

    await pageRoot.locator('[data-testid="secret-store-create"]').click()
    const form = pageRoot.locator('[data-testid="secret-store-form"]')
    await form.locator('[data-field="name"]').fill('team-vault')
    await form.locator('[data-field="provider"]').selectOption('vault')
    await form.locator('[data-field="auth"]').selectOption('kubernetes')
    // Only addresses, mounts and roles: no token / password field exists
    await expect(form.locator('input[type="password"]')).toHaveCount(0)
    await form.locator('[data-field="settings.server"]').fill('https://vault.example.test:8200')
    await form.locator('[data-field="settings.role"]').fill('runr')

    const created = mockApi.waitFor('POST', '/secret-stores')
    await form.locator('[data-testid="secret-store-save"]').click()
    const body = (await created).body
    expect(body).toMatchObject({ name: 'team-vault', provider: 'vault', auth: 'kubernetes', settings: { server: 'https://vault.example.test:8200', role: 'runr' } })
    expect(Object.keys(body.settings).some(k => /token|password|secret/i.test(k))).toBe(false)

    const row = pageRoot.locator('[data-testid="secret-store-team-vault"]')
    await expect(row).toContainText('Vault / OpenBao')

    await row.locator('[data-testid="secret-store-trust-team-vault"]').click()
    const trust = pageRoot.locator('[data-testid="secret-store-trust"]')
    await expect(trust.locator('[data-testid="secret-store-trust-issuer"]')).toContainText('https://kubernetes.default.svc.cluster.local')
    await expect(trust.locator('[data-testid="secret-store-trust-sa"]')).toHaveText(/^runr-ss-/)
    await expect(trust.locator('[data-testid="secret-store-trust-private"]')).toBeVisible()
    await expect(trust.locator('[data-testid="secret-store-trust-jwks"]')).toContainText('e2e-key')
    await expect(trust.locator('[data-testid="secret-store-snippet-vaultKubernetesRole"]')).toContainText('bound_service_account_names=runr-ss-')

    const verified = mockApi.waitFor('POST', '/secret-stores/:id/verify')
    await trust.locator('[data-testid="secret-store-verify"]').click()
    expect((await verified).body).toMatchObject({ region_id: expect.any(String) })
    await expect(trust.locator('[data-testid="secret-store-verify-result"]')).toContainText('Ready')
  })

  test('a store that project secrets refer to is not deleted', async ({ page, auth, mockApi }) => {
    mockApi.db.secretStores.push(STORE)
    mockApi.on('DELETE', '/secret-stores/ss-1', () => ({ status: 409, body: { message: 'Secret variables of orders refer to this store: change them first.', projects: ['orders'] } }))
    await auth.signIn()
    await page.goto('/settings?tab=secret-stores')

    await page.locator('[data-testid="secret-store-delete-team-vault"]').click()
    await confirmDialog(page, 'Delete')
    await expect(page.getByText('Secret variables of orders refer to this store')).toBeVisible()
    await expect(page.locator('[data-testid="secret-store-team-vault"]')).toBeVisible()
  })

  test('installs External Secrets Operator from the region\'s Secrets tab', async ({ page, auth, mockApi }) => {
    Object.assign(mockApi.db.regions[0], { is_synced: false })
    await auth.signIn()
    await page.goto('/settings')
    await page.locator(`[data-region-row="${mockApi.db.regions[0].name}"]`).getByRole('button', { name: 'Edit', exact: true }).click()
    await page.getByRole('button', { name: 'Secrets', exact: true }).click()

    const card = page.locator('[data-testid="eso-card"]')
    await expect(card.locator('[data-testid="eso-status"]')).toHaveText('Not installed')
    const sent = mockApi.waitFor('POST', '/knative-regions/:id/external-secrets')
    await card.locator('[data-testid="eso-install"]').click()
    await sent
    // installing → ready once the mocked operator runs (the card polls)
    await expect(card.locator('[data-testid="eso-status"]')).toHaveText('Ready')
    await expect(card.locator('[data-testid="eso-chart-version"]')).toContainText('0.19.2')
  })
})

// The region's own OpenBao (docs/secret-stores.md, "OpenBao in the region"): installed from the
// region's Secrets tab once External Secrets Operator runs, the place new values go, and the
// issuer of dynamic Postgres credentials for bindings (docs/bindings.md).
const ORDERS_DB: Json = {
  id: 'b-orders', name: 'orders-db', resource_name: 'orders-db-abcd1234', type: 'postgres', plan: 'shared',
  knative_region_id: 'r-ready', region: { id: 'r-ready', name: 'eu-1' }, status: 'ready', status_message: null,
  settings: { storage_gb: 1, connection_limit: 20, statement_timeout: '30s' },
  env_keys: ['DATABASE_URL', 'PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD', 'PGSSLMODE'],
  details: { cluster: 'runr-pg-shared', database: 'b_orders_db' }, usage_bytes: 0, purge_at: null,
}

/** The project bindings endpoints for one test: one ready Postgres binding to attach. */
function fakeProjectBindings(mockApi: MockApi) {
  const attachments: Json[] = []
  const json = (a: Json) => ({ ...a, env_names: ORDERS_DB.env_keys.map((k: string) => (a.env_prefix ?? '') + k), binding: ORDERS_DB })
  mockApi.on('GET', '/bindings/types', () => ({ body: { data: {}, retention_days: 7 } }))
  mockApi.on('GET', '/projects/:id/bindings', () => ({ body: {
    data: attachments.map(json),
    available: attachments.length ? [] : [ORDERS_DB],
    secret: 'hello-bindings',
    abilities: { manage: true, view_credentials: true },
  } }))
  mockApi.on('POST', '/projects/:id/bindings', (req) => {
    const a = { id: 'a-1', binding_id: req.body?.binding_id, project_id: req.params.id, env_prefix: req.body?.env_prefix ?? '', credential_mode: req.body?.credentials ?? 'static' }
    attachments.push(a)
    return { status: 201, body: { data: json(a) } }
  })
  mockApi.on('PATCH', '/projects/:id/bindings/:aid', (req) => {
    const a = attachments.find(x => x.id === req.params.aid)!
    if (req.body?.credentials) a.credential_mode = req.body.credentials
    return { body: { data: json(a) } }
  })
  return attachments
}

test.describe('Project secrets: the region\'s own OpenBao', () => {
  test.skip(({ e2eMode }) => e2eMode === 'stack', 'needs the mocked API (an OpenBao in a region)')

  test('installs OpenBao from the region\'s Secrets tab and sets where new values go', async ({ page, auth, mockApi }) => {
    Object.assign(mockApi.db.regions[0], { is_synced: false, eso_status: 'ready' })
    await auth.signIn()
    await page.goto('/settings')
    await page.locator(`[data-region-row="${mockApi.db.regions[0].name}"]`).getByRole('button', { name: 'Edit', exact: true }).click()
    await page.getByRole('button', { name: 'Secrets', exact: true }).click()

    const card = page.locator('[data-testid="openbao-card"]')
    await expect(card.locator('[data-testid="openbao-status"]')).toHaveText('Not installed')
    await expect(card).toContainText('RunR keeps no OpenBao token')
    const sent = mockApi.waitFor('POST', '/knative-regions/:id/openbao')
    await card.locator('[data-testid="openbao-install"]').click()
    await sent
    // installing → ready (the card polls)
    await expect(card.locator('[data-testid="openbao-status"]')).toHaveText('Ready')
    await expect(card.locator('[data-testid="openbao-version"]')).toContainText('2.4.1')

    const toggle = card.locator('[data-testid="openbao-default"]')
    await expect(toggle).toBeChecked()
    const patched = mockApi.waitFor('PATCH', '/knative-regions/:id/openbao')
    await toggle.uncheck()
    expect((await patched).body).toEqual({ default_for_values: false })
  })

  test('waits for External Secrets Operator before OpenBao can be installed', async ({ page, auth, mockApi }) => {
    Object.assign(mockApi.db.regions[0], { is_synced: false, eso_status: 'not_installed' })
    await auth.signIn()
    await page.goto('/settings')
    await page.locator(`[data-region-row="${mockApi.db.regions[0].name}"]`).getByRole('button', { name: 'Edit', exact: true }).click()
    await page.getByRole('button', { name: 'Secrets', exact: true }).click()

    const card = page.locator('[data-testid="openbao-card"]')
    await expect(card.locator('[data-testid="openbao-install"]')).toBeDisabled()
    await expect(card.locator('[data-testid="openbao-needs-eso"]')).toBeVisible()
  })

  test('shows a value that lives in the region\'s OpenBao', async ({ page, auth, mockApi }) => {
    listSecrets(mockApi, [
      inRegion('API_TOKEN'),
      inRegion('BAO_TOKEN', { source: 'openbao', stored_in: 'openbao', fingerprint: '9f8e7d6c5b4a' }),
    ])
    await auth.signIn()
    const editor = await openSecrets(page)

    await expect(editor.locator('[data-testid="secret-source-BAO_TOKEN"]')).toHaveText('OpenBao (in-region)')
    await expect(editor.locator('[data-testid="secret-fingerprint-BAO_TOKEN"]')).toContainText('9f8e7d6c5b4a')
    await expect(editor.locator('[data-testid="secret-source-API_TOKEN"]')).toHaveText('RunR (in-region)')
  })

  test('attaches a Postgres binding with dynamic credentials from OpenBao', async ({ page, auth, mockApi }) => {
    fakeProjectBindings(mockApi)
    await auth.signIn()
    await page.goto('/projects/p-xaas#bindings')

    await page.locator('[data-bindings-open]').click()
    const form = page.locator('[data-bindings-form]')
    await form.locator('[data-field="binding_id"]').selectOption('b-orders')
    await form.locator('[data-field="env_prefix"]').fill('ORDERS_')
    await expect(form.locator('[data-dynamic-option]')).toContainText('Dynamic credentials (OpenBao)')
    await form.locator('[data-field="dynamic"]').check()

    const attached = mockApi.waitFor('POST', '/projects/p-xaas/bindings')
    await form.locator('[data-bindings-submit]').click()
    expect((await attached).body).toEqual({ binding_id: 'b-orders', env_prefix: 'ORDERS_', credentials: 'dynamic' })

    const row = page.locator('[data-attachment="orders-db"]')
    await expect(row.locator('[data-dynamic-badge]')).toHaveText('Dynamic (OpenBao)')

    // Back to the binding's own user
    const patched = mockApi.waitFor('PATCH', '/projects/p-xaas/bindings/:aid')
    await row.locator('[data-action="switch-credentials"]').click()
    await confirmDialog(page, 'Use the binding\'s own user')
    expect((await patched).body).toEqual({ credentials: 'static' })
    await expect(row.locator('[data-dynamic-badge]')).toHaveCount(0)
  })
})
