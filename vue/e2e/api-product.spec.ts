import { test, expect } from './fixtures/test'
import type { MockApi } from './fixtures/mock-api'
import { confirmDialog } from './helpers/ui'

// Build & Run → API gateways (docs/api-product.md): route authentication, consumers and their keys
// (shown once), plans, usage, the gateway's custom domain and the public developer page. A gateway
// needs a project routed in a region, so the flow runs against the mocked API.

test.skip(({ e2eMode }) => e2eMode === 'stack', 'gateways need a project routed in a region; covered with the mocked API')

const GW = 'gw-1'
const ROUTE = 'rt-orders'
const KEY = 'rk_e2eSecretKeyValue0000000000000000000000000'

function gatewayJson(over: Record<string, any> = {}) {
  return {
    id: GW, subdomain: 'admin', custom_domain: null, custom_domain_pending: null, custom_domain_verified_at: null,
    status: 'active', knative_region_id: 'r-eu', region: { id: 'r-eu', name: 'eu-1' }, host: 'admin.eu-1.runr.test',
    rate_limit: null, developer_page: false, developer_page_key: null, description: null, created_at: '2026-10-01T00:00:00Z',
    routes: [{
      id: ROUTE, path: '/orders', methods: null, project_id: 'p-xaas', project: { id: 'p-xaas', name: 'orders', slug: 'orders' },
      url: 'https://admin.eu-1.runr.test/orders', auth_mode: 'public', jwt: null, gateway_only: false,
    }],
    ...over,
  }
}

/** The API product endpoints on top of the mocked API, with in-test state. */
function mockApiProduct(mockApi: MockApi) {
  let gateway = gatewayJson()
  const consumers: any[] = []
  const keys: any[] = []
  const plans: any[] = []

  mockApi
    .on('GET', '/gateways', () => ({ body: { data: [gateway] } }))
    .on('GET', `/gateways/${GW}`, () => ({ body: { data: gateway } }))
    .on('PATCH', `/gateways/${GW}/routes/${ROUTE}`, (req) => {
      gateway = { ...gateway, routes: [{ ...gateway.routes[0], auth_mode: req.body.auth_mode, gateway_only: req.body.gateway_only, jwt: req.body.jwt ?? null }] }
      return { body: { data: gateway.routes[0] } }
    })
    .on('PATCH', `/gateways/${GW}`, (req) => {
      gateway = { ...gateway, developer_page: req.body.developer_page, description: req.body.description, developer_page_key: req.body.developer_page ? `_.${GW}` : null }
      return { body: { data: gateway } }
    })
    .on('PUT', `/gateways/${GW}/custom-domain`, (req) => {
      gateway = {
        ...gateway,
        custom_domain_pending: {
          domain: req.body.domain,
          verification: { type: 'TXT', name: `_runr-challenge.${req.body.domain}`, value: 'runr-verify=0123abcd' },
          dns_targets: [{ type: 'CNAME', name: req.body.domain, value: 'admin.eu-1.runr.test' }],
        },
      }
      return { body: { data: gateway } }
    })
    .on('POST', `/gateways/${GW}/custom-domain/verify`, () => ({
      status: 422,
      body: { message: 'No TXT record _runr-challenge.api.example.com = runr-verify=0123abcd yet.', data: gateway },
    }))
    .on('GET', '/api-consumers', () => ({ body: { data: consumers } }))
    .on('POST', '/api-consumers', (req) => {
      const c = { id: `c-${consumers.length + 1}`, gateway_id: GW, scope: 'gateway', name: req.body.name, email: req.body.email ?? null,
        external_id: req.body.external_id ?? null, jwt_subject: null, plan_id: req.body.plan_id ?? null, plan: null, status: 'active',
        metadata: {}, keys: { total: 0, active: 0 }, can_manage: true }
      consumers.unshift(c)
      return { status: 201, body: { data: c } }
    })
    .on('PATCH', '/api-consumers/:id', (req) => {
      const c = consumers.find((x) => x.id === req.params.id)
      Object.assign(c, req.body)
      return { body: { data: c } }
    })
    .on('GET', '/api-consumers/:id/keys', (req) => ({ body: { data: keys.filter((k) => k.consumer_id === req.params.id) } }))
    .on('POST', '/api-consumers/:id/keys', (req) => {
      const k = { id: `k-${keys.length + 1}`, consumer_id: req.params.id, name: req.body.name, prefix: KEY.slice(0, 11), scopes: req.body.scopes ?? null,
        plan_id: null, status: 'active', expires_at: null, revoked_at: null, last_used_at: null, rotated_from_id: null }
      keys.push(k)
      return { status: 201, body: { data: { ...k, key: KEY } } }
    })
    .on('POST', '/api-keys/:id/revoke', (req) => {
      const k = keys.find((x) => x.id === req.params.id)
      k.status = 'revoked'
      return { body: { data: k } }
    })
    .on('GET', '/api-plans', () => ({ body: { data: plans } }))
    .on('POST', '/api-plans', (req) => {
      const p = { id: `pl-${plans.length + 1}`, gateway_id: GW, scope: 'gateway', name: req.body.name, description: null,
        rate_per_second: req.body.rate_per_second ?? null, burst: req.body.burst ?? req.body.rate_per_second ?? null,
        rate_per_minute: null, quota_per_day: null, quota_per_month: req.body.quota_per_month ?? null }
      plans.push(p)
      return { status: 201, body: { data: p } }
    })
    .on('GET', '/api-usage', (req) => ({
      body: {
        from: '2026-10-14T00:00:00Z', to: '2026-10-21T11:00:00Z', group_by: req.query.get('group_by'),
        totals: { requests: 1200, unauthorized: 7, forbidden: 1, rate_limited: 12, quota_exceeded: 0 },
        data: req.query.get('group_by') === 'consumer'
          ? [{ consumer_id: 'c-1', consumer: { id: 'c-1', name: 'Acme Ltd', external_id: 'CUST-1042' }, subject: null, requests: 1200, unauthorized: 0, forbidden: 1, rate_limited: 12, quota_exceeded: 0 }]
          : [{ period: '2026-10-20', requests: 1200, unauthorized: 7, forbidden: 1, rate_limited: 12, quota_exceeded: 0 }],
      },
    }))
    .on('GET', `/public/developer-pages/_.${GW}`, () => ({
      body: { data: { name: 'admin', description: 'Orders API of Admin Ltd.', base_urls: ['https://admin.eu-1.runr.test'],
        routes: [{ path: '/orders', methods: ['GET'], auth: 'key', jwt_issuer: null }], key_headers: [], rate_limit_headers: [] } },
    }))
}

test.describe('API gateways', () => {
  test.beforeEach(async ({ mockApi }) => mockApiProduct(mockApi))

  test('a route asks for an API key and becomes gateway-only', async ({ page, auth, mockApi }) => {
    await auth.signIn()
    await page.goto('/settings?tab=gateways')

    const gateways = page.locator('[data-test="api-gateways"]')
    await expect(gateways.getByRole('heading', { name: 'API gateways' })).toBeVisible()
    await expect(gateways.getByText('admin.eu-1.runr.test').first()).toBeVisible()

    const route = gateways.locator('[data-route="/orders"]')
    await route.locator('[data-test="route-auth"]').selectOption('key')
    await route.locator('[data-test="route-gateway-only"]').check()
    await expect(route.getByText('never the key')).toBeVisible()
    const sent = mockApi.waitFor('PATCH', `/gateways/${GW}/routes/${ROUTE}`)
    await route.locator('[data-test="route-save"]').click()
    expect((await sent).body).toEqual({ auth_mode: 'key', gateway_only: true })
    await expect(page.getByText('Route /orders saved.')).toBeVisible()

    // JWT needs the issuer's JWKS
    await route.locator('[data-test="route-auth"]').selectOption('jwt')
    await route.locator('[data-test="route-jwks"]').fill('https://id.example.com/jwks')
    const jwt = mockApi.waitFor('PATCH', `/gateways/${GW}/routes/${ROUTE}`)
    await route.locator('[data-test="route-save"]').click()
    expect((await jwt).body.jwt).toMatchObject({ jwks_url: 'https://id.example.com/jwks', subject_claim: 'sub' })
  })

  test('a consumer gets a plan and a key that is shown once, then revoked', async ({ page, auth, mockApi }) => {
    await auth.signIn()
    await page.goto('/settings?tab=gateways')
    const gateways = page.locator('[data-test="api-gateways"]')

    await gateways.locator('[data-section="plans"]').click()
    await gateways.locator('[data-test="plan-name"]').fill('Pro')
    await gateways.locator('[data-test="plan-rate_per_second"]').fill('10')
    await gateways.locator('[data-test="plan-quota_per_month"]').fill('100000')
    const plan = mockApi.waitFor('POST', '/api-plans')
    await gateways.locator('[data-test="plan-create"]').click()
    expect((await plan).body).toEqual({ gateway_id: GW, name: 'Pro', rate_per_second: 10, quota_per_month: 100000 })
    await expect(gateways.locator('[data-plan="Pro"] [data-test="plan-limits"]')).toContainText('10/s')

    await gateways.locator('[data-section="consumers"]').click()
    await gateways.locator('[data-test="consumer-name"]').fill('Acme Ltd')
    await gateways.locator('[data-test="consumer-external-id"]').fill('CUST-1042')
    await gateways.locator('[data-test="consumer-plan"]').selectOption({ label: 'Pro' })
    const consumer = mockApi.waitFor('POST', '/api-consumers')
    await gateways.locator('[data-test="consumer-create"]').click()
    expect((await consumer).body).toEqual({ gateway_id: GW, name: 'Acme Ltd', external_id: 'CUST-1042', plan_id: 'pl-1' })

    const row = gateways.locator('[data-consumer="Acme Ltd"]')
    await row.locator('[data-test="key-name"]').fill('production')
    await row.locator('[data-test="key-route-/orders"]').check()
    const key = mockApi.waitFor('POST', '/api-consumers/:id/keys')
    await row.locator('[data-test="key-create"]').click()
    expect((await key).body).toEqual({ name: 'production', scopes: [{ route_id: ROUTE }] })

    await expect(row.locator('[data-test="key-secret-value"]')).toHaveText(KEY)
    await row.locator('[data-test="key-secret-close"]').click()
    await expect(row.locator('[data-test="key-secret"]')).toHaveCount(0)
    await expect(page.getByText(KEY)).toHaveCount(0)
    await expect(row.locator(`[data-key="${KEY.slice(0, 11)}"]`)).toBeVisible()

    await row.locator('[data-test="key-revoke"]').click()
    const revoke = mockApi.waitFor('POST', '/api-keys/:id/revoke')
    await confirmDialog(page, 'Revoke')
    await revoke
    await expect(row.locator('[data-test="key-status"]')).toHaveText('Revoked')

    const suspend = mockApi.waitFor('PATCH', '/api-consumers/:id')
    await row.locator('[data-test="consumer-suspend"]').click()
    expect((await suspend).body).toEqual({ status: 'suspended' })
    await expect(row.locator('[data-test="consumer-status"]')).toHaveText('Suspended')
  })

  test('usage shows the totals per consumer', async ({ page, auth }) => {
    await auth.signIn()
    await page.goto('/settings?tab=gateways')
    const gateways = page.locator('[data-test="api-gateways"]')

    await gateways.locator('[data-section="usage"]').click()
    await expect(gateways.locator('[data-test="usage-requests"]')).toHaveText((1200).toLocaleString('en'))
    await gateways.locator('[data-test="usage-group"]').selectOption('consumer')
    await expect(gateways.locator('[data-test="usage-row"]').first()).toContainText('Acme Ltd (CUST-1042)')
  })

  test('a custom domain waits for its DNS records; the developer page is published', async ({ page, auth, mockApi }) => {
    await auth.signIn()
    await page.goto('/settings?tab=gateways')
    const gateways = page.locator('[data-test="api-gateways"]')

    await gateways.locator('[data-section="domain"]').click()
    await gateways.locator('[data-test="domain-input"]').fill('api.example.com')
    const set = mockApi.waitFor('PUT', `/gateways/${GW}/custom-domain`)
    await gateways.locator('[data-test="domain-request"]').click()
    expect((await set).body).toEqual({ domain: 'api.example.com' })
    await expect(gateways.locator('[data-test="domain-txt"]')).toHaveText('runr-verify=0123abcd')

    await gateways.locator('[data-test="domain-verify"]').click()
    await expect(gateways.locator('[data-test="domain-verify-message"]')).toContainText('No TXT record')

    await gateways.locator('[data-test="page-enabled"]').check()
    await gateways.locator('[data-test="page-description"]').fill('Orders API of Admin Ltd.')
    const pageSaved = mockApi.waitFor('PATCH', `/gateways/${GW}`)
    await gateways.locator('[data-test="page-save"]').click()
    // the routes' metrics stay off the page unless asked for (developer_page_metrics)
    expect((await pageSaved).body).toEqual({ developer_page: true, description: 'Orders API of Admin Ltd.', developer_page_metrics: false })
    await expect(gateways.locator('[data-test="page-link"]')).toContainText(`/developers/_.${GW}`)
  })

  test('a gateway whose auth is not ready says that its key routes refuse calls', async ({ page, auth, mockApi }) => {
    const message = 'API authentication is starting in the region. Until it is ready, the key and JWT routes answer 503 auth_unavailable.'
    mockApi.on('GET', '/gateways', () => ({ body: { data: [gatewayJson({ status: 'pending', status_message: message })] } }))
    await auth.signIn()
    await page.goto('/settings?tab=gateways')

    const status = page.locator('[data-test="api-gateway-status"]')
    await expect(status).toContainText('answer 503 until it is ready')
    await expect(status.locator('[data-test="api-gateway-status-message"]')).toHaveText(message)
  })

  test('the developer page is public', async ({ page }) => {
    await page.goto(`/developers/_.${GW}`)
    const dev = page.locator('[data-test="developer-page"]')
    await expect(dev.getByRole('heading', { name: 'admin' })).toBeVisible()
    await expect(dev.getByText('Orders API of Admin Ltd.')).toBeVisible()
    await expect(dev.locator('[data-route="/orders"]')).toContainText("X-Api-Key: rk_…")
  })
})
