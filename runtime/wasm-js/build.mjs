// Bundles the function (index.ts when the project has one, else index.js) with esbuild and
// componentizes it into dist/app.wasm (Spin JS SDK, StarlingMonkey). Run by `spin build`.
import { existsSync } from 'node:fs'
import { build } from 'esbuild'
import { SpinEsbuildPlugin } from '@spinframework/build-tools/plugins/esbuild'

const entry = ['./index.ts', './index.mjs', './index.js'].find((f) => existsSync(f)) ?? './index.js'

await build({
  entryPoints: [entry],
  outfile: './build/bundle.js',
  bundle: true,
  format: 'esm',
  platform: 'browser',
  sourcemap: true,
  minify: false,
  resolveExtensions: ['.ts', '.mjs', '.js'],
  plugins: [await SpinEsbuildPlugin({
    componentize: {
      debug: process.argv.includes('--debug'),
      output: './dist/app.wasm',
      initLocation: 'http://runr-function.localhost',
    },
  })],
})
