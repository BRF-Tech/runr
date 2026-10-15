import { test, expect } from './fixtures/test'
import type { Page } from '@playwright/test'
import type { MockApi } from './fixtures/mock-api'
import { ALL_PERMISSIONS, effectivePermissions, type Json } from './fixtures/data'

// White-label console: branding (login page, header, admin editor), self-service signup with
// e-mail verification, and — multi-tenant (@modules) — the read-only plan page, the tenant's
// charges, the billing settings of the platform and the onboarding wizard. The white-label
// elements carry data-test attributes.

const byTest = (page: Page, id: string) => page.locator(`[data-test="${id}"]`)

const LOGO_DATA_URI = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMjAiIGhlaWdodD0iMzIiPjxyZWN0IHdpZHRoPSIxMjAiIGhlaWdodD0iMzIiIGZpbGw9IiMwMDlmZTMiLz48L3N2Zz4='

const OCEAN = { 300: '102 197 237', 400: '51 181 233', 500: '0 159 227', 600: '0 123 192', 700: '0 85 184', 800: '0 68 153', 900: '0 43 128' }
const RUNR = { 300: '253 186 116', 400: '251 146 60', 500: '249 115 22', 600: '234 88 12', 700: '194 65 12', 800: '154 52 18', 900: '124 45 18' }

/** GET /api/branding of a re-branded platform (Ocean blue palette, data: URI dark logo). */
function rebranded(features: Json = {}): Json {
  return {
    product_name: 'Acme Cloud',
    company_name: 'Acme Ltd',
    logo_light_url: null,
    logo_dark_url: LOGO_DATA_URI,
    logo_mark_url: null,
    favicon_url: null,
    primary_color: '#009fe3',
    accent_color: '#112233',
    palette: OCEAN,
    login_headline: 'Functions for Acme teams',
    login_text: 'Deploy in seconds.',
    footer_links: [{ label: 'Status', url: 'https://status.acme.test' }],
    support_email: 'help@acme.test',
    support_url: '',
    email_from_name: 'Acme Cloud',
    email_logo_url: null,
    powered_by: true,
    is_default_brand: false,
    tenant: null,
    features: { plans: false, tenant_branding: false, multi_tenant: false, ...features },
  }
}

/** GET /api/branding with nothing customised: RunR by BRF Teknoloji. */
function runrDefault(features: Json = {}): Json {
  return {
    ...rebranded(features),
    product_name: 'RunR',
    company_name: 'BRF Teknoloji',
    logo_dark_url: null,
    primary_color: '#f97316',
    accent_color: '#e63946',
    palette: RUNR,
    login_headline: '',
    login_text: '',
    footer_links: [],
    support_email: '',
    email_from_name: '',
    is_default_brand: true,
  }
}

function useBranding(mockApi: MockApi, branding: Json) {
  mockApi.on('GET', '/branding', () => ({ body: branding }))
}

const brandVars = (page: Page, keys: string[]) => page.evaluate((list) => {
  const style = getComputedStyle(document.documentElement)
  return list.map(k => style.getPropertyValue(`--brand-${k}`).trim())
}, keys)

const plan = (over: Json = {}): Json => ({
  id: 'p-starter', slug: 'starter', name: 'Starter', description: '', currency: 'USD',
  price_monthly: 10, price_yearly: 100, trial_days: 0, is_default: false,
  limits: { cpu_quota_m: 2000, memory_quota_mb: 4096, max_projects: 5, gpu_quota: 0, invocations_per_month: 100000, storage_quota_mb: 1024 },
  features: {}, ...over,
})

test.describe('White-label branding and signup', () => {
  test.skip(({ e2eMode }) => e2eMode === 'stack', 'needs the mocked API')

  test('the login page is branded before sign-in', async ({ page, mockApi }) => {
    useBranding(mockApi, rebranded())
    await page.goto('/login')

    await expect(page).toHaveTitle('Acme Cloud')
    await expect(byTest(page, 'login-headline')).toContainText('Functions for Acme teams')
    await expect(byTest(page, 'login-headline')).toContainText('Deploy in seconds.')

    // the dark logo is a data: URI — used as the image source as it is
    const logo = byTest(page, 'login-brand').locator('[data-test="wordmark-logo"]')
    await expect(logo).toHaveAttribute('src', LOGO_DATA_URI)
    await expect(logo).toHaveAttribute('alt', 'Acme Cloud')
    expect(await logo.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true)

    const footer = byTest(page, 'login-footer')
    await expect(footer.getByRole('link', { name: 'Status' })).toHaveAttribute('href', 'https://status.acme.test')
    await expect(footer).toContainText('help@acme.test')
    await expect(byTest(page, 'login-credit')).toHaveText(`© ${new Date().getFullYear()} Acme Ltd`)
    await expect(byTest(page, 'login-powered-by')).toHaveText('Powered by RunR')

    // the palette of the payload → --brand-300 … --brand-900, accent → --brand-accent
    expect(await brandVars(page, ['300', '500', '600', '900', 'accent'])).toEqual(['102 197 237', '0 159 227', '0 123 192', '0 43 128', '17 34 51'])
  })

  test('without customisation the console is RunR by BRF Teknoloji', async ({ page, mockApi }) => {
    useBranding(mockApi, runrDefault())
    await page.goto('/login')

    await expect(page).toHaveTitle('RunR')
    await expect(byTest(page, 'login-brand').locator('[data-test="wordmark-runr"]')).toContainText('RunR')
    await expect(byTest(page, 'login-credit')).toHaveText('RunR by BRF Teknoloji')
    await expect(byTest(page, 'login-powered-by')).toHaveCount(0)
    await expect(page.locator('link[rel~="icon"]')).toHaveAttribute('href', '/favicon.svg')
    expect(await brandVars(page, ['300', '500', '900', 'accent'])).toEqual(['253 186 116', '249 115 22', '124 45 18', '230 57 70'])
  })

  test('the header shows the brand after sign-in', async ({ page, auth, mockApi }) => {
    useBranding(mockApi, { ...rebranded(), logo_dark_url: null, logo_mark_url: LOGO_DATA_URI })
    await auth.signIn()
    await page.goto('/projects')

    await expect(page.locator('header [data-test="header-mark"]')).toHaveAttribute('src', LOGO_DATA_URI)
    await expect(page.locator('header [data-test="wordmark-name"]')).toHaveText('Acme Cloud')
    await expect(page).toHaveTitle('Acme Cloud')
  })

  test('signup with e-mail verification ends on the check-inbox screen', async ({ page, mockApi }) => {
    mockApi.on('GET', '/auth/registration-status', () => ({
      body: {
        enabled: true, invite_only: false, email_verification: true, allowed_domains: [],
        captcha: { provider: 'none', site_key: null }, terms_url: 'https://acme.test/terms', privacy_url: null,
        tenant_auto_create: true,
      },
    }))
    mockApi.on('POST', '/auth/register', req => ({ status: 202, body: { verification_required: true, email: req.body.email } }))
    mockApi.on('POST', '/auth/signup/resend', () => ({ body: { message: 'sent' } }))
    await page.goto('/login')

    await page.getByRole('button', { name: 'Create Account' }).click()
    const inputs = page.locator('input.login-input')
    await inputs.nth(0).fill('Lin Learner')
    await inputs.nth(1).fill('lin')
    await inputs.nth(2).fill('lin@acme.test')
    await page.locator('button.login-submit').click()

    await page.locator('input[autocomplete="new-password"]').nth(0).fill('secret-password')
    await page.locator('input[autocomplete="new-password"]').nth(1).fill('secret-password')
    await byTest(page, 'terms-checkbox').check()
    const registered = mockApi.waitFor('POST', '/auth/register')
    await page.locator('button.login-submit').click()

    expect((await registered).body).toMatchObject({ email: 'lin@acme.test', username: 'lin', accept_terms: true })
    await expect(byTest(page, 'signup-check-inbox')).toContainText('lin@acme.test')
    // no session yet: the account is created when the link is opened
    expect(await page.evaluate(() => localStorage.getItem('token'))).toBeNull()

    const resent = mockApi.waitFor('POST', '/auth/signup/resend')
    await byTest(page, 'signup-resend').click()
    expect((await resent).body).toEqual({ email: 'lin@acme.test' })
  })

  test('the verification link signs the user in and opens the projects page', async ({ page, mockApi }) => {
    mockApi.on('POST', '/auth/signup/verify', (_, db) => ({ status: 201, body: { user: db.user, token: '7|verified' } }))
    const verified = mockApi.waitFor('POST', '/auth/signup/verify')

    await page.goto('/signup/verify?token=tok-123')

    expect((await verified).body).toEqual({ token: 'tok-123' })
    await expect(page).toHaveURL(/\/projects$/)
    expect(await page.evaluate(() => localStorage.getItem('token'))).toBe('7|verified')
  })

  test('an expired verification link offers a new one', async ({ page, mockApi }) => {
    mockApi.on('POST', '/auth/signup/verify', () => ({ status: 410, body: { message: 'This link has expired.', code: 'expired' } }))
    await page.goto('/signup/verify?token=old')
    await expect(byTest(page, 'verify-state')).toContainText('expired')
    await expect(byTest(page, 'verify-resend')).toBeVisible()
  })

  test('the administrator edits the branding with a colour preset', async ({ page, auth, mockApi }) => {
    const admin = {
      branding: rebranded(),
      stored: { product_name: 'Acme Cloud', primary_color: '#cc3300' },
      defaults: { product_name: 'RunR', company_name: 'BRF Teknoloji', primary_color: '#f97316', accent_color: '#e63946', powered_by: true },
      tenant_overrides_enabled: false,
    }
    useBranding(mockApi, runrDefault())
    mockApi.on('GET', '/admin/branding', () => ({ body: admin }))
    mockApi.on('PUT', '/admin/branding', req => ({
      body: { ...admin, branding: { ...rebranded(), product_name: req.body.product_name, palette: OCEAN }, stored: req.body },
    }))
    await auth.signIn()
    await page.goto('/settings?tab=branding')

    const name = byTest(page, 'field-product_name')
    await expect(name).toHaveValue('Acme Cloud')
    await name.fill('Acme Functions')
    await byTest(page, 'branding-preset').selectOption('ocean')
    await expect(byTest(page, 'swatch-600')).toHaveAttribute('title', '#007bc0')

    const saved = mockApi.waitFor('PUT', '/admin/branding')
    await byTest(page, 'branding-save').click()
    const body = (await saved).body
    expect(body).toMatchObject({ product_name: 'Acme Functions', primary_color: '#009fe3', accent_color: '#009fe3' })
    expect(body.primary_scale).toEqual({ 300: '#66c5ed', 400: '#33b5e9', 500: '#009fe3', 600: '#007bc0', 700: '#0055b8', 800: '#004499', 900: '#002b80' })

    // the console follows the saved branding right away
    await expect(page).toHaveTitle('Acme Functions')
    expect(await brandVars(page, ['600'])).toEqual(['0 123 192'])
  })

  test('the branding and signup tabs need branding.manage / signup.manage', async ({ page, auth, mockApi }) => {
    mockApi.db.user = { ...mockApi.db.user, is_admin: false }
    mockApi.db.permissions = effectivePermissions('developer', ['projects.view', 'branding.manage'])
    await auth.signIn()
    await page.goto('/settings')

    await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Branding' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Signup' })).toHaveCount(0)
  })
})

test.describe('White-label plans and billing', { tag: '@modules' }, () => {
  test.skip(({ e2eMode }) => e2eMode === 'stack', 'needs the mocked API')

  const tenantMember = (mockApi: MockApi, permissions: string[] = [...ALL_PERMISSIONS]) => {
    mockApi.db.user = { ...mockApi.db.user, is_admin: false }
    mockApi.db.activeTenant = { ...mockApi.db.tenants[0] }
    mockApi.db.permissions = { ...effectivePermissions('owner', permissions), edition: 'multi' }
  }

  const subscription = (over: Json = {}): Json => ({
    plans_enabled: true,
    subscription: {
      plan_id: 'p-starter', plan: plan(), interval: 'month', status: 'active', prepaid: true, external_ref: 'shop-1',
      trial_ends_at: null, current_period_start: '2026-10-01T00:00:00Z', current_period_end: '2026-11-01T00:00:00Z', cancel_at_period_end: false,
    },
    limits: { ...plan().limits, max_projects: 8 },
    addon_limits: { max_projects: 3 },
    plans: [plan(), plan({ id: 'p-pro', slug: 'pro', name: 'Pro', price_monthly: 49, price_yearly: 490 })],
    usage: { projects: 1, invocations_this_month: 120, storage_mb: 12, gpu: 0 },
    store_url: 'https://shop.acme.test/runr',
    ...over,
  })

  test('a tenant sees its plan read-only and buys in the store', async ({ page, auth, mockApi }) => {
    tenantMember(mockApi)
    useBranding(mockApi, runrDefault({ plans: true, multi_tenant: true }))
    mockApi.on('GET', '/onboarding', () => ({ body: { status: 'completed', can_complete: true } }))
    mockApi.on('GET', '/tenant/subscription', () => ({ body: subscription() }))
    await auth.signIn()
    await page.goto('/settings?tab=subscription')

    await expect(byTest(page, 'current-plan')).toContainText('Starter')
    await expect(byTest(page, 'prepaid-badge')).toBeVisible()
    await expect(byTest(page, 'usage-max_projects')).toContainText('1 / 8')
    await expect(byTest(page, 'addon-max_projects')).toContainText('+3')
    await expect(byTest(page, 'store-link')).toHaveAttribute('href', 'https://shop.acme.test/runr')
    await expect(byTest(page, 'store-link')).toHaveAttribute('target', '_blank')
    // plans are information here: no buttons to choose or pay
    await expect(byTest(page, 'plan-card-pro').locator('button')).toHaveCount(0)
    await byTest(page, 'interval-year').click()
    await expect(byTest(page, 'plan-card-pro')).toContainText('/ year')
  })

  test('the subscription tab is open to every member while plans are on', async ({ page, auth, mockApi }) => {
    tenantMember(mockApi, ['projects.view'])
    useBranding(mockApi, runrDefault({ plans: true, multi_tenant: true }))
    mockApi.on('GET', '/onboarding', () => ({ body: { status: 'completed', can_complete: false } }))
    await auth.signIn()
    await page.goto('/settings')
    await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Subscription' })).toBeVisible()
    // charges need billing.view
    await expect(page.getByRole('button', { name: 'Charges' })).toHaveCount(0)
  })

  test('a tenant with billing.view sees its charges, credit and purchases', async ({ page, auth, mockApi }) => {
    tenantMember(mockApi, ['projects.view', 'billing.view'])
    useBranding(mockApi, runrDefault({ plans: false, multi_tenant: true }))
    mockApi.on('GET', '/onboarding', () => ({ body: { status: 'completed', can_complete: false } }))
    mockApi.on('GET', '/tenant/billing', () => ({
      body: {
        charges: [{
          id: 'c-1', tenant_id: 't-acme', kind: 'usage', description: 'Usage 2026-09', period: '2026-09', period_start: '2026-09-01', period_end: '2026-09-30',
          currency: 'USD', status: 'closed', final: true, amount: 0, total: 80, credit_applied: 30, amount_due: 50, external_ref: 'INV-42',
          line_items: [{ description: 'Project: shop (HTTP, Scale-to-Zero)', quantity: 15000, unit: 'sessions', unit_price: 0.005333, total: 80, metadata: {} }],
          closed_at: '2026-10-01T02:30:00Z', voided_at: null,
        }],
        credit_balances: { USD: 20 },
        purchases: [{ id: 'p-1', kind: 'credit', external_ref: 'order-1', description: 'Credit pack', amount: 50, currency: 'USD', plan: null, purchased_at: '2026-09-10T00:00:00Z', voided_at: null }],
        store_url: null,
      },
    }))
    await auth.signIn()
    await page.goto('/settings?tab=charges')

    await expect(byTest(page, 'charge-status-c-1')).toHaveText('Final')
    await expect(byTest(page, 'charge-due-c-1')).toContainText('50.00')
    await expect(byTest(page, 'credit-balances')).toContainText('$20.00')
    await expect(byTest(page, 'purchase-p-1')).toContainText('Credit pack')
    await byTest(page, 'charge-c-1').getByText('Usage 2026-09').click()
    await expect(byTest(page, 'charge-lines-c-1')).toContainText('Project: shop')
    await expect(byTest(page, 'charge-ref-c-1')).toContainText('INV-42')
    await expect(byTest(page, 'charge-void-c-1')).toHaveCount(0)
  })

  test('a new organization goes through the onboarding wizard', async ({ page, auth, mockApi }) => {
    tenantMember(mockApi)
    useBranding(mockApi, runrDefault({ plans: false, multi_tenant: true }))
    mockApi.on('GET', '/onboarding', () => ({
      body: {
        status: 'pending', can_complete: true, tenant: { id: 't-acme', name: 'Acme Corp', slug: 'acme' },
        regions: [{ id: 'r-ist', name: 'ist-1', region_mode: 'managed' }, { id: 'r-ank', name: 'ank-1', region_mode: 'managed' }],
        region_assigned: false, default_quota: { cpu_quota_m: 2000, memory_quota_mb: 4096 }, plan: null,
      },
    }))
    mockApi.on('POST', '/onboarding', req => ({ body: { status: 'completed', tenant: { id: 't-acme', name: req.body.org_name, slug: 'acme' } } }))
    await auth.signIn()
    await page.goto('/projects')

    const wizard = byTest(page, 'onboarding-wizard')
    await expect(wizard).toBeVisible()
    await byTest(page, 'onboarding-org-name').fill('Acme Industries')
    await byTest(page, 'onboarding-next').click()
    await byTest(page, 'onboarding-region-r-ank').click()
    const completed = mockApi.waitFor('POST', '/onboarding')
    mockApi.on('GET', '/onboarding', () => ({ body: { status: 'completed', can_complete: true } }))
    await byTest(page, 'onboarding-next').click()

    expect((await completed).body).toEqual({ org_name: 'Acme Industries', region_id: 'r-ank' })
    await expect(wizard).toBeHidden()
  })

  test('the central administrator connects the billing system and changes a plan', async ({ page, auth, mockApi }) => {
    useBranding(mockApi, runrDefault({ multi_tenant: true }))
    const settings = {
      plans_enabled: false,
      webhook: { url: null, url_source: 'config', secret_set: true },
      usage_thresholds: [],
      store_url: null,
      default_currency: 'USD',
    }
    mockApi.on('GET', '/admin/billing-settings', () => ({ body: settings }))
    mockApi.on('PUT', '/admin/billing-settings', req => ({ body: { ...settings, plans_enabled: req.body.plans_enabled, webhook: { url: req.body.webhook_url, url_source: 'settings', secret_set: true } } }))
    mockApi.on('GET', '/admin/plans', () => ({ body: [{ ...plan(), is_public: true, is_archived: false, sort_order: 0, tenant_count: 2 }] }))
    mockApi.on('POST', '/billing/events/test', () => ({ status: 409, body: { message: 'No billing webhook is configured.' } }))
    mockApi.on('PUT', '/billing/tenants/:id/subscription', req => ({
      body: { subscription: { plan_id: req.body.plan, plan: plan(), interval: req.body.interval, status: 'active', prepaid: false, external_ref: null, current_period_end: '2026-11-15T00:00:00Z', cancel_at_period_end: false } },
    }))
    await auth.signIn()
    await page.goto('/settings?tab=plans')

    await expect(byTest(page, 'plan-row-starter')).toBeVisible()
    await expect(byTest(page, 'billing-no-webhook')).toBeVisible()
    await byTest(page, 'field-plans_enabled').check()
    await byTest(page, 'field-webhook_url').fill('https://billing.acme.test/hooks/runr')
    await byTest(page, 'field-usage_thresholds').fill('500, 100')
    await byTest(page, 'webhook-secret-clear').click()

    const saved = mockApi.waitFor('PUT', '/admin/billing-settings')
    await byTest(page, 'billing-save').click()
    const body = (await saved).body
    expect(body).toEqual({ plans_enabled: true, webhook_url: 'https://billing.acme.test/hooks/runr', usage_thresholds: [100, 500], clear_secrets: ['webhook_secret'] })

    await byTest(page, 'billing-test-event').click()
    await expect(page.getByText('No billing webhook is configured.').first()).toBeVisible()

    const changed = mockApi.waitFor('PUT', '/billing/tenants/:id/subscription')
    await byTest(page, 'assign-acme').selectOption('p-starter')
    await byTest(page, 'interval-acme').selectOption('year')
    await byTest(page, 'save-acme').click()
    expect((await changed).body).toEqual({ plan: 'p-starter', interval: 'year' })
  })
})

test.describe('White-label branding on the stack', () => {
  test.skip(({ e2eMode }) => e2eMode !== 'stack', 'stack-mode smoke check; the mock variants above cover the details')

  test('the login page shows the installed branding', async ({ page }) => {
    const res = await page.request.get('/api/branding', { headers: { Accept: 'application/json' } })
    expect(res.ok()).toBe(true)
    const branding = await res.json()
    await page.goto('/login')

    await expect(page).toHaveTitle(branding.product_name)
    await expect(byTest(page, 'login-credit')).toBeVisible()
    const primary500 = await brandVars(page, ['500'])
    expect(primary500[0]).toMatch(/^\d{1,3} \d{1,3} \d{1,3}$/)
  })
})
