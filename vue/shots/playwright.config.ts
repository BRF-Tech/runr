import { defineConfig, devices } from '@playwright/test'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))

// Showcase screenshots of the console (landing/shots/*.png, docs: shots/README.md).
//
//   npm run shots                 renders every scene in both themes
//   npm run shots -- -g projects  one scene
//
// The console runs on the mocked-API Vite server of the e2e suite (e2e/vite.config.e2e.ts) and
// every /api call is answered in the browser by e2e/fixtures/mock-api.ts with the dataset of
// shots/db.ts: no backend, no cluster, the same pixels for every run. The scenes live in
// shots/scenes.ts, the runner in shots/shots.spec.ts, the PNG and WebP step (run it after
// every render: `python shots/optimize.py`) in shots/optimize.py.

const PORT = Number(process.env.SHOTS_PORT || 4175)

export default defineConfig({
  testDir: '.',
  testMatch: /shots\.spec\.ts$/,
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 90_000,
  expect: { timeout: 15_000 },
  outputDir: resolve(__dirname, '../test-results/shots'),
  reporter: [['list']],
  use: {
    ...devices['Desktop Chrome'],
    baseURL: `http://127.0.0.1:${PORT}`,
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 1,
    locale: 'en-US',
    timezoneId: 'Europe/Berlin',
    colorScheme: 'light',
    trace: 'retain-on-failure',
  },
  webServer: {
    command: `npx vite --config e2e/vite.config.e2e.ts --port ${PORT} --strictPort`,
    cwd: resolve(__dirname, '..'),
    url: `http://127.0.0.1:${PORT}`,
    reuseExistingServer: true,
    timeout: 180_000,
    stdout: 'ignore',
    stderr: 'pipe',
    env: {
      E2E_VITE_CACHE_DIR: `node_modules/.vite-shots-${PORT}`,
      VITE_API_URL: '/api',
      VITE_TENANCY_ENABLED: 'false',
      VITE_TICKET_ENABLED: 'false',
      VITE_AUTH_PROVIDER_ENABLED: 'false',
      VITE_BILLING_ENABLED: 'false',
      VITE_SENTRY_DSN: '',
    },
  },
})
