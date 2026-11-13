// @vitest-environment node
// Bundles monaco-editor's sanitizer the two ways Vite does (the build, and the dev server's
// pre-bundling of dependencies; both Rolldown since Vite 8) and reads which DOMPurify ends up in the
// output.
import { describe, expect, it } from 'vitest'
import { mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { build as viteBuild, createServer } from 'vite'
import { VENDORED_IMPORT, isVendoredDompurify, monacoDompurifyPlugin } from '../../build/monacoDompurifyPlugin.js'

const root = resolve(import.meta.dirname, '../..')
const sanitizer = resolve(root, 'node_modules/monaco-editor/esm/vs/base/browser/domSanitize.js')
const installed = JSON.parse(readFileSync(resolve(root, 'node_modules/dompurify/package.json'), 'utf8')).version
const vendored = readFileSync(resolve(root, 'node_modules/monaco-editor/esm/vs/base/browser/dompurify/dompurify.js'), 'utf8')
  .match(/DOMPurify\.version = ['"]([^'"]+)['"]/)[1]

/** The DOMPurify versions in bundled code. */
const versionsIn = (code) => [...code.matchAll(/DOMPurify\.version = ['"]([^'"]+)['"]/g)].map((m) => m[1])

/** domSanitize.js bundled by Vite's build (its other imports left out), with the given plugins. */
async function buildBundle(plugins, input = sanitizer) {
  const out = await viteBuild({
    configFile: false,
    envFile: false,
    root,
    logLevel: 'silent',
    plugins,
    build: {
      write: false,
      minify: false,
      rolldownOptions: { input, external: (id) => !/dompurify|domSanitize|\0entry|virtual:entry/i.test(id) },
    },
  })
  return (Array.isArray(out) ? out[0] : out).output.map((o) => o.code ?? '').join('\n')
}

/** domSanitize.js pre-bundled by a dev server with the given plugins (its dependency optimizer). */
async function devPreBundle(plugins) {
  const cacheDir = mkdtempSync(join(tmpdir(), 'runr-deps-'))
  try {
    const server = await createServer({
      configFile: false,
      envFile: false,
      root,
      cacheDir,
      logLevel: 'silent',
      plugins,
      server: { middlewareMode: true, ws: false, watch: null },
      optimizeDeps: { include: ['monaco-editor/base/browser/domSanitize.js'], noDiscovery: true, force: true },
    })
    try {
      const optimizer = server.environments.client.depsOptimizer
      await optimizer.init()
      await optimizer.scanProcessing
    } finally {
      await server.close()
    }
    const deps = join(cacheDir, 'deps')
    return readdirSync(deps).filter((f) => f.endsWith('.js')).map((f) => readFileSync(join(deps, f), 'utf8')).join('\n')
  } finally {
    rmSync(cacheDir, { recursive: true, force: true })
  }
}

describe('monaco-editor sanitizes with the dompurify package', () => {
  it('monaco-editor still imports its own copy where the plugin redirects it', () => {
    expect(readFileSync(sanitizer, 'utf8')).toContain(`import purify from '${VENDORED_IMPORT}';`)
    expect(isVendoredDompurify(VENDORED_IMPORT, sanitizer)).toBe(true)
    expect(isVendoredDompurify(VENDORED_IMPORT, resolve(root, 'src/main.js'))).toBe(false)
    expect(isVendoredDompurify('dompurify', sanitizer)).toBe(false)
  })

  it('the build bundles the installed dompurify, not monaco-editor\'s copy', async () => {
    expect(versionsIn(await buildBundle([monacoDompurifyPlugin()]))).toEqual([installed])
    // what the build ships without the plugin: the copy, whatever package.json pins
    expect(versionsIn(await buildBundle([]))).toEqual([vendored])
  })

  it('the dev server\'s pre-bundling bundles the installed dompurify as well', async () => {
    expect(versionsIn(await devPreBundle([monacoDompurifyPlugin()]))).toEqual([installed])
    // what pre-bundling serves without the plugin: the copy
    expect(versionsIn(await devPreBundle([]))).toEqual([vendored])
  })

  it('fails a build in which monaco-editor\'s copy was never redirected', async () => {
    const entry = {
      name: 'entry',
      resolveId: (id) => (id === 'virtual:entry' ? '\0entry' : null),
      load: (id) => (id === '\0entry' ? 'export default 1' : null),
    }
    await expect(buildBundle([entry, monacoDompurifyPlugin()], 'virtual:entry')).rejects.toThrow(/no longer imports/)
  })
})
