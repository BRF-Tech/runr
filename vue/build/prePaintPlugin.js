// Vite plugin: index.html's pre-paint script. The `<!-- runr:pre-paint -->` placeholder becomes
// an inline classic script, src/modules/white-label/prePaint.js bundled with its colour helpers
// (esbuild, the bundler Vite itself runs on), so it runs before the first paint and derives the
// theme tones of the platform's branding defaults with the same code as the app. In the dev
// server and in the build alike.
import { build } from 'esbuild'
import { fileURLToPath } from 'node:url'
import { dirname } from 'node:path'

export const PRE_PAINT_PLACEHOLDER = '<!-- runr:pre-paint -->'

const whiteLabelDir = fileURLToPath(new URL('../src/modules/white-label/', import.meta.url))

/** The pre-paint as a classic script's code: an IIFE that runs prePaint() at once. */
export async function prePaintScript() {
  const result = await build({
    stdin: {
      contents: "import { prePaint } from './prePaint.js'\ntry { prePaint() } catch (e) { /* RunR's own theme stays */ }\n",
      resolveDir: dirname(whiteLabelDir + 'prePaint.js'),
      loader: 'js',
    },
    bundle: true,
    write: false,
    format: 'iife',
    minify: true,
    target: 'es2019',
    legalComments: 'none',
  })
  return result.outputFiles[0].text.trim()
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
