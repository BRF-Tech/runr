import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import DataPage from '@/components/bindings/DataPage.vue'
import { bindingsApi } from '@/api/bindings'
import { settingsApi } from '@/api/settings'
import { usePermissionsStore } from '@/stores/permissions'
import { useSettingsStore } from '@/stores/settings'
import { withPlugins } from '../../helpers'

// Data & Integrations → Data (docs/bindings.md): the organization's bindings with attachments and usage,
// limits, create (dedicated Postgres), delete with a typed name and retention, restore,
// rotate, credentials only with bindings.view_credentials.

vi.mock('@/api/bindings', () => ({
  bindingsApi: {
    list: vi.fn(), quota: vi.fn(), types: vi.fn(), create: vi.fn(), destroy: vi.fn(), restore: vi.fn(),
    rotate: vi.fn(), credentials: vi.fn(),
  },
}))
vi.mock('@/api/settings', () => ({ settingsApi: { get: vi.fn(), update: vi.fn() } }))

const confirmMock = vi.fn()
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: confirmMock }) }))

const ORDERS_DB = {
  id: 'b-orders', name: 'orders-db', type: 'postgres', plan: 'shared', status: 'ready',
  region: { id: 'r1', name: 'eu-1' }, settings: { storage_gb: 1 }, usage_bytes: 512 * 1024 ** 2,
  env_keys: ['DATABASE_URL'], details: { cluster: 'runr-pg-shared', database: 'b_orders_db' },
  attachments: [{ id: 'a1', project_id: 'p1', project: { id: 'p1', name: 'Shop' }, env_prefix: 'ORDERS_' }],
}
const OLD_FILES = {
  id: 'b-files', name: 'old-files', type: 'object', plan: 'shared', status: 'deleting',
  region: { id: 'r1', name: 'eu-1' }, settings: { quota_gb: 5 }, usage_bytes: 0, env_keys: [],
  details: { bucket: 'old-files-1234abcd', scoped_keys: false }, purge_at: '2026-10-18T10:00:00Z', attachments: [],
}
const QUOTA = {
  limits: { max_bindings: 10, kv_mb: 1024, object_gb: 100, postgres_gb: 50, dedicated_postgres: 0 },
  used: { max_bindings: 2, kv_mb: 0, object_gb: 5, postgres_gb: 1, dedicated_postgres: 0 },
  measured_bytes: { kv: 0, object: 0, postgres: 536870912 },
}

function mountPage(permissions = null) {
  const plugins = withPlugins()
  if (permissions) {
    usePermissionsStore().apply({ edition: 'single', platform_admin: false, role: 'custom', roles: ['custom'], permissions, projects: {}, token_scoped: false })
  }
  useSettingsStore().update({ regions: [{ id: 'r1', name: 'eu-1', has_kubeconfig: true }] })
  useSettingsStore().loaded = true
  return mount(DataPage, {
    ...plugins,
    global: { ...plugins.global, stubs: { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } } },
  })
}

async function factory(permissions = null) {
  const wrapper = mountPage(permissions)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  confirmMock.mockReset()
  confirmMock.mockResolvedValue(true)
  bindingsApi.list.mockResolvedValue({ data: { data: [ORDERS_DB, OLD_FILES], abilities: { manage: true } } })
  bindingsApi.quota.mockResolvedValue({ data: { data: QUOTA } })
  bindingsApi.types.mockResolvedValue({ data: { data: {}, retention_days: 14 } })
  bindingsApi.create.mockResolvedValue({ data: { data: {} } })
  bindingsApi.destroy.mockResolvedValue({ data: {} })
  bindingsApi.restore.mockResolvedValue({ data: {} })
  bindingsApi.rotate.mockResolvedValue({ data: {} })
  bindingsApi.credentials.mockResolvedValue({ data: { data: { env: { DATABASE_URL: 'postgresql://b_orders_db:pw@host:5432/b_orders_db?sslmode=require' } } } })
  settingsApi.get.mockResolvedValue({ data: { regions: [] } })
})

describe('DataPage', () => {
  it('lists bindings with region, status, projects and usage', async () => {
    const wrapper = await factory()

    const row = wrapper.find('[data-binding-row="orders-db"]')
    expect(row.find('[data-binding-status]').text()).toBe('Ready')
    expect(row.text()).toContain('eu-1')
    expect(row.text()).toContain('Shop')
    expect(row.find('a').attributes('href')).toBe('/projects/p1#bindings')
    expect(row.find('[data-usage]').text()).toBe('512 MiB / 1.0 GiB')
  })

  it('shows the organization limits', async () => {
    const wrapper = await factory()
    expect(wrapper.find('[data-quota-key="max_bindings"]').text()).toContain('2')
    expect(wrapper.find('[data-quota-key="max_bindings"]').text()).toContain('10')
    expect(wrapper.find('[data-quota-key="dedicated_postgres"]').text()).toContain('unlimited')
  })

  it('shows the purge date of a deleted binding and restores it', async () => {
    const wrapper = await factory()
    const row = wrapper.find('[data-binding-row="old-files"]')

    expect(row.find('[data-binding-status]').text()).toBe('Deleting')
    expect(row.find('[data-purge-date]').text()).toContain('2026')
    expect(row.text()).toContain('Shared key')

    await row.find('[data-action="restore"]').trigger('click')
    await flushPromises()
    expect(bindingsApi.restore).toHaveBeenCalledWith('b-files')
  })

  it('deletes only after the binding name is typed, naming the retention', async () => {
    const wrapper = await factory()
    await wrapper.find('[data-binding-row="orders-db"] [data-action="delete"]').trigger('click')

    const dialog = wrapper.find('[data-delete-dialog]')
    expect(dialog.text()).toContain('14 days')
    const confirm = dialog.find('[data-action="confirm-delete"]')
    expect(confirm.attributes('disabled')).toBeDefined()

    await dialog.find('[data-field="confirm_name"]').setValue('orders')
    expect(confirm.attributes('disabled')).toBeDefined()
    await dialog.find('[data-field="confirm_name"]').setValue('orders-db')
    expect(confirm.attributes('disabled')).toBeUndefined()

    await confirm.trigger('click')
    await flushPromises()
    expect(bindingsApi.destroy).toHaveBeenCalledWith('b-orders', 'orders-db')
    expect(wrapper.find('[data-delete-dialog]').exists()).toBe(false)
  })

  it('creates a dedicated Postgres binding in a region', async () => {
    const wrapper = await factory()
    await wrapper.find('[data-data-create]').trigger('click')

    await wrapper.find('[data-field="name"]').setValue('ledger')
    await wrapper.find('[data-field="type"]').setValue('postgres')
    await wrapper.find('[data-field="plan"]').setValue('dedicated')
    await wrapper.find('[data-field="instances"]').setValue('2')
    await wrapper.find('[data-data-form]').trigger('submit')
    await flushPromises()

    expect(bindingsApi.create).toHaveBeenCalledWith(expect.objectContaining({
      name: 'ledger', type: 'postgres', plan: 'dedicated', knative_region_id: 'r1',
      settings: expect.objectContaining({ instances: 2, storage_gb: 5, backups: false }),
    }))
  })

  it('creates an external binding without a region', async () => {
    const wrapper = await factory()
    await wrapper.find('[data-data-create]').trigger('click')

    await wrapper.find('[data-field="name"]').setValue('payments')
    await wrapper.find('[data-field="type"]').setValue('external')
    expect(wrapper.find('[data-field="region"]').exists()).toBe(false)
    await wrapper.find('[data-field="external_key"]').setValue('api_url')
    await wrapper.find('[data-field="external_value"]').setValue('https://pay.example.com')
    await wrapper.find('[data-data-form]').trigger('submit')
    await flushPromises()

    expect(bindingsApi.create).toHaveBeenCalledWith({ name: 'payments', type: 'external', values: { API_URL: 'https://pay.example.com' } })
  })

  it('rotates after confirmation and reveals credentials', async () => {
    const wrapper = await factory()
    const row = wrapper.find('[data-binding-row="orders-db"]')

    await row.find('[data-action="rotate"]').trigger('click')
    await flushPromises()
    expect(bindingsApi.rotate).toHaveBeenCalledWith('b-orders')

    await row.find('[data-action="reveal"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-binding-row="orders-db"] [data-credentials]').text()).toContain('postgresql://b_orders_db')
  })

  it('hides changes and credentials without the permissions', async () => {
    const wrapper = await factory(['bindings.view'])

    expect(wrapper.find('[data-data-create]').exists()).toBe(false)
    expect(wrapper.find('[data-action="delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-action="restore"]').exists()).toBe(false)
    expect(wrapper.find('[data-action="reveal"]').exists()).toBe(false)
    expect(wrapper.find('[data-binding-row="orders-db"]').exists()).toBe(true)
  })

  it('filters by type', async () => {
    const wrapper = await factory()
    await wrapper.find('[data-field="filter"]').setValue('object')
    expect(wrapper.find('[data-binding-row="orders-db"]').exists()).toBe(false)
    expect(wrapper.find('[data-binding-row="old-files"]').exists()).toBe(true)
  })
})
