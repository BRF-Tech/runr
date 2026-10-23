import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { expect, type APIRequestContext, type Page } from '@playwright/test'
import type { Json } from '../fixtures/data'

// Login helpers.
//   loginViaUi   drives the real login form, incl. the two-factor step
//   useSession   fast path: writes token/user to localStorage before the app boots
//   apiLogin     stack mode: logs in through the real API (POST /api/auth/login)
//   saveStackSession / loadStackSession
//                stack mode: the session e2e/stack.setup.ts signs in once per run, shared by
//                every worker (the login route is rate limited per IP + login, and Playwright
//                starts a fresh worker after each failed test)

/** Stack-mode credentials: the dev-stack administrator (AdminSeeder: ADMIN_EMAIL default in api/config/app.php, ADMIN_PASSWORD in api/.env.docker). */
export const STACK_LOGIN = process.env.E2E_LOGIN || 'admin@example.com'
export const STACK_PASSWORD = process.env.E2E_PASSWORD || 'admin123'
/** The user name of that account (ADMIN_USERNAME); empty when E2E_LOGIN names another account without E2E_USERNAME. */
export const STACK_USERNAME = process.env.E2E_USERNAME || (process.env.E2E_LOGIN ? '' : 'admin')

export interface Session {
  token: string
  user: Json
  activeTenant?: Json | null
  tenants?: Json[]
}

export interface UiLogin {
  /** The user name or the e-mail address. */
  login: string
  password: string
  /** Second factor; when given, the 2FA step is expected and completed. */
  code?: string
}

/** The sign-in field: the user name or the e-mail address. */
export const LOGIN_FIELD = 'E-mail or user name'

export async function loginViaUi(page: Page, { login, password, code }: UiLogin): Promise<void> {
  await page.goto('/login')
  await page.getByLabel(LOGIN_FIELD).fill(login)
  await page.getByPlaceholder('••••••••').fill(password)
  // exact: with the tenancy module the account-type cards read "… Sign in with …"
  await page.getByRole('button', { name: 'Sign In', exact: true }).click()
  if (code !== undefined) {
    await expect(page.getByRole('heading', { name: 'Two-Factor Authentication' })).toBeVisible()
    await page.getByPlaceholder('Verification Code').fill(code)
    await page.getByRole('button', { name: 'Verify', exact: true }).click()
  }
}

/**
 * Seeds the session once per page (the app reads localStorage at boot). A later
 * logout stays logged out: the seed does not repeat on reloads of the same tab.
 */
export async function useSession(page: Page, session: Session): Promise<void> {
  await page.addInitScript((s) => {
    if (sessionStorage.getItem('e2e-session-seeded')) return
    sessionStorage.setItem('e2e-session-seeded', '1')
    localStorage.setItem('token', s.token)
    localStorage.setItem('user', JSON.stringify(s.user))
    if (s.activeTenant) localStorage.setItem('active_tenant', JSON.stringify(s.activeTenant))
    else localStorage.removeItem('active_tenant')
  }, session)
}

export async function apiLogin(request: APIRequestContext, login = STACK_LOGIN, password = STACK_PASSWORD): Promise<Session> {
  const res = await request.post('/api/auth/login', {
    data: { login, password },
    headers: { Accept: 'application/json' },
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok()) {
    throw new Error(`Stack login as ${login} failed (${res.status()}): ${body.message ?? 'no message'} — set E2E_LOGIN / E2E_PASSWORD`)
  }
  if (body.requires_2fa) {
    throw new Error(`Stack login user ${login} has two-factor authentication enabled; point E2E_LOGIN at an account without it`)
  }
  return { token: body.token, user: body.user, activeTenant: body.active_tenant ?? null, tenants: body.tenants ?? [] }
}

interface StoredSession {
  baseURL: string
  login: string
  session: Session
}

/** Where the stack session of a run lives: next to the projects' output directory (not cleaned per run). */
export function stackSessionFile(outputDir: string): string {
  return join(dirname(outputDir), '.auth', 'stack-session.json')
}

export function saveStackSession(outputDir: string, baseURL: string, session: Session): void {
  const file = stackSessionFile(outputDir)
  mkdirSync(dirname(file), { recursive: true })
  const stored: StoredSession = { baseURL, login: STACK_LOGIN, session }
  writeFileSync(file, JSON.stringify(stored))
}

/** The stored session when it belongs to this stack and account and its token still works. */
export async function loadStackSession(request: APIRequestContext, outputDir: string, baseURL: string): Promise<Session | null> {
  let stored: StoredSession
  try {
    stored = JSON.parse(readFileSync(stackSessionFile(outputDir), 'utf8'))
  } catch {
    return null
  }
  if (stored.baseURL !== baseURL || stored.login !== STACK_LOGIN || !stored.session?.token) return null
  const res = await request.get('/api/auth/me', {
    headers: { Accept: 'application/json', Authorization: `Bearer ${stored.session.token}` },
  })
  return res.ok() ? stored.session : null
}

/**
 * The stack account has seen the welcome tour (the mocked user has as well): on a fresh stack
 * the seeded administrator has not, and the tour's dialog would cover the console.
 */
export async function completeTutorial(request: APIRequestContext, session: Session): Promise<Session> {
  if (session.user?.tutorial_completed_at) return session
  const res = await request.post('/api/tutorial/complete', {
    headers: { Accept: 'application/json', Authorization: `Bearer ${session.token}` },
  })
  if (!res.ok()) throw new Error(`POST /api/tutorial/complete → ${res.status()}`)
  return { ...session, user: { ...session.user, tutorial_completed_at: new Date().toISOString() } }
}
