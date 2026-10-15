// Vite dev server for the mocked-API Playwright projects (see playwright.config.ts).
// Each mock web server gets its own dependency cache so two servers can start in
// parallel without racing on node_modules/.vite. The browser never reaches /api:
// every API call is answered by the in-memory fake (e2e/fixtures/mock-api.ts).
import { defineConfig, mergeConfig } from 'vite'
import base from '../vite.config.js'

export default mergeConfig(base, defineConfig({
  cacheDir: process.env.E2E_VITE_CACHE_DIR || 'node_modules/.vite-e2e',
  clearScreen: false,
  server: {
    host: '127.0.0.1',
    strictPort: true,
    hmr: false,
  },
}))
