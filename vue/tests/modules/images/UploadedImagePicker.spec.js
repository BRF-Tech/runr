import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import UploadedImagePicker from '@/modules/images/components/UploadedImagePicker.vue'
import RuntimeUploadVersion from '@/modules/images/components/RuntimeUploadVersion.vue'
import { imagesApi } from '@/modules/images/api'
import { usePermissionsStore } from '@/stores/permissions'
import { makeImagesI18n, image, DIGEST } from './support'

vi.mock('@/modules/images/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, imagesApi: { list: vi.fn() } }
})

const i18n = makeImagesI18n()
const mounted = []

function withPermissions(permissions) {
  setActivePinia(createPinia())
  usePermissionsStore().apply({ permissions })
}

async function mountPicker(props = {}) {
  const w = mount(UploadedImagePicker, {
    props: { modelValue: '', regionId: 'r1', ...props, 'onUpdate:modelValue': (v) => w.setProps({ modelValue: v }) },
    global: { plugins: [i18n] },
  })
  mounted.push(w)
  await flushPromises()
  return w
}

beforeEach(() => {
  vi.clearAllMocks()
  imagesApi.list.mockResolvedValue({ data: { data: [image(), image({ id: 'img2', name: 'api:2', image_ref: `10.43.0.5:5000/runr-uploads/platform/api@${DIGEST}` })] } })
})
afterEach(() => mounted.splice(0).forEach(w => w.unmount()))

describe('UploadedImagePicker', () => {
  it('lists the container images of the region and pins the chosen one by digest', async () => {
    withPermissions(['images.view'])
    const w = await mountPicker()
    expect(imagesApi.list).toHaveBeenCalledWith({ knative_region_id: 'r1', kind: 'image' })

    await w.find('[data-testid="image-source-uploaded"]').trigger('click')
    expect(w.emitted('update:modelValue').at(-1)).toEqual([image().image_ref])

    await w.find('[data-testid="uploaded-image-select"]').setValue(`10.43.0.5:5000/runr-uploads/platform/api@${DIGEST}`)
    expect(w.emitted('update:modelValue').at(-1)).toEqual([`10.43.0.5:5000/runr-uploads/platform/api@${DIGEST}`])
    expect(w.text()).toContain(`Pinned by digest: ${DIGEST}`)

    await w.find('[data-testid="image-source-registry"]').trigger('click')
    expect(w.emitted('update:modelValue').at(-1)).toEqual([''])
    await w.find('[data-testid="image-ref-input"]').setValue('nginx:1.27')
    expect(w.emitted('update:modelValue').at(-1)).toEqual(['nginx:1.27'])
  })

  it('does not offer an image the region\'s registry does not have since the region moved', async () => {
    withPermissions(['images.view'])
    imagesApi.list.mockResolvedValue({ data: { data: [image({ in_registry: false }), image({ id: 'img2', name: 'api:2', image_ref: `10.43.0.5:5000/runr-uploads/platform/api@${DIGEST}`, in_registry: true })] } })
    const w = await mountPicker()

    await w.find('[data-testid="image-source-uploaded"]').trigger('click')
    expect(w.findAll('[data-testid="uploaded-image-select"] option').map(o => o.text())).toEqual([expect.any(String), expect.stringContaining('api:2')])
    expect(w.emitted('update:modelValue').at(-1)).toEqual([`10.43.0.5:5000/runr-uploads/platform/api@${DIGEST}`])
  })

  it('opens on the uploaded image a project already uses', async () => {
    withPermissions(['images.view'])
    const w = await mountPicker({ modelValue: image().image_ref })
    expect(w.find('[data-testid="uploaded-image-select"]').exists()).toBe(true)
  })

  it('is a plain reference field without images.view', async () => {
    withPermissions(['projects.create'])
    const w = await mountPicker()
    expect(imagesApi.list).not.toHaveBeenCalled()
    expect(w.find('[data-testid="image-source-uploaded"]').exists()).toBe(false)
    expect(w.find('[data-testid="image-ref-input"]').exists()).toBe(true)
  })

  it('says so when the region has no uploaded images', async () => {
    withPermissions(['images.view'])
    imagesApi.list.mockResolvedValue({ data: { data: [] } })
    const w = await mountPicker()
    await w.find('[data-testid="image-source-uploaded"]').trigger('click')
    expect(w.find('[data-testid="uploaded-image-none"]').exists()).toBe(true)
  })
})

describe('RuntimeUploadVersion', () => {
  it('emits a version for an uploaded image', async () => {
    withPermissions(['images.view'])
    const w = mount(RuntimeUploadVersion, { global: { plugins: [i18n] } })
    mounted.push(w)
    await flushPromises()

    expect(w.find('[data-testid="runtime-upload-add"]').attributes('disabled')).toBeDefined()
    await w.find('[data-testid="runtime-upload-image"]').setValue('web:1.0')
    expect(w.find('[data-testid="runtime-upload-version-label"]').element.value).toBe('1.0')
    await w.find('input[type="checkbox"]').setValue(true)
    await w.find('[data-testid="runtime-upload-add"]').trigger('click')

    expect(w.emitted('add')[0][0]).toEqual({ version: '1.0', upload: 'web:1.0', makeDefault: true })
  })
})
