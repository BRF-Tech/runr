import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ExportPanel from '@/modules/org-transfer/components/ExportPanel.vue'
import { orgTransferApi } from '@/modules/org-transfer/api'
import { makeOrgTransferI18n, transfer, pkg } from './support'

// The export card (docs/org-export.md#export): the size estimate comes first, the passphrase is
// checked, the request carries only what was chosen, the progress and the finished package show.

vi.mock('@/modules/org-transfer/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, orgTransferApi: { estimate: vi.fn(), startExport: vi.fn() } }
})

const i18n = makeOrgTransferI18n()
const mounted = []

const ESTIMATE = {
  projects: 2, versions: 5, code_bytes: 52311,
  bindings: [{ id: 'b1', name: 'db', type: 'postgres', plan: 'shared', region: 'eu-1', usage_bytes: 10485760, data_supported: true }],
  binding_data_bytes: 10485760, images: { count: 0, bytes: null }, total_bytes: 10538071, warnings: [],
}

function mountPanel(props = {}) {
  setActivePinia(createPinia())
  const w = mount(ExportPanel, {
    props: {
      transfer: null,
      projects: [{ id: 'p1', name: 'api' }, { id: 'p2', name: 'web' }],
      bindings: [{ id: 'b1', name: 'db', type: 'postgres', usage_bytes: 10485760 }, { id: 'b2', name: 'files', type: 'object', usage_bytes: 0 }, { id: 'b3', name: 'stripe', type: 'external' }],
      ...props,
    },
    global: { plugins: [i18n] },
  })
  mounted.push(w)
  return w
}

beforeEach(() => {
  vi.clearAllMocks()
  orgTransferApi.estimate.mockResolvedValue({ data: { data: ESTIMATE } })
  orgTransferApi.startExport.mockResolvedValue({ data: { data: transfer({ status: 'queued', progress: 0 }) } })
})
afterEach(() => mounted.splice(0).forEach(w => w.unmount()))

describe('ExportPanel', () => {
  it('shows the size estimate before the export can start', async () => {
    const w = mountPanel()
    const start = w.find('[data-testid="export-start"]')
    expect(start.attributes('disabled')).toBeDefined()

    await w.find('[data-binding="b1"] input').setValue(true)
    await w.find('[data-testid="export-estimate"]').trigger('click')
    await flushPromises()

    expect(orgTransferApi.estimate).toHaveBeenCalledWith({ include_members: false, include_images: false, include_secrets: false, include_history: false, binding_data: ['b1'] })
    const result = w.find('[data-testid="export-estimate-result"]')
    expect(result.text()).toContain('2 projects, 5 versions')
    expect(result.text()).toContain('Binding data 10.0 MiB')
    expect(start.attributes('disabled')).toBeUndefined()

    // another choice: the estimate is asked again
    await w.find('[data-testid="export-members"]').setValue(true)
    expect(w.find('[data-testid="export-estimate-result"]').exists()).toBe(false)
    expect(w.find('[data-testid="export-start"]').attributes('disabled')).toBeDefined()
  })

  it('offers binding data for managed bindings only and the object bindings as destination', async () => {
    const w = mountPanel()
    expect(w.find('[data-binding="b3"]').exists()).toBe(false)

    await w.find('[data-testid="export-destination"]').setValue('binding')
    const options = w.find('[data-testid="export-binding-target"]').findAll('option').map(o => o.text())
    expect(options).toContain('files')
    expect(options).not.toContain('db')
  })

  it('checks the passphrase and sends it with the start request only', async () => {
    const w = mountPanel()
    await w.find('[data-testid="export-secrets"]').setValue(true)
    await w.find('[data-testid="export-estimate"]').trigger('click')
    await flushPromises()

    await w.find('[data-testid="export-passphrase"]').setValue('short')
    expect(w.find('[data-testid="export-passphrase-hint"]').text()).toContain('at least 12 characters')
    expect(w.find('[data-testid="export-start"]').attributes('disabled')).toBeDefined()

    await w.find('[data-testid="export-passphrase"]').setValue('correct horse battery')
    await w.find('[data-testid="export-passphrase-repeat"]').setValue('correct horse batterY')
    expect(w.find('[data-testid="export-passphrase-hint"]').text()).toContain('do not match')
    expect(w.find('[data-testid="export-start"]').attributes('disabled')).toBeDefined()

    await w.find('[data-testid="export-passphrase-repeat"]').setValue('correct horse battery')
    expect(w.find('[data-testid="export-start"]').attributes('disabled')).toBeUndefined()
    expect(orgTransferApi.estimate.mock.calls[0][0]).not.toHaveProperty('passphrase')

    await w.find('[data-testid="export-start"]').trigger('click')
    await flushPromises()

    expect(orgTransferApi.startExport).toHaveBeenCalledWith(expect.objectContaining({ include_secrets: true, passphrase: 'correct horse battery', destination: { type: 'download' } }))
    expect(w.emitted('started')[0][0]).toMatchObject({ id: 'tr-1', status: 'queued' })
    // not kept once the export runs
    expect(w.find('[data-testid="export-passphrase"]').element.value).toBe('')
  })

  it('needs every S3 field and sends the keys with the request', async () => {
    const w = mountPanel()
    await w.find('[data-testid="export-estimate"]').trigger('click')
    await flushPromises()
    await w.find('[data-testid="export-destination"]').setValue('s3')
    expect(w.find('[data-testid="export-start"]').attributes('disabled')).toBeDefined()

    await w.find('[data-testid="export-s3-endpoint"]').setValue('https://s3.example.com')
    await w.find('[data-testid="export-s3-bucket"]').setValue('backups')
    await w.find('[data-testid="export-s3-access-key"]').setValue('AK')
    await w.find('[data-testid="export-s3-secret-key"]').setValue('SK')
    await w.find('[data-testid="export-start"]').trigger('click')
    await flushPromises()

    expect(orgTransferApi.startExport.mock.calls[0][0].destination).toEqual({
      type: 's3', endpoint: 'https://s3.example.com', bucket: 'backups', access_key_id: 'AK', secret_access_key: 'SK', path_style: true, prefix: 'runr-exports',
    })
    expect(w.find('[data-testid="export-s3-secret-key"]').element.value).toBe('')
  })

  it('shows the error of a refused start', async () => {
    orgTransferApi.startExport.mockRejectedValue({ response: { status: 429, data: { message: 'An export of this organization is already running.', code: 'too_many_transfers' } } })
    const w = mountPanel()
    await w.find('[data-testid="export-estimate"]').trigger('click')
    await flushPromises()
    await w.find('[data-testid="export-start"]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid="export-error"]').text()).toBe('An export of this organization is already running.')
    expect(w.emitted('started')).toBeUndefined()
  })

  it('follows the running export and offers the finished package', async () => {
    const w = mountPanel({ transfer: transfer({ status: 'running', phase: 'binding_data', progress: 40, message: 'Dumping db' }) })
    expect(w.find('[data-testid="export-status"]').text()).toContain('Running · Binding data')
    expect(w.find('[data-testid="export-progress-bar"]').attributes('style')).toContain('width: 40%')
    expect(w.find('[data-testid="export-start"]').attributes('disabled')).toBeDefined()

    await w.setProps({ transfer: transfer({ status: 'completed', phase: 'done', progress: 100, package: pkg(), report: { entries: 12, bytes: 5 * 1024 * 1024, warnings: [], skipped: [] } }) })
    await w.find('[data-testid="export-download"]').trigger('click')
    expect(w.emitted('download')[0][0].id).toBe('tr-1')
    await w.find('[data-testid="export-report"]').trigger('click')
    expect(w.emitted('report')).toHaveLength(1)
  })
})
