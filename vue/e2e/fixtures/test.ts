import { test as base, expect } from '@playwright/test'
import { MockApi } from './mock-api'
import { FakeRealtime } from './realtime'
import { Network } from './network'
import { apiLogin, completeTutorial, loadStackSession, loginViaUi, STACK_LOGIN, STACK_PASSWORD, STACK_USERNAME, useSession, type Session, type UiLogin } from '../helpers/auth'
import { MockSeeder, StackSeeder, type Seeder } from '../helpers/seed'

/*
 * Shared fixtures for every vue/e2e/<feature>.spec.ts. A new spec:
 *
 *   import { test, expect } from './fixtures/test'
 *
 *   test.describe('Widgets', () => {
 *     // Needs data or behaviour only the fake API has (or a Kubernetes cluster)?
 *     test.skip(({ e2eMode }) => e2eMode === 'stack', 'needs the mocked API')
 *
 *     test('creates a widget', async ({ page, auth, seed, mockApi }) => {
 *       const project = await seed.project('xaas')       // both modes
 *       await auth.signIn()                              // session in localStorage
 *       await page.goto(`/projects/${project.id}`)
 *       const sent = mockApi.waitFor('POST', '/widgets') // mock mode only
 *       ...
 *     })
 *   })
 *
 * Fixtures:
 *   e2eMode   'mock' | 'stack' (worker option, set per project in playwright.config.ts)
 *   modules   true when the tenancy/ticket/auth-provider modules are built in
 *   mockApi   the in-memory fake API (mock mode; throws in stack mode)
 *   realtime  fake Reverb/Pusher socket: realtime.platform(topic, payload)
 *   auth      signIn() fast path, loginViaUi(), credentials for the current mode
 *   seed      seed.project(type), seed.readyRegion(): mock rows or real API records,
 *             removed after the test in stack mode
 *   network   network.hold(method, path): holds API answers until release(), in both modes, to
 *             put a websocket event / click / navigation between a request and its answer
 * Specs that need the multi-tenant modules carry the @modules tag:
 *   test.describe('Billing', { tag: '@modules' }, () => { ... })
 * Every page starts with the English locale (the app defaults to Turkish).
 */

export type E2eMode = 'mock' | 'stack'

export interface E2eOptions {
  e2eMode: E2eMode
  modules: boolean
}

export interface Auth {
  /** E-mail address (login), user name and password of the account the current mode signs in as. */
  credentials: { login: string; username: string; password: string }
  /** Puts a signed-in session into localStorage before the next navigation. */
  signIn(session?: Partial<Session>): Promise<Session>
  /** Drives the login form (and the 2FA step when `code` is given). */
  loginViaUi(login?: Partial<UiLogin>): Promise<void>
}

interface TestFixtures {
  mockApi: MockApi
  realtime: FakeRealtime
  network: Network
  auth: Auth
  seed: Seeder
  isStack: boolean
}

interface WorkerFixtures extends E2eOptions {
  stackSession: Session | null
}

function stackGuard(): MockApi {
  return new Proxy({} as MockApi, {
    get(_, prop) {
      if (prop === 'then' || typeof prop === 'symbol') return undefined
      throw new Error(`mockApi.${String(prop)} is only available in mock mode; skip this test in stack mode`)
    },
  })
}

export const test = base.extend<TestFixtures, WorkerFixtures>({
  e2eMode: ['mock', { option: true, scope: 'worker' }],
  modules: [false, { option: true, scope: 'worker' }],

  // Stack mode: the session e2e/stack.setup.ts signed in once for the run (the login route is
  // rate limited); a worker logs in itself only when there is none (e.g. run with --no-deps).
  stackSession: [async ({ e2eMode, playwright }, use, workerInfo) => {
    if (e2eMode !== 'stack') { await use(null); return }
    const baseURL = String(workerInfo.project.use.baseURL)
    const ctx = await playwright.request.newContext({ baseURL })
    try {
      const stored = await loadStackSession(ctx, workerInfo.project.outputDir, baseURL)
      await use(stored ?? await completeTutorial(ctx, await apiLogin(ctx, STACK_LOGIN, STACK_PASSWORD)))
    } finally {
      await ctx.dispose()
    }
  }, { scope: 'worker' }],

  page: async ({ page }, use) => {
    await page.addInitScript(() => {
      if (!localStorage.getItem('locale')) localStorage.setItem('locale', 'en')
    })
    await use(page)
  },

  isStack: async ({ e2eMode }, use) => { await use(e2eMode === 'stack') },

  mockApi: [async ({ page, e2eMode, modules }, use, testInfo) => {
    if (e2eMode === 'stack') { await use(stackGuard()); return }
    const api = new MockApi({ modules })
    await api.install(page)
    await use(api)
    api.releaseAll()
    if (api.unhandled.length) {
      await testInfo.attach('unhandled-api-calls.txt', { body: [...new Set(api.unhandled)].join('\n'), contentType: 'text/plain' })
    }
  }, { auto: true }],

  network: async ({ page, e2eMode, mockApi }, use) => {
    const network = new Network(page, e2eMode === 'mock' ? mockApi : null)
    await use(network)
    network.releaseAll()
  },

  realtime: [async ({ page, e2eMode }, use) => {
    const rt = new FakeRealtime()
    if (e2eMode === 'mock') await rt.install(page)
    await use(rt)
  }, { auto: true }],

  auth: async ({ page, e2eMode, mockApi, stackSession }, use) => {
    const isMock = e2eMode === 'mock'
    const credentials = isMock
      ? { login: mockApi.db.user.email, username: mockApi.db.user.username, password: mockApi.db.password }
      : { login: STACK_LOGIN, username: STACK_USERNAME, password: STACK_PASSWORD }
    await use({
      credentials,
      async signIn(overrides = {}) {
        const base: Session = isMock
          ? {
              token: `1|e2e-token-${mockApi.db.user.id}`,
              user: mockApi.db.user,
              activeTenant: mockApi.db.activeTenant,
            }
          : stackSession!
        const session = { ...base, ...overrides }
        await useSession(page, session)
        return session
      },
      async loginViaUi(login = {}) {
        await loginViaUi(page, { ...credentials, ...login })
      },
    })
  },

  seed: async ({ e2eMode, mockApi, stackSession, playwright }, use, testInfo) => {
    if (e2eMode === 'mock') {
      await use(new MockSeeder(mockApi))
      return
    }
    const ctx = await playwright.request.newContext({ baseURL: testInfo.project.use.baseURL })
    const seeder = new StackSeeder(ctx, stackSession!)
    try {
      await use(seeder)
    } finally {
      await seeder.cleanup()
      await ctx.dispose()
    }
  },
})

export { expect }
