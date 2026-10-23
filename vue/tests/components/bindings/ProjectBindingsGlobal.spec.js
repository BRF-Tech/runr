import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ProjectBindings from '@/components/project/ProjectBindings.vue'
import { bindingsApi } from '@/api/bindings'
import { withPlugins } from '../../helpers'

// Project → Bindings of a global project (docs/global-projects.md, "Data"): each attachment says
// how the copies in the other regions reach the binding — a replica per region, the data link,
// or directly — from the project's global summary.

vi.mock('@/api/bindings', () => ({
  bindingsApi: {
    projectBindings: vi.fn(), types: vi.fn(), attach: vi.fn(), updateAttachment: vi.fn(), detach: vi.fn(),
    rotate: vi.fn(), credentials: vi.fn(),
  },
}))
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: vi.fn(() => Promise.resolve(true)) }) }))

const PG_KEYS = ['DATABASE_URL', 'PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD', 'PGSSLMODE']
const ORDERS_DB = {
  id: 'b-orders', name: 'orders-db', type: 'postgres', plan: 'dedicated', status: 'ready', region: { id: 'r-eu', name: 'eu-1' },
  env_keys: PG_KEYS, settings: { storage_gb: 5 }, usage_bytes: 0, details: {},
}
const CACHE = {
  id: 'b-cache', name: 'cache', type: 'kv', plan: 'shared', status: 'ready', region: { id: 'r-eu', name: 'eu-1' },
  env_keys: ['REDIS_URL', 'KV_PREFIX'], settings: { memory_mb: 64 }, usage_bytes: 0, details: {},
}

const attach = (id, binding) => ({ id, binding_id: binding.id, project_id: 'p-shop', env_prefix: '', env_names: binding.env_keys, binding })

beforeEach(() => {
  vi.clearAllMocks()
  bindingsApi.projectBindings.mockResolvedValue({
    data: { data: [attach('a1', ORDERS_DB), attach('a2', CACHE)], available: [], secret: 'shop-bindings', abilities: { manage: true } },
  })
  bindingsApi.types.mockResolvedValue({ data: { data: {}, retention_days: 7 } })
})

async function factory(project) {
  const wrapper = mount(ProjectBindings, { props: { project }, ...withPlugins() })
  await flushPromises()
  return wrapper
}

describe('ProjectBindings — global project', () => {
  it('names how each binding is reached in the other regions', async () => {
    const w = await factory({
      id: 'p-shop', name: 'shop', slug: 'shop', service_type: 'caas', knative_region_id: 'r-eu', placement: 'global',
      global: { bindings: [{ binding_id: 'b-orders', access: 'replicas' }, { binding_id: 'b-cache', access: 'link' }] },
    })
    expect(w.find('[data-attachment="orders-db"] [data-global-access]').text()).toBe('Replica per region')
    expect(w.find('[data-attachment="orders-db"] [data-global-access]').attributes('title')).toContain('DATABASE_URL_RO')
    expect(w.find('[data-attachment="cache"] [data-global-access]').text()).toBe('Data link')
  })

  it('shows nothing of the kind for a regional project', async () => {
    const w = await factory({ id: 'p-shop', name: 'shop', slug: 'shop', service_type: 'caas', knative_region_id: 'r-eu' })
    expect(w.find('[data-global-access]').exists()).toBe(false)
  })
})
