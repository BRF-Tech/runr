import { defineConfig, devices } from '@playwright/test'
import type { E2eOptions } from './e2e/fixtures/test'

// Playwright UI end-to-end tests (vue/e2e/<feature>.spec.ts).
//
//   npm run test:e2e:mock   mocked API: Playwright starts two Vite dev servers and
//                           answers every /api call from an in-memory fake
//                           (project "mock" = core, "mock-modules" = tenancy,
//                           ticket and auth-provider modules enabled, @modules specs)
//   npm run test:e2e        full stack: a running dev stack (docker compose up),
//                           E2E_BASE_URL (default http://localhost:8061 = compose "vue")
//
// Environment:
//   E2E_BASE_URL      stack mode UI URL (default http://localhost:8061)
//   E2E_LOGIN         stack mode login e-mail (default admin@example.com, AdminSeeder)
//   E2E_PASSWORD      stack mode password (default admin123, api/.env.docker)
//   E2E_TENANCY=true  stack mode: the stack runs with the tenancy, auth-provider and ticket modules
//                     (TENANCY_ENABLED, AUTH_PROVIDER_ENABLED, TICKET_ENABLED), also run @modules specs
//   E2E_SEED_DEMO=true stack mode: load the demo data when the stack has no ready region (e2e/stack.setup.ts)
//   E2E_MODE=stack    never start the mock web servers (they also stay off when only
//                     --project=stack runs; --project=mock starts only the mock server)
//   E2E_MOCK_PORT / E2E_MODULES_PORT  ports of the two mock dev servers (4173 / 4174)

const MOCK_PORT = Number(process.env.E2E_MOCK_PORT || 4173)
const MODULES_PORT = Number(process.env.E2E_MODULES_PORT || 4174)
const isCI = !!process.env.CI
const stackTenancy = process.env.E2E_TENANCY === 'true'

// Boot only the dev servers of the projects being run (`--project=stack` boots none).
const argv = process.argv.slice(2).join(' ')
const projectsAsked = [...argv.matchAll(/--project(?:=|\s+)(\S+)/g)].map(m => m[1])
const wants = (name: string) => process.env.E2E_MODE !== 'stack'
  && (projectsAsked.length === 0 || projectsAsked.includes(name))
const webServers = [
  ...(wants('mock') ? [mockServer(MOCK_PORT, false)] : []),
  ...(wants('mock-modules') ? [mockServer(MODULES_PORT, true)] : []),
]

function mockServer(port: number, modules: boolean) {
  const flag = modules ? 'true' : 'false'
  return {
    command: `npx vite --config e2e/vite.config.e2e.ts --port ${port} --strictPort`,
    url: `http://127.0.0.1:${port}`,
    reuseExistingServer: !isCI,
    timeout: 180_000,
    stdout: 'ignore' as const,
    stderr: 'pipe' as const,
    env: {
      E2E_VITE_CACHE_DIR: `node_modules/.vite-e2e-${port}`,
      VITE_API_URL: '/api',
      VITE_TENANCY_ENABLED: flag,
      VITE_TICKET_ENABLED: flag,
      VITE_AUTH_PROVIDER_ENABLED: flag,
      VITE_SENTRY_DSN: '',
    },
  }
}

export default defineConfig<E2eOptions>({
  testDir: './e2e',
  testMatch: '**/*.spec.ts',
  fullyParallel: true,
  forbidOnly: isCI,
  retries: isCI ? 1 : 0,
  workers: isCI ? 2 : undefined,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  outputDir: 'test-results/output',
  reporter: isCI
    ? [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }], ['junit', { outputFile: 'test-results/junit.xml' }]]
    : [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
  use: {
    ...devices['Desktop Chrome'],
    viewport: { width: 1440, height: 900 },
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    locale: 'en-US',
  },
  projects: [
    {
      name: 'mock',
      grepInvert: /@modules/,
      use: { baseURL: `http://127.0.0.1:${MOCK_PORT}`, e2eMode: 'mock', modules: false },
    },
    {
      name: 'mock-modules',
      grep: /@modules/,
      use: { baseURL: `http://127.0.0.1:${MODULES_PORT}`, e2eMode: 'mock', modules: true },
    },
    {
      // Signs the stack account in once and stores the session for the workers (e2e/stack.setup.ts)
      name: 'stack-setup',
      testMatch: /stack\.setup\.ts$/,
      use: { baseURL: process.env.E2E_BASE_URL || 'http://localhost:8061' },
    },
    {
      name: 'stack',
      dependencies: ['stack-setup'],
      ...(stackTenancy ? {} : { grepInvert: /@modules/ }),
      use: {
        baseURL: process.env.E2E_BASE_URL || 'http://localhost:8061',
        e2eMode: 'stack',
        modules: stackTenancy,
      },
    },
  ],
  webServer: webServers.length ? webServers : undefined,
})
