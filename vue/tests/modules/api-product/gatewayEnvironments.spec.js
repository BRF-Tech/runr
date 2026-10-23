import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/modules/api-product/locales/en.js'
import tr from '@/modules/api-product/locales/tr.js'
import GatewayRoutes from '@/modules/api-product/components/GatewayRoutes.vue'
import GatewaysSettings from '@/modules/api-product/components/GatewaysSettings.vue'
import { apiProductApi } from '@/modules/api-product/api/apiProduct'
import { projectsApi } from '@/api/projects'
import { environmentsApi } from '@/api/environments'
import { useConfirm } from '@/composables/useConfirm'
import { useSettingsStore } from '@/stores/settings'

// Gateway routes to an environment (docs/environments.md#gateway-routes): the route form offers
// the project's environments that run in the gateway's region, a route shows its environment, a
// gateway of one's own is added for another region.

vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/modules/api-product/api/apiProduct', () => ({
  apiProductApi: {
    gateways: vi.fn(), gateway: vi.fn(), createGateway: vi.fn(), addRoute: vi.fn(), removeRoute: vi.fn(), updateRoute: vi.fn(),
  },
}))

const ok = (data) => Promise.resolve({ data })

function i18n() {
  return createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', missingWarn: false, fallbackWarn: false, messages: { en: { ...en, common: { cancel: 'Cancel' } }, tr } })
}

const gateway = {
  id: 'gw1',
  knative_region_id: 'r1',
  routes: [
    { id: 'r-prod', path: '/shop', project: { name: 'shop' }, environment: null, url: 'https://jane.eu.test/shop', auth_mode: 'public', gateway_only: false },
    { id: 'r-staging', path: '/staging', project: { name: 'shop' }, environment: 'staging', url: 'https://jane.eu.test/staging', auth_mode: 'public', gateway_only: false },
  ],
}

beforeEach(() => setActivePinia(createPinia()))
afterEach(() => vi.restoreAllMocks())

describe('GatewayRoutes with environments', () => {
  it('shows the environment a route leads to', () => {
    const w = mount(GatewayRoutes, { props: { gateway }, global: { plugins: [i18n()] } })

    const badges = w.findAll('[data-test="route-environment"]')
    expect(badges.map((b) => b.text())).toEqual(['staging'])
  })

  it('adds a route to an environment that runs in the gateway\'s region', async () => {
    vi.spyOn(projectsApi, 'list').mockReturnValue(ok({ data: [{ id: 'p1', name: 'shop' }] }))
    vi.spyOn(environmentsApi, 'list').mockReturnValue(ok({ data: [
      { id: 'e-dev', name: 'dev', is_primary: false, knative_region_id: 'r2' },
      { id: 'e-staging', name: 'staging', is_primary: false, knative_region_id: 'r1' },
      { id: 'e-prod', name: 'production', is_primary: true, knative_region_id: 'r1' },
    ] }))
    vi.mocked(apiProductApi.addRoute).mockReturnValue(ok({ data: { id: 'new' } }))
    vi.mocked(apiProductApi.gateway).mockReturnValue(ok({ data: gateway }))
    const w = mount(GatewayRoutes, { props: { gateway: { ...gateway, routes: [] } }, global: { plugins: [i18n()] } })

    await w.get('[data-test="route-add-open"]').trigger('click')
    await flushPromises()
    await w.get('[data-test="route-add-project"]').setValue('p1')
    await flushPromises()

    // dev runs in another region: not offered
    const options = w.get('[data-test="route-add-environment"]').findAll('option').map((o) => o.text())
    expect(options).toEqual(['staging', 'production'])

    await w.get('[data-test="route-add-environment"]').setValue('staging')
    await w.get('[data-test="route-add-path"]').setValue('/staging')
    await w.get('[data-test="route-add"]').trigger('submit')
    await flushPromises()

    expect(apiProductApi.addRoute).toHaveBeenCalledWith('gw1', { project_id: 'p1', path: '/staging', environment: 'staging' })
    expect(w.emitted('updated')[0][0].id).toBe('gw1')

    // production: no environment in the request
    await w.get('[data-test="route-add-open"]').trigger('click')
    await w.get('[data-test="route-add-project"]').setValue('p1')
    await flushPromises()
    await w.get('[data-test="route-add-environment"]').setValue('production')
    await w.get('[data-test="route-add-path"]').setValue('/shop')
    await w.get('[data-test="route-add"]').trigger('submit')
    await flushPromises()
    expect(apiProductApi.addRoute).toHaveBeenLastCalledWith('gw1', { project_id: 'p1', path: '/shop' })
  })

  it('says so when no environment of the project runs in the region', async () => {
    vi.spyOn(projectsApi, 'list').mockReturnValue(ok({ data: [{ id: 'p1', name: 'shop' }] }))
    vi.spyOn(environmentsApi, 'list').mockReturnValue(ok({ data: [{ id: 'e-prod', name: 'production', is_primary: true, knative_region_id: 'r9' }] }))
    const w = mount(GatewayRoutes, { props: { gateway }, global: { plugins: [i18n()] } })

    await w.get('[data-test="route-add-open"]').trigger('click')
    await flushPromises()
    await w.get('[data-test="route-add-project"]').setValue('p1')
    await flushPromises()

    expect(w.find('[data-test="route-add-no-environment"]').exists()).toBe(true)
    expect(w.get('[data-test="route-add-save"]').attributes('disabled')).toBeDefined()
  })

  it('removes a route after the confirmation', async () => {
    vi.mocked(apiProductApi.removeRoute).mockReturnValue(ok(null))
    vi.mocked(apiProductApi.gateway).mockReturnValue(ok({ data: { ...gateway, routes: [gateway.routes[0]] } }))
    const w = mount(GatewayRoutes, { props: { gateway }, global: { plugins: [i18n()] } })

    await w.findAll('[data-test="route-remove"]')[1].trigger('click')
    useConfirm().accept()
    await flushPromises()

    expect(apiProductApi.removeRoute).toHaveBeenCalledWith('gw1', 'r-staging')
    expect(w.emitted('updated')[0][0].routes).toHaveLength(1)
  })
})

// A key or JWT route is created with its authentication in one call (docs/api-product.md#routes):
// it is never public, not even until a second request sets it.
describe('GatewayRoutes: a new route with its authentication', () => {
  async function openForm() {
    vi.mocked(apiProductApi.addRoute).mockReset()
    vi.mocked(apiProductApi.updateRoute).mockReset()
    vi.spyOn(projectsApi, 'list').mockReturnValue(ok({ data: [{ id: 'p1', name: 'shop' }] }))
    vi.spyOn(environmentsApi, 'list').mockReturnValue(ok({ data: [{ id: 'e-prod', name: 'production', is_primary: true, knative_region_id: 'r1' }] }))
    vi.mocked(apiProductApi.addRoute).mockReturnValue(ok({ data: { id: 'new' } }))
    vi.mocked(apiProductApi.gateway).mockReturnValue(ok({ data: gateway }))
    const w = mount(GatewayRoutes, { props: { gateway: { ...gateway, routes: [] } }, global: { plugins: [i18n()] } })
    await w.get('[data-test="route-add-open"]').trigger('click')
    await flushPromises()
    await w.get('[data-test="route-add-project"]').setValue('p1')
    await flushPromises()
    await w.get('[data-test="route-add-path"]').setValue('/orders')
    return w
  }

  it('sends a key route\'s authentication and gateway-only with the route', async () => {
    const w = await openForm()
    await w.get('[data-test="route-add-auth"]').setValue('key')
    await w.get('[data-test="route-add-gateway-only"]').setValue(true)
    expect(w.get('[data-test="route-add-auth-hint"]').text()).toContain('never public')

    await w.get('[data-test="route-add"]').trigger('submit')
    await flushPromises()

    expect(apiProductApi.addRoute).toHaveBeenCalledTimes(1)
    expect(apiProductApi.addRoute).toHaveBeenCalledWith('gw1', { project_id: 'p1', path: '/orders', auth_mode: 'key', gateway_only: true })
    expect(apiProductApi.updateRoute).not.toHaveBeenCalled()
  })

  it('sends a JWT route\'s settings with the route and shows a refusal by its field', async () => {
    const w = await openForm()
    await w.get('[data-test="route-add-auth"]').setValue('jwt')
    await w.get('[data-test="route-add-jwks"]').setValue('https://id.example.com/jwks')

    await w.get('[data-test="route-add"]').trigger('submit')
    await flushPromises()

    expect(apiProductApi.addRoute).toHaveBeenCalledWith('gw1', {
      project_id: 'p1', path: '/orders', auth_mode: 'jwt',
      jwt: { jwks_url: 'https://id.example.com/jwks', issuer: null, audience: null, subject_claim: 'sub', require_consumer: false },
    })
    expect(apiProductApi.updateRoute).not.toHaveBeenCalled()

    // the API refuses: the form stays, the error by its field
    vi.mocked(apiProductApi.addRoute).mockImplementationOnce(() => Promise.reject({ response: { data: { message: 'Invalid', errors: { path: ['The gateway already has a route on /orders.'] } } } }))
    await w.get('[data-test="route-add-open"]').trigger('click')
    await flushPromises()
    await w.get('[data-test="route-add-project"]').setValue('p1')
    await flushPromises()
    await w.get('[data-test="route-add-path"]').setValue('/orders')
    await w.get('[data-test="route-add"]').trigger('submit')
    await flushPromises()
    expect(w.get('[data-test="route-add-path-error"]').text()).toContain('already has a route')
  })
})

describe('GatewaysSettings', () => {
  it('adds the caller\'s gateway in a region that has none', async () => {
    vi.mocked(apiProductApi.gateways)
      .mockReturnValueOnce(ok({ data: [] }))
      .mockReturnValue(ok({ data: [{ id: 'gw2', knative_region_id: 'r2', region: { id: 'r2', name: 'us' }, host: 'jane.us.test', status: 'active', routes: [] }] }))
    vi.mocked(apiProductApi.createGateway).mockReturnValue(ok({ data: { id: 'gw2', region: { id: 'r2', name: 'us' } } }))
    const settings = useSettingsStore()
    settings.data.regions = [{ id: 'r2', name: 'us' }]
    settings.loaded = true

    const w = mount(GatewaysSettings, { global: { plugins: [i18n()], stubs: { GatewayRoutes: true, ApiConsumers: true, ApiPlans: true, ApiUsage: true, GatewayDomain: true } } })
    await flushPromises()

    await w.get('[data-test="api-gateway-new-region"]').setValue('r2')
    await w.get('[data-test="api-gateway-new"]').trigger('submit')
    await flushPromises()

    expect(apiProductApi.createGateway).toHaveBeenCalledWith('r2')
    expect(w.find('[data-gateway="gw2"]').exists()).toBe(true)
    // its region has a gateway now: nothing left to add
    expect(w.find('[data-test="api-gateway-new"]').exists()).toBe(false)
  })
})
