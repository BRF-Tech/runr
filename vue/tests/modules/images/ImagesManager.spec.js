import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ImagesManager from '@/modules/images/components/ImagesManager.vue'
import { imagesApi } from '@/modules/images/api'
import { useConfirm } from '@/composables/useConfirm'
import { usePermissionsStore } from '@/stores/permissions'
import { useSettingsStore } from '@/stores/settings'
import { makeImagesI18n, image, upload } from './support'

vi.mock('@/modules/images/api', async (importOriginal) => {
  const actual = await importOriginal()
  return {
    ...actual,
    imagesApi: {
      list: vi.fn(), remove: vi.fn(), quota: vi.fn(), uploads: vi.fn(), abortUpload: vi.fn(),
      createUpload: vi.fn(), getUpload: vi.fn(), uploadChunk: vi.fn(), completeUpload: vi.fn(),
    },
  }
})
vi.mock('@/composables/useConfirm', () => {
  const confirm = vi.fn()
  return { useConfirm: () => ({ confirm }) }
})

const i18n = makeImagesI18n()
const mounted = []

async function mountManager({ images = [image()], uploads = [], permissions = ['images.view', 'images.upload', 'images.delete'] } = {}) {
  setActivePinia(createPinia())
  usePermissionsStore().apply({ permissions })
  const settings = useSettingsStore()
  settings.loaded = true
  settings.data.regions = [{ id: 'r1', name: 'eu-1', is_active: true, is_synced: true }, { id: 'r2', name: 'tr-1', is_active: true, is_synced: true }]
  imagesApi.list.mockResolvedValue({ data: { data: images, quota: { limit_bytes: 1024 ** 3, used_bytes: 52428800, images: images.length } } })
  imagesApi.uploads.mockResolvedValue({ data: { data: uploads } })
  const w = mount(ImagesManager, { global: { plugins: [i18n] } })
  mounted.push(w)
  await flushPromises()
  return w
}

beforeEach(() => { vi.clearAllMocks(); useConfirm().confirm.mockResolvedValue(true) })
afterEach(() => mounted.splice(0).forEach(w => w.unmount()))

describe('ImagesManager', () => {
  it('lists the images with kind, platforms, size, scan and the quota', async () => {
    const w = await mountManager({ images: [image(), image({ id: 'img2', name: 'q-interpreter:1.0', kind: 'wasm', platforms: ['wasip1/wasm'], security: null, knative_region_id: 'r2', region_name: 'tr-1' })] })

    const rows = w.findAll('tbody tr')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('web:1.0')
    expect(rows[0].text()).toContain('Container image')
    expect(rows[0].text()).toContain('linux/amd64, linux/arm64')
    expect(rows[0].text()).toContain('50.0 MiB')
    expect(rows[0].find('[data-testid="image-security"]').text()).toBe('C 1 · H 2')
    expect(rows[1].text()).toContain('WebAssembly module')
    expect(w.find('[data-testid="images-quota"]').text()).toContain('50.0 MiB of 1.0 GiB used')

    await w.find('[data-testid="images-region-filter"]').setValue('r2')
    expect(w.findAll('tbody tr')).toHaveLength(1)
    expect(w.find('tbody tr').text()).toContain('q-interpreter:1.0')
  })

  it('deletes after confirming and refreshes the quota', async () => {
    imagesApi.remove.mockResolvedValue({ data: { deleted: true } })
    imagesApi.quota.mockResolvedValue({ data: { limit_bytes: 1024 ** 3, used_bytes: 0, images: 0 } })
    const w = await mountManager()

    await w.find('[data-testid="image-delete"]').trigger('click')
    await flushPromises()

    expect(useConfirm().confirm).toHaveBeenCalledWith(expect.objectContaining({ destructive: true }))
    expect(imagesApi.remove).toHaveBeenCalledWith('img1')
    expect(w.find('[data-testid="images-empty"]').exists()).toBe(true)
  })

  it('shows the API reason when an image is in use', async () => {
    imagesApi.remove.mockRejectedValue({ response: { status: 409, data: { message: 'web:1.0 is still used by shop.' } } })
    const w = await mountManager()
    await w.find('[data-testid="image-delete"]').trigger('click')
    await flushPromises()
    expect(w.findAll('tbody tr')).toHaveLength(1)
  })

  it('offers unfinished uploads to resume or abort', async () => {
    imagesApi.abortUpload.mockResolvedValue({})
    const w = await mountManager({ uploads: [upload({ uploaded_bytes: 4 })] })

    const open = w.find('[data-testid="images-open-uploads"]')
    expect(open.text()).toContain('web:1.0')
    await open.find('[data-testid="images-resume"]').trigger('click')
    expect(w.find('[data-testid="image-upload-dialog"]').exists()).toBe(true)
    expect(w.find('[data-testid="image-upload-resume"]').text()).toContain('40%')

    await open.find('[data-testid="images-abort"]').trigger('click')
    await flushPromises()
    expect(imagesApi.abortUpload).toHaveBeenCalledWith('up1')
  })

  it('hides upload and delete without the permissions', async () => {
    const w = await mountManager({ permissions: ['images.view'] })
    expect(w.find('[data-testid="images-upload"]').exists()).toBe(false)
    expect(w.find('[data-testid="image-delete"]').exists()).toBe(false)
    expect(imagesApi.uploads).not.toHaveBeenCalled()
  })
})
