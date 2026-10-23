import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import router from '@/router'
import { usePermissionsStore } from '@/stores/permissions'
import plugin from '@/modules/api-product/plugin'
import en from '@/modules/api-product/locales/en.js'
import tr from '@/modules/api-product/locales/tr.js'
import KeySecret from '@/modules/api-product/components/KeySecret.vue'
import GatewayRoutes from '@/modules/api-product/components/GatewayRoutes.vue'
import { apiProductApi } from '@/modules/api-product/api/apiProduct'
import { curlExample, planLimits, rangeParams, refused } from '@/modules/api-product/utils/format'

vi.mock('@/router', () => ({ default: { addRoute: vi.fn() } }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/modules/api-product/api/apiProduct', () => ({
  apiProductApi: { updateRoute: vi.fn(), gateway: vi.fn() },
}))

function i18n() {
  return createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', missingWarn: false, fallbackWarn: false, messages: { en, tr } })
}

const t = (key, params) => i18n().global.t(key, params)

describe('api-product module plugin', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(router.addRoute).mockClear()
  })

  it('registers the settings tab, the public developer page and its messages', () => {
    const registry = { settingsTabs: [], localeMessages: [] }
    plugin(registry)

    expect(registry.settingsTabs.map((tab) => tab.key)).toEqual(['gateways'])
    expect(router.addRoute).toHaveBeenCalledWith(expect.objectContaining({ path: '/developers/:key', meta: { public: true } }))
    expect(registry.localeMessages[0].en).toBe(en)
  })

  it('shows the tab to whoever sees projects', () => {
    const registry = { settingsTabs: [], localeMessages: [] }
    plugin(registry)
    const [tab] = registry.settingsTabs

    usePermissionsStore().apply({ permissions: ['billing.view'] })
    expect(tab.visibleIf()).toBe(false)
    usePermissionsStore().apply({ permissions: ['projects.view'] })
    expect(tab.visibleIf()).toBe(true)
  })
})

describe('format helpers', () => {
  it('labels plan limits', () => {
    expect(planLimits({ rate_per_second: 10, burst: 20, quota_per_month: 100000 }, t)).toBe(`10/s (burst 20) · ${(100000).toLocaleString()}/month`)
    expect(planLimits({ rate_per_second: 5, burst: 5 }, t)).toBe('5/s')
    expect(planLimits({}, t)).toBe('No limits')
  })

  it('sums the refused calls', () => {
    expect(refused({ requests: 9, unauthorized: 1, forbidden: 2, rate_limited: 3, quota_exceeded: 4 })).toBe(10)
    expect(refused(null)).toBe(0)
  })

  it('writes the curl example of a route', () => {
    expect(curlExample('https://api.example.com', { path: '/orders', auth: 'key' })).toBe("curl https://api.example.com/orders -H 'X-Api-Key: rk_…'")
    expect(curlExample('https://api.example.com', { path: '/p', auth_mode: 'jwt' })).toContain('Authorization: Bearer')
    expect(curlExample('https://api.example.com', { path: '/s', auth: 'public' })).toBe('curl https://api.example.com/s')
  })

  it('turns a range into UTC from / to', () => {
    const now = new Date(Date.UTC(2026, 9, 21, 10, 30))
    expect(rangeParams('lastMonth', now)).toEqual({ from: '2026-09-01T00:00:00.000Z', to: '2026-10-01T00:00:00.000Z' })
    expect(rangeParams('thisMonth', now)).toEqual({ from: '2026-10-01T00:00:00.000Z', to: '2026-10-21T11:00:00.000Z' })
    expect(rangeParams('last7', now)).toEqual({ from: '2026-10-14T11:00:00.000Z', to: '2026-10-21T11:00:00.000Z' })
  })
})

describe('KeySecret', () => {
  it('shows the key once and copies it', async () => {
    const writeText = vi.fn().mockResolvedValue()
    Object.assign(navigator, { clipboard: { writeText } })
    const w = mount(KeySecret, { props: { value: 'rk_secret', name: 'production' }, global: { plugins: [i18n()] } })

    expect(w.get('[data-test="key-secret-value"]').text()).toBe('rk_secret')
    expect(w.text()).toContain('shown this once')
    await w.get('[data-test="key-secret-copy"]').trigger('click')
    expect(writeText).toHaveBeenCalledWith('rk_secret')
    await w.get('[data-test="key-secret-close"]').trigger('click')
    expect(w.emitted('close')).toHaveLength(1)
  })
})

describe('GatewayRoutes', () => {
  const gateway = {
    id: 'gw1',
    routes: [{ id: 'r1', path: '/orders', project: { name: 'orders' }, url: 'https://jane.eu-1.runr.test/orders', auth_mode: 'public', gateway_only: false }],
  }

  it('sends the JWT settings of a jwt route and reports the new gateway', async () => {
    vi.mocked(apiProductApi.updateRoute).mockResolvedValue({ data: { data: { id: 'r1' } } })
    vi.mocked(apiProductApi.gateway).mockResolvedValue({ data: { data: { ...gateway, routes: [{ ...gateway.routes[0], auth_mode: 'jwt' }] } } })
    const w = mount(GatewayRoutes, { props: { gateway }, global: { plugins: [i18n()] } })

    await w.get('[data-test="route-auth"]').setValue('jwt')
    await w.get('[data-test="route-jwks"]').setValue('https://id.example.com/jwks')
    await w.get('[data-test="route-save"]').trigger('click')
    await flushPromises()

    expect(apiProductApi.updateRoute).toHaveBeenCalledWith('gw1', 'r1', {
      auth_mode: 'jwt',
      gateway_only: false,
      jwt: { jwks_url: 'https://id.example.com/jwks', issuer: null, audience: null, subject_claim: 'sub', require_consumer: false },
    })
    expect(w.emitted('updated')[0][0].routes[0].auth_mode).toBe('jwt')
  })

  it('shows the validation error of the JWKS URL', async () => {
    vi.mocked(apiProductApi.updateRoute).mockRejectedValue({ response: { status: 422, data: { message: 'invalid', errors: { 'jwt.jwks_url': ['JWT authentication needs the JWKS URL of the token issuer.'] } } } })
    const w = mount(GatewayRoutes, { props: { gateway }, global: { plugins: [i18n()] } })

    await w.get('[data-test="route-auth"]').setValue('jwt')
    await w.get('[data-test="route-save"]').trigger('click')
    await flushPromises()

    expect(w.get('[data-test="route-jwks-error"]').text()).toContain('JWKS URL')
  })
})
