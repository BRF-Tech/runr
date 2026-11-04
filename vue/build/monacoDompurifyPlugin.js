// Vite plugin: Monaco's sanitizer (esm/vs/base/browser/domSanitize.js, which cleans the markdown
// of hovers and suggestions) runs on the dompurify package instead of the DOMPurify copy that
// monaco-editor ships in esm/vs/base/browser/dompurify/dompurify.js: monaco-editor 0.57.0 ships
// DOMPurify 3.4.15, which the DOMPurify advisories up to 3.4.17 cover. That copy is dompurify's
// own ESM build (dist/purify.es.mjs), so the package takes its place one to one; package.json
// names dompurify as a dependency and its `overrides` give monaco-editor the same version, so the
// version npm audit reads is the one in the bundle. In the build and in the dev server's
// dependency pre-bundling (esbuild) alike.
//
// A build in which the redirect did not happen fails: a monaco-editor that moves or drops its copy
// is looked at (does it still ship an old DOMPurify?) instead of bundling it unnoticed.
import { dirname } from 'node:path'

/** How domSanitize.js imports monaco-editor's DOMPurify copy. */
export const VENDORED_IMPORT = './dompurify/dompurify.js'

const SANITIZER = /[\\/]monaco-editor[\\/]esm[\\/]vs[\\/]base[\\/]browser[\\/]domSanitize\.js$/

/** Whether `source`, imported by `importer`, is monaco-editor's DOMPurify copy. */
export function isVendoredDompurify(source, importer) {
  return source === VENDORED_IMPORT && typeof importer === 'string' && SANITIZER.test(importer.split('?')[0])
}

/** The same redirect for esbuild, which pre-bundles monaco-editor for the dev server. */
export const esbuildMonacoDompurify = {
  name: 'runr-monaco-dompurify',
  setup(build) {
    build.onResolve({ filter: /^\.\/dompurify\/dompurify\.js$/ }, async (args) => {
      if (!isVendoredDompurify(args.path, args.importer)) return undefined
      const resolved = await build.resolve('dompurify', { kind: args.kind, resolveDir: dirname(args.importer) })
      return resolved.errors.length ? { errors: resolved.errors } : { path: resolved.path }
    })
  },
}

export function monacoDompurifyPlugin() {
  let command = 'serve'
  let redirected = false
  return {
    name: 'runr-monaco-dompurify',
    enforce: 'pre',
    config: () => ({ optimizeDeps: { esbuildOptions: { plugins: [esbuildMonacoDompurify] } } }),
    configResolved(config) { command = config.command },
    async resolveId(source, importer, options) {
      if (!isVendoredDompurify(source, importer)) return null
      const resolved = await this.resolve('dompurify', importer, { ...options, skipSelf: true })
      if (!resolved) this.error(`dompurify is not installed: it replaces monaco-editor's DOMPurify copy (${importer})`)
      redirected = true
      return resolved
    },
    buildEnd(error) {
      if (error || command !== 'build' || redirected) return
      this.error(`monaco-editor's sanitizer no longer imports ${VENDORED_IMPORT}: check which DOMPurify the editor ships (build/monacoDompurifyPlugin.js)`)
    },
  }
}
