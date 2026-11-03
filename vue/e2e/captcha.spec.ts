import { createHash, createHmac, randomBytes } from 'node:crypto'
import type { APIRequestContext, Page } from '@playwright/test'
import { test, expect } from './fixtures/test'
import type { MockApi, MockRequest } from './fixtures/mock-api'
import type { Json } from './fixtures/data'
import { LOGIN_FIELD } from './helpers/auth'

// Bot protection of the anonymous forms (docs/security.md#bot-protection) — the test gate of the
// shared plan: (1) the widget renders, (2) a POST without a token gets 422, (3) an invalid token
// gets 422, (4) with siteverify out of reach the local check runs and the form completes (its
// solve time measured: < 3 s on a desktop), (5) provider "none": no widget, the POST passes.
//
// Mock mode drives the console against a fake API that keeps the contract (the challenge and its
// verification as in captcha-local-spec) and a fake Turnstile script. Stack mode checks the real
// API on a form no other spec sends (POST /api/auth/signup/resend), with Cloudflare's Turnstile
// test keys; the outage case needs an API whose CAPTCHA_TURNSTILE_VERIFY_URL does not answer
// (E2E_CAPTCHA_VERIFY_BLOCKED=true).

const byTest = (page: Page, id: string) => page.locator(`[data-test="${id}"]`)

// Cloudflare's Turnstile test keys (developers.cloudflare.com/turnstile/troubleshooting/testing)
const SITE_KEY = '1x00000000000000000000AA'
const SECRET_PASS = '1x0000000000000000000000000000000AA'
const SECRET_FAIL = '2x0000000000000000000000000000000AA'
const DUMMY_TOKEN = 'XXXX.DUMMY.TOKEN.XXXX'
const ALL_FORMS = ['login', 'register', 'password_reset', 'demo_login', 'public_forms']

// ── The local check, as the API does it (captcha-local-spec) ──────────────────────────────────

const HMAC_KEY = 'e2e-captcha-key'
const sha256 = (s: string) => createHash('sha256').update(s).digest('hex')

function challenge(maxnumber = 200000, number = Math.floor(maxnumber * 0.9)): Json {
  const salt = `${randomBytes(16).toString('hex')}?expires=${Math.floor(Date.now() / 1000) + 600}`
  const hash = sha256(salt + number)
  return { algorithm: 'SHA-256', salt, hash, signature: createHmac('sha256', HMAC_KEY).update(hash).digest('hex'), maxnumber }
}

function verifyLocal(payload: unknown, used: Set<string>): string {
  if (typeof payload !== 'string') return 'bad_payload'
  let s: Json
  try { s = JSON.parse(Buffer.from(payload, 'base64').toString('utf8')) } catch { return 'bad_payload' }
  if (!Number.isInteger(s.number)) return 'bad_payload'
  if (s.algorithm !== 'SHA-256') return 'bad_algorithm'
  const expires = Number(/[?&]expires=(\d+)/.exec(String(s.salt))?.[1])
  if (!expires) return 'bad_salt'
  if (Date.now() / 1000 >= expires) return 'expired'
  if (createHmac('sha256', HMAC_KEY).update(String(s.hash).toLowerCase()).digest('hex') !== String(s.signature).toLowerCase()) return 'bad_signature'
  if (sha256(s.salt + s.number) !== String(s.hash).toLowerCase()) return 'bad_hash'
  if (used.has(s.signature)) return 'replayed'
  used.add(s.signature)
  return 'ok'
}

/** Node's solution of a challenge (stack mode: the client side without a browser). */
function solve(c: Json): string {
  for (let n = 0; n <= c.maxnumber; n++) {
    if (sha256(c.salt + n) === c.hash) {
      return Buffer.from(JSON.stringify({ algorithm: c.algorithm, salt: c.salt, number: n, hash: c.hash, signature: c.signature })).toString('base64')
    }
  }
  throw new Error('no solution')
}

// ── The fake provider (mock mode) ─────────────────────────────────────────────────────────────

/**
 * Serves a Turnstile stand-in instead of challenges.cloudflare.com: it renders a marker and hands
 * out `token` (none for null) shortly after rendering; window.__turnstileResets counts resets.
 */
async function fakeTurnstile(page: Page, token: string | null = DUMMY_TOKEN): Promise<void> {
  await page.route('https://challenges.cloudflare.com/**', route => route.fulfill({
    contentType: 'application/javascript',
    body: `window.__turnstileResets = 0;
      window.turnstile = {
        render(el, opts) {
          const box = document.createElement('div'); box.setAttribute('data-fake-turnstile', opts.sitekey); el.appendChild(box)
          ${token === null ? '' : `setTimeout(() => opts.callback(${JSON.stringify(token)}), 30)`}
          return 'w1'
        },
        reset() { window.__turnstileResets++; },
        remove() {},
      };`,
  }))
}

function protect(mockApi: MockApi, forms = ALL_FORMS): void {
  mockApi.on('GET', '/captcha/config', () => ({ body: { provider: 'turnstile', site_key: SITE_KEY, fallback: 'local', forms } }))
}

const refuse = (reason: string, field = 'captcha_token') => ({
  status: 422,
  body: { message: `refused: ${reason}`, captcha: reason, errors: { [field]: [`refused: ${reason}`] } },
})

async function signInWithForm(page: Page, login: string, password: string): Promise<void> {
  await page.getByLabel(LOGIN_FIELD).fill(login)
  await page.getByPlaceholder('••••••••').fill(password)
  await page.getByRole('button', { name: 'Sign In', exact: true }).click()
}

test.describe('Bot protection in the console', () => {
  test.skip(({ e2eMode }) => e2eMode === 'stack', 'drives a fake provider and a fake API')

  test('(1) the widget renders with the site key and its token goes with the sign-in', async ({ page, auth, mockApi }) => {
    protect(mockApi)
    await fakeTurnstile(page)
    await page.goto('/login')

    await expect(byTest(page, 'captcha')).toBeVisible()
    await expect(page.locator(`[data-fake-turnstile="${SITE_KEY}"]`)).toHaveCount(1)

    const sent = mockApi.waitFor('POST', '/auth/login')
    await signInWithForm(page, auth.credentials.login, auth.credentials.password)
    expect((await sent).body).toMatchObject({ captcha_token: DUMMY_TOKEN })
    await expect(page).toHaveURL(/\/projects$/)
  })

  test('(2) a sign-in without a token gets 422 and the error', async ({ page, auth, mockApi }) => {
    protect(mockApi)
    await fakeTurnstile(page, null)   // the widget never answers
    mockApi.on('POST', '/auth/login', (req) => (req.body?.captcha_token ? { body: {} } : refuse('required')))
    await page.goto('/login')

    await signInWithForm(page, auth.credentials.login, auth.credentials.password)

    await expect(page.getByText('refused: required')).toBeVisible()
    await expect(page).toHaveURL(/\/login/)
    expect(mockApi.calls('POST', '/auth/login')[0].body.captcha_token).toBeUndefined()
  })

  test('(3) an invalid token gets 422, the widget starts over and nothing falls back', async ({ page, auth, mockApi }) => {
    protect(mockApi)
    await fakeTurnstile(page, 'forged-token')
    mockApi.on('POST', '/auth/login', () => refuse('invalid'))
    await page.goto('/login')
    await expect(page.locator('[data-fake-turnstile]')).toHaveCount(1)

    await signInWithForm(page, auth.credentials.login, auth.credentials.password)

    await expect(page.getByText('refused: invalid')).toBeVisible()
    await expect.poll(() => page.evaluate(() => (window as any).__turnstileResets)).toBeGreaterThan(0)
    await expect(byTest(page, 'captcha-local')).toHaveCount(0)
    const logins = mockApi.calls('POST', '/auth/login')
    expect(logins).toHaveLength(1)
    expect(logins[0].body.captcha_local).toBeUndefined()
  })

  test('(4) siteverify out of reach: the browser check runs and the sign-in completes', async ({ page, auth, mockApi }) => {
    protect(mockApi)
    await fakeTurnstile(page)
    const used = new Set<string>()
    let fallbackAt = 0
    // the second answer waits for the test: the solved check stays on the page meanwhile
    let release!: () => void
    const gate = new Promise<void>(resolve => { release = resolve })
    let arrive!: (req: MockRequest) => void
    const arrived = new Promise<MockRequest>(resolve => { arrive = resolve })
    mockApi.on('POST', '/auth/login', async (req: MockRequest, db) => {
      if (req.body?.captcha_local) {
        arrive(req)
        await gate
        const reason = verifyLocal(req.body.captcha_local, used)
        return reason === 'ok'
          ? { body: { token: `1|e2e-token-${db.user.id}`, user: db.user } }
          : refuse(reason, 'captcha_local')
      }
      // the API could not reach siteverify: 409 with a local challenge
      fallbackAt = Date.now()
      return { status: 409, body: { message: 'unreachable', captcha: 'fallback', challenge: challenge() } }
    })
    await page.goto('/login')
    await expect(page.locator('[data-fake-turnstile]')).toHaveCount(1)

    await signInWithForm(page, auth.credentials.login, auth.credentials.password)

    const resent = await arrived
    const solveMs = Date.now() - fallbackAt
    expect(resent.body.captcha_token).toBeUndefined()
    expect(resent.body.captcha_local).toEqual(expect.any(String))
    expect(resent.body).toMatchObject({ login: auth.credentials.login })

    const check = byTest(page, 'captcha-local')
    await expect(check).toHaveAttribute('data-captcha-solve-ms', /^\d+$/)
    const measured = Number(await check.getAttribute('data-captcha-solve-ms'))
    // desktop target of the shared plan: under 3 s for maxnumber 200000
    expect(measured).toBeLessThan(3000)
    expect(solveMs).toBeLessThan(3000 + 1000)
    await expect(check).toHaveAttribute('role', 'status')

    release()
    await expect(page).toHaveURL(/\/projects$/)
    expect(mockApi.calls('POST', '/auth/login')).toHaveLength(2)
  })

  test('(4b) the widget cannot load: the console asks for the browser check while the provider is down', async ({ page, auth, mockApi }) => {
    protect(mockApi)
    await page.route('https://challenges.cloudflare.com/**', route => route.abort('blockedbyclient'))
    const used = new Set<string>()
    mockApi.on('POST', '/captcha/local/challenge', () => ({ body: { challenge: challenge(50000) } }))
    mockApi.on('POST', '/auth/login', (req, db) => (verifyLocal(req.body?.captcha_local, used) === 'ok'
      ? { body: { token: `1|e2e-token-${db.user.id}`, user: db.user } }
      : refuse('fallback_inactive', 'captcha_local')))
    const asked = mockApi.waitFor('POST', '/captcha/local/challenge')
    await page.goto('/login')

    expect((await asked).body).toMatchObject({ form: 'login' })
    await expect(byTest(page, 'captcha-local')).toHaveAttribute('data-captcha-solve-ms', /^\d+$/)
    await signInWithForm(page, auth.credentials.login, auth.credentials.password)
    await expect(page).toHaveURL(/\/projects$/)
  })

  test('(4c) the widget cannot load and the provider answers the API: the console says so', async ({ page, mockApi }) => {
    protect(mockApi)
    await page.route('https://challenges.cloudflare.com/**', route => route.abort('blockedbyclient'))
    mockApi.on('POST', '/captcha/local/challenge', () => ({ status: 409, body: { message: 'reachable', captcha: 'provider' } }))
    await page.goto('/login')

    await expect(byTest(page, 'captcha-problem')).toContainText('could not load')
    // the server reaches the provider: the browser blocks the widget (shared contract §3.5)
    await expect(byTest(page, 'captcha-problem')).toContainText('Turn off your ad blocker')
    await expect(byTest(page, 'captcha-retry')).toBeVisible()
  })

  test('(4d) a wrong configuration (no site key): no widget, the browser check at once', async ({ page, auth, mockApi }) => {
    mockApi.on('GET', '/captcha/config', () => ({ body: { provider: 'turnstile', site_key: null, fallback: 'local', forms: ALL_FORMS, misconfigured: true } }))
    let scripts = 0
    await page.route('https://challenges.cloudflare.com/**', route => { scripts++; return route.abort('blockedbyclient') })
    const used = new Set<string>()
    mockApi.on('POST', '/captcha/local/challenge', () => ({ body: { challenge: challenge(50000) } }))
    mockApi.on('POST', '/auth/login', (req, db) => (verifyLocal(req.body?.captcha_local, used) === 'ok'
      ? { body: { token: `1|e2e-token-${db.user.id}`, user: db.user } }
      : refuse('required')))
    const asked = mockApi.waitFor('POST', '/captcha/local/challenge')
    await page.goto('/login')

    expect((await asked).body).toMatchObject({ form: 'login' })
    await expect(byTest(page, 'captcha-local')).toHaveAttribute('data-captcha-solve-ms', /^\d+$/)
    await expect(byTest(page, 'captcha')).toHaveCount(0)
    await expect(byTest(page, 'captcha-problem')).toHaveCount(0)
    await signInWithForm(page, auth.credentials.login, auth.credentials.password)
    await expect(page).toHaveURL(/\/projects$/)
    expect(scripts).toBe(0)
    expect(mockApi.calls('POST', '/auth/login')).toHaveLength(1)
  })

  test('(5) provider "none": no widget and the sign-in passes without a token', async ({ page, auth, mockApi }) => {
    let scripts = 0
    await page.route('https://challenges.cloudflare.com/**', route => { scripts++; return route.abort() })
    await page.goto('/login')

    await expect(page.getByLabel(LOGIN_FIELD)).toBeVisible()
    await expect(byTest(page, 'captcha-field')).toHaveCount(0)
    const sent = mockApi.waitFor('POST', '/auth/login')
    await signInWithForm(page, auth.credentials.login, auth.credentials.password)

    const body = (await sent).body
    expect(body.captcha_token).toBeUndefined()
    expect(body.captcha_local).toBeUndefined()
    await expect(page).toHaveURL(/\/projects$/)
    expect(scripts).toBe(0)
  })

  test('the two-factor step reuses the answer of the password step', async ({ page, auth, mockApi }) => {
    protect(mockApi)
    await fakeTurnstile(page)
    mockApi.db.twoFactorCode = '123456'
    await page.goto('/login')
    await expect(page.locator('[data-fake-turnstile]')).toHaveCount(1)

    await signInWithForm(page, auth.credentials.login, auth.credentials.password)
    await page.getByPlaceholder('Verification Code').fill('123456')
    await page.getByRole('button', { name: 'Verify', exact: true }).click()

    await expect(page).toHaveURL(/\/projects$/)
    const logins = mockApi.calls('POST', '/auth/login')
    expect(logins).toHaveLength(2)
    expect(logins[1].body).toMatchObject({ captcha_token: DUMMY_TOKEN, two_factor_code: '123456' })
  })

  test('only the guarded forms show the widget', async ({ page, mockApi }) => {
    protect(mockApi, ['register'])
    mockApi.on('GET', '/auth/registration-status', () => ({ body: { enabled: true, invite_only: false, email_verification: false, allowed_domains: [], captcha: { provider: 'turnstile', site_key: SITE_KEY }, terms_url: null, privacy_url: null, tenant_auto_create: false } }))
    await fakeTurnstile(page)
    await page.goto('/login')

    await expect(page.getByLabel(LOGIN_FIELD)).toBeVisible()
    await expect(byTest(page, 'captcha-field')).toHaveCount(0)

    await page.getByRole('button', { name: 'Create Account' }).click()
    const inputs = page.locator('input.login-input')
    await inputs.nth(0).fill('Ada Lovelace')
    await inputs.nth(1).fill('ada')
    await inputs.nth(2).fill('ada@acme.test')
    await page.locator('button.login-submit').click()            // → step 2
    await expect(byTest(page, 'captcha')).toBeVisible()
  })

  test('Administration → Bot protection saves the provider and never shows the secret', async ({ page, auth, mockApi }) => {
    let stored: Json = {}
    mockApi.on('PUT', '/admin/captcha', (req) => {
      stored = req.body
      return { body: {
        provider: req.body.provider, site_key: req.body.site_key, secret_set: !!req.body.secret, forms: req.body.forms,
        fallback: req.body.fallback, verify_timeout_ms: req.body.verify_timeout_ms, source: 'platform',
        stored: { provider: req.body.provider }, defaults: { provider: 'none', forms: ALL_FORMS, fallback: 'local', verify_timeout_ms: 4000, secret_set: false, legacy: false },
        providers: ['none', 'turnstile', 'recaptcha', 'hcaptcha'], form_keys: ALL_FORMS, multi_tenant: false,
        fallback_active: false, fallback_total: 2, fallback_last_at: null,
      } }
    })
    await auth.signIn()
    await page.goto('/settings?tab=bot-protection')

    await expect(byTest(page, 'bot-protection-platform')).toBeVisible()
    await byTest(page, 'field-provider').selectOption('turnstile')
    await byTest(page, 'field-site_key').fill(SITE_KEY)
    await byTest(page, 'field-secret').fill(SECRET_PASS)
    await byTest(page, 'form-public_forms').uncheck()
    await byTest(page, 'bot-protection-save').click()

    await expect(byTest(page, 'fallback-total')).toContainText('2')
    expect(stored).toMatchObject({ provider: 'turnstile', site_key: SITE_KEY, secret: SECRET_PASS, fallback: 'local' })
    expect(stored.forms).not.toContain('public_forms')
    // write-only: the field is empty again, the state says stored
    await expect(byTest(page, 'field-secret')).toHaveValue('')
    await expect(byTest(page, 'field-secret-state')).toContainText('Stored')
  })
})

// ── The real API (stack mode) ─────────────────────────────────────────────────────────────────

test.describe('Bot protection on the API', () => {
  test.skip(({ e2eMode }) => e2eMode !== 'stack', 'checks the running API')
  test.describe.configure({ mode: 'serial' })

  const RESEND = '/api/auth/signup/resend'
  const body = (extra: Json = {}) => ({ email: `nobody-${Date.now()}@example.com`, ...extra })

  async function configure(request: APIRequestContext, token: string, data: Json): Promise<void> {
    const res = await request.put('/api/admin/captcha', { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }, data })
    expect(res.status(), await res.text()).toBe(200)
    const answer = await res.json()
    expect(JSON.stringify(answer)).not.toContain(SECRET_PASS)
    expect(JSON.stringify(answer)).not.toContain(SECRET_FAIL)
  }

  const post = (request: APIRequestContext, data: Json) => request.post(RESEND, { headers: { Accept: 'application/json' }, data })

  // The API spends a token the provider checked (single use, 5 minutes): a fresh one per request,
  // so a test, its retry and an earlier run never send the same one
  let sent = 0
  const freshToken = () => `${DUMMY_TOKEN}-${Date.now()}-${++sent}`

  // Only public_forms: the sign-ins of the other specs stay unguarded
  const turnstile = (secret: string) => ({ provider: 'turnstile', site_key: SITE_KEY, secret, forms: ['public_forms'] })

  test.afterEach(async ({ request, stackSession }) => {
    await configure(request, stackSession!.token, { reset: true })
  })

  test('(5) provider "none": the form passes without a token', async ({ request, stackSession }) => {
    await configure(request, stackSession!.token, { provider: 'none' })
    const config = await (await request.get('/api/captcha/config')).json()
    expect(config).toMatchObject({ provider: 'none', site_key: null, forms: [] })
    expect((await post(request, body())).status()).toBe(200)
  })

  test('(2) (3) no token and an invalid token get 422, never the fallback', async ({ request, stackSession }) => {
    await configure(request, stackSession!.token, turnstile(SECRET_FAIL))
    expect(await (await request.get('/api/captcha/config')).json()).toMatchObject({ provider: 'turnstile', site_key: SITE_KEY, forms: ['public_forms'] })

    const missing = await post(request, body())
    expect(missing.status()).toBe(422)
    expect((await missing.json()).captcha).toBe('required')

    const invalid = await post(request, body({ captcha_token: freshToken() }))
    test.skip(invalid.status() === 409, 'the API cannot reach challenges.cloudflare.com: run (4) with E2E_CAPTCHA_VERIFY_BLOCKED=true instead')
    expect(invalid.status()).toBe(422)
    expect((await invalid.json()).captcha).toBe('invalid')

    // a local answer is refused while the provider answers
    const local = await post(request, body({ captcha_local: Buffer.from('{}').toString('base64') }))
    expect(local.status()).toBe(422)
    expect((await local.json()).captcha).toBe('fallback_inactive')
  })

  test('(3b) a secret the provider refuses counts as an outage: 409, the solved check completes the form', async ({ request, stackSession }) => {
    // Cloudflare answers a wrong secret with HTTP 400 invalid-input-secret: a configuration
    // error, never a 422 for everyone (captcha-turnstile-plan §3.5)
    await configure(request, stackSession!.token, turnstile('0x4AAAAAAAe2eNotTheWidgetsSecret'))
    const refused = await post(request, body({ captcha_token: freshToken() }))
    expect(refused.status()).toBe(409)
    const answer = await refused.json()
    expect(answer.captcha).toBe('fallback')

    const settings = await request.get('/api/admin/captcha', { headers: { Authorization: `Bearer ${stackSession!.token}`, Accept: 'application/json' } })
    test.skip((await settings.json()).problem?.reason !== 'secret_rejected', 'the API cannot reach challenges.cloudflare.com: (4) covers the outage')
    expect((await post(request, body({ captcha_local: solve(answer.challenge) }))).status()).toBe(200)
  })

  test('a valid token passes once', async ({ request, stackSession }) => {
    await configure(request, stackSession!.token, turnstile(SECRET_PASS))
    const token = freshToken()
    const first = await post(request, body({ captcha_token: token }))
    test.skip(first.status() === 409, 'the API cannot reach challenges.cloudflare.com')
    expect(first.status()).toBe(200)
    expect((await post(request, body({ captcha_token: token }))).status()).toBe(422)
  })

  test('(4) siteverify out of reach: 409, the solved check completes the form, once', async ({ request, stackSession }) => {
    test.skip(process.env.E2E_CAPTCHA_VERIFY_BLOCKED !== 'true', 'needs an API with CAPTCHA_TURNSTILE_VERIFY_URL pointing at an address that does not answer')
    await configure(request, stackSession!.token, turnstile(SECRET_PASS))

    const refused = await post(request, body({ captcha_token: freshToken() }))
    expect(refused.status()).toBe(409)
    const answer = await refused.json()
    expect(answer.captcha).toBe('fallback')

    const started = Date.now()
    const solution = solve(answer.challenge)
    expect(Date.now() - started).toBeLessThan(3000)

    expect((await post(request, body({ captcha_local: solution }))).status()).toBe(200)
    const replay = await post(request, body({ captcha_local: solution }))
    expect(replay.status()).toBe(422)
    expect((await replay.json()).captcha).toBe('replayed')

    const metrics = await request.get('/api/admin/captcha/metrics', { headers: { Authorization: `Bearer ${stackSession!.token}` } })
    expect(await metrics.text()).toMatch(/captcha_fallback_total\{form="public_forms",provider="turnstile"\} [1-9]/)
  })
})
