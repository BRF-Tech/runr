// Vite plugin: Monaco's sanitizer (esm/vs/base/browser/domSanitize.js, which cleans the markdown
// of hovers and suggestions) runs on the dompurify package instead of the DOMPurify copy that
// monaco-editor ships in esm/vs/base/browser/dompurify/dompurify.js: monaco-editor 0.57.0 ships
// DOMPurify 3.4.15, which the DOMPurify advisories up to 3.4.17 cover. That copy is dompurify's
// own ESM build (dist/purify.es.mjs), so the package takes its place one to one; package.json
// names dompurify as a dependency and its `overrides` give monaco-editor the same version, so the
// version npm audit reads is the one in the bundle. In the build and in the dev server's
// dependency pre-bundling alike (both Rolldown since Vite 8).
//
// A build in which the redirect did not happen fails: a monaco-editor that moves or drops its copy
// is looked at (does it still ship an old DOMPurify?) instead of bundling it unnoticed.

/** How domSanitize.js imports monaco-editor's DOMPurify copy. */
export const VENDORED_IMPORT = './dompurify/dompurify.js'

const SANITIZER = /[\\/]monaco-editor[\\/]esm[\\/]vs[\\/]base[\\/]browser[\\/]domSanitize\.js$/

/** Whether `source`, imported by `importer`, is monaco-editor's DOMPurify copy. */
export function isVendoredDompurify(source, importer) {
  return source === VENDORED_IMPORT && typeof importer === 'string' && SANITIZER.test(importer.split('?')[0])
}

/** The same redirect for the dev server's pre-bundling of monaco-editor (a Rolldown plugin). */
export const depsMonacoDompurify = {
  name: 'runr-monaco-dompurify-deps',
  async resolveId(source, importer, options) {
    if (!isVendoredDompurify(source, importer)) return null
    return this.resolve('dompurify', importer, { ...options, skipSelf: true })
  },
}

export function monacoDompurifyPlugin() {
  let command = 'serve'
  let redirected = false
  return {
    name: 'runr-monaco-dompurify',
    enforce: 'pre',
    config: () => ({ optimizeDeps: { rolldownOptions: { plugins: [depsMonacoDompurify] } } }),
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
