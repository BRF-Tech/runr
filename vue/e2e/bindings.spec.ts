import { test, expect } from './fixtures/test'
import type { MockApi } from './fixtures/mock-api'
import { effectivePermissions, type Json } from './fixtures/data'
import { confirmDialog } from './helpers/ui'

// Bindings (docs/bindings.md): project Bindings tab (create + attach with an env prefix, attach
// existing, rotate, detach), Settings → Data (delete with typed name, restore), Region → Data
// services (external S3 store, Postgres) and the credentials reveal permission. Provisioning
// runs in a Kubernetes region, so the flows run against the mocked API.

test.skip(({ e2eMode }) => e2eMode === 'stack', 'bindings need region data services; covered with the mocked API')

const KEYS: Record<string, string[]> = {
  kv: ['REDIS_URL', 'KV_PREFIX'],
  object: ['S3_ENDPOINT', 'S3_BUCKET', 'S3_ACCESS_KEY_ID', 'S3_SECRET_ACCESS_KEY', 'S3_REGION', 'S3_FORCE_PATH_STYLE'],
  postgres: ['DATABASE_URL', 'PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD', 'PGSSLMODE'],
}

interface BindingsState {
  bindings: Json[]
  attachments: Json[]
  services: Record<string, Json>
}

function bindingRow(id: string, name: string, type: string, extra: Json = {}): Json {
  return {
    id, name, resource_name: `${name}-abcd1234`, type, plan: 'shared', knative_region_id: 'r-ready',
    region: { id: 'r-ready', name: 'eu-1' }, status: 'ready', status_message: null,
    settings: type === 'postgres' ? { storage_gb: 1, connection_limit: 20, statement_timeout: '30s' } : { memory_mb: 64 },
    env_keys: KEYS[type] ?? [], details: {}, usage_bytes: 0, purge_at: null, ...extra,
  }
}

function emptyService(service: string): Json {
  return { service, installed: false, mode: null, status: 'not_installed', status_message: null, config: null, bindings_count: 0 }
}

/** Stateful fake of the bindings endpoints for one test. */
function fakeBindings(mockApi: MockApi, seed: Partial<BindingsState> = {}): BindingsState {
  const state: BindingsState = {
    bindings: seed.bindings ?? [],
    attachments: seed.attachments ?? [],
    services: seed.services ?? { kv: emptyService('kv'), object: emptyService('object'), postgres: emptyService('postgres') },
  }
  const byId = (id: string) => state.bindings.find(b => b.id === id)
  const withAttachments = (b: Json, db: any) => ({
    ...b,
    attachments: state.attachments.filter(a => a.binding_id === b.id).map(a => ({
      ...a, project: { id: a.project_id, name: db.projects.find((p: Json) => p.id === a.project_id)?.name ?? a.project_id },
    })),
  })
  const attachmentJson = (a: Json) => {
    const binding = byId(a.binding_id)!
    return { ...a, env_names: (binding.env_keys ?? []).map((k: string) => (a.env_prefix ?? '') + k), binding }
  }

  mockApi.on('GET', '/bindings/types', () => ({ body: {
    data: {
      kv: { env_keys: KEYS.kv, defaults: { memory_mb: 64 }, max: { memory_mb: 4096 } },
      object: { env_keys: KEYS.object, defaults: { quota_gb: 5 }, max: { quota_gb: 1000 } },
      postgres: { env_keys: KEYS.postgres, defaults: { storage_gb: 1, connection_limit: 20, statement_timeout: '30s', instances: 1, dedicated_storage_gb: 5 }, max: { storage_gb: 500, connection_limit: 500, instances: 3 } },
      external: { env_keys: [], defaults: {}, max: { values: 30 } },
    },
    retention_days: 7,
  } }))
  mockApi.on('GET', '/bindings/quota', () => ({ body: { data: {
    limits: { max_bindings: 20, kv_mb: 1024, object_gb: 100, postgres_gb: 50, dedicated_postgres: 2 },
    used: { max_bindings: state.bindings.length, kv_mb: 0, object_gb: 0, postgres_gb: 0, dedicated_postgres: 0 },
    measured_bytes: { kv: 0, object: 0, postgres: 0 },
  } } }))
  mockApi.on('GET', '/bindings', (_, db) => ({ body: { data: state.bindings.map(b => withAttachments(b, db)), abilities: { manage: true, view_credentials: true } } }))
  mockApi.on('POST', '/bindings/:id/rotate', (req) => {
    const b = byId(req.params.id)!
    b.rotated_at = new Date().toISOString()
    return { status: 202, body: { message: 'Rotating credentials.', data: b } }
  })
  mockApi.on('DELETE', '/bindings/:id', (req) => {
    const b = byId(req.params.id)!
    if (req.body?.confirm !== b.name) return { status: 422, body: { message: 'Type the binding name to confirm the deletion.', errors: { confirm: ['x'] } } }
    b.status = 'deleting'
    b.purge_at = '2026-10-18T10:00:00.000Z'
    state.attachments = state.attachments.filter(a => a.binding_id !== b.id)
    return { status: 202, body: { message: 'Scheduled.', data: b } }
  })
  mockApi.on('POST', '/bindings/:id/restore', (req) => {
    const b = byId(req.params.id)!
    b.status = 'ready'
    b.purge_at = null
    return { body: { message: 'Restored.', data: b } }
  })
  mockApi.on('GET', '/bindings/:id/credentials', (req) => {
    const b = byId(req.params.id)!
    return { body: { data: { env: Object.fromEntries((b.env_keys ?? []).map((k: string) => [k, `${k.toLowerCase()}-value`])) } } }
  })

  mockApi.on('GET', '/projects/:id/bindings', (req, db) => {
    const project = db.projects.find((p: Json) => p.id === req.params.id)
    const attached = state.attachments.filter(a => a.project_id === req.params.id)
    const ids = attached.map(a => a.binding_id)
    return { body: {
      data: attached.map(attachmentJson),
      available: state.bindings.filter(b => !ids.includes(b.id) && b.status !== 'deleting'
        && (b.type === 'external' || b.knative_region_id === project?.knative_region_id)),
      secret: `${project?.slug}-bindings`,
      abilities: { manage: true, view_credentials: true },
    } }
  })
  mockApi.on('POST', '/projects/:id/bindings', (req, db) => {
    let binding: Json | undefined
    if (req.body?.binding_id) {
      binding = byId(req.body.binding_id)
    } else {
      const body = req.body?.binding ?? {}
      const project = db.projects.find((p: Json) => p.id === req.params.id)
      binding = bindingRow(`b-${state.bindings.length + 1}`, body.name, body.type, { settings: body.settings ?? {}, knative_region_id: project?.knative_region_id })
      state.bindings.push(binding)
    }
    const attachment = { id: `a-${state.attachments.length + 1}`, binding_id: binding!.id, project_id: req.params.id, env_prefix: req.body?.env_prefix ?? '' }
    state.attachments.push(attachment)
    return { status: 201, body: { data: attachmentJson(attachment) } }
  })
  mockApi.on('PATCH', '/projects/:id/bindings/:aid', (req) => {
    const a = state.attachments.find(x => x.id === req.params.aid)!
    a.env_prefix = req.body?.env_prefix ?? ''
    return { body: { data: attachmentJson(a) } }
  })
  mockApi.on('DELETE', '/projects/:id/bindings/:aid', (req) => {
    state.attachments = state.attachments.filter(x => x.id !== req.params.aid)
    return { body: { message: 'Binding detached from the project.' } }
  })

  mockApi.on('GET', '/knative-regions/:id/data-services', (req) => ({ body: {
    data: {
      region_id: req.params.id, namespace: 'runr-data', services: state.services,
      region_redis: { mode: 'managed', status: 'ready', has_password: true },
    },
    abilities: { manage: true },
  } }))
  mockApi.on('POST', '/knative-regions/:id/data-services/:service', (req) => {
    const service = req.params.service
    const body = req.body ?? {}
    state.services[service] = {
      ...emptyService(service), installed: true, status: 'ready', mode: body.mode ?? 'managed',
      config: service === 'object' && body.mode === 'external'
        ? { provider: body.provider, endpoint: body.endpoint, s3_region: body.region, scoped_keys: body.provider === 'garage' }
        : { storage: body.storage, instances: body.instances, backups: !!body.backups },
    }
    return { status: 202, body: { message: 'Installing.', data: { service, status: 'installing', mode: body.mode ?? 'managed' } } }
  })

  return state
}

test.describe('Bindings', () => {
  test('creates a KV binding on the project tab with a prefixed env preview', async ({ page, auth, mockApi }) => {
    fakeBindings(mockApi)
    await auth.signIn()
    await page.goto('/projects/p-xaas#bindings')
    await expect(page.getByText('No bindings are attached to this project.')).toBeVisible()
    await expect(page.getByText('hello-snippet-bindings', { exact: false })).toBeVisible()

    await page.getByRole('button', { name: 'Attach binding' }).click()
    await page.locator('[data-field="name"]').fill('orders-cache')
    await page.locator('[data-field="env_prefix"]').fill('orders_')
    await expect(page.locator('[data-field="env_prefix"]')).toHaveValue('ORDERS_')
    await expect(page.locator('[data-env-preview]')).toContainText('ORDERS_REDIS_URL')
    await expect(page.locator('[data-env-preview]')).toContainText('ORDERS_KV_PREFIX')

    const sent = mockApi.waitFor('POST', '/projects/p-xaas/bindings')
    await page.getByRole('button', { name: 'Create and attach' }).click()
    expect((await sent).body).toEqual({
      env_prefix: 'ORDERS_',
      binding: { name: 'orders-cache', type: 'kv', settings: { memory_mb: 64 } },
    })

    const row = page.locator('[data-attachment="orders-cache"]')
    await expect(row).toBeVisible()
    await expect(row).toContainText('ORDERS_REDIS_URL')
    await expect(row.locator('[data-binding-status]')).toHaveText('Ready')
  })

  test('attaches an existing binding, rotates its credentials and detaches it', async ({ page, auth, mockApi }) => {
    fakeBindings(mockApi, { bindings: [bindingRow('b-orders', 'orders-db', 'postgres')] })
    await auth.signIn()
    await page.goto('/projects/p-xaas#bindings')

    await page.getByRole('button', { name: 'Attach binding' }).click()
    await page.locator('[data-field="binding_id"]').selectOption('b-orders')
    await page.locator('[data-field="env_prefix"]').fill('ORDERS_')
    await expect(page.locator('[data-env-preview]')).toContainText('ORDERS_DATABASE_URL')

    const attached = mockApi.waitFor('POST', '/projects/p-xaas/bindings')
    await page.getByRole('button', { name: 'Attach', exact: true }).click()
    expect((await attached).body).toEqual({ env_prefix: 'ORDERS_', binding_id: 'b-orders' })

    const row = page.locator('[data-attachment="orders-db"]')
    await expect(row).toContainText('ORDERS_PGPASSWORD')

    const rotated = mockApi.waitFor('POST', '/bindings/b-orders/rotate')
    await row.locator('[data-action="rotate"]').click()
    await confirmDialog(page, 'Rotate credentials')
    await rotated

    const detached = mockApi.waitFor('DELETE', '/projects/p-xaas/bindings/:aid')
    await row.locator('[data-action="detach"]').click()
    await expect(page.getByText('Detach orders-db from this project?', { exact: false })).toBeVisible()
    await confirmDialog(page, 'Detach')
    expect((await detached).path).toBe('/projects/p-xaas/bindings/a-1')
    await expect(page.getByText('No bindings are attached to this project.')).toBeVisible()
  })

  test('deletes a binding with its typed name and restores it on the Data page', async ({ page, auth, mockApi }) => {
    fakeBindings(mockApi, { bindings: [bindingRow('b-cache', 'cache', 'kv')] })
    await auth.signIn()
    await page.goto('/settings?tab=data')

    const row = page.locator('[data-binding-row="cache"]')
    await expect(row).toBeVisible()
    await row.locator('[data-action="delete"]').click()

    const dialog = page.locator('[data-delete-dialog]')
    await expect(dialog).toContainText('deleted after 7 days')
    const confirm = dialog.locator('[data-action="confirm-delete"]')
    await expect(confirm).toBeDisabled()
    await dialog.locator('[data-field="confirm_name"]').fill('cache')
    await expect(confirm).toBeEnabled()

    const deleted = mockApi.waitFor('DELETE', '/bindings/b-cache')
    await confirm.click()
    expect((await deleted).body).toEqual({ confirm: 'cache' })
    await expect(row.locator('[data-binding-status]')).toHaveText('Deleting')
    await expect(row.locator('[data-purge-date]')).toContainText('2026')

    const restored = mockApi.waitFor('POST', '/bindings/b-cache/restore')
    await row.getByRole('button', { name: 'Restore' }).click()
    await restored
    await expect(row.locator('[data-binding-status]')).toHaveText('Ready')
  })

  test('installs an external S3 store and Postgres on a region', async ({ page, auth, mockApi }) => {
    fakeBindings(mockApi)
    await auth.signIn()
    await page.goto('/settings')

    await page.locator('[data-region-row="region-b"]').getByRole('button', { name: 'Edit', exact: true }).click()
    await page.getByRole('button', { name: 'Data services', exact: true }).click()

    const object = page.locator('[data-service="object"]')
    await expect(object.locator('[data-service-status]')).toHaveText('Not installed')
    await object.locator('[data-field="object_mode"]').selectOption('external')
    await object.locator('[data-field="object_endpoint"]').fill('https://s3.example.com')
    await object.locator('[data-field="object_region"]').fill('eu-central-1')
    await object.locator('[data-field="object_access_key_id"]').fill('AKIAEXAMPLE')
    await object.locator('[data-field="object_secret_access_key"]').fill('s3cr3t')

    const objectSent = mockApi.waitFor('POST', '/knative-regions/r-cron/data-services/object')
    await object.locator('[data-action="install"]').click()
    expect((await objectSent).body).toMatchObject({
      mode: 'external', provider: 's3', endpoint: 'https://s3.example.com', region: 'eu-central-1',
      access_key_id: 'AKIAEXAMPLE', secret_access_key: 's3cr3t',
    })
    await expect(object.locator('[data-service-status]')).toHaveText('Ready')
    await expect(object.locator('[data-shared-key]')).toBeVisible()

    const postgres = page.locator('[data-service="postgres"]')
    await postgres.locator('[data-field="pg_instances"]').fill('2')
    await postgres.locator('[data-field="pg_backups"]').check()
    const pgSent = mockApi.waitFor('POST', '/knative-regions/r-cron/data-services/postgres')
    await postgres.locator('[data-action="install"]').click()
    expect((await pgSent).body).toEqual({ instances: 2, storage: '10Gi', backups: true })
    await expect(postgres.locator('[data-service-status]')).toHaveText('Ready')
  })

  test('shows no credentials without bindings.view_credentials', async ({ page, auth, mockApi }) => {
    fakeBindings(mockApi, {
      bindings: [bindingRow('b-cache', 'cache', 'kv')],
      attachments: [{ id: 'a-1', binding_id: 'b-cache', project_id: 'p-xaas', env_prefix: 'CACHE_' }],
    })
    mockApi.db.permissions = effectivePermissions('developer', [
      'projects.view', 'env.view', 'env.edit', 'regions.view', 'bindings.view', 'bindings.manage',
    ])
    await auth.signIn()
    await page.goto('/projects/p-xaas#bindings')

    const row = page.locator('[data-attachment="cache"]')
    await expect(row).toContainText('CACHE_REDIS_URL')
    await expect(row.locator('[data-action="detach"]')).toBeVisible()
    await expect(row.locator('[data-action="reveal"]')).toHaveCount(0)
    expect(mockApi.calls('GET', '/bindings/:id/credentials')).toHaveLength(0)
  })

  test('reveals the credentials with the attachment prefix for an owner', async ({ page, auth, mockApi }) => {
    fakeBindings(mockApi, {
      bindings: [bindingRow('b-cache', 'cache', 'kv')],
      attachments: [{ id: 'a-1', binding_id: 'b-cache', project_id: 'p-xaas', env_prefix: 'CACHE_' }],
    })
    await auth.signIn()
    await page.goto('/projects/p-xaas#bindings')

    const shown = mockApi.waitFor('GET', '/bindings/b-cache/credentials')
    await page.locator('[data-attachment="cache"] [data-action="reveal"]').click()
    await shown
    await expect(page.locator('[data-credentials]')).toContainText('CACHE_REDIS_URL')
    await expect(page.locator('[data-credentials]')).toContainText('redis_url-value')
  })
})
