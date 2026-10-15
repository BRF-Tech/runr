import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ProjectBindings from '@/components/project/ProjectBindings.vue'
import { bindingsApi } from '@/api/bindings'
import { usePermissionsStore } from '@/stores/permissions'
import { withPlugins } from '../../helpers'

// Project → Bindings tab (docs/bindings.md): attachments, create + attach with an env prefix and
// live env-name preview, attach existing, prefix change, rotate, detach, credentials reveal,
// what users without bindings.manage / env.edit / bindings.view_credentials see.

vi.mock('@/api/bindings', () => ({
  bindingsApi: {
    projectBindings: vi.fn(), types: vi.fn(), attach: vi.fn(), updateAttachment: vi.fn(), detach: vi.fn(),
    rotate: vi.fn(), credentials: vi.fn(),
  },
}))

const confirmMock = vi.fn()
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: confirmMock }) }))

const PROJECT = { id: 'p1', name: 'Hello', slug: 'hello', service_type: 'xaas', knative_region_id: 'r1' }

const CACHE = {
  id: 'b-cache', name: 'cache', type: 'kv', plan: 'shared', status: 'ready', region: { id: 'r1', name: 'eu-1' },
  env_keys: ['REDIS_URL', 'KV_PREFIX'], settings: { memory_mb: 64 }, usage_bytes: 2048,
  details: { mode: 'acl', prefix: 'cache-abcd1234:' },
}
const ORDERS_DB = {
  id: 'b-orders', name: 'orders-db', type: 'postgres', plan: 'shared', status: 'ready', region: { id: 'r1', name: 'eu-1' },
  env_keys: ['DATABASE_URL', 'PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD', 'PGSSLMODE'],
  settings: { storage_gb: 1 }, usage_bytes: 0, details: { cluster: 'runr-pg-shared', database: 'b_orders_db' },
}
const ATTACHMENT = {
  id: 'a1', binding_id: 'b-cache', project_id: 'p1', env_prefix: 'CACHE_',
  env_names: ['CACHE_REDIS_URL', 'CACHE_KV_PREFIX'], binding: CACHE,
}

function respond({ attachments = [ATTACHMENT], available = [ORDERS_DB] } = {}) {
  bindingsApi.projectBindings.mockResolvedValue({ data: { data: attachments, available, secret: 'hello-bindings', abilities: { manage: true } } })
}

// permissions: null = not loaded (the UI shows everything, the API decides)
function mountTab(permissions = null, projects = {}) {
  const plugins = withPlugins()
  if (permissions) {
    usePermissionsStore().apply({ edition: 'single', platform_admin: false, role: 'custom', roles: ['custom'], permissions, projects, token_scoped: false })
  }
  return mount(ProjectBindings, { props: { project: PROJECT }, ...plugins })
}

async function factory(permissions = null, projects = {}) {
  const wrapper = mountTab(permissions, projects)
  await flushPromises()
  return wrapper
}

// Resolves pending promise chains without timers (fake-timer tests)
async function settle() {
  for (let i = 0; i < 20; i++) await Promise.resolve()
}

beforeEach(() => {
  confirmMock.mockReset()
  confirmMock.mockResolvedValue(true)
  respond()
  bindingsApi.types.mockResolvedValue({ data: { data: { kv: { defaults: { memory_mb: 64 }, max: { memory_mb: 4096 } } }, retention_days: 7 } })
  bindingsApi.attach.mockResolvedValue({ data: { data: {} } })
  bindingsApi.updateAttachment.mockResolvedValue({ data: { data: {} } })
  bindingsApi.detach.mockResolvedValue({ data: {} })
  bindingsApi.rotate.mockResolvedValue({ data: {} })
  bindingsApi.credentials.mockResolvedValue({ data: { data: { env: { REDIS_URL: 'redis://cache:pw@runr-kv:6379', KV_PREFIX: 'cache-abcd1234:' } } } })
})

describe('ProjectBindings', () => {
  it('lists the attachments with status, env names and the Secret name', async () => {
    const wrapper = await factory()

    expect(bindingsApi.projectBindings).toHaveBeenCalledWith('p1')
    const row = wrapper.find('[data-attachment="cache"]')
    expect(row.exists()).toBe(true)
    expect(row.find('[data-binding-status]').text()).toBe('Ready')
    expect(row.text()).toContain('CACHE_REDIS_URL')
    expect(row.text()).toContain('cache-abcd1234:')
    expect(wrapper.text()).toContain('hello-bindings')
  })

  it('previews prefixed env names while creating a KV binding and sends it with the attach request', async () => {
    respond({ available: [] })
    const wrapper = await factory()

    await wrapper.find('[data-bindings-open]').trigger('click')
    expect(wrapper.find('[data-mode="new"]').exists()).toBe(true)
    await wrapper.find('[data-field="name"]').setValue('orders-cache')
    await wrapper.find('[data-field="env_prefix"]').setValue('orders_')

    expect(wrapper.find('[data-field="env_prefix"]').element.value).toBe('ORDERS_')
    const preview = wrapper.findAll('[data-env-preview] code').map(c => c.text())
    expect(preview).toEqual(['ORDERS_REDIS_URL', 'ORDERS_KV_PREFIX'])

    await wrapper.find('[data-bindings-form]').trigger('submit')
    await flushPromises()

    expect(bindingsApi.attach).toHaveBeenCalledWith('p1', {
      env_prefix: 'ORDERS_',
      binding: { name: 'orders-cache', type: 'kv', settings: { memory_mb: 64 } },
    })
    expect(wrapper.find('[data-bindings-form]').exists()).toBe(false)
  })

  it('attaches an existing binding of the region', async () => {
    const wrapper = await factory()
    await wrapper.find('[data-bindings-open]').trigger('click')

    await wrapper.find('[data-field="binding_id"]').setValue('b-orders')
    await wrapper.find('[data-field="env_prefix"]').setValue('ORDERS_')
    expect(wrapper.findAll('[data-env-preview] code').map(c => c.text())).toContain('ORDERS_DATABASE_URL')

    await wrapper.find('[data-bindings-form]').trigger('submit')
    await flushPromises()
    expect(bindingsApi.attach).toHaveBeenCalledWith('p1', { env_prefix: 'ORDERS_', binding_id: 'b-orders' })
  })

  it('blocks names another attachment already sets', async () => {
    respond({ attachments: [{ ...ATTACHMENT, env_prefix: '', env_names: ['REDIS_URL', 'KV_PREFIX'] }], available: [] })
    const wrapper = await factory()
    await wrapper.find('[data-bindings-open]').trigger('click')
    await wrapper.find('[data-field="name"]').setValue('second-cache')

    expect(wrapper.find('[data-env-conflict]').text()).toContain('REDIS_URL')
    expect(wrapper.find('[data-bindings-submit]').attributes('disabled')).toBeDefined()

    await wrapper.find('[data-field="env_prefix"]').setValue('SECOND_')
    expect(wrapper.find('[data-env-conflict]').exists()).toBe(false)
    expect(wrapper.find('[data-bindings-submit]').attributes('disabled')).toBeUndefined()
  })

  it('shows the API validation error of a failed attach', async () => {
    respond({ available: [] })
    bindingsApi.attach.mockRejectedValue({ response: { status: 422, data: { message: 'Binding limit reached', errors: { binding: ['Binding limit reached'] } } } })
    const wrapper = await factory()
    await wrapper.find('[data-bindings-open]').trigger('click')
    await wrapper.find('[data-field="name"]').setValue('big-db')
    await wrapper.find('[data-bindings-form]').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-bindings-form]').text()).toContain('Binding limit reached')
  })

  it('changes the prefix of an attachment', async () => {
    const wrapper = await factory()
    await wrapper.find('[data-action="edit-prefix"]').trigger('click')
    await wrapper.find('[data-field="edit_prefix"]').setValue('kv_')
    await wrapper.find('[data-action="save-prefix"]').trigger('click')
    await flushPromises()

    expect(bindingsApi.updateAttachment).toHaveBeenCalledWith('p1', 'a1', { env_prefix: 'KV_' })
  })

  it('rotates and detaches after confirmation', async () => {
    const wrapper = await factory()

    await wrapper.find('[data-action="rotate"]').trigger('click')
    await flushPromises()
    expect(bindingsApi.rotate).toHaveBeenCalledWith('b-cache')

    await wrapper.find('[data-action="detach"]').trigger('click')
    await flushPromises()
    expect(confirmMock).toHaveBeenLastCalledWith(expect.objectContaining({ destructive: true }))
    expect(bindingsApi.detach).toHaveBeenCalledWith('p1', 'a1')
  })

  it('does nothing when the confirmation is cancelled', async () => {
    confirmMock.mockResolvedValue(false)
    const wrapper = await factory()
    await wrapper.find('[data-action="detach"]').trigger('click')
    await flushPromises()
    expect(bindingsApi.detach).not.toHaveBeenCalled()
  })

  it('reveals the credentials with the attachment prefix', async () => {
    const wrapper = await factory()
    await wrapper.find('[data-action="reveal"]').trigger('click')
    await flushPromises()

    expect(bindingsApi.credentials).toHaveBeenCalledWith('b-cache')
    const creds = wrapper.find('[data-credentials]').text()
    expect(creds).toContain('CACHE_REDIS_URL')
    expect(creds).toContain('redis://cache:pw@runr-kv:6379')
  })

  it('hides changes and the reveal without the permissions', async () => {
    const wrapper = await factory(['projects.view', 'bindings.view'])

    expect(wrapper.find('[data-bindings-open]').exists()).toBe(false)
    expect(wrapper.find('[data-action="detach"]').exists()).toBe(false)
    expect(wrapper.find('[data-action="rotate"]').exists()).toBe(false)
    expect(wrapper.find('[data-action="reveal"]').exists()).toBe(false)
    expect(wrapper.find('[data-attachment="cache"]').exists()).toBe(true)
  })

  it('needs env.edit on the project to attach', async () => {
    const viewer = await factory(['projects.view', 'bindings.view', 'bindings.manage'], { p1: ['projects.view'] })
    expect(viewer.find('[data-bindings-open]').exists()).toBe(false)

    const editor = await factory(['projects.view', 'bindings.view', 'bindings.manage'], { p1: ['env.edit'] })
    expect(editor.find('[data-bindings-open]').exists()).toBe(true)
  })

  it('polls while a binding is being provisioned', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    try {
      respond({ attachments: [{ ...ATTACHMENT, binding: { ...CACHE, status: 'provisioning' } }] })
      mountTab()
      await settle()
      expect(bindingsApi.projectBindings).toHaveBeenCalledTimes(1)

      respond()
      await vi.advanceTimersByTimeAsync(3100)
      await settle()
      expect(bindingsApi.projectBindings).toHaveBeenCalledTimes(2)
    } finally {
      vi.useRealTimers()
    }
  })
})
