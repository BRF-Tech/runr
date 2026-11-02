import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { test, type Page } from '@playwright/test'
import { MockApi } from '../e2e/fixtures/mock-api'
import { FakeRealtime } from '../e2e/fixtures/realtime'
import { applyShotsDb } from './db'
import { SCENES, type Scene } from './scenes'

// Renders every scene of shots/scenes.ts in the light and the dark theme into landing/shots/
// (<name>-<theme>.png, 1440x900 viewport) and writes landing/shots/manifest.json. Run through
// `npm run shots` (shots/playwright.config.ts); optimize the PNGs afterwards with
// `python shots/optimize.py`.

export const THEMES = ['light', 'dark'] as const
type Theme = (typeof THEMES)[number]

const __dirname = dirname(fileURLToPath(import.meta.url))
const OUT_DIR = resolve(__dirname, '../../landing/shots')
const MANIFEST = resolve(OUT_DIR, 'manifest.json')
const VIEWPORT = { width: 1440, height: 900 }

interface ManifestEntry {
  name: string
  title: string
  page: string
  taken: string
  viewport: string
  theme_files: Partial<Record<Theme, string>>
  source: string
  missing?: string
}

function readManifest(): Record<string, ManifestEntry> {
  try {
    const parsed = JSON.parse(readFileSync(MANIFEST, 'utf8'))
    return Object.fromEntries((parsed.scenes ?? []).map((s: ManifestEntry) => [s.name, s]))
  } catch {
    return {}
  }
}

function writeManifest(entries: Record<string, ManifestEntry>): void {
  const scenes = SCENES.map(s => entries[s.name]).filter(Boolean)
  const payload = {
    generated: new Date().toISOString(),
    source: 'mocked API',
    source_detail: 'The real console UI (vue/) rendered on the in-browser mocked API of the e2e suite (vue/e2e/fixtures/mock-api.ts) with the fictional dataset of vue/shots/db.ts: no backend, no cluster. Numbers that read as measurements (sandbox start times) follow the README section "Measured cold starts"; everything else is the fictional organization Northwind.',
    not_live: 'No kind region was set up for these shots: the live frames (rollout, logs, environments, sandboxes, models, MCP, global DNS, supply chain) need Knative, a registry, Kata/gVisor and a GPU node on the cluster, which the WSL host of the run (26 GB free, no nested virtualization for Kata, no GPU) cannot provide.',
    viewport: `${VIEWPORT.width}x${VIEWPORT.height}`,
    device_scale_factor: 1,
    locale: 'en',
    themes: [...THEMES],
    scenes,
  }
  mkdirSync(OUT_DIR, { recursive: true })
  writeFileSync(MANIFEST, JSON.stringify(payload, null, 2) + '\n')
}

/** Signs the showcase user in and picks the locale and theme before the app boots. */
async function boot(page: Page, api: MockApi, theme: Theme): Promise<void> {
  await page.addInitScript(({ theme, user }) => {
    localStorage.setItem('locale', 'en')
    localStorage.setItem('theme', theme)
    localStorage.setItem('token', '1|shots-session')
    localStorage.setItem('user', JSON.stringify(user))
    localStorage.removeItem('active_tenant')
  }, { theme, user: api.db.user })
  await api.install(page)
  await new FakeRealtime().install(page)
}

async function settle(page: Page): Promise<void> {
  await page.evaluate(() => (document as any).fonts?.ready)
  await page.mouse.move(VIEWPORT.width - 4, VIEWPORT.height - 4)
  await page.waitForLoadState('networkidle').catch(() => {})
  await page.waitForTimeout(400)
}

for (const scene of SCENES) {
  test.describe(scene.name, () => {
    for (const theme of THEMES) {
      test(`${scene.name} (${theme})`, async ({ page }, testInfo) => {
        const api = new MockApi()
        applyShotsDb(api.db)
        scene.seed?.(api)
        await boot(page, api, theme)

        const entries = readManifest()
        const entry: ManifestEntry = entries[scene.name] ?? {
          name: scene.name, title: scene.title, page: scene.page, taken: '', viewport: `${VIEWPORT.width}x${VIEWPORT.height}`,
          theme_files: {}, source: 'mocked API',
        }
        try {
          await scene.prepare(page, api)
          await settle(page)
          mkdirSync(OUT_DIR, { recursive: true })
          const file = `${scene.name}-${theme}.png`
          await page.screenshot({ path: resolve(OUT_DIR, file), animations: 'disabled', caret: 'hide' })
          entry.theme_files[theme] = file
          entry.taken = new Date().toISOString()
          delete entry.missing
        } catch (error) {
          delete entry.theme_files[theme]
          entry.missing = `${theme}: ${(error as Error).message.split('\n')[0]}`
          throw error
        } finally {
          entries[scene.name] = { ...entry, title: scene.title, page: scene.page }
          writeManifest(entries)
          if (api.unhandled.length) {
            await testInfo.attach('unhandled-api-calls.txt', { body: [...new Set(api.unhandled)].join('\n'), contentType: 'text/plain' })
          }
        }
      })
    }
  })
}
