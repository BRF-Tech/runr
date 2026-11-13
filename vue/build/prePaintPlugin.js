// Vite plugin: index.html's pre-paint script. The `<!-- runr:pre-paint -->` placeholder becomes
// an inline classic script, src/modules/white-label/prePaint.js bundled with its colour helpers
// (by Vite's own build: a library build of its own, without the console's config), so it runs
// before the first paint and derives the theme tones of the platform's branding defaults with the
// same code as the app. In the dev server and in the build alike.
import { build } from 'vite'
import { fileURLToPath } from 'node:url'

export const PRE_PAINT_PLACEHOLDER = '<!-- runr:pre-paint -->'

const whiteLabelDir = fileURLToPath(new URL('../src/modules/white-label/', import.meta.url))

// The script's entry, a module of no file: it runs prePaint() at once
const ENTRY = '\0runr-pre-paint'
const entryCode = `import { prePaint } from ${JSON.stringify(whiteLabelDir + 'prePaint.js')}\n`
  + "try { prePaint() } catch (e) { /* RunR's own theme stays */ }\n"

/** The pre-paint as a classic script's code: an IIFE that runs prePaint() at once. */
export async function prePaintScript() {
  const out = await build({
    configFile: false,
    envFile: false,
    root: whiteLabelDir,
    publicDir: false,
    logLevel: 'silent',
    plugins: [{
      name: 'runr-pre-paint-entry',
      resolveId: (id) => (id === ENTRY ? id : null),
      load: (id) => (id === ENTRY ? entryCode : null),
    }],
    build: {
      write: false,
      minify: true,
      target: 'es2019',
      modulePreload: false,
      copyPublicDir: false,
      reportCompressedSize: false,
      rolldownOptions: { input: ENTRY, output: { format: 'iife', comments: { legal: false } } },
    },
  })
  return (Array.isArray(out) ? out[0] : out).output[0].code.trim()
}

export function prePaintPlugin() {
  return {
    name: 'runr-pre-paint',
    async transformIndexHtml(html) {
      if (!html.includes(PRE_PAINT_PLACEHOLDER)) return html
      return html.replace(PRE_PAINT_PLACEHOLDER, `<script>${await prePaintScript()}</script>`)
    },
  }
}
