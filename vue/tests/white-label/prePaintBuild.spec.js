// @vitest-environment node
// esbuild (which the plugin bundles with) does not run under jsdom: the built script runs in a
// JSDOM window of its own here.
import { beforeAll, describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { JSDOM } from 'jsdom'
import { PRE_PAINT_PLACEHOLDER, prePaintPlugin, prePaintScript } from '../../build/prePaintPlugin.js'
import { expandPalette } from '@/modules/white-label/utils/color'

// index.html carries a placeholder; the runr-pre-paint Vite plugin puts src/modules/white-label/
// prePaint.js there, bundled into a classic script that runs before the first paint, in the dev
// server and in the build. prePaint.spec.js tests what the script does; this, that the built one
// does it in a page.

const html = readFileSync(resolve(import.meta.dirname, '../../index.html'), 'utf8')

let script
beforeAll(async () => { script = await prePaintScript() })

/** A page with /branding-defaults.js already read and, optionally, a cached branding. */
function page({ defaults = null, cache = null, theme = null } = {}) {
  const dom = new JSDOM('<!doctype html><html><head></head><body></body></html>', { url: 'https://console.example.com/', runScripts: 'outside-only' })
  if (cache) dom.window.localStorage.setItem('branding', JSON.stringify(cache))
  if (theme) {
    dom.window.localStorage.setItem('theme', theme)
    dom.window.localStorage.setItem('theme_chosen', '1')
  }
  dom.window.__RUNR_BRANDING_DEFAULTS__ = defaults
  dom.window.eval(script)
  return dom.window.document
}

describe('the built pre-paint script', () => {
  it('index.html reads /branding-defaults.js, then runs the pre-paint, then the app', () => {
    const defaults = html.indexOf('<script src="/branding-defaults.js"></script>')
    const prePaintAt = html.indexOf(PRE_PAINT_PLACEHOLDER)
    expect(defaults).toBeGreaterThan(0)
    expect(prePaintAt).toBeGreaterThan(defaults)
    expect(html.indexOf('src="/src/main.js"')).toBeGreaterThan(prePaintAt)
    expect(html).toMatch(/<html lang="en">/)
  })

  it('is inlined in place of the placeholder as a plain classic script, small', async () => {
    const out = await prePaintPlugin().transformIndexHtml(html)
    expect(out).not.toContain(PRE_PAINT_PLACEHOLDER)
    expect(out).toContain(`<script>${script}</script>`)
    expect(script).not.toMatch(/\bimport\b|\bexport\b/)
    expect(script).not.toContain('</script')
    // the colour helpers, not Vue or Pinia
    expect(script.length).toBeLessThan(12_000)
  })

  it('opens a first visit in the platform\'s default theme and colours', () => {
    const doc = page({ defaults: { primary_color: '#2563eb', light_color: '#f8fafc', default_theme: 'light' } })
    expect(doc.documentElement.dataset.theme).toBe('light')
    expect(doc.getElementById('runr-branding').textContent).toContain(`--brand-500: ${expandPalette('#2563eb')['500']};`)
  })

  it('keeps the cached branding first and the user\'s pick over both', () => {
    const cached = page({ defaults: { default_theme: 'light' }, cache: { default_theme: 'dark', palette: { 500: '1 2 3' } } })
    expect(cached.documentElement.dataset.theme).toBe('fulldark')
    expect(cached.getElementById('runr-branding').textContent).toContain('--brand-500: 1 2 3;')

    expect(page({ defaults: { default_theme: 'light' }, theme: 'fulldark' }).documentElement.dataset.theme).toBe('fulldark')
  })

  it('without defaults or a cache: RunR\'s dark theme, nothing written', () => {
    const doc = page()
    expect(doc.documentElement.dataset.theme).toBe('fulldark')
    expect(doc.getElementById('runr-branding')).toBeNull()
  })

  it('never stops the page: broken defaults leave RunR\'s theme', () => {
    expect(() => page({ defaults: { dark_scale: { get 950() { throw new Error('boom') } } } })).not.toThrow()
  })
})
