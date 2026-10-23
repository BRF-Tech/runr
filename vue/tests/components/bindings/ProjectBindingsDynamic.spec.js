import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ProjectBindings from '@/components/project/ProjectBindings.vue'
import { bindingsApi } from '@/api/bindings'
import { withPlugins } from '../../helpers'

// Project → Bindings: dynamic Postgres credentials from the region's OpenBao (docs/bindings.md) —
// offered when attaching a ready Postgres binding, a badge on dynamic attachments, the switch
// static ⇄ dynamic on an existing one, and the server's refusal shown as it is.

vi.mock('@/api/bindings', () => ({
  bindingsApi: {
    projectBindings: vi.fn(), types: vi.fn(), attach: vi.fn(), updateAttachment: vi.fn(), detach: vi.fn(),
    rotate: vi.fn(), credentials: vi.fn(),
  },
}))

const confirmMock = vi.fn()
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: confirmMock }) }))

const toast = { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }
vi.mock('@/composables/useToast', () => ({ useToast: () => toast }))

const PROJECT = { id: 'p1', name: 'Hello', slug: 'hello', service_type: 'xaas', knative_region_id: 'r1' }

const PG_KEYS = ['DATABASE_URL', 'PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD', 'PGSSLMODE']
const ORDERS_DB = {
  id: 'b-orders', name: 'orders-db', type: 'postgres', plan: 'shared', status: 'ready', region: { id: 'r1', name: 'eu-1' },
  env_keys: PG_KEYS, settings: { storage_gb: 1 }, usage_bytes: 0, details: { cluster: 'runr-pg-shared', database: 'b_orders_db' },
}
const NEW_DB = { ...ORDERS_DB, id: 'b-new', name: 'new-db', status: 'provisioning' }
const CACHE = {
  id: 'b-cache', name: 'cache', type: 'kv', plan: 'shared', status: 'ready', region: { id: 'r1', name: 'eu-1' },
  env_keys: ['REDIS_URL', 'KV_PREFIX'], settings: { memory_mb: 64 }, usage_bytes: 0, details: {},
}

function attachment(mode) {
  return {
    id: 'a1', binding_id: 'b-orders', project_id: 'p1', env_prefix: 'ORDERS_', credential_mode: mode,
    env_names: PG_KEYS.map(k => `ORDERS_${k}`), binding: ORDERS_DB,
  }
}

function respond({ attachments = [], available = [ORDERS_DB, NEW_DB, CACHE] } = {}) {
  bindingsApi.projectBindings.mockResolvedValue({ data: { data: attachments, available, secret: 'hello-bindings', abilities: { manage: true } } })
}

async function factory() {
  const wrapper = mount(ProjectBindings, { props: { project: PROJECT }, ...withPlugins() })
  await flushPromises()
  return wrapper
}

async function chooseExisting(wrapper, id) {
  await wrapper.find('[data-bindings-open]').trigger('click')
  await wrapper.find('[data-field="binding_id"]').setValue(id)
}

beforeEach(() => {
  vi.clearAllMocks()
  confirmMock.mockResolvedValue(true)
  respond()
  bindingsApi.types.mockResolvedValue({ data: { data: {}, retention_days: 7 } })
  bindingsApi.attach.mockResolvedValue({ data: { data: {} } })
  bindingsApi.updateAttachment.mockResolvedValue({ data: { data: {} } })
})

describe('ProjectBindings — dynamic credentials', () => {
  it('attaches a ready Postgres binding with dynamic credentials', async () => {
    const wrapper = await factory()
    await chooseExisting(wrapper, 'b-orders')

    const option = wrapper.find('[data-dynamic-option]')
    expect(option.exists()).toBe(true)
    expect(option.text()).toContain('Dynamic credentials (OpenBao)')
    await wrapper.find('[data-field="dynamic"]').setValue(true)
    await wrapper.find('[data-field="env_prefix"]').setValue('ORDERS_')
    await wrapper.find('[data-bindings-form]').trigger('submit')
    await flushPromises()

    expect(bindingsApi.attach).toHaveBeenCalledWith('p1', { env_prefix: 'ORDERS_', binding_id: 'b-orders', credentials: 'dynamic' })
  })

  it('sends no credentials field without the option', async () => {
    const wrapper = await factory()
    await chooseExisting(wrapper, 'b-orders')
    await wrapper.find('[data-bindings-form]').trigger('submit')
    await flushPromises()

    expect(bindingsApi.attach).toHaveBeenCalledWith('p1', { env_prefix: null, binding_id: 'b-orders' })
  })

  it('offers it for Postgres only, and only once the binding is ready', async () => {
    const wrapper = await factory()
    await chooseExisting(wrapper, 'b-cache')
    expect(wrapper.find('[data-dynamic-option]').exists()).toBe(false)

    await wrapper.find('[data-field="binding_id"]').setValue('b-new')
    expect(wrapper.find('[data-field="dynamic"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-dynamic-option]').text()).toContain('not ready yet')
  })

  it('explains that a new Postgres binding is switched to dynamic credentials once it is ready', async () => {
    respond({ available: [] })
    const wrapper = await factory()
    await wrapper.find('[data-bindings-open]').trigger('click')
    await wrapper.find('[data-field="type"]').setValue('postgres')

    expect(wrapper.find('[data-dynamic-new-hint]').exists()).toBe(true)
    expect(wrapper.find('[data-dynamic-option]').exists()).toBe(false)
  })

  it('marks a dynamic attachment and switches it back to the binding\'s own user', async () => {
    respond({ attachments: [attachment('dynamic')], available: [] })
    const wrapper = await factory()

    const row = wrapper.find('[data-attachment="orders-db"]')
    expect(row.find('[data-dynamic-badge]').text()).toBe('Dynamic (OpenBao)')
    const toggle = row.find('[data-action="switch-credentials"]')
    expect(toggle.text()).toBe('Use the binding\'s own user')

    await toggle.trigger('click')
    await flushPromises()
    expect(confirmMock).toHaveBeenCalled()
    expect(bindingsApi.updateAttachment).toHaveBeenCalledWith('p1', 'a1', { credentials: 'static' })
    expect(toast.success).toHaveBeenCalled()
  })

  it('shows the server\'s refusal when the region has no OpenBao', async () => {
    respond({ attachments: [attachment('static')], available: [] })
    bindingsApi.updateAttachment.mockRejectedValue({ response: { status: 422, data: {
      message: 'Dynamic credentials come from the OpenBao of region "eu-1": install External Secrets Operator and OpenBao there first.',
    } } })
    const wrapper = await factory()

    expect(wrapper.find('[data-dynamic-badge]').exists()).toBe(false)
    await wrapper.find('[data-action="switch-credentials"]').trigger('click')
    await flushPromises()

    expect(bindingsApi.updateAttachment).toHaveBeenCalledWith('p1', 'a1', { credentials: 'dynamic' })
    expect(toast.error).toHaveBeenCalledWith(expect.stringContaining('install External Secrets Operator and OpenBao'))
  })
})
