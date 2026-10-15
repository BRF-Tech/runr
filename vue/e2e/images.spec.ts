import { test, expect } from './fixtures/test'
import { continueButton } from './helpers/ui'
import type { Json } from './fixtures/data'
import type { MockApi } from './fixtures/mock-api'

// Uploaded images (docs/images.md) with the mocked API: Settings → Images lists the uploads with
// their scan and the quota, uploads a file in chunks (progress, completion), resumes and deletes;
// the CaaS step of the create wizard picks an uploaded image of the region.

const DIGEST = 'sha256:' + 'ab'.repeat(32)
const REF = `10.43.0.5:5000/runr-uploads/platform/web@${DIGEST}`

function uploaded(extra: Json = {}): Json {
  return {
    id: 'img-1', name: 'web:1.0', repository: 'web', tag: '1.0', knative_region_id: 'r-ready', region_name: 'eu-1',
    image_ref: REF, tag_ref: '10.43.0.5:5000/runr-uploads/platform/web:1.0', digest: DIGEST,
    media_type: 'application/vnd.oci.image.index.v1+json', kind: 'image', source_format: 'oci',
    size_bytes: 52428800, archive_bytes: 60000000, platforms: ['linux/amd64', 'linux/arm64'], layers: 4,
    source_filename: 'web.tar', note: null, created_at: '2026-10-11T08:00:00Z',
    security: { status: 'completed', scan_status: 'ok', counts: { critical: 0, high: 3, medium: 1, low: 0, unknown: 0 } },
    ...extra,
  }
}

function imagesBackend(mockApi: MockApi, state: { images: Json[], uploads: Json[] }) {
  const quota = () => ({ limit_bytes: 50 * 1024 ** 3, used_bytes: state.images.reduce((s, i) => s + (i.size_bytes as number), 0), images: state.images.length })
  mockApi.on('GET', '/images', (req) => {
    const region = req.query.get('knative_region_id')
    const kind = req.query.get('kind')
    const data = state.images.filter(i => (!region || i.knative_region_id === region) && (!kind || i.kind === kind))
    return { status: 200, body: { data, quota: quota() } }
  })
  mockApi.on('GET', '/images/quota', () => ({ status: 200, body: quota() }))
  mockApi.on('GET', '/images/uploads', () => ({ status: 200, body: { data: state.uploads } }))
  mockApi.on('DELETE', /^\/images\/(img-[\w-]+)$/, (req) => {
    state.images = state.images.filter(i => !req.path.endsWith(i.id as string))
    return { status: 200, body: { deleted: true, registry_removed: true } }
  })
}

test.describe('Uploaded images', () => {
  test.skip(({ e2eMode }) => e2eMode === 'stack', 'needs the mocked API (uploads go to a region registry)')

  test('Settings → Images lists the uploads with their scan and the quota, and deletes', async ({ page, auth, mockApi }) => {
    const state = { images: [uploaded(), uploaded({ id: 'img-2', name: 'q-interpreter:1.0', kind: 'wasm', platforms: ['wasip1/wasm'], security: null, size_bytes: 1048576 })], uploads: [] }
    imagesBackend(mockApi, state)
    await auth.signIn()
    await page.goto('/settings?tab=images')

    const manager = page.getByTestId('images-manager')
    await expect(manager.locator('[data-image="web:1.0"]')).toContainText('Container image')
    await expect(manager.locator('[data-image="web:1.0"]')).toContainText('linux/amd64, linux/arm64')
    await expect(manager.locator('[data-image="web:1.0"] [data-testid="image-security"]')).toHaveText('C 0 · H 3')
    await expect(manager.locator('[data-image="q-interpreter:1.0"]')).toContainText('WebAssembly module')
    await expect(page.getByTestId('images-quota')).toContainText('of 50.0 GiB used')

    const deleted = mockApi.waitFor('DELETE', '/images/img-2')
    await manager.locator('[data-image="q-interpreter:1.0"] [data-testid="image-delete"]').click()
    await page.getByRole('button', { name: 'Delete', exact: true }).last().click()
    await deleted
    await expect(manager.locator('[data-image="q-interpreter:1.0"]')).toBeHidden()
  })

  test('uploads a docker save archive in chunks and lists it', async ({ page, auth, mockApi }) => {
    const state = { images: [] as Json[], uploads: [] as Json[] }
    imagesBackend(mockApi, state)
    const body = Buffer.alloc(10 * 1024 * 1024 + 5, 7) // two chunks of 8 MiB at most

    let received = 0
    let polls = 0
    const base: Json = {
      id: 'up-1', name: 'web:1.0', repository: 'web', tag: '1.0', knative_region_id: 'r-ready', region_name: 'eu-1',
      filename: 'web.tar', format: 'auto', size_bytes: body.length, sha256: null, chunk_size: 8 * 1024 * 1024, message: null, image: null,
    }
    mockApi.on('POST', '/images/uploads', () => ({ status: 201, body: { data: { ...base, status: 'ready', uploaded_bytes: 0 } } }))
    mockApi.on('PUT', '/images/uploads/up-1/chunk', (req) => {
      const offset = Number(req.query.get('offset'))
      received = Math.min(body.length, offset + 8 * 1024 * 1024)
      return { status: 200, body: { uploaded_bytes: received, size_bytes: body.length } }
    })
    mockApi.on('POST', '/images/uploads/up-1/complete', () => ({ status: 202, body: { data: { ...base, status: 'verifying', uploaded_bytes: received } } }))
    mockApi.on('GET', '/images/uploads/up-1', () => {
      polls++
      if (polls < 2) return { status: 200, body: { data: { ...base, status: 'pushing', uploaded_bytes: received } } }
      state.images = [uploaded()]
      return { status: 200, body: { data: { ...base, status: 'completed', uploaded_bytes: received, image: uploaded() } } }
    })

    await auth.signIn()
    await page.goto('/settings?tab=images')
    await expect(page.getByTestId('images-empty')).toBeVisible()
    await page.getByTestId('images-upload').click()

    const dialog = page.getByTestId('image-upload-dialog')
    await dialog.getByTestId('image-upload-region').selectOption('r-ready')
    await dialog.getByTestId('image-upload-file').setInputFiles({ name: 'web.tar', mimeType: 'application/x-tar', buffer: body })
    await expect(dialog.getByTestId('image-upload-name')).toHaveValue('web:latest')
    await dialog.getByTestId('image-upload-name').fill('web:1.0')

    const created = mockApi.waitFor('POST', '/images/uploads')
    await dialog.getByTestId('image-upload-start').click()
    expect((await created).body).toMatchObject({ knative_region_id: 'r-ready', filename: 'web.tar', size: body.length, name: 'web:1.0', format: 'auto' })

    await expect(dialog).toBeHidden({ timeout: 15000 })
    expect(mockApi.calls('PUT', '/images/uploads/up-1/chunk').map(r => r.query.get('offset'))).toEqual(['0', String(8 * 1024 * 1024)])
    await expect(page.getByTestId('images-manager').locator('[data-image="web:1.0"]')).toBeVisible()
  })

  test('an unfinished upload resumes from what the region has', async ({ page, auth, mockApi }) => {
    const pending: Json = {
      id: 'up-9', name: 'web:1.0', repository: 'web', tag: '1.0', knative_region_id: 'r-ready', region_name: 'eu-1',
      filename: 'web.tar', format: 'auto', size_bytes: 20, sha256: null, uploaded_bytes: 8, chunk_size: 8, status: 'ready', message: null, image: null,
    }
    const state = { images: [] as Json[], uploads: [pending] }
    imagesBackend(mockApi, state)
    mockApi.on('GET', '/images/uploads/up-9', () => ({ status: 200, body: { data: state.uploads.length ? pending : { ...pending, status: 'completed', uploaded_bytes: 20, image: uploaded() } } }))
    mockApi.on('PUT', '/images/uploads/up-9/chunk', (req) => ({ status: 200, body: { uploaded_bytes: Math.min(20, Number(req.query.get('offset')) + 8) } }))
    mockApi.on('POST', '/images/uploads/up-9/complete', () => {
      state.uploads = []
      return { status: 202, body: { data: { ...pending, status: 'verifying', uploaded_bytes: 20 } } }
    })

    await auth.signIn()
    await page.goto('/settings?tab=images')
    await expect(page.getByTestId('images-open-uploads')).toContainText('web:1.0')
    await page.getByTestId('images-resume').click()

    const dialog = page.getByTestId('image-upload-dialog')
    await expect(dialog.getByTestId('image-upload-resume')).toContainText('40%')
    await dialog.getByTestId('image-upload-file').setInputFiles({ name: 'web.tar', mimeType: 'application/x-tar', buffer: Buffer.alloc(20, 1) })
    await dialog.getByTestId('image-upload-start').click()

    await expect(dialog).toBeHidden({ timeout: 15000 })
    expect(mockApi.calls('POST', '/images/uploads')).toHaveLength(0)
    expect(mockApi.calls('PUT', '/images/uploads/up-9/chunk').map(r => r.query.get('offset'))).toEqual(['8', '16'])
  })

  test('the CaaS step runs an uploaded image of the region', async ({ page, auth, mockApi }) => {
    imagesBackend(mockApi, { images: [uploaded()], uploads: [] })
    await auth.signIn()
    await page.goto('/projects')
    await page.getByRole('button', { name: '+ New Project' }).click()
    await page.locator('[data-tour="create-name"] input').first().fill('web-from-upload')
    await page.locator('[data-tour="create-region"]').getByRole('button', { name: /eu-1/ }).click()
    await continueButton(page).click()
    await page.getByRole('button', { name: /Container/ }).click()
    await continueButton(page).click()

    await expect(page.getByText('Step 3 / 4')).toBeVisible()
    const picker = page.getByTestId('uploaded-image-picker')
    await picker.getByTestId('image-source-uploaded').click()
    await expect(picker.getByTestId('uploaded-image-select')).toHaveValue(REF)
    await expect(picker).toContainText(`Pinned by digest: ${DIGEST}`)
    await continueButton(page).click()

    const sent = mockApi.waitFor('POST', '/projects')
    await page.getByRole('button', { name: 'Create Project' }).click()
    expect((await sent).body.config).toMatchObject({ image: REF })
  })
})
