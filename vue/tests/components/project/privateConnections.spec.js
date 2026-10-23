import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { projectsApi } from '@/api/projects'
import { usePermissionsStore } from '@/stores/permissions'
import PrivateConnections from '@/components/project/PrivateConnections.vue'
import { withPlugins } from '../../helpers'

// Private database connections follow the environment switcher (docs/environments.md): the list
// and a new connection are those of the selected environment; production's are its own.

vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))

const ok = (data) => Promise.resolve({ data })
const connection = (id, environment = null) => ({
  id, name: 'orders-db', host: `orders-db-${id}`, port: 5432, routing_key: 'pg', sslmode: 'require', env_prefix: '', environment,
})

function mountWith(environment) {
  const plugins = withPlugins()
  usePermissionsStore().apply({ edition: 'single', platform_admin: false, role: 'custom', roles: ['custom'], permissions: ['connections.manage', 'projects.view'], projects: {}, token_scoped: false })
  return mount(PrivateConnections, { props: { project: { id: 'p1' }, environment }, global: plugins.global })
}

afterEach(() => vi.restoreAllMocks())

describe('PrivateConnections', () => {
  it('lists the selected environment\'s connections and follows the switcher', async () => {
    const list = vi.spyOn(projectsApi, 'privateConnections').mockImplementation((id, env) => ok({ data: [connection(env ?? 'prod', env)] }))
    vi.spyOn(projectsApi, 'privateConnectionStatus').mockReturnValue(ok({ status: { state: 'ready' } }))

    const w = mountWith('staging')
    await flushPromises()

    expect(list).toHaveBeenLastCalledWith('p1', 'staging')
    expect(w.find('[data-testid="private-connections-environment"]').text()).toContain('staging')
    expect(w.text()).toContain('orders-db-staging')

    await w.setProps({ environment: null })
    await flushPromises()

    expect(list).toHaveBeenLastCalledWith('p1', null)
    expect(w.find('[data-testid="private-connections-environment"]').exists()).toBe(false)
    expect(w.text()).toContain('orders-db-prod')
    expect(w.text()).not.toContain('orders-db-staging')
  })

  it('creates the connection in the selected environment', async () => {
    vi.spyOn(projectsApi, 'privateConnections').mockReturnValue(ok({ data: [] }))
    const create = vi.spyOn(projectsApi, 'createPrivateConnection').mockReturnValue(ok({ data: connection('x', 'staging') }))

    const w = mountWith('staging')
    await flushPromises()
    await w.find('button.btn-primary').trigger('click')
    const form = w.find('form')
    const inputs = form.findAll('input')
    await inputs[0].setValue('orders-db')
    await inputs[1].setValue('pg-orders-rw')
    await form.find('textarea').setValue('apiVersion: skupper.io/v2alpha1\nkind: AccessToken')
    await form.trigger('submit')
    await flushPromises()

    expect(create).toHaveBeenCalledWith('p1', expect.objectContaining({ name: 'orders-db', routing_key: 'pg-orders-rw', environment: 'staging' }))
  })

  it('creates a production connection without an environment', async () => {
    vi.spyOn(projectsApi, 'privateConnections').mockReturnValue(ok({ data: [] }))
    const create = vi.spyOn(projectsApi, 'createPrivateConnection').mockReturnValue(ok({ data: connection('x') }))

    const w = mountWith(null)
    await flushPromises()
    await w.find('button.btn-primary').trigger('click')
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(create.mock.calls[0][1]).not.toHaveProperty('environment')
  })
})
