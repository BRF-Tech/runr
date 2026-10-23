import { test, expect } from './fixtures/test'
import { ALL_PERMISSIONS, effectivePermissions, type Json } from './fixtures/data'
import type { MockApi } from './fixtures/mock-api'
import { openNavMenu } from './helpers/ui'

// Organization export / import (docs/org-export.md) with the mocked API: Administration → Export /
// Import estimates the size before an export, starts it, follows its progress over the platform
// channel and downloads the package; an import resumes the unfinished upload of the same file,
// shows the dry-run plan (slug collision rename, region mapping), refuses to apply while the plan
// blocks, applies it and shows the report. Owners only: an admin does not see the tab.

const MiB = 1024 * 1024
const FILENAME = 'runr-org-acme-20261021-120000.tar.zst'

function baseTransfer(extra: Json = {}): Json {
  return {
    id: 'tr-1', kind: 'export', status: 'queued', phase: 'prepare', progress: 0, message: null,
    options: { projects: null, include_members: true, include_images: false, binding_data: [], include_secrets: false },
    estimate: null, destination: { type: 'download' }, package: null, upload: null, plan: null, report: null,
    created_by: { id: '1', name: 'Admin' }, created_at: '2026-10-21T12:00:00Z', updated_at: '2026-10-21T12:00:00Z',
    finished_at: null, expires_at: '2026-10-28T12:00:00Z',
    ...extra,
  }
}

function packageInfo(extra: Json = {}): Json {
  return {
    filename: FILENAME, size_bytes: 3 * MiB, sha256: 'ab'.repeat(32), schema_version: 1, runr_version: 'v0.5.11',
    source: { organization: 'Acme', slug: 'acme', exported_at: '2026-10-21T12:00:00Z' }, downloadable: true,
    ...extra,
  }
}

function plan(blocked: boolean): Json {
  return {
    generated_at: '2026-10-21T12:05:00Z',
    source: { organization: 'Acme', slug: 'acme', runr_version: 'v0.5.10', schema_version: 1, exported_at: '2026-10-21T12:00:00Z' },
    contents: { projects: 1, versions: 3, bindings: 0, binding_data: 0, images: 0, secrets: 'absent', members: 0, roles: 0, groups: 0, alert_channels: 0, alert_rules: 0, status_page: false, gateways: 0, extensions: [] },
    regions: [{ source_id: 'src-eu', source_name: 'eu-1', target_id: blocked ? null : 'r-ready', target_name: blocked ? null : 'Ready region', options: [{ id: 'r-ready', name: 'Ready region' }] }],
    projects: [{
      source_id: 'p-src', name: 'hello', target_name: 'hello-2', slug: 'hello-2', service_type: 'faas', runtime: 'python', runtime_version: '3.12',
      source_region: 'eu-1', target_region_id: blocked ? null : 'r-ready', target_region: blocked ? null : 'Ready region', versions: 3,
      domains: ['hello.example.com'], bindings: [], collision: true, suggested_name: 'hello-2', status: 'create',
      issues: [
        ...(blocked ? [{ level: 'error', code: 'region_unmapped', message: 'Map region eu-1 to a region of this installation.' }] : []),
        { level: 'warning', code: 'slug_taken', message: 'A project named hello exists here: imported as hello-2.' },
        { level: 'manual', code: 'dns', message: 'hello.example.com must point at the new region and be verified again.' },
      ],
    }],
    bindings: [],
    organization: { roles: [], groups: [], members: [], alert_channels: [], status_page: 'skip' },
    manual_steps: [{ code: 'dns', message: 'Point these domains at the new region and verify them again.', items: ['hello.example.com'] }],
    blocking: blocked ? 1 : 0, warnings: 1, can_apply: !blocked,
  }
}

function transferBackend(mockApi: MockApi, state: { transfers: Json[] }) {
  mockApi.on('GET', '/org-transfers', () => ({ status: 200, body: { data: state.transfers } }))
  mockApi.on('GET', /^\/org-transfers\/([\w-]+)$/, (req) => {
    const found = state.transfers.find(t => req.path.endsWith(`/${t.id as string}`))
    return found ? { status: 200, body: { data: found } } : { status: 404, body: { message: 'Not found.' } }
  })
}

function update(state: { transfers: Json[] }, id: string, changes: Json): Json {
  const i = state.transfers.findIndex(t => t.id === id)
  state.transfers[i] = { ...state.transfers[i], ...changes }
  return state.transfers[i]
}

test.describe('Organization export / import', () => {
  test.skip(({ e2eMode }) => e2eMode === 'stack', 'needs the mocked API (packages, region data)')

  test('estimates, exports with live progress and downloads the package', async ({ page, auth, mockApi, realtime }) => {
    const state = { transfers: [] as Json[] }
    transferBackend(mockApi, state)
    mockApi.on('POST', '/org-transfers/export/estimate', () => ({
      status: 200,
      body: { data: { projects: 2, versions: 7, code_bytes: 48 * 1024, bindings: [], binding_data_bytes: 0, images: { count: 0, bytes: null }, total_bytes: 48 * 1024, warnings: [] } },
    }))
    mockApi.on('POST', '/org-transfers/export', () => {
      const created = baseTransfer()
      state.transfers.unshift(created)
      return { status: 202, body: { data: created } }
    })
    mockApi.on('GET', '/org-transfers/tr-1/download', () => ({
      status: 200,
      headers: { 'content-disposition': `attachment; filename="${FILENAME}"` },
      body: { fake: 'package' },
    }))

    await auth.signIn()
    await page.goto('/settings?tab=org-transfer')
    const panel = page.getByTestId('export-panel')
    await expect(panel).toBeVisible()
    await expect(page.getByTestId('org-transfer-empty')).toBeVisible()

    // the size estimate comes first
    await panel.getByTestId('export-members').check()
    await expect(panel.getByTestId('export-start')).toBeDisabled()
    const estimated = mockApi.waitFor('POST', '/org-transfers/export/estimate')
    await panel.getByTestId('export-estimate').click()
    expect((await estimated).body).toMatchObject({ include_members: true, include_secrets: false })
    expect((await estimated).body).not.toHaveProperty('destination')
    await expect(panel.getByTestId('export-estimate-result')).toContainText('2 projects, 7 versions')

    const started = mockApi.waitFor('POST', '/org-transfers/export')
    await panel.getByTestId('export-start').click()
    expect((await started).body).toMatchObject({ include_members: true, include_images: false, include_secrets: false, destination: { type: 'download' } })
    expect((await started).body).not.toHaveProperty('passphrase')

    // progress over the platform channel
    // the API keeps the progress message with the transfer, as the event carries it
    update(state, 'tr-1', { status: 'running', phase: 'code', progress: 45, message: 'Exporting the code of hello (1/2)' })
    await realtime.platform('org_transfer.progress', { transfer_id: 'tr-1', kind: 'export', status: 'running', phase: 'code', progress: 45, message: 'Exporting the code of hello (1/2)' })
    await expect(panel.getByTestId('export-status')).toContainText('Running · Code')
    await expect(panel.getByTestId('export-message')).toHaveText('Exporting the code of hello (1/2)')

    update(state, 'tr-1', { status: 'completed', phase: 'done', progress: 100, package: packageInfo(), report: { entries: 14, bytes: 3 * MiB, warnings: [], skipped: [] } })
    await realtime.platform('org_transfer.completed', { transfer_id: 'tr-1', kind: 'export', status: 'completed' })
    await expect(panel.getByTestId('export-download')).toBeVisible()

    const download = page.waitForEvent('download')
    await panel.getByTestId('export-download').click()
    expect((await download).suggestedFilename()).toBe(FILENAME)

    await page.locator('[data-transfer="tr-1"] [data-testid="transfer-report"]').click()
    await expect(page.getByTestId('report-entries')).toContainText('14 entries')
  })

  test('asks for a matching passphrase before exporting secrets', async ({ page, auth, mockApi }) => {
    const state = { transfers: [] as Json[] }
    transferBackend(mockApi, state)
    mockApi.on('POST', '/org-transfers/export/estimate', () => ({ status: 200, body: { data: { projects: 1, versions: 1, code_bytes: 100, bindings: [], binding_data_bytes: 0, images: { count: 0, bytes: null }, total_bytes: 100, warnings: [] } } }))
    mockApi.on('POST', '/org-transfers/export', () => ({ status: 202, body: { data: baseTransfer({ options: { include_secrets: true } }) } }))

    await auth.signIn()
    await page.goto('/settings?tab=org-transfer')
    const panel = page.getByTestId('export-panel')
    await panel.getByTestId('export-secrets').check()
    await panel.getByTestId('export-estimate').click()
    await expect(panel.getByTestId('export-estimate-result')).toBeVisible()

    await panel.getByTestId('export-passphrase').fill('too short')
    await expect(panel.getByTestId('export-passphrase-hint')).toContainText('at least 12 characters')
    await expect(panel.getByTestId('export-start')).toBeDisabled()

    await panel.getByTestId('export-passphrase').fill('correct horse battery')
    await panel.getByTestId('export-passphrase-repeat').fill('correct horse battery')
    const started = mockApi.waitFor('POST', '/org-transfers/export')
    await panel.getByTestId('export-start').click()
    expect((await started).body).toMatchObject({ include_secrets: true, passphrase: 'correct horse battery' })
    await expect(panel.getByTestId('export-passphrase')).toHaveValue('')
  })

  test('resumes the upload, plans with a rename and region mapping, applies and reports', async ({ page, auth, mockApi, realtime }) => {
    const body = Buffer.alloc(8 * MiB + 1024, 3)
    const open: Json = baseTransfer({
      id: 'im-1', kind: 'import', status: 'uploading', phase: null, progress: 50, destination: null,
      options: { filename: FILENAME }, upload: { size_bytes: body.length, uploaded_bytes: 8 * MiB, chunk_size: 8 * MiB, sha256: null },
    })
    const state = { transfers: [open] }
    transferBackend(mockApi, state)
    mockApi.on('PUT', '/org-transfers/im-1/chunk', (req) => {
      const offset = Number(req.query.get('offset'))
      const received = Math.min(body.length, offset + 8 * MiB)
      update(state, 'im-1', { upload: { ...(open.upload as Json), uploaded_bytes: received } })
      return { status: 200, body: { uploaded_bytes: received, size_bytes: body.length } }
    })
    mockApi.on('POST', '/org-transfers/im-1/complete', () => ({ status: 202, body: { data: update(state, 'im-1', { status: 'verifying', phase: 'verify', progress: 0 }) } }))
    mockApi.on('POST', '/org-transfers/im-1/plan', (req) => {
      const mapped = (req.body?.region_map ?? {})['src-eu'] === 'r-ready'
      return { status: 200, body: { data: update(state, 'im-1', { plan: plan(!mapped) }) } }
    })
    mockApi.on('POST', '/org-transfers/im-1/apply', () => ({ status: 202, body: { data: update(state, 'im-1', { status: 'applying', phase: 'apply', progress: 0 }) } }))

    await auth.signIn()
    await page.goto('/settings?tab=org-transfer')
    const panel = page.getByTestId('import-panel')

    // the same file again: the upload resumes after what the API holds
    await panel.getByTestId('import-file').setInputFiles({ name: FILENAME, mimeType: 'application/zstd', buffer: body })
    await expect(panel.getByTestId('import-resume-note')).toContainText('8.0 MiB')
    const completed = mockApi.waitFor('POST', '/org-transfers/im-1/complete')
    await panel.getByTestId('import-upload').click()
    await completed
    expect(mockApi.calls('PUT', '/org-transfers/im-1/chunk').map(r => r.query.get('offset'))).toEqual([String(8 * MiB)])
    expect(mockApi.calls('POST', '/org-transfers/import')).toHaveLength(0)
    await expect(panel.getByTestId('import-verifying')).toBeVisible()

    // the check is done: the plan blocks until the region is mapped
    update(state, 'im-1', { status: 'planned', phase: 'plan', progress: 100, package: packageInfo({ downloadable: false }), plan: plan(true) })
    await realtime.platform('org_transfer.progress', { transfer_id: 'im-1', kind: 'import', status: 'planned', phase: 'plan', progress: 100 })
    const planView = panel.getByTestId('import-plan')
    await expect(planView).toBeVisible()
    await expect(planView.getByTestId('plan-source')).toContainText('From Acme (acme)')
    const row = planView.locator('[data-plan-project="p-src"]')
    await expect(row.getByTestId('plan-target-name')).toHaveValue('hello-2')
    await expect(row.locator('[data-level="error"]')).toContainText('Map region eu-1')
    await expect(planView.getByTestId('plan-manual-steps')).toContainText('hello.example.com')
    await expect(planView.getByTestId('plan-blocking')).toContainText('1 blocking issue')
    await expect(planView.getByTestId('import-apply')).toBeDisabled()

    await planView.locator('[data-region="src-eu"] select').selectOption('r-ready')
    await row.getByTestId('plan-target-name').fill('hello-copy')
    const planned = mockApi.waitFor('POST', '/org-transfers/im-1/plan')
    await planView.getByTestId('plan-recheck').click()
    expect((await planned).body).toMatchObject({ region_map: { 'src-eu': 'r-ready' }, renames: { 'p-src': 'hello-copy' }, skip_projects: [] })
    await expect(planView.getByTestId('plan-blocking')).toBeHidden()
    await expect(planView.getByTestId('import-apply')).toBeEnabled()

    const applied = mockApi.waitFor('POST', '/org-transfers/im-1/apply')
    await planView.getByTestId('import-deploy').check()
    await planView.getByTestId('import-apply').click()
    await page.getByRole('button', { name: 'Import', exact: true }).last().click()
    expect((await applied).body).toMatchObject({ region_map: { 'src-eu': 'r-ready' }, renames: { 'p-src': 'hello-copy' }, deploy: true })

    update(state, 'im-1', { status: 'applying', phase: 'deploy', progress: 80 })
    await realtime.platform('org_transfer.progress', { transfer_id: 'im-1', kind: 'import', status: 'applying', phase: 'deploy', progress: 80 })
    await expect(planView.getByTestId('import-progress')).toContainText('Deploying')

    update(state, 'im-1', {
      status: 'completed', phase: 'done', progress: 100,
      report: {
        started_at: '2026-10-21T12:06:00Z', finished_at: '2026-10-21T12:08:00Z',
        counts: { created: 6, exists: 0, skipped: 0, failed: 0, manual: 1 },
        items: [{ section: 'project', source: 'p-src', name: 'hello-copy', target_id: 'p-new', status: 'created', message: null },
          { section: 'domain', source: 'hello.example.com', name: 'hello.example.com', target_id: null, status: 'manual', message: 'Verify the domain again.' }],
        manual_steps: [{ code: 'dns', message: 'Point these domains at the new region and verify them again.', items: ['hello.example.com'] }],
        warnings: [],
      },
    })
    await realtime.platform('org_transfer.completed', { transfer_id: 'im-1', kind: 'import', status: 'completed' })
    await expect(planView.getByTestId('import-counts')).toHaveText('6 created · 0 existed · 0 skipped · 0 failed · 1 manual')

    await panel.getByTestId('import-report-open').click()
    const report = page.getByTestId('transfer-report-dialog')
    await expect(report.locator('[data-item-section="project"]')).toContainText('hello-copy')
    await expect(report.locator('[data-item-status="manual"]')).toContainText('Verify the domain again.')
  })

  test('a package from a newer RunR is refused with its message', async ({ page, auth, mockApi, realtime }) => {
    const body = Buffer.alloc(2048, 1)
    const state = { transfers: [] as Json[] }
    transferBackend(mockApi, state)
    mockApi.on('POST', '/org-transfers/import', (req) => {
      const created = baseTransfer({ id: 'im-7', kind: 'import', status: 'uploading', destination: null, options: { filename: req.body?.filename }, upload: { size_bytes: body.length, uploaded_bytes: 0, chunk_size: 8 * MiB, sha256: null } })
      state.transfers.unshift(created)
      return { status: 201, body: { data: created } }
    })
    mockApi.on('PUT', '/org-transfers/im-7/chunk', () => ({ status: 200, body: { uploaded_bytes: body.length, size_bytes: body.length } }))
    mockApi.on('POST', '/org-transfers/im-7/complete', () => ({ status: 202, body: { data: update(state, 'im-7', { status: 'verifying', phase: 'verify' }) } }))

    await auth.signIn()
    await page.goto('/settings?tab=org-transfer')
    const panel = page.getByTestId('import-panel')
    await panel.getByTestId('import-file').setInputFiles({ name: 'future.tar.zst', mimeType: 'application/zstd', buffer: body })
    const created = mockApi.waitFor('POST', '/org-transfers/import')
    await panel.getByTestId('import-upload').click()
    expect((await created).body).toMatchObject({ filename: 'future.tar.zst', size: body.length })
    await expect(panel.getByTestId('import-verifying')).toBeVisible()

    const message = 'The package was written by a newer RunR (package schema 2); this installation reads schema 1 and older. Upgrade RunR first.'
    update(state, 'im-7', { status: 'failed', phase: 'verify', message })
    await realtime.platform('org_transfer.failed', { transfer_id: 'im-7', kind: 'import', status: 'failed', error: message })
    await expect(panel.getByTestId('import-failed')).toContainText('newer RunR')
  })

  test('an admin who is not an owner does not see the tab', async ({ page, auth, mockApi }) => {
    mockApi.db.permissions = effectivePermissions('admin', [...ALL_PERMISSIONS])
    await auth.signIn()
    await page.goto('/settings?tab=org-transfer')

    // the page shows nothing of it, the Administration menu has no link to it
    await expect(page.getByTestId('settings-title')).toBeVisible()
    await expect(page.getByTestId('org-transfer-manager')).toHaveCount(0)
    const admin = await openNavMenu(page, 'admin')
    await expect(admin.getByTestId('nav-link-users')).toBeVisible()
    await expect(admin.getByTestId('nav-link-org-transfer')).toHaveCount(0)
  })
})
