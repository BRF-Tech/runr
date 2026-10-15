import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { withPlugins } from '../helpers'

// Secret env vars editor (docs/security.md): write-only values, local mode for the create wizard.
const api = vi.hoisted(() => ({
  list: vi.fn(),
  set: vi.fn(),
  remove: vi.fn(),
  reveal: vi.fn(),
}))
vi.mock('@/api/security', () => ({ projectSecretsApi: api, auditApi: {} }))

const confirm = vi.hoisted(() => vi.fn())
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm }) }))

import SecretEnvVarsEditor from '@/components/project/SecretEnvVarsEditor.vue'

function mountEditor(props) {
  return mount(SecretEnvVarsEditor, { props, global: withPlugins('en').global })
}

beforeEach(() => {
  api.list.mockResolvedValue({ data: { data: [], can_view_values: false } })
  api.set.mockResolvedValue({ data: { data: {} } })
  api.remove.mockResolvedValue({ data: {} })
  confirm.mockResolvedValue(true)
})

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
