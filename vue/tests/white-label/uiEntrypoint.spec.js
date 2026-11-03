// @vitest-environment node
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'

// vue/docker-entrypoint.sh: the ui container's start writes the platform's branding defaults
// (the BRANDING_* keys of runr-config the Helm chart passes, the API's) to /branding-defaults.js
// for the pre-paint script, then runs Caddy. Only checked values reach the file: it is a script
// the console runs. The image's shell is BusyBox sh; any POSIX sh runs it here (CI: Linux).

const entrypoint = resolve(import.meta.dirname, '../../docker-entrypoint.sh')
const hasSh = spawnSync('sh', ['-c', 'exit 0']).status === 0

let root
beforeEach(() => { root = mkdtempSync(join(tmpdir(), 'runr-ui-')) })
afterEach(() => rmSync(root, { recursive: true, force: true }))

/** Runs the entrypoint with `env` (and `echo started` as the command); the defaults it wrote. */
function start(env) {
  const result = spawnSync('sh', [entrypoint, 'echo', 'started'], {
    env: { PATH: process.env.PATH, RUNR_UI_ROOT: root, ...env },
    encoding: 'utf8',
  })
  const file = readFileSync(join(root, 'branding-defaults.js'), 'utf8')
  const win = {}
  new Function('window', file)(win)
  return { result, defaults: win.__RUNR_BRANDING_DEFAULTS__, file }
}

// Each run starts a few dozen processes (grep, printenv): milliseconds on Linux, seconds on a
// busy Windows machine with Git Bash
describe.skipIf(!hasSh)('the ui entrypoint', { timeout: 60_000 }, () => {
  it('writes the branding defaults and starts the command', () => {
    const { result, defaults } = start({
      BRANDING_PRIMARY_COLOR: '#2563eb',
      BRANDING_PRIMARY_SCALE: '',
      BRANDING_ACCENT_COLOR: '#E11D48',
      BRANDING_DARK_COLOR: '#101418',
      BRANDING_DARK_SCALE: '',
      BRANDING_LIGHT_COLOR: '',
      BRANDING_LIGHT_SCALE: '{"600":"#475569","950":"248 250 252"}',
      BRANDING_DEFAULT_THEME: 'light',
    })
    expect(result.status).toBe(0)
    expect(result.stdout.trim()).toBe('started')
    expect(defaults).toEqual({
      primary_color: '#2563eb', accent_color: '#E11D48', dark_color: '#101418',
      light_scale: { 600: '#475569', 950: '248 250 252' }, default_theme: 'light',
    })
  })

  it('takes the shade maps Helm writes (toJson), rgb() and "r, g, b" values too', () => {
    const { defaults } = start({ BRANDING_DARK_SCALE: '{"50":"rgb(252, 253, 255)","900":"15, 36, 96","950":"9 27 78"}' })
    expect(defaults).toEqual({ dark_scale: { 50: 'rgb(252, 253, 255)', 900: '15, 36, 96', 950: '9 27 78' } })
  })

  it('turns an "r g b" colour (a shade map\'s 500, as the chart writes it) into #rrggbb', () => {
    const { defaults, result } = start({
      BRANDING_PRIMARY_COLOR: '29 100 199',
      BRANDING_ACCENT_COLOR: 'rgb(225, 29, 72)',
      BRANDING_DARK_COLOR: '016, 020, 024',
      BRANDING_LIGHT_COLOR: '256 0 0',
    })
    expect(defaults).toEqual({ primary_color: '#1d64c7', accent_color: '#e11d48', dark_color: '#101418' })
    expect(result.stderr).toContain('BRANDING_LIGHT_COLOR')
    expect(result.stderr).not.toContain('BRANDING_PRIMARY_COLOR')
  })

  it('writes null without branding (an install with RunR\'s own)', () => {
    const { defaults, result } = start({ BRANDING_PRIMARY_SCALE: 'null', BRANDING_DARK_SCALE: '{}', BRANDING_DEFAULT_THEME: '' })
    expect(defaults).toBeNull()
    expect(result.stderr).toBe('')
  })

  it('leaves out what is not a colour, a shade map or a theme, and says so', () => {
    const { defaults, result, file } = start({
      BRANDING_PRIMARY_COLOR: '"};alert(1);//',
      BRANDING_ACCENT_COLOR: 'red',
      BRANDING_DARK_SCALE: '{"950":"9 27 78"};alert(1);({"a":"b"}',
      BRANDING_LIGHT_SCALE: '{"950":"</script><script>alert(1)</script>"}',
      BRANDING_DEFAULT_THEME: 'sepia',
      BRANDING_LIGHT_COLOR: '#fffcf8',
    })
    expect(defaults).toEqual({ light_color: '#fffcf8' })
    expect(file).not.toContain('alert')
    for (const name of ['BRANDING_PRIMARY_COLOR', 'BRANDING_ACCENT_COLOR', 'BRANDING_DARK_SCALE', 'BRANDING_LIGHT_SCALE', 'BRANDING_DEFAULT_THEME']) {
      expect(result.stderr).toContain(name)
    }
  })

  it('still starts when the file cannot be written (a read-only root file system)', () => {
    writeFileSync(join(root, 'branding-defaults.js'), 'window.__RUNR_BRANDING_DEFAULTS__ = null\n')
    const result = spawnSync('sh', [entrypoint, 'echo', 'started'], {
      env: { PATH: process.env.PATH, RUNR_UI_ROOT: join(root, 'missing', 'dir'), BRANDING_DEFAULT_THEME: 'light' },
      encoding: 'utf8',
    })
    expect(result.status).toBe(0)
    expect(result.stdout.trim()).toBe('started')
    expect(result.stderr).toContain('could not write')
  })
})
