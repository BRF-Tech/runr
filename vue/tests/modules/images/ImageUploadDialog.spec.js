import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ImageUploadDialog from '@/modules/images/components/ImageUploadDialog.vue'
import { imagesApi } from '@/modules/images/api'
import { useSettingsStore } from '@/stores/settings'
import { makeImagesI18n, upload, image } from './support'

vi.mock('@/modules/images/api', async (importOriginal) => {
  const actual = await importOriginal()
  const imagesApi = {
    createUpload: vi.fn(), getUpload: vi.fn(), uploadChunk: vi.fn(), completeUpload: vi.fn(), abortUpload: vi.fn(),
  }
  return {
    ...actual,
    imagesApi,
    // the real chunk loop; its default `api` is the module's own imagesApi, so hand it the mock
    uploadImageFile: (opts) => actual.uploadImageFile({ api: imagesApi, ...opts }),
  }
})

const i18n = makeImagesI18n()
const mounted = []

function mountDialog(props = {}) {
  setActivePinia(createPinia())
  const settings = useSettingsStore()
  settings.loaded = true
  settings.data.regions = [{ id: 'r1', name: 'eu-1', is_active: true, is_synced: true }]
  const w = mount(ImageUploadDialog, { props, global: { plugins: [i18n] } })
  mounted.push(w)
  return w
}

// Lets the dialog's 2 s polls run (only setTimeout is faked: flushPromises keeps working)
async function settle(w, done, rounds = 12) {
  for (let i = 0; i < rounds && !done(); i++) {
    await vi.advanceTimersByTimeAsync(2000)
    await flushPromises()
  }
}

async function pickFile(w, file) {
  const input = w.find('[data-testid="image-upload-file"]')
  Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
  await input.trigger('change')
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  imagesApi.uploadChunk.mockImplementation(async (id, offset, blob) => ({ data: { uploaded_bytes: offset + blob.size } }))
})
afterEach(() => {
  mounted.splice(0).forEach(w => w.unmount())
  vi.useRealTimers()
})

describe('ImageUploadDialog', () => {
  it('starts an upload, sends the chunks, completes and waits for the push', async () => {
    imagesApi.createUpload.mockResolvedValue({ data: { data: upload({ status: 'preparing' }) } })
    imagesApi.getUpload
      .mockResolvedValueOnce({ data: { data: upload({ status: 'ready' }) } })
      .mockResolvedValueOnce({ data: { data: upload({ status: 'pushing', uploaded_bytes: 10 }) } })
      .mockResolvedValueOnce({ data: { data: upload({ status: 'completed', uploaded_bytes: 10, image: image() }) } })
    imagesApi.completeUpload.mockResolvedValue({ data: { data: upload({ status: 'verifying' }) } })

    const w = mountDialog()
    await flushPromises()
    expect(w.find('[data-testid="image-upload-region"]').element.value).toBe('r1')

    await pickFile(w, new File(['abcdefghij'], 'Web.tar'))
    expect(w.find('[data-testid="image-upload-name"]').element.value).toBe('web:latest')
    await w.find('[data-testid="image-upload-name"]').setValue('web:1.0')

    await w.find('[data-testid="image-upload-start"]').trigger('click')
    // the SHA-256 of the file comes from Web Crypto (real async work), then the upload is created
    await vi.waitFor(() => expect(imagesApi.createUpload).toHaveBeenCalled())
    // receiver preparing → ready, chunks, complete, pushing → completed
    await settle(w, () => !!w.emitted('completed'))

    expect(imagesApi.createUpload).toHaveBeenCalledWith(expect.objectContaining({
      knative_region_id: 'r1', filename: 'Web.tar', size: 10, name: 'web:1.0', format: 'auto',
    }))
    const sha = imagesApi.createUpload.mock.calls[0][0].sha256
    if (sha !== undefined) expect(sha).toMatch(/^[a-f0-9]{64}$/)
    expect(imagesApi.uploadChunk.mock.calls.map(c => c[1])).toEqual([0, 4, 8])
    expect(imagesApi.completeUpload).toHaveBeenCalledWith('up1')
    expect(w.emitted('completed')[0][0]).toMatchObject({ id: 'img1' })
    expect(w.find('[data-testid="image-upload-phase"]').text()).toBe('Uploaded')
  })

  it('resumes an unfinished upload from the bytes the region has', async () => {
    imagesApi.getUpload
      .mockResolvedValueOnce({ data: { data: upload({ uploaded_bytes: 8 }) } })
      .mockResolvedValueOnce({ data: { data: upload({ status: 'completed', uploaded_bytes: 10, image: image() }) } })
    imagesApi.completeUpload.mockResolvedValue({ data: { data: upload({ status: 'verifying' }) } })

    const w = mountDialog({ resume: upload({ uploaded_bytes: 8 }) })
    await flushPromises()

    // another file of another size cannot resume it
    await pickFile(w, new File(['xyz'], 'other.tar'))
    expect(w.find('[data-testid="image-upload-start"]').attributes('disabled')).toBeDefined()

    await pickFile(w, new File(['abcdefghij'], 'web.tar'))
    await w.find('[data-testid="image-upload-start"]').trigger('click')
    await settle(w, () => !!w.emitted('completed'))

    expect(imagesApi.createUpload).not.toHaveBeenCalled()
    expect(imagesApi.uploadChunk.mock.calls.map(c => c[1])).toEqual([8])
    expect(w.emitted('completed')).toHaveLength(1)
  })

  it('shows why the push failed', async () => {
    imagesApi.createUpload.mockResolvedValue({ data: { data: upload() } })
    imagesApi.getUpload.mockResolvedValue({ data: { data: upload({ status: 'failed', message: 'the archive holds 2 images' }) } })
    imagesApi.completeUpload.mockResolvedValue({ data: { data: upload({ status: 'verifying' }) } })

    const w = mountDialog()
    await flushPromises()
    await pickFile(w, new File(['abcdefghij'], 'two.tar'))
    await w.find('[data-testid="image-upload-start"]').trigger('click')
    await vi.waitFor(() => expect(imagesApi.createUpload).toHaveBeenCalled())
    await settle(w, () => w.find('[data-testid="image-upload-error"]').exists())

    expect(w.find('[data-testid="image-upload-error"]').text()).toBe('the archive holds 2 images')
  })

  it('refuses names the API would refuse', async () => {
    const w = mountDialog()
    await flushPromises()
    await pickFile(w, new File(['x'], 'a.tar'))
    await w.find('[data-testid="image-upload-name"]').setValue('Bad Name')
    expect(w.text()).toContain('Use repository[:tag]')
    expect(w.find('[data-testid="image-upload-start"]').attributes('disabled')).toBeDefined()
  })
})
