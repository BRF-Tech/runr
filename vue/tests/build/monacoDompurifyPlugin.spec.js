// @vitest-environment node
// Bundles monaco-editor's sanitizer with the bundlers Vite runs (Rollup for the build, esbuild for
// the dev server's pre-bundling) and reads which DOMPurify ends up in the output.
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { build as viteBuild } from 'vite'
import { build as esbuild } from 'esbuild'
import { VENDORED_IMPORT, esbuildMonacoDompurify, isVendoredDompurify, monacoDompurifyPlugin } from '../../build/monacoDompurifyPlugin.js'

const root = resolve(import.meta.dirname, '../..')
const sanitizer = resolve(root, 'node_modules/monaco-editor/esm/vs/base/browser/domSanitize.js')
const installed = JSON.parse(readFileSync(resolve(root, 'node_modules/dompurify/package.json'), 'utf8')).version
const vendored = readFileSync(resolve(root, 'node_modules/monaco-editor/esm/vs/base/browser/dompurify/dompurify.js'), 'utf8')
  .match(/DOMPurify\.version = ['"]([^'"]+)['"]/)[1]

/** The DOMPurify versions in bundled code. */
const versionsIn = (code) => [...code.matchAll(/DOMPurify\.version = ['"]([^'"]+)['"]/g)].map((m) => m[1])

/** domSanitize.js bundled by Vite's build (its other imports left out), with the given plugins. */
async function rollupBundle(plugins, input = sanitizer) {
  const out = await viteBuild({
    configFile: false,
    envFile: false,
    root,
    logLevel: 'silent',
    plugins,
    build: {
      write: false,
      minify: false,
      rollupOptions: { input, external: (id) => !/dompurify|domSanitize|\0entry|virtual:entry/i.test(id) },
    },
  })
  return (Array.isArray(out) ? out[0] : out).output.map((o) => o.code ?? '').join('\n')
}

describe('monaco-editor sanitizes with the dompurify package', () => {
  it('monaco-editor still imports its own copy where the plugin redirects it', () => {
    expect(readFileSync(sanitizer, 'utf8')).toContain(`import purify from '${VENDORED_IMPORT}';`)
    expect(isVendoredDompurify(VENDORED_IMPORT, sanitizer)).toBe(true)
    expect(isVendoredDompurify(VENDORED_IMPORT, resolve(root, 'src/main.js'))).toBe(false)
    expect(isVendoredDompurify('dompurify', sanitizer)).toBe(false)
  })

  it('the build bundles the installed dompurify, not monaco-editor\'s copy', async () => {
    expect(versionsIn(await rollupBundle([monacoDompurifyPlugin()]))).toEqual([installed])
    // what the build ships without the plugin: the copy, whatever package.json pins
    expect(versionsIn(await rollupBundle([]))).toEqual([vendored])
  })

  it('the dev server\'s pre-bundling bundles the installed dompurify as well', async () => {
    const leaveOut = {
      name: 'leave-out',
      setup(b) {
        b.onResolve({ filter: /^\.\.?\// }, (args) => (args.path.includes('dompurify') ? undefined : { path: args.path, external: true }))
      },
    }
    const out = await esbuild({ entryPoints: [sanitizer], bundle: true, write: false, format: 'esm', plugins: [leaveOut, esbuildMonacoDompurify] })
    expect(versionsIn(out.outputFiles[0].text)).toEqual([installed])
  })

  it('fails a build in which monaco-editor\'s copy was never redirected', async () => {
    const entry = {
      name: 'entry',
      resolveId: (id) => (id === 'virtual:entry' ? '\0entry' : null),
      load: (id) => (id === '\0entry' ? 'export default 1' : null),
    }
    await expect(rollupBundle([entry, monacoDompurifyPlugin()], 'virtual:entry')).rejects.toThrow(/no longer imports/)
  })
})
