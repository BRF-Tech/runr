import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from '@/modules/api-product/locales/en.js'
import tr from '@/modules/api-product/locales/tr.js'
import ApiUsage from '@/modules/api-product/components/ApiUsage.vue'
import GatewayDomain from '@/modules/api-product/components/GatewayDomain.vue'
import DeveloperPage from '@/modules/api-product/components/DeveloperPage.vue'
import { apiProductApi } from '@/modules/api-product/api/apiProduct'
import { bytes, latency, successRate } from '@/modules/api-product/utils/format'

vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: vi.fn().mockResolvedValue(true) }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ params: { key: '_.01JGATEWAY' } }) }))
vi.mock('@/modules/api-product/api/apiProduct', () => ({
  apiProductApi: { usage: vi.fn(), updateGateway: vi.fn(), developerPage: vi.fn() },
}))

function i18n() {
  return createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', missingWarn: false, fallbackWarn: false, messages: { en, tr } })
}

const answered = {
  requests: 100, unauthorized: 2, forbidden: 0, rate_limited: 1, quota_exceeded: 0,
  responses: 100, status_2xx: 96, status_3xx: 0, status_4xx: 1, status_5xx: 2, upstream_errors: 1, errors: 3,
  latency_avg_ms: 40.1, latency_p50_ms: 25.5, latency_p95_ms: 174.75, latency_p99_ms: 194.95, bytes_in: 4000, bytes_out: 2 * 1024 * 1024,
}

describe('answer helpers', () => {
  it('prints latencies, the success rate and bytes', () => {
    expect(latency(null)).toBe('—')
    expect(latency(25.55)).toBe(`${(25.6).toLocaleString()} ms`)
    expect(latency(194.95)).toBe('195 ms')
    expect(successRate({ responses: 0 })).toBe('—')
    expect(successRate({ responses: 100, errors: 3 })).toBe(`${(97).toLocaleString()}%`)
    expect(bytes(512)).toBe('512 B')
    expect(bytes(2 * 1024 * 1024)).toBe(`${(2).toLocaleString()} MB`)
  })
})

describe('ApiUsage', () => {
  beforeEach(() => vi.mocked(apiProductApi.usage).mockReset())

  it('shows what the functions answered next to the gateway\'s decisions', async () => {
    vi.mocked(apiProductApi.usage).mockResolvedValue({ data: {
      group_by: 'route', totals: answered,
      data: [{ route_id: 'r1', path: '/orders', ...answered }],
    } })
    const w = mount(ApiUsage, { props: { gateway: { id: 'gw1' } }, global: { plugins: [i18n()] } })
    await flushPromises()

    expect(w.get('[data-test="usage-success"]').text()).toBe(`${(97).toLocaleString()}%`)
    expect(w.get('[data-test="usage-errors"]').text()).toBe('3')
    expect(w.get('[data-test="usage-latency"]').text()).toContain('175 ms')
    expect(w.get('[data-test="usage-row-errors"]').attributes('title')).toContain('5xx 2')
    expect(w.get('[data-test="usage-row-p95"]').text()).toBe('175 ms')
    expect(w.get('[data-test="usage-table"]').text()).toContain('p99')
  })

  it('leaves the answer columns out while nothing was measured', async () => {
    vi.mocked(apiProductApi.usage).mockResolvedValue({ data: {
      group_by: 'day', totals: { requests: 4, responses: 0 },
      data: [{ period: '2026-10-21', requests: 4, responses: 0 }],
    } })
    const w = mount(ApiUsage, { props: { gateway: { id: 'gw1' } }, global: { plugins: [i18n()] } })
    await flushPromises()

    expect(w.find('[data-test="usage-answers"]').exists()).toBe(false)
    expect(w.get('[data-test="usage-table"]').text()).not.toContain('p95')
  })
})

describe('the developer page and its metrics', () => {
  it('the owner switches the route health on the page', async () => {
    vi.mocked(apiProductApi.updateGateway).mockResolvedValue({ data: { data: { id: 'gw1', developer_page: true, developer_page_metrics: true } } })
    const w = mount(GatewayDomain, { props: { gateway: { id: 'gw1', host: 'jane.eu-1.runr.test', developer_page: true, developer_page_metrics: false } }, global: { plugins: [i18n()] } })

    await w.get('[data-test="page-metrics"]').setValue(true)
    await w.get('[data-test="page-save"]').trigger('click')
    await flushPromises()

    expect(apiProductApi.updateGateway).toHaveBeenCalledWith('gw1', { developer_page: true, developer_page_metrics: true, description: null })
  })

  it('shows each key route\'s success rate and latency, never counts', async () => {
    vi.mocked(apiProductApi.developerPage).mockResolvedValue({ data: { data: {
      name: 'jane', base_urls: ['https://jane.eu-1.runr.test'], metrics_days: 7,
      routes: [
        { path: '/orders', auth: 'key', health: { success_rate: 99.5, latency_p50_ms: 25.5, latency_p95_ms: 120, latency_p99_ms: 480 } },
        { path: '/billing', auth: 'jwt', health: null },
        { path: '/status', auth: 'public' },
      ],
    } } })
    const w = mount(DeveloperPage, { global: { plugins: [i18n()] } })
    await flushPromises()

    const health = w.findAll('[data-test="route-health"]')
    expect(health).toHaveLength(1)
    expect(health[0].text()).toContain(`${(99.5).toLocaleString()}%`)
    expect(health[0].text()).toContain('480 ms')
    expect(w.get('[data-route="/billing"]').text()).toContain('No calls measured in the last 7 days.')
    expect(w.get('[data-route="/status"]').text()).not.toContain('last 7 days')
  })
})
