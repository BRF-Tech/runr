import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { withPlugins } from '../helpers'

// Secret env vars editor (docs/security.md, docs/secret-stores.md): write-only values that live in
// the region, references to secret stores with their sync status, local mode for the create wizard.
const api = vi.hoisted(() => ({
  list: vi.fn(),
  set: vi.fn(),
  setReference: vi.fn(),
  refresh: vi.fn(),
  remove: vi.fn(),
  reveal: vi.fn(),
}))
vi.mock('@/api/security', () => ({ projectSecretsApi: api, auditApi: {} }))

const storesApi = vi.hoisted(() => ({ list: vi.fn() }))
vi.mock('@/api/secretStores', () => ({ secretStoresApi: storesApi, externalSecretsApi: {} }))

const confirm = vi.hoisted(() => vi.fn())
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm }) }))

import SecretEnvVarsEditor from '@/components/project/SecretEnvVarsEditor.vue'

function mountEditor(props) {
  return mount(SecretEnvVarsEditor, { props, global: withPlugins('en').global })
}

beforeEach(() => {
  vi.clearAllMocks()
  api.list.mockResolvedValue({ data: { data: [], can_view_values: false } })
  api.set.mockResolvedValue({ data: { data: {} } })
  api.setReference.mockResolvedValue({ data: { data: {} } })
  api.remove.mockResolvedValue({ data: {} })
  storesApi.list.mockResolvedValue({ data: { data: [{ id: 'ss-1', name: 'team-vault', provider: 'vault', auth: 'kubernetes' }], providers: [] } })
  confirm.mockResolvedValue(true)
})

const IN_REGION = {
  name: 'API_TOKEN', source: 'region', version: 3, stored_in: 'region', fingerprint: '0a1b2c3d4e5f',
  updated_at: '2026-10-20T10:00:00Z', updated_by: { name: 'Jane' },
}
const LEGACY = { name: 'OLD_KEY', source: 'region', version: 1, stored_in: 'database', fingerprint: null }
const REFERENCE = {
  name: 'DB_PASSWORD', source: 'external', version: 1,
  reference: { store_id: 'ss-1', store_name: 'team-vault', provider: 'vault', path: 'apps/orders', key: 'password' },
  sync: { status: 'failed', message: 'permission denied', synced_at: null },
}

describe('SecretEnvVarsEditor — local mode (create project)', () => {
  it('collects name + value pairs through v-model without calling the API', async () => {
    const w = mountEditor({ modelValue: [] })
    await w.find('[data-testid="secret-new-name"]').setValue('DB_PASSWORD')
    await w.find('[data-testid="secret-new-value"]').setValue('hunter2')
    await w.find('[data-testid="secret-add"]').trigger('click')

    expect(w.emitted('update:modelValue').at(-1)).toEqual([[{ name: 'DB_PASSWORD', value: 'hunter2' }]])
    expect(api.set).not.toHaveBeenCalled()
    expect(api.list).not.toHaveBeenCalled()
  })

  it('never renders a pending value', async () => {
    const w = mountEditor({ modelValue: [{ name: 'API_KEY', value: 'pending-secret' }] })
    expect(w.text()).toContain('API_KEY')
    expect(w.text()).not.toContain('pending-secret')
    expect(w.find('[data-testid="secret-value-API_KEY"]').text()).toBe('••••••••')
  })

  it('rejects invalid, reserved, duplicate and plain names', async () => {
    const w = mountEditor({ modelValue: [{ name: 'TAKEN', value: 'x' }], plainNames: ['LOG_LEVEL'] })
    const add = async (name, value = 'v') => {
      await w.find('[data-testid="secret-new-name"]').setValue(name)
      await w.find('[data-testid="secret-new-value"]').setValue(value)
      await w.find('[data-testid="secret-add"]').trigger('click')
      return w.find('[data-testid="secret-error"]').text()
    }

    expect(await add('9LIVES')).toContain('letters, digits')
    expect(await add('PORT')).toContain('"PORT" is set by the platform')
    expect(await add('TAKEN')).toContain('"TAKEN" already exists')
    expect(await add('LOG_LEVEL')).toContain('plain variable')
    expect(await add('EMPTY', '')).toBe('Enter a value.')
    expect(w.emitted('update:modelValue')).toBeUndefined()
  })

  it('removes a pending secret', async () => {
    const w = mountEditor({ modelValue: [{ name: 'A', value: '1' }, { name: 'B', value: '2' }] })
    await w.find('[data-testid="secret-remove-A"]').trigger('click')
    expect(w.emitted('update:modelValue').at(-1)).toEqual([[{ name: 'B', value: '2' }]])
  })
})

describe('SecretEnvVarsEditor — project mode (edit project)', () => {
  it('lists names only and adds a secret through the API', async () => {
    api.list.mockResolvedValueOnce({ data: { data: [{ name: 'EXISTING', updated_at: '2026-10-01T10:00:00Z', updated_by: { name: 'Jane' } }], can_view_values: false } })
    const w = mountEditor({ projectId: 'p-1' })
    await flushPromises()

    expect(api.list).toHaveBeenCalledWith('p-1')
    expect(w.find('[data-testid="secret-row-EXISTING"]').text()).toContain('••••••••')
    expect(w.text()).toContain('Jane')
    expect(w.find('[data-testid="secret-reveal-EXISTING"]').exists()).toBe(false)

    await w.find('[data-testid="secret-new-name"]').setValue('NEW_ONE')
    await w.find('[data-testid="secret-new-value"]').setValue('s3cr3t')
    await w.find('[data-testid="secret-add"]').trigger('click')
    await flushPromises()

    expect(api.set).toHaveBeenCalledWith('p-1', 'NEW_ONE', 's3cr3t')
    expect(w.emitted('changed')).toHaveLength(1)
    expect(w.find('[data-testid="secret-new-value"]').element.value).toBe('')
  })

  it('replaces a value without ever showing the old one', async () => {
    api.list.mockResolvedValue({ data: { data: [{ name: 'TOKEN' }], can_view_values: false } })
    const w = mountEditor({ projectId: 'p-1' })
    await flushPromises()

    await w.find('[data-testid="secret-replace-TOKEN"]').trigger('click')
    await w.find('[data-testid="secret-replace-input-TOKEN"]').setValue('rotated')
    await w.find('[data-testid="secret-replace-input-TOKEN"]').trigger('keydown.enter')
    await flushPromises()

    expect(api.set).toHaveBeenCalledWith('p-1', 'TOKEN', 'rotated')
    expect(w.find('[data-testid="secret-replace-input-TOKEN"]').exists()).toBe(false)
  })

  it('reveals a value only for users who may view values', async () => {
    api.list.mockResolvedValue({ data: { data: [{ name: 'TOKEN' }], can_view_values: true } })
    api.reveal.mockResolvedValue({ data: { name: 'TOKEN', value: 'plain-token' } })
    const w = mountEditor({ projectId: 'p-1' })
    await flushPromises()

    await w.find('[data-testid="secret-reveal-TOKEN"]').trigger('click')
    await flushPromises()
    expect(api.reveal).toHaveBeenCalledWith('p-1', 'TOKEN')
    expect(w.find('[data-testid="secret-value-TOKEN"]').text()).toBe('plain-token')

    await w.find('[data-testid="secret-reveal-TOKEN"]').trigger('click')
    expect(w.find('[data-testid="secret-value-TOKEN"]').text()).toBe('••••••••')
  })

  it('asks before removing a secret', async () => {
    api.list.mockResolvedValue({ data: { data: [{ name: 'TOKEN' }], can_view_values: false } })
    const w = mountEditor({ projectId: 'p-1' })
    await flushPromises()

    confirm.mockResolvedValueOnce(false)
    await w.find('[data-testid="secret-remove-TOKEN"]').trigger('click')
    await flushPromises()
    expect(api.remove).not.toHaveBeenCalled()

    await w.find('[data-testid="secret-remove-TOKEN"]').trigger('click')
    await flushPromises()
    expect(api.remove).toHaveBeenCalledWith('p-1', 'TOKEN')
  })

  it('shows the API validation message', async () => {
    api.set.mockRejectedValueOnce({ response: { data: { errors: { name: ['"X" is already a plain environment variable of this project; remove it there first.'] } } } })
    const w = mountEditor({ projectId: 'p-1' })
    await flushPromises()

    await w.find('[data-testid="secret-new-name"]').setValue('X')
    await w.find('[data-testid="secret-new-value"]').setValue('v')
    await w.find('[data-testid="secret-add"]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid="secret-error"]').text()).toContain('already a plain environment variable')
  })
})

describe('SecretEnvVarsEditor — where values live (pass-through, secret stores)', () => {
  it('shows a value in the region with its fingerprint, and one still in the database', async () => {
    api.list.mockResolvedValue({ data: { data: [IN_REGION, LEGACY], can_view_values: true } })
    const w = mountEditor({ projectId: 'p-1' })
    await flushPromises()

    expect(w.find('[data-testid="secret-source-API_TOKEN"]').text()).toBe('RunR (in-region)')
    expect(w.find('[data-testid="secret-fingerprint-API_TOKEN"]').text()).toContain('0a1b2c3d4e5f')
    expect(w.find('[data-testid="secret-in-database-API_TOKEN"]').exists()).toBe(false)
    expect(w.find('[data-testid="secret-in-database-OLD_KEY"]').text()).toBe('Still in the database')
    expect(w.find('[data-testid="secret-fingerprint-OLD_KEY"]').exists()).toBe(false)
    // No reference: nothing to sync
    expect(w.find('[data-testid="secret-sync-now"]').exists()).toBe(false)
  })

  it('shows a value in the region\'s own OpenBao as such, with its fingerprint', async () => {
    const inBao = { ...IN_REGION, name: 'BAO_KEY', source: 'openbao', stored_in: 'openbao' }
    api.list.mockResolvedValue({ data: { data: [IN_REGION, inBao], can_view_values: true } })
    const w = mountEditor({ projectId: 'p-1' })
    await flushPromises()

    expect(w.find('[data-testid="secret-source-BAO_KEY"]').text()).toBe('OpenBao (in-region)')
    expect(w.find('[data-testid="secret-source-API_TOKEN"]').text()).toBe('RunR (in-region)')
    expect(w.find('[data-testid="secret-fingerprint-BAO_KEY"]').text()).toContain('0a1b2c3d4e5f')
    expect(w.find('[data-testid="secret-in-database-BAO_KEY"]').exists()).toBe(false)
  })

  it('shows a reference with its store, path, key and sync status — never a reveal', async () => {
    api.list.mockResolvedValue({ data: { data: [REFERENCE], can_view_values: true } })
    const w = mountEditor({ projectId: 'p-1' })
    await flushPromises()

    expect(w.find('[data-testid="secret-value-DB_PASSWORD"]').text()).toContain('Secret store: team-vault · apps/orders#password')
    expect(w.find('[data-testid="secret-sync-DB_PASSWORD"]').text()).toBe('Sync failed')
    expect(w.find('[data-testid="secret-sync-message-DB_PASSWORD"]').text()).toBe('permission denied')
    expect(w.find('[data-testid="secret-reveal-DB_PASSWORD"]').exists()).toBe(false)
  })

  it('syncs the references now and shows what the region reports', async () => {
    api.list.mockResolvedValue({ data: { data: [REFERENCE], can_view_values: false } })
    api.refresh.mockResolvedValue({ data: { data: [{ ...REFERENCE, sync: { status: 'synced', message: null, synced_at: '2026-10-21T08:00:00Z' } }], can_view_values: false } })
    const w = mountEditor({ projectId: 'p-1' })
    await flushPromises()

    await w.find('[data-testid="secret-sync-now"]').trigger('click')
    await flushPromises()
    expect(api.refresh).toHaveBeenCalledWith('p-1')
    expect(w.find('[data-testid="secret-sync-DB_PASSWORD"]').text()).toBe('Synced')
  })

  it('adds a reference to a secret store', async () => {
    const w = mountEditor({ projectId: 'p-1' })
    await flushPromises()

    await w.find('[data-testid="secret-add-reference"]').trigger('click')
    await flushPromises()
    expect(storesApi.list).toHaveBeenCalled()

    await w.find('[data-testid="secret-ref-name"]').setValue('DB_PASSWORD')
    await w.find('[data-testid="secret-ref-store"]').setValue('ss-1')
    await w.find('[data-testid="secret-ref-path"]').setValue('apps/orders')
    await w.find('[data-testid="secret-ref-key"]').setValue('password')
    await w.find('[data-testid="secret-ref-save"]').trigger('click')
    await flushPromises()

    expect(api.setReference).toHaveBeenCalledWith('p-1', 'DB_PASSWORD', { store_id: 'ss-1', path: 'apps/orders', key: 'password' })
    expect(api.set).not.toHaveBeenCalled()
    expect(w.emitted('changed')).toHaveLength(1)
    expect(w.find('[data-testid="secret-reference-form"]').exists()).toBe(false)
  })

  it('needs a store and a path for a reference, and leaves the key out when empty', async () => {
    const w = mountEditor({ projectId: 'p-1' })
    await flushPromises()
    await w.find('[data-testid="secret-add-reference"]').trigger('click')
    await flushPromises()

    await w.find('[data-testid="secret-ref-name"]').setValue('DB_PASSWORD')
    await w.find('[data-testid="secret-ref-save"]').trigger('click')
    expect(w.find('[data-testid="secret-ref-error"]').text()).toBe('Choose a secret store.')

    await w.find('[data-testid="secret-ref-store"]').setValue('ss-1')
    await w.find('[data-testid="secret-ref-save"]').trigger('click')
    expect(w.find('[data-testid="secret-ref-error"]').text()).toContain('path')

    await w.find('[data-testid="secret-ref-path"]').setValue('apps/orders')
    await w.find('[data-testid="secret-ref-save"]').trigger('click')
    await flushPromises()
    expect(api.setReference).toHaveBeenCalledWith('p-1', 'DB_PASSWORD', { store_id: 'ss-1', path: 'apps/orders' })
  })

  it('says when the organization has no secret store', async () => {
    storesApi.list.mockResolvedValueOnce({ data: { data: [], providers: [] } })
    const w = mountEditor({ projectId: 'p-1' })
    await flushPromises()
    await w.find('[data-testid="secret-add-reference"]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid="secret-ref-no-stores"]').text()).toContain('Administration → Secret stores')
  })

  it('offers no reference while the project does not exist yet', () => {
    const w = mountEditor({ modelValue: [] })
    expect(w.find('[data-testid="secret-add-reference"]').exists()).toBe(false)
  })

  it('warns when the revealed value is not the one RunR wrote', async () => {
    api.list.mockResolvedValue({ data: { data: [IN_REGION], can_view_values: true } })
    api.reveal.mockResolvedValue({ data: { name: 'API_TOKEN', value: 'placeholder-value', matches_fingerprint: false } })
    const w = mountEditor({ projectId: 'p-1' })
    await flushPromises()

    await w.find('[data-testid="secret-reveal-API_TOKEN"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-testid="secret-mismatch-API_TOKEN"]').text()).toContain('changed in the region')
  })

  it('shows the region\'s answer when the value cannot be read there', async () => {
    api.list.mockResolvedValue({ data: { data: [IN_REGION], can_view_values: true } })
    api.reveal.mockRejectedValueOnce({ response: { status: 409, data: { reason: 'value_missing', message: 'The values of API_TOKEN are not in the cluster of region "eu-1".' } } })
    const w = mountEditor({ projectId: 'p-1' })
    await flushPromises()

    await w.find('[data-testid="secret-reveal-API_TOKEN"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-testid="secret-row-error-API_TOKEN"]').text()).toContain('not in the cluster of region "eu-1"')
    expect(w.find('[data-testid="secret-value-API_TOKEN"]').text()).toBe('••••••••')
  })

  it('shows the server message when the region does not answer', async () => {
    api.set.mockRejectedValueOnce({ response: { status: 503, data: { reason: 'region_unreachable', message: 'The cluster of region "eu-1" does not answer.' } } })
    const w = mountEditor({ projectId: 'p-1' })
    await flushPromises()

    await w.find('[data-testid="secret-new-name"]').setValue('NEW_TOKEN')
    await w.find('[data-testid="secret-new-value"]').setValue('placeholder')
    await w.find('[data-testid="secret-add"]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid="secret-error"]').text()).toBe('The cluster of region "eu-1" does not answer.')
    expect(w.emitted('changed')).toBeUndefined()
  })
})
