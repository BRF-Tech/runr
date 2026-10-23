import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import OrgTransferManager from '@/modules/org-transfer/components/OrgTransferManager.vue'
import { orgTransferApi, savePackage } from '@/modules/org-transfer/api'
import { projectsApi } from '@/api/projects'
import { bindingsApi } from '@/api/bindings'
import { dispatchPlatformEvent } from '@/composables/useJobEvents'
import { useConfirm } from '@/composables/useConfirm'
import { useSettingsStore } from '@/stores/settings'
import { makeOrgTransferI18n, transfer, importTransfer, pkg } from './support'

// Administration → Export / Import (docs/org-export.md): the recent transfers, live progress from the
// platform channel (org_transfer.progress / .completed / .failed), download, cancel, continue an
// import, the report.

vi.mock('@/modules/org-transfer/api', async (importOriginal) => {
  const actual = await importOriginal()
  return {
    ...actual,
    savePackage: vi.fn(),
    orgTransferApi: {
      list: vi.fn(), get: vi.fn(), cancel: vi.fn(), download: vi.fn(), estimate: vi.fn(), startExport: vi.fn(),
      startImport: vi.fn(), uploadChunk: vi.fn(), complete: vi.fn(), plan: vi.fn(), apply: vi.fn(),
    },
  }
})
vi.mock('@/api/projects', () => ({ projectsApi: { list: vi.fn() } }))
vi.mock('@/api/bindings', () => ({ bindingsApi: { list: vi.fn() } }))
vi.mock('@/composables/useConfirm', () => {
  const confirm = vi.fn()
  return { useConfirm: () => ({ confirm }) }
})

const i18n = makeOrgTransferI18n()
const mounted = []

async function mountManager(transfers = []) {
  setActivePinia(createPinia())
  const settings = useSettingsStore()
  settings.loaded = true
  settings.data.regions = [{ id: 'r1', name: 'eu-west' }]
  orgTransferApi.list.mockResolvedValue({ data: { data: transfers } })
  const w = mount(OrgTransferManager, { global: { plugins: [i18n] } })
  mounted.push(w)
  await flushPromises()
  return w
}

beforeEach(() => {
  vi.clearAllMocks()
  useConfirm().confirm.mockResolvedValue(true)
  projectsApi.list.mockResolvedValue({ data: { data: [{ id: 'p1', name: 'api' }] } })
  bindingsApi.list.mockResolvedValue({ data: { data: [] } })
})
afterEach(() => mounted.splice(0).forEach(w => w.unmount()))

describe('OrgTransferManager', () => {
  it('lists the recent transfers with their status and package', async () => {
    const w = await mountManager([
      transfer({ id: 'tr-2', status: 'completed', progress: 100, package: pkg(), report: { entries: 3, bytes: 10, warnings: [], skipped: [] } }),
      importTransfer({ id: 'im-1', status: 'failed', message: 'Restoring db failed' }),
    ])

    const exp = w.find('[data-transfer="tr-2"]')
    expect(exp.text()).toContain('Export')
    expect(exp.text()).toContain('Completed')
    expect(exp.text()).toContain('runr-org-acme-20261021-120000.tar.zst')
    expect(exp.find('[data-testid="transfer-download"]').exists()).toBe(true)
    expect(exp.find('[data-testid="transfer-cancel"]').exists()).toBe(false)

    const imp = w.find('[data-transfer="im-1"]')
    expect(imp.text()).toContain('Failed')
    expect(imp.text()).toContain('Restoring db failed')
    expect(imp.find('[data-testid="transfer-download"]').exists()).toBe(false)
  })

  it('shows the empty state', async () => {
    const w = await mountManager([])
    expect(w.find('[data-testid="org-transfer-empty"]').exists()).toBe(true)
  })

  it('follows progress events and loads the transfer when its status changes', async () => {
    const running = transfer({ id: 'tr-5', status: 'running', phase: 'projects', progress: 10 })
    const w = await mountManager([running])
    expect(w.find('[data-testid="export-progress"]').exists()).toBe(true)

    dispatchPlatformEvent('org_transfer.progress', { transfer_id: 'tr-5', kind: 'export', status: 'running', phase: 'binding_data', progress: 55, message: 'Dumping db' })
    await flushPromises()
    expect(w.find('[data-testid="export-status"]').text()).toContain('Binding data')
    expect(w.find('[data-testid="export-progress-bar"]').attributes('style')).toContain('width: 55%')
    expect(orgTransferApi.get).not.toHaveBeenCalled()

    orgTransferApi.get.mockResolvedValue({ data: { data: transfer({ id: 'tr-5', status: 'completed', phase: 'done', progress: 100, package: pkg() }) } })
    dispatchPlatformEvent('org_transfer.completed', { transfer_id: 'tr-5', kind: 'export', status: 'completed' })
    await flushPromises()
    expect(orgTransferApi.get).toHaveBeenCalledWith('tr-5')
    expect(w.find('[data-testid="export-download"]').exists()).toBe(true)
  })

  it('adds a transfer it did not know from its event', async () => {
    const w = await mountManager([])
    orgTransferApi.get.mockResolvedValue({ data: { data: importTransfer({ id: 'im-9', status: 'verifying', plan: null }) } })

    dispatchPlatformEvent('org_transfer.progress', { transfer_id: 'im-9', kind: 'import', status: 'verifying', phase: 'verify', progress: 30 })
    await flushPromises()

    expect(orgTransferApi.get).toHaveBeenCalledWith('im-9')
    expect(w.find('[data-transfer="im-9"]').exists()).toBe(true)
    expect(w.find('[data-testid="import-verifying"]').exists()).toBe(true)
  })

  it('downloads a finished package', async () => {
    const done = transfer({ id: 'tr-2', status: 'completed', package: pkg() })
    orgTransferApi.download.mockResolvedValue({ data: new Blob(['x']), headers: { 'content-disposition': 'attachment; filename="runr-org-acme.tar.zst"' } })
    const w = await mountManager([done])

    await w.find('[data-transfer="tr-2"] [data-testid="transfer-download"]').trigger('click')
    await flushPromises()

    expect(orgTransferApi.download).toHaveBeenCalledWith('tr-2')
    expect(savePackage).toHaveBeenCalledWith(expect.objectContaining({ headers: expect.any(Object) }), 'runr-org-acme-20261021-120000.tar.zst')
  })

  it('cancels a running transfer after confirming', async () => {
    orgTransferApi.cancel.mockResolvedValue({ status: 204 })
    const w = await mountManager([transfer({ id: 'tr-5', status: 'running' })])

    await w.find('[data-transfer="tr-5"] [data-testid="transfer-cancel"]').trigger('click')
    await flushPromises()

    expect(useConfirm().confirm).toHaveBeenCalled()
    expect(orgTransferApi.cancel).toHaveBeenCalledWith('tr-5')
    expect(w.find('[data-transfer="tr-5"] [data-testid="transfer-status"]').text()).toContain('Cancelled')
  })

  it('continues a planned import and opens a report', async () => {
    const older = importTransfer({ id: 'im-1', status: 'planned' })
    const newer = importTransfer({ id: 'im-2', status: 'completed', report: { counts: { created: 3, exists: 0, skipped: 0, failed: 0, manual: 1 }, items: [{ section: 'project', source: 'p1', name: 'api', target_id: 'p77', status: 'created', message: null }], manual_steps: [{ code: 'dns', message: 'Verify the domains.', items: ['api.example.com'] }], warnings: [] } })
    const w = await mountManager([newer, older])

    // the planned import is shown by the import card
    expect(w.find('[data-testid="import-plan"]').exists()).toBe(true)

    await w.find('[data-transfer="im-2"] [data-testid="transfer-report"]').trigger('click')
    const dialog = w.find('[data-testid="transfer-report-dialog"]')
    expect(dialog.find('[data-testid="report-counts"]').text()).toContain('3 created')
    expect(dialog.find('[data-item-section="project"]').text()).toContain('api')
    expect(dialog.find('[data-testid="report-manual-steps"]').text()).toContain('api.example.com')
    await dialog.find('[data-testid="transfer-report-close"]').trigger('click')
    expect(w.find('[data-testid="transfer-report-dialog"]').exists()).toBe(false)
  })
})
