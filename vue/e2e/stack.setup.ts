import { expect, test as setup } from '@playwright/test'
import { apiLogin, completeTutorial, saveStackSession, STACK_LOGIN, STACK_PASSWORD } from './helpers/auth'

// Stack mode: project "stack" depends on this one (playwright.config.ts). It signs the stack
// account in once per run and stores the session for every worker: the login route is rate
// limited per IP + login (AUTH_RATE_LIMIT_PER_MINUTE), and Playwright starts a fresh worker
// after each failed test, so one login per worker ran into 429 once a few tests had failed.
//
// E2E_SEED_DEMO=true: a stack without a ready region loads the demo data first (POST /api/demo,
// seeded by a queued job — Horizon must run), so the project specs run instead of skipping.

setup('signs in the stack account', async ({ request }, testInfo) => {
  const baseURL = String(testInfo.project.use.baseURL)
  const session = await completeTutorial(request, await apiLogin(request, STACK_LOGIN, STACK_PASSWORD))
  saveStackSession(testInfo.project.outputDir, baseURL, session)

  if (process.env.E2E_SEED_DEMO !== 'true') return
  const headers = { Accept: 'application/json', Authorization: `Bearer ${session.token}` }
  const settings = async () => (await request.get('/api/settings', { headers })).json()
  const ready = (s: { regions?: Array<Record<string, unknown>> }) =>
    (s.regions ?? []).some(r => r.is_active && (r.is_synced || r.is_demo || r.has_kubeconfig))
  if (ready(await settings())) return

  setup.setTimeout(180_000)
  expect((await request.post('/api/demo', { headers })).ok()).toBe(true)
  await expect.poll(async () => (await settings()).demo_status, { timeout: 150_000, intervals: [2_000] }).toBe('loaded')
})
