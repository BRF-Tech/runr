import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ImportPanel from '@/modules/org-transfer/components/ImportPanel.vue'
import { orgTransferApi } from '@/modules/org-transfer/api'
import { makeOrgTransferI18n, transfer, importTransfer } from './support'

// The import card (docs/org-export.md#import): a .tar.zst package goes up in chunks — a new
// import, or the unfinished upload of the same file resumed — then the API checks it; the plan
// shows once the transfer carries one.

vi.mock('@/modules/org-transfer/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, orgTransferApi: { get: vi.fn(), startImport: vi.fn(), uploadChunk: vi.fn(), complete: vi.fn(), plan: vi.fn(), apply: vi.fn(), resumeFetch: vi.fn() } }
})

const i18n = makeOrgTransferI18n()
const mounted = []

function mountPanel(props = {}) {
  setActivePinia(createPinia())
  const w = mount(ImportPanel, { props: { transfer: null, transfers: [], regions: [], ...props }, global: { plugins: [i18n] } })
  mounted.push(w)
  return w
}

function pick(w, name, size) {
  const file = Object.assign(new Blob([new Uint8Array(size).fill(1)]), { name })
  const input = w.find('[data-testid="import-file"]')
  Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
  return input.trigger('change')
}

function uploading(extra = {}) {
  return transfer({ id: 'im-1', kind: 'import', status: 'uploading', progress: 0, destination: null, upload: { size_bytes: 10, uploaded_bytes: 0, chunk_size: 4, sha256: null }, ...extra })
}

beforeEach(() => {
  vi.clearAllMocks()
  orgTransferApi.uploadChunk.mockImplementation(async (id, offset, blob) => ({ data: { uploaded_bytes: offset + blob.size, size_bytes: 10 } }))
  orgTransferApi.complete.mockResolvedValue({ data: { data: uploading({ status: 'verifying', phase: 'verify' }) } })
})
afterEach(() => mounted.splice(0).forEach(w => w.unmount()))

describe('ImportPanel sources', () => {
  const fetchingTransfer = (extra = {}) => transfer({ id: 'im-2', kind: 'import', status: 'fetching', phase: 'fetch', progress: 40, destination: null, source: { type: 'binding', binding_id: 'b9', binding_name: 'backups', key: 'dr/p.tar.zst' }, upload: { size_bytes: 1000, uploaded_bytes: 400 }, ...extra })

  it('starts an import from an object binding', async () => {
    orgTransferApi.startImport.mockResolvedValue({ data: { data: fetchingTransfer() } })
    const w = mountPanel({ bindings: [{ id: 'b9', name: 'backups', type: 'object' }, { id: 'db', name: 'db', type: 'postgres' }] })
    await w.find('[data-testid="import-mode-binding"]').setValue(true)
    expect(w.find('[data-testid="import-file"]').exists()).toBe(false)
    expect(w.findAll('[data-testid="import-source-binding"] option').map(o => o.text())).toEqual(['—', 'backups'])
    expect(w.find('[data-testid="import-source-start"]').attributes('disabled')).toBeDefined()

    await w.find('[data-testid="import-source-binding"]').setValue('b9')
    await w.find('[data-testid="import-source-key"]').setValue('dr/p.tar.zst')
    await w.find('[data-testid="import-source-start"]').trigger('click')
    await flushPromises()

    expect(orgTransferApi.startImport).toHaveBeenCalledWith({ source: { type: 'binding', binding_id: 'b9', key: 'dr/p.tar.zst' } })
    expect(w.emitted('changed')[0][0].status).toBe('fetching')
  })

  it('sends S3 credentials with the start only and shows the read', async () => {
    orgTransferApi.startImport.mockResolvedValue({ data: { data: fetchingTransfer() } })
    const w = mountPanel()
    await w.find('[data-testid="import-mode-s3"]').setValue(true)
    await w.find('[data-testid="import-source-endpoint"]').setValue('https://s3.example.com')
    await w.find('[data-testid="import-source-bucket"]').setValue('backups')
    await w.find('[data-testid="import-source-access-key"]').setValue('AK')
    await w.find('[data-testid="import-source-secret-key"]').setValue('SK')
    await w.find('[data-testid="import-source-key"]').setValue('p.tar.zst')
    await w.find('[data-testid="import-source-start"]').trigger('click')
    await flushPromises()
    expect(orgTransferApi.startImport.mock.calls[0][0].source).toMatchObject({ type: 's3', bucket: 'backups', access_key_id: 'AK', secret_access_key: 'SK' })
    expect(w.find('[data-testid="import-source-secret-key"]').element.value).toBe('')

    await w.setProps({ transfer: fetchingTransfer() })
    expect(w.find('[data-testid="import-fetching"]').text()).toContain('40%')
  })

  it('resumes a read that failed, with the S3 credentials again', async () => {
    orgTransferApi.resumeFetch.mockResolvedValue({ data: { data: fetchingTransfer() } })
    const failed = fetchingTransfer({ status: 'failed', message: 'network', source: { type: 's3', endpoint: 'https://s3', bucket: 'b', key: 'k.tar.zst' } })
    const w = mountPanel({ transfer: failed })
    expect(w.find('[data-testid="import-fetch-failed"]').text()).toContain('network')
    expect(w.find('[data-testid="import-failed"]').exists()).toBe(false)

    await w.find('[data-testid="import-resume-access-key"]').setValue('AK')
    await w.find('[data-testid="import-resume-secret-key"]').setValue('SK')
    await w.find('[data-testid="import-fetch-resume"]').trigger('click')
    await flushPromises()
    expect(orgTransferApi.resumeFetch).toHaveBeenCalledWith('im-2', { access_key_id: 'AK', secret_access_key: 'SK' })
    expect(w.emitted('changed')[0][0].status).toBe('fetching')
  })
})

describe('ImportPanel', () => {
  it('refuses a file that is not a package', async () => {
    const w = mountPanel()
    await pick(w, 'backup.zip', 10)
    expect(w.find('[data-testid="import-error"]').text()).toContain('.tar.zst')
    expect(w.find('[data-testid="import-upload"]').attributes('disabled')).toBeDefined()
  })

  it('starts an import, sends the chunks and completes the upload', async () => {
    orgTransferApi.startImport.mockResolvedValue({ data: { data: uploading() } })
    const w = mountPanel()
    await pick(w, 'runr-org-acme.tar.zst', 10)
    await w.find('[data-testid="import-upload"]').trigger('click')
    // hashing the file (Web Crypto) is asynchronous
    await vi.waitFor(() => expect(orgTransferApi.complete).toHaveBeenCalled())
    await flushPromises()

    const started = orgTransferApi.startImport.mock.calls[0][0]
    expect(started).toMatchObject({ filename: 'runr-org-acme.tar.zst', size: 10 })
    if (started.sha256 !== undefined) expect(started.sha256).toMatch(/^[a-f0-9]{64}$/)
    expect(orgTransferApi.uploadChunk.mock.calls.map(c => c[1])).toEqual([0, 4, 8])
    expect(orgTransferApi.complete).toHaveBeenCalledWith('im-1')
    const changed = w.emitted('changed').map(e => e[0].status)
    expect(changed).toEqual(['uploading', 'verifying'])
  })

  it('resumes the unfinished upload of the same file', async () => {
    const open = uploading({ upload: { size_bytes: 10, uploaded_bytes: 8, chunk_size: 4, sha256: null }, options: { filename: 'runr-org-acme.tar.zst' } })
    orgTransferApi.get.mockResolvedValue({ data: { data: open } })
    const w = mountPanel({ transfers: [open] })
    await pick(w, 'runr-org-acme.tar.zst', 10)

    expect(w.find('[data-testid="import-resume-note"]').text()).toContain('continues from 8 B')
    expect(w.find('[data-testid="import-upload"]').text()).toContain('Resume the upload')
    await w.find('[data-testid="import-upload"]').trigger('click')
    await flushPromises()

    expect(orgTransferApi.startImport).not.toHaveBeenCalled()
    expect(orgTransferApi.uploadChunk.mock.calls.map(c => c[1])).toEqual([8])
    expect(orgTransferApi.complete).toHaveBeenCalledWith('im-1')
  })

  it('shows the error of a refused upload', async () => {
    orgTransferApi.startImport.mockRejectedValue({ response: { status: 429, data: { message: 'An import of this organization is already running.', code: 'too_many_transfers' } } })
    const w = mountPanel()
    await pick(w, 'runr-org-acme.tar.zst', 10)
    await w.find('[data-testid="import-upload"]').trigger('click')
    await vi.waitFor(() => expect(w.find('[data-testid="import-error"]').exists()).toBe(true))

    expect(w.find('[data-testid="import-error"]').text()).toBe('An import of this organization is already running.')
    expect(orgTransferApi.uploadChunk).not.toHaveBeenCalled()
  })

  it('shows the check, a refused package and the plan', async () => {
    const w = mountPanel({ transfer: uploading({ status: 'verifying', progress: 40 }) })
    expect(w.find('[data-testid="import-verifying"]').text()).toContain('Checking the package')

    await w.setProps({ transfer: uploading({ status: 'failed', message: 'The package was written by a newer RunR (schema 2); this installation reads schema 1 and older.' }) })
    expect(w.find('[data-testid="import-failed"]').text()).toContain('newer RunR')

    await w.setProps({ transfer: importTransfer() })
    expect(w.find('[data-testid="import-plan"]').exists()).toBe(true)
  })
})
