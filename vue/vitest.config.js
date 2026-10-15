import { defineConfig, mergeConfig } from 'vitest/config'
import viteConfig from './vite.config.js'

export default mergeConfig(viteConfig, defineConfig({
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.spec.js'],
    setupFiles: ['tests/setup.js'],
    // Every test starts with empty call histories (vi.fn() mocks are module-level in most
    // specs; restoreMocks alone only restores vi.spyOn spies since Vitest 3).
    clearMocks: true,
    restoreMocks: true,
    coverage: {
      provider: 'v8',
      include: ['src/**/*.{js,vue}'],
      // fixtures and helpers of the specs, not application code
      exclude: ['src/test-support/**'],
      reporter: ['text-summary', 'text', 'html', 'cobertura'],
      reportsDirectory: 'coverage',
      // Floor for `npm run test:coverage` (CI): the measured coverage rounded down to whole
      // percent. Raise it with the coverage; a change that lowers it needs tests.
      thresholds: { statements: 66, branches: 61, functions: 55, lines: 68 },
    },
  },
}))
