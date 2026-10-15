import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import DataServicesTab from '@/components/region/DataServicesTab.vue'
import { bindingsApi } from '@/api/bindings'
import { usePermissionsStore } from '@/stores/permissions'
import { withPlugins } from '../../helpers'

// Region → Data services (docs/bindings.md "Region setup"): status of KV / object storage /
// Postgres, install requests per mode (external S3 credentials, CNPG with backups), the region
// Redis password warning, uninstall after confirmation, polling while installing, read-only
// without regions.data_services.manage.

vi.mock('@/api/bindings', () => ({
  bindingsApi: { dataServices: vi.fn(), installDataService: vi.fn(), uninstallDataService: vi.fn() },
}))

const confirmMock = vi.fn()
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: confirmMock }) }))

function service(name, overrides = {}) {
  return {
    service: name, installed: false, mode: null, status: 'not_installed', status_message: null,
    config: null, bindings_count: 0, updated_at: null, ...overrides,
  }
}

function state(services = {}, regionRedis = { mode: 'managed', status: 'ready', has_password: false }) {
  return {
    data: {
      data: {
        region_id: 'r1', namespace: 'runr-data', region_redis: regionRedis,
        services: { kv: service('kv'), object: service('object'), postgres: service('postgres'), ...services },
      },
      abilities: { manage: true },
    },
  }
}

function mountTab(permissions = null) {
  const plugins = withPlugins()
  if (permissions) {
    usePermissionsStore().apply({ edition: 'single', platform_admin: false, role: 'custom', roles: ['custom'], permissions, projects: {}, token_scoped: false })
  }
  return mount(DataServicesTab, { props: { regionId: 'r1', region: { id: 'r1', name: 'eu-1' } }, ...plugins })
}

async function factory(permissions = null) {
  const wrapper = mountTab(permissions)
  await flushPromises()
  return wrapper
}

const card = (wrapper, name) => wrapper.find(`[data-service="${name}"]`)

beforeEach(() => {
  confirmMock.mockReset()
  confirmMock.mockResolvedValue(true)
  bindingsApi.dataServices.mockResolvedValue(state())
  bindingsApi.installDataService.mockResolvedValue({ data: { data: { status: 'installing' } } })
  bindingsApi.uninstallDataService.mockResolvedValue({ data: { data: { status: 'uninstalling' } } })
})

describe('DataServicesTab', () => {
  it('shows the three services with their status and the data namespace', async () => {
    bindingsApi.dataServices.mockResolvedValue(state({
      kv: service('kv', { installed: true, mode: 'managed', status: 'ready', bindings_count: 3, config: { host: 'runr-kv.runr-data.svc.cluster.local', storage: '2Gi', acl: true } }),
    }))
    const wrapper = await factory()

    expect(bindingsApi.dataServices).toHaveBeenCalledWith('r1', false)
    expect(wrapper.text()).toContain('runr-data')
    expect(card(wrapper, 'kv').find('[data-service-status]').text()).toBe('Ready')
    expect(card(wrapper, 'kv').text()).toContain('3 binding(s)')
    expect(card(wrapper, 'kv').text()).toContain('runr-kv.runr-data.svc.cluster.local')
    expect(card(wrapper, 'object').find('[data-service-status]').text()).toBe('Not installed')
    expect(card(wrapper, 'postgres').find('[data-action="uninstall"]').exists()).toBe(false)
  })

  it('installs the managed KV Redis with its size', async () => {
    const wrapper = await factory()
    await card(wrapper, 'kv').find('[data-field="kv_maxmemory"]').setValue('1024')
    await card(wrapper, 'kv').find('[data-field="kv_storage"]').setValue('5Gi')
    await card(wrapper, 'kv').find('[data-action="install"]').trigger('click')
    await flushPromises()

    expect(bindingsApi.installDataService).toHaveBeenCalledWith('r1', 'kv', { mode: 'managed', maxmemory_mb: 1024, storage: '5Gi' })
  })

  it('warns that KV on the region Redis needs a password', async () => {
    const wrapper = await factory()
    await card(wrapper, 'kv').find('[data-field="kv_mode"]').setValue('region')
    expect(card(wrapper, 'kv').find('[data-kv-password-warning]').exists()).toBe(true)

    bindingsApi.dataServices.mockResolvedValue(state({}, { mode: 'managed', status: 'ready', has_password: true }))
    const secured = await factory()
    await card(secured, 'kv').find('[data-field="kv_mode"]').setValue('region')
    expect(card(secured, 'kv').find('[data-kv-password-warning]').exists()).toBe(false)
  })

  it('configures an external S3 endpoint with its credentials', async () => {
    const wrapper = await factory()
    const object = card(wrapper, 'object')
    await object.find('[data-field="object_mode"]').setValue('external')
    await object.find('[data-field="object_endpoint"]').setValue('https://s3.example.com')
    await object.find('[data-field="object_region"]').setValue('eu-central-1')
    await object.find('[data-field="object_vendor"]').setValue('AWS')
    await object.find('[data-field="object_access_key_id"]').setValue('AKIAEXAMPLE')
    await object.find('[data-field="object_secret_access_key"]').setValue('s3cr3t')
    await object.find('[data-action="install"]').trigger('click')
    await flushPromises()

    expect(bindingsApi.installDataService).toHaveBeenCalledWith('r1', 'object', {
      mode: 'external', provider: 's3', endpoint: 'https://s3.example.com', region: 'eu-central-1',
      path_style: true, vendor: 'AWS', access_key_id: 'AKIAEXAMPLE', secret_access_key: 's3cr3t',
    })
    // Secrets are not kept in the form after the request
    expect(object.find('[data-field="object_secret_access_key"]').element.value).toBe('')
  })

  it('sends the admin endpoint and token of an external Garage', async () => {
    const wrapper = await factory()
    const object = card(wrapper, 'object')
    await object.find('[data-field="object_mode"]').setValue('external')
    await object.find('[data-field="object_provider"]').setValue('garage')
    await object.find('[data-field="object_endpoint"]').setValue('https://garage.example.com')
    await object.find('[data-field="object_admin_endpoint"]').setValue('https://garage-admin.example.com')
    await object.find('[data-field="object_admin_token"]').setValue('tok')
    await object.find('[data-action="install"]').trigger('click')
    await flushPromises()

    expect(bindingsApi.installDataService).toHaveBeenCalledWith('r1', 'object', expect.objectContaining({
      mode: 'external', provider: 'garage', admin_endpoint: 'https://garage-admin.example.com', admin_token: 'tok',
    }))
    expect(bindingsApi.installDataService.mock.calls[0][2]).not.toHaveProperty('access_key_id')
  })

  it('marks an external store whose keys are shared', async () => {
    bindingsApi.dataServices.mockResolvedValue(state({
      object: service('object', { installed: true, mode: 'external', status: 'ready', config: { provider: 's3', endpoint: 'https://s3.example.com', scoped_keys: false } }),
    }))
    const wrapper = await factory()
    expect(card(wrapper, 'object').find('[data-shared-key]').exists()).toBe(true)
  })

  it('offers Postgres backups only with a ready object store', async () => {
    const wrapper = await factory()
    expect(card(wrapper, 'postgres').find('[data-field="pg_backups"]').attributes('disabled')).toBeDefined()

    bindingsApi.dataServices.mockResolvedValue(state({ object: service('object', { installed: true, mode: 'managed', status: 'ready', config: { scoped_keys: true } }) }))
    const ready = await factory()
    const pg = card(ready, 'postgres')
    expect(pg.find('[data-field="pg_backups"]').attributes('disabled')).toBeUndefined()

    await pg.find('[data-field="pg_instances"]').setValue('2')
    await pg.find('[data-field="pg_backups"]').setValue(true)
    await pg.find('[data-action="install"]').trigger('click')
    await flushPromises()
    expect(bindingsApi.installDataService).toHaveBeenCalledWith('r1', 'postgres', { instances: 2, storage: '10Gi', backups: true })
  })

  it('shows the API error of a refused request', async () => {
    bindingsApi.installDataService.mockRejectedValue({ response: { status: 422, data: { message: 'The given data was invalid.', errors: { endpoint: ['The endpoint field is required.'] } } } })
    const wrapper = await factory()
    await card(wrapper, 'object').find('[data-field="object_mode"]').setValue('external')
    await card(wrapper, 'object').find('[data-action="install"]').trigger('click')
    await flushPromises()

    expect(card(wrapper, 'object').find('[data-service-error]').text()).toContain('The endpoint field is required.')
  })

  it('uninstalls after confirmation', async () => {
    bindingsApi.dataServices.mockResolvedValue(state({ postgres: service('postgres', { installed: true, mode: 'managed', status: 'ready', config: { instances: 1 } }) }))
    const wrapper = await factory()
    await card(wrapper, 'postgres').find('[data-action="uninstall"]').trigger('click')
    await flushPromises()

    expect(confirmMock).toHaveBeenCalledWith(expect.objectContaining({ destructive: true }))
    expect(bindingsApi.uninstallDataService).toHaveBeenCalledWith('r1', 'postgres')
  })

  it('runs a live health check', async () => {
    const wrapper = await factory()
    await wrapper.find('[data-action="live"]').trigger('click')
    await flushPromises()
    expect(bindingsApi.dataServices).toHaveBeenLastCalledWith('r1', true)
  })

  it('polls while a service is installing', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    try {
      bindingsApi.dataServices.mockResolvedValue(state({ kv: service('kv', { installed: true, mode: 'managed', status: 'installing' }) }))
      mountTab()
      for (let i = 0; i < 20; i++) await Promise.resolve()
      expect(bindingsApi.dataServices).toHaveBeenCalledTimes(1)

      await vi.advanceTimersByTimeAsync(3100)
      for (let i = 0; i < 20; i++) await Promise.resolve()
      expect(bindingsApi.dataServices).toHaveBeenCalledTimes(2)
    } finally {
      vi.useRealTimers()
    }
  })

  it('is read-only without regions.data_services.manage', async () => {
    const wrapper = await factory(['regions.view', 'bindings.view'])
    expect(wrapper.find('[data-action="install"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('permission to manage region data services')

    bindingsApi.dataServices.mockResolvedValue({ data: { ...state().data, abilities: { manage: false } } })
    const refused = await factory()
    expect(refused.find('[data-action="install"]').exists()).toBe(false)
  })
})
