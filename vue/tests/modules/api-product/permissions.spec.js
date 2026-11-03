import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/modules/api-product/locales/en.js'
import tr from '@/modules/api-product/locales/tr.js'
import GatewaysSettings from '@/modules/api-product/components/GatewaysSettings.vue'
import GatewayRoutes from '@/modules/api-product/components/GatewayRoutes.vue'
import ApiConsumers from '@/modules/api-product/components/ApiConsumers.vue'
import ApiPlans from '@/modules/api-product/components/ApiPlans.vue'
import GatewayDomain from '@/modules/api-product/components/GatewayDomain.vue'
import { apiProductApi } from '@/modules/api-product/api/apiProduct'
import { projectsApi } from '@/api/projects'
import { usePermissionsStore } from '@/stores/permissions'
import { useSettingsStore } from '@/stores/settings'

// Who may change a gateway (GatewayPolicy, docs/api-product.md#permissions): the API says so per
// gateway (can_manage); a route also needs projects.update on its project. Everyone else, e.g. the
// read-only demo role, sees the gateway, its routes, consumers, plans and domain without the
// controls that would change them.

vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/modules/api-product/api/apiProduct', () => ({
  apiProductApi: {
    gateways: vi.fn(), gateway: vi.fn(), createGateway: vi.fn(), updateGateway: vi.fn(),
    addRoute: vi.fn(), updateRoute: vi.fn(), removeRoute: vi.fn(),
    setDomain: vi.fn(), verifyDomain: vi.fn(), removeDomain: vi.fn(),
    consumers: vi.fn(), createConsumer: vi.fn(), plans: vi.fn(), createPlan: vi.fn(), deletePlan: vi.fn(),
  },
}))

const READ_ONLY = [
  'projects.view', 'git.pull', 'env.view', 'secret_stores.view', 'logs.view', 'metrics.view', 'traces.view',
  'workflows.view', 'sandboxes.view', 'regions.view', 'images.view', 'bindings.view', 'members.view',
]

const ok = (data) => Promise.resolve({ data })

function i18n() {
  return createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', missingWarn: false, fallbackWarn: false, messages: { en: { ...en, common: { cancel: 'Cancel' } }, tr } })
}

function grant(permissions, projects = {}) {
  usePermissionsStore().apply({ permissions, platform_admin: false, edition: 'single', role: null, roles: [], projects })
}

const routes = [
  { id: 'r-orders', path: '/orders', project_id: 'p1', project: { id: 'p1', name: 'orders' }, environment: null, url: 'https://jane.eu.test/orders', auth_mode: 'jwt', jwt: { jwks_url: 'https://id.example.com/jwks', subject_claim: 'sub' }, gateway_only: false },
  { id: 'r-billing', path: '/billing', project_id: 'p2', project: { id: 'p2', name: 'billing' }, environment: null, url: 'https://jane.eu.test/billing', auth_mode: 'public', gateway_only: false },
]

const gatewayOf = (canManage, extra = {}) => ({
  id: 'gw1', knative_region_id: 'r1', region: { id: 'r1', name: 'eu' }, host: 'jane.eu.test', status: 'active',
  can_manage: canManage, routes, developer_page: true, developer_page_key: 'k1', description: 'Orders API', ...extra,
})

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

describe('GatewaysSettings', () => {
  const stubs = { GatewayRoutes: true, ApiConsumers: true, ApiPlans: true, ApiUsage: true, GatewayDomain: true }

  function regions() {
    const settings = useSettingsStore()
    settings.data.regions = [{ id: 'r1', name: 'eu' }, { id: 'r2', name: 'us' }]
    settings.loaded = true
  }

  it('offers no new gateway to the read-only role and says why there is none', async () => {
    grant(READ_ONLY)
    regions()
    vi.mocked(apiProductApi.gateways).mockReturnValue(ok({ data: [] }))
    const w = mount(GatewaysSettings, { global: { plugins: [i18n()], stubs } })
    await flushPromises()

    expect(w.find('[data-test="api-gateway-new"]').exists()).toBe(false)
    expect(w.get('[data-test="api-gateways-empty"]').text()).toBe(en.apiProduct.emptyReadOnly)
  })

  it('shows a gateway the user may not change as read-only', async () => {
    grant(READ_ONLY)
    regions()
    vi.mocked(apiProductApi.gateways).mockReturnValue(ok({ data: [gatewayOf(false)] }))
    const w = mount(GatewaysSettings, { global: { plugins: [i18n()], stubs } })
    await flushPromises()

    expect(w.find('[data-gateway="gw1"]').exists()).toBe(true)
    expect(w.get('[data-test="api-gateway-read-only"]').text()).toContain('not change it')
    expect(w.find('[data-test="api-gateway-new"]').exists()).toBe(false)
  })

  it('offers a gateway in another region to whoever may change a project', async () => {
    grant(['projects.view'], { p1: ['projects.view', 'projects.update'] })
    regions()
    vi.mocked(apiProductApi.gateways).mockReturnValue(ok({ data: [gatewayOf(true)] }))
    const w = mount(GatewaysSettings, { global: { plugins: [i18n()], stubs } })
    await flushPromises()

    expect(w.get('[data-test="api-gateway-new-region"]').findAll('option').map((o) => o.text())).toEqual(['Region', 'us'])
    expect(w.find('[data-test="api-gateway-read-only"]').exists()).toBe(false)
  })
})

describe('GatewayRoutes', () => {
  it('shows the routes and their authentication read-only without can_manage', () => {
    grant(READ_ONLY)
    const w = mount(GatewayRoutes, { props: { gateway: gatewayOf(false) }, global: { plugins: [i18n()] } })

    expect(w.find('[data-route="/orders"]').exists()).toBe(true)
    expect(w.find('[data-test="route-add-open"]').exists()).toBe(false)
    expect(w.find('[data-test="route-remove"]').exists()).toBe(false)
    expect(w.find('[data-test="route-save"]').exists()).toBe(false)
    expect(w.find('[data-test="route-read-only"]').exists()).toBe(false)
    for (const sel of ['route-auth', 'route-gateway-only', 'route-jwks', 'route-issuer', 'route-audience', 'route-subject-claim', 'route-require-consumer']) {
      for (const field of w.findAll(`[data-test="${sel}"]`)) expect(field.attributes('disabled'), sel).toBeDefined()
    }
    expect(w.get('[data-test="route-jwks"]').element.value).toBe('https://id.example.com/jwks')
  })

  it('lets the gateway\'s manager change only the routes of projects it may change', async () => {
    grant(['projects.view'], { p1: ['projects.view', 'projects.update'], p2: ['projects.view'] })
    vi.spyOn(projectsApi, 'list').mockReturnValue(ok({ data: [{ id: 'p1', name: 'orders' }, { id: 'p2', name: 'billing' }] }))
    const w = mount(GatewayRoutes, { props: { gateway: gatewayOf(true) }, global: { plugins: [i18n()] } })

    const orders = w.get('[data-route="/orders"]')
    expect(orders.find('[data-test="route-remove"]').exists()).toBe(true)
    expect(orders.find('[data-test="route-save"]').exists()).toBe(true)
    expect(orders.get('[data-test="route-auth"]').attributes('disabled')).toBeUndefined()

    const billing = w.get('[data-route="/billing"]')
    expect(billing.find('[data-test="route-remove"]').exists()).toBe(false)
    expect(billing.find('[data-test="route-save"]').exists()).toBe(false)
    expect(billing.get('[data-test="route-auth"]').attributes('disabled')).toBeDefined()
    expect(billing.get('[data-test="route-read-only"]').text()).toContain('billing')

    // a new route: only to a project the user may change
    await w.get('[data-test="route-add-open"]').trigger('click')
    await flushPromises()
    const options = w.get('[data-test="route-add-project"]').findAll('option').map((o) => o.text())
    expect(options).toEqual(['—', 'orders'])
  })

  it('adds no route for a manager who may change no project (regions.manage alone)', () => {
    grant(['projects.view', 'regions.manage'])
    const w = mount(GatewayRoutes, { props: { gateway: gatewayOf(true) }, global: { plugins: [i18n()] } })

    expect(w.find('[data-test="route-add-open"]').exists()).toBe(false)
    expect(w.findAll('[data-test="route-read-only"]')).toHaveLength(2)
  })
})

describe('ApiConsumers and ApiPlans', () => {
  beforeEach(() => {
    vi.mocked(apiProductApi.consumers).mockReturnValue(ok({ data: [] }))
    vi.mocked(apiProductApi.plans).mockReturnValue(ok({ data: [{ id: 'pl1', name: 'Pro', scope: 'gateway', rate_per_second: 5 }] }))
  })

  it('hides the new consumer form without can_manage', async () => {
    grant(READ_ONLY)
    const w = mount(ApiConsumers, { props: { gateway: gatewayOf(false) }, global: { plugins: [i18n()] } })
    await flushPromises()
    expect(w.find('[data-test="consumer-form"]').exists()).toBe(false)
    expect(w.find('[data-test="consumers-empty"]').exists()).toBe(true)

    const manager = mount(ApiConsumers, { props: { gateway: gatewayOf(true) }, global: { plugins: [i18n()] } })
    await flushPromises()
    expect(manager.find('[data-test="consumer-form"]').exists()).toBe(true)
  })

  it('lists the plans without the form and the delete button without can_manage', async () => {
    grant(READ_ONLY)
    const w = mount(ApiPlans, { props: { gateway: gatewayOf(false) }, global: { plugins: [i18n()] } })
    await flushPromises()
    expect(w.find('[data-plan="Pro"]').exists()).toBe(true)
    expect(w.find('[data-test="plan-form"]').exists()).toBe(false)
    expect(w.find('[data-test="plan-delete"]').exists()).toBe(false)

    const manager = mount(ApiPlans, { props: { gateway: gatewayOf(true) }, global: { plugins: [i18n()] } })
    await flushPromises()
    expect(manager.find('[data-test="plan-form"]').exists()).toBe(true)
    expect(manager.find('[data-test="plan-delete"]').exists()).toBe(true)
  })
})

describe('GatewayDomain', () => {
  const pending = { domain: 'api.example.com', verification: { type: 'TXT', name: '_runr.api.example.com', value: 'abc' }, dns_targets: [] }

  it('shows the domain and the page read-only without can_manage', () => {
    grant(READ_ONLY)
    const active = mount(GatewayDomain, { props: { gateway: gatewayOf(false, { custom_domain: 'api.example.com' }) }, global: { plugins: [i18n()] } })
    expect(active.get('[data-test="domain-active"]').text()).toContain('api.example.com')
    expect(active.find('[data-test="domain-remove"]').exists()).toBe(false)
    expect(active.find('[data-test="domain-form"]').exists()).toBe(false)
    expect(active.get('[data-test="page-enabled"]').attributes('disabled')).toBeDefined()
    expect(active.get('[data-test="page-metrics"]').attributes('disabled')).toBeDefined()
    expect(active.get('[data-test="page-description"]').attributes('disabled')).toBeDefined()
    expect(active.find('[data-test="page-save"]').exists()).toBe(false)
    expect(active.find('[data-test="page-link"]').exists()).toBe(true)

    const waiting = mount(GatewayDomain, { props: { gateway: gatewayOf(false, { custom_domain_pending: pending }) }, global: { plugins: [i18n()] } })
    expect(waiting.get('[data-test="domain-txt"]').text()).toBe('abc')
    expect(waiting.find('[data-test="domain-verify"]').exists()).toBe(false)
    expect(waiting.find('[data-test="domain-cancel"]').exists()).toBe(false)
  })

  it('keeps every control for the gateway\'s manager', () => {
    const active = mount(GatewayDomain, { props: { gateway: gatewayOf(true, { custom_domain: 'api.example.com' }) }, global: { plugins: [i18n()] } })
    expect(active.find('[data-test="domain-remove"]').exists()).toBe(true)
    expect(active.find('[data-test="domain-form"]').exists()).toBe(true)
    expect(active.get('[data-test="page-enabled"]').attributes('disabled')).toBeUndefined()
    expect(active.find('[data-test="page-save"]').exists()).toBe(true)

    const waiting = mount(GatewayDomain, { props: { gateway: gatewayOf(true, { custom_domain_pending: pending }) }, global: { plugins: [i18n()] } })
    expect(waiting.find('[data-test="domain-verify"]').exists()).toBe(true)
    expect(waiting.find('[data-test="domain-cancel"]').exists()).toBe(true)
  })
})
