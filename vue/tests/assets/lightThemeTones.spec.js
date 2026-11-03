import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import postcss from 'postcss'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import DeploymentTable from '@/components/DeploymentTable.vue'
import SandboxStatusBadge from '@/modules/sandboxes/components/SandboxStatusBadge.vue'
import SeverityBadges from '@/modules/supply-chain/components/SeverityBadges.vue'
import { RUNR_DARK, RUNR_LIGHT, SURFACE_SHADES } from '@/modules/white-label/utils/color'
import { withPlugins } from '../helpers'

vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRouter: () => ({ push: vi.fn() }), useRoute: () => ({ query: {} }) }))

// The dark theme tints badges, chips and notices with a dark background, light text and a dark
// border (bg-brand-900/40 text-brand-300 border-brand-800). main.css swaps those tones on the light
// theme, one family of the palette at a time: a family it missed (sky, until the screenshot run)
// left light text on a dark tint, unreadable. Every tint tone the console uses needs its light
// rule, and the result has to read (WCAG AA, 4.5:1).

// Vitest hands CSS imports over empty: the stylesheet is read from disk
const mainCss = readFileSync(resolve(import.meta.dirname, '../../src/assets/main.css'), 'utf8')
const sources = import.meta.glob(['/src/**/*.vue', '/src/**/*.js'], { query: '?raw', import: 'default', eager: true })

const FAMILIES = 'brand|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose'
const TINTS = `bg-(?:${FAMILIES})-(?:900|950)|text-(?:${FAMILIES})-(?:100|200|300|400)|border-(?:${FAMILIES})-(?:800|900)`
// A class token as written (not a variant of one: hover:text-red-400 is the hover state)
const TOKEN = new RegExp(`(?<![\\w:/\\[-])(${TINTS})(?:\\/\\d+)?(?![\\w-])`, 'g')
const HOVER_TOKEN = new RegExp(`(?<![\\w/\\[-])hover:(${TINTS})(?:\\/\\d+)?(?![\\w-])`, 'g')

// Tones that sit on a surface that is dark on every theme (none: the login page's dark panel
// takes its colours from the --surface-dark-* tones, not from classes)
const ALWAYS_DARK = {}

const PROPERTY = { bg: 'background-color', text: 'color', border: 'border-color' }

/** The light theme's rules of main.css: { selector, decls } in source order. */
const lightRules = (() => {
  const rules = []
  postcss.parse(mainCss).walkRules((rule) => {
    if (!rule.selector.startsWith('[data-theme="light"]')) return
    const decls = {}
    rule.walkDecls((d) => { decls[d.prop] = d.value })
    rules.push({ selector: rule.selector, decls })
  })
  return rules
})()

let root
afterEach(() => {
  root?.remove()
  root = null
})

/** The element under a light-theme root, in the document (descendant selectors need one). */
function inLightTheme(el) {
  root?.remove()
  root = document.createElement('div')
  root.dataset.theme = 'light'
  root.appendChild(el)
  document.body.appendChild(root)
  return el
}

/** The light-theme value of each property for the element: the last matching rule wins. */
function lightValues(el) {
  const out = {}
  for (const rule of lightRules) {
    if (!el.matches(rule.selector)) continue
    Object.assign(out, rule.decls)
  }
  return out
}

function lightValuesFor(className) {
  const el = document.createElement('span')
  el.className = className
  const values = lightValues(inLightTheme(el))
  root.remove()
  root = null
  return values
}

/** Every tint token of the console's sources: token → the files that use it. */
function tintTokens(pattern) {
  const found = {}
  for (const [file, source] of Object.entries(sources)) {
    for (const m of source.matchAll(pattern)) (found[m[1]] ??= new Set()).add(file)
  }
  return found
}

// ── Contrast (WCAG 2.1) ───────────────────────────────────────────────────────────────────────

const BRAND = { '--brand-300': [253, 186, 116], '--brand-700': [194, 65, 12] }
// The light theme's page (gray-950) and text (gray-100) tones
const WHITE = [255, 255, 255]

/** rgb(r g b / a), rgb(var(--brand-300) / a) → [r, g, b, a] */
function parseRgb(value) {
  const brand = value.match(/var\((--brand-\d+)\)(?:\s*\/\s*([\d.]+))?/)
  if (brand) return [...BRAND[brand[1]], brand[2] ? Number(brand[2]) : 1]
  const m = value.match(/rgb\(\s*(\d+)\s+(\d+)\s+(\d+)(?:\s*\/\s*([\d.]+))?\s*\)/)
  if (!m) throw new Error(`not an rgb() colour: ${value}`)
  return [Number(m[1]), Number(m[2]), Number(m[3]), m[4] ? Number(m[4]) : 1]
}

const over = ([r, g, b, a], base) => [r, g, b].map((c, i) => c * a + base[i] * (1 - a))

function luminance(rgb) {
  const [r, g, b] = rgb.map((c) => {
    const s = c / 255
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

function contrast(a, b) {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (hi + 0.05) / (lo + 0.05)
}

/** Contrast of a badge's text on its background, on the light theme's white page. */
function badgeContrast(values) {
  const bg = values['background-color'] ? over(parseRgb(values['background-color']), WHITE) : WHITE
  return contrast(over(parseRgb(values.color), bg), bg)
}

// ── The console's tint tones ──────────────────────────────────────────────────────────────────

describe('light theme: the tint tones of badges, chips and notices', () => {
  it('every tint tone the console uses has its light rule', () => {
    const missing = []
    for (const [token, files] of Object.entries(tintTokens(TOKEN))) {
      if ([...files].every(f => ALWAYS_DARK[token]?.includes(f))) continue
      const kind = token.split('-')[0]
      if (!lightValuesFor(token)[PROPERTY[kind]]) missing.push(`${token} (${[...files][0]})`)
    }
    expect(missing).toEqual([])
  })

  it('a hover: tone takes its light rule while hovered, and only then', () => {
    const hoverTokens = Object.keys(tintTokens(HOVER_TOKEN))
    expect(hoverTokens).toEqual(expect.arrayContaining(['text-red-400', 'text-brand-300', 'bg-red-900']))
    const selectors = lightRules.map(r => r.selector).join('\n')
    // e.g. text-brand-300 → [class*="hover:text-brand-3"]:hover
    const missing = hoverTokens.filter(token => !selectors.includes(`[class*="hover:${token.slice(0, -2)}"]:hover`))
    expect(missing).toEqual([])
    // not hovered: the element keeps its own tone (an icon button stays gray, not red)
    expect(lightValuesFor('text-gray-400 hover:text-red-400').color).toBeUndefined()
    expect(lightValuesFor('text-gray-100 hover:text-brand-300').color).toBeUndefined()
    expect(lightValuesFor('rounded-md hover:bg-red-900/20')['background-color']).toBeUndefined()
    // the element's own tone next to a hover: one still swaps
    expect(lightValuesFor('text-brand-400 hover:text-brand-300').color).toBe('rgb(var(--brand-700))')
  })

  it('swaps a tone with an opacity modifier, wherever it is in the class list', () => {
    expect(lightValuesFor('bg-brand-900/40')['background-color']).toBe('rgb(var(--brand-300) / 0.15)')
    expect(lightValuesFor('rounded-sm bg-brand-900/40 text-brand-300/90 border-brand-800/50')).toMatchObject({
      'background-color': 'rgb(var(--brand-300) / 0.15)', color: 'rgb(var(--brand-700))', 'border-color': 'rgb(var(--brand-300) / 0.6)',
    })
    // a brand border of the 500 / 600 tones (a selection, an outlined chip, a spinner) stays the brand's
    expect(lightValuesFor('border-brand-500/60 text-brand-400')['border-color']).toBeUndefined()
  })

  it('every family reads on the light theme: its text on its background, 4.5:1 at least', () => {
    const families = [...FAMILIES.split('|'), 'brand']
    const weak = []
    for (const family of families) {
      const values = lightValuesFor(`bg-${family}-900/40 text-${family}-300`)
      if (!values.color) continue // a family the console does not use
      const ratio = badgeContrast(values)
      if (ratio < 4.5) weak.push(`${family}: ${ratio.toFixed(2)}`)
    }
    expect(weak).toEqual([])
  })
})

// ── The badges of the screenshot run ──────────────────────────────────────────────────────────

/** The light-theme contrast of a rendered badge (its classes as the component renders them). */
function renderedContrast(el) {
  const clone = el.cloneNode(true)
  const values = lightValues(inLightTheme(clone))
  // an outlined chip has no background of its own: it sits on the page
  expect(values).toHaveProperty('color')
  return badgeContrast(values)
}

describe('light theme: the badges that did not read', () => {
  it('the restored config badge and the rollout chips of the deployments', () => {
    const inMinutes = (m) => new Date(Date.now() + m * 60_000).toISOString()
    const rollout = (over = {}) => ({
      id: 'r2', project_id: 'p1', deployment_id: 'd2', stable_deployment_id: 'd1', strategy: 'canary', status: 'progressing',
      traffic: { candidate: 10, stable: 90 }, keep_until: null, created_at: inMinutes(-5), ...over,
    })
    const deployment = (id, over = {}) => ({
      id, status: 'active', version: { id: `v-${id}`, label: id }, k8s_job_name: 'orders-api',
      deployed_by: 'Ada', created_at: inMinutes(-10), rollout: null, rollout_role: null, ...over,
    })
    const project = { id: 'p1', name: 'orders-api', service_type: 'faas', config: { trigger_type: 'http' }, deploy_strategy: 'canary' }
    const mountTable = (props) => mount(DeploymentTable, { props: { project, ...props }, global: withPlugins().global })

    const canary = rollout()
    let w = mountTable({
      currentRollout: canary,
      deployments: [
        deployment('d2', { status: 'running', rollout: canary, rollout_role: 'candidate' }),
        deployment('d1', { rollout_role: 'stable', config_source: 'restored' }),
      ],
    })
    expect(renderedContrast(w.get('[data-testid="deployment-restored"]').element)).toBeGreaterThanOrEqual(4.5)
    const chips = w.findAll('[data-test="rollout-chip"]')
    expect(chips.map(c => c.text())).toEqual(['New · canary 10 %', 'Current · 90 %'])
    for (const chip of chips) expect(renderedContrast(chip.element)).toBeGreaterThanOrEqual(4.5)

    // the blue-green waiting for promotion: the outlined chip
    const waiting = rollout({ strategy: 'blue_green', status: 'waiting_promotion', traffic: { candidate: 0, stable: 100 } })
    w = mountTable({
      currentRollout: waiting,
      deployments: [deployment('d2', { status: 'running', rollout: waiting, rollout_role: 'candidate' })],
    })
    expect(w.get('[data-test="rollout-chip"]').text()).toBe('New · waiting for promotion')
    expect(renderedContrast(w.get('[data-test="rollout-chip"]').element)).toBeGreaterThanOrEqual(4.5)
  })

  it('the sandbox Paused status', () => {
    const w = mount(SandboxStatusBadge, { props: { status: 'paused' }, global: withPlugins().global })
    expect(w.attributes('data-status')).toBe('paused')
    expect(renderedContrast(w.element)).toBeGreaterThanOrEqual(4.5)
  })

  it('the supply chain Low severity', () => {
    const w = mount(SeverityBadges, { props: { counts: { critical: 0, high: 0, medium: 0, low: 2, unknown: 0 } }, global: withPlugins().global })
    const low = w.get('[data-severity="low"]')
    expect(low.text()).toContain('2')
    expect(renderedContrast(low.element)).toBeGreaterThanOrEqual(4.5)
  })
})

// ── RunR's colours: no blue, violet, amber or orange left ─────────────────────────────────────
// The console's accents are the brand scale (white-label settable): badges, chips, links,
// highlights and charts; categories are told apart by its shades and styles (solid, tinted,
// outlined). Status keeps its colours: green / emerald for success, yellow for warnings, red for
// errors. The blue, sky, indigo, violet, purple, cyan and teal accents moved to the brand; amber
// and orange warnings to yellow, as they read as the RunR orange; slate (navy-tinted gray) to the
// theme tones.

const RETIRED = 'sky|blue|indigo|violet|purple|cyan|teal|slate|amber|orange'
const RETIRED_CLASS = new RegExp(
  `(?<![\\w-])(?:[a-z-]+:)*(?:bg|text|border(?:-[trblxy])?|ring|ring-offset|from|via|to|fill|stroke|outline|divide|shadow|accent|decoration|caret|placeholder)-(?:${RETIRED})-\\d{2,3}\\b`,
  'g',
)

// Tailwind's values of those families (tailwind-v3-compat.css) as hex and as "r g b". Not orange's:
// Tailwind's orange is RunR's brand scale (the defaults of the white-label code)
const compatCss = readFileSync(resolve(import.meta.dirname, '../../src/assets/tailwind-v3-compat.css'), 'utf8')
const RETIRED_VALUES = RETIRED.split('|').filter(f => f !== 'orange').join('|')
const RETIRED_HEX = [...compatCss.matchAll(new RegExp(`--color-(?:${RETIRED_VALUES})-\\d+:\\s*(#[0-9a-fA-F]{6})`, 'g'))]
  .map(m => m[1].toLowerCase())
const tripletOf = hex => [1, 3, 5].map(i => parseInt(hex.slice(i, i + 2), 16))

// Allowed, and why
const ALLOWED_HEX = {
  // xterm's ANSI colours: a program in the shell prints "blue", "cyan" or "magenta" by number
  '/src/components/ShellTerminalModal.vue': ['#60a5fa', '#93c5fd', '#22d3ee', '#67e8f9', '#c084fc', '#d8b4fe'],
}

const hueOf = ([r, g, b]) => {
  const [R, G, B] = [r, g, b].map(v => v / 255)
  const max = Math.max(R, G, B), min = Math.min(R, G, B), d = max - min
  if (d === 0) return 0
  const h = max === R ? ((G - B) / d) % 6 : max === G ? (B - R) / d + 2 : (R - G) / d + 4
  return (h * 60 + 360) % 360
}

describe("RunR's colours: the retired families are gone", () => {
  it('finds the palette of the retired families', () => {
    expect(RETIRED_HEX.length).toBeGreaterThan(90)
    expect(RETIRED_HEX).toContain('#0ea5e9') // sky-500
    expect(RETIRED_HEX).toContain('#f59e0b') // amber-500
  })

  it('no class of a retired family in the console', () => {
    const found = []
    for (const [file, source] of Object.entries(sources)) {
      for (const m of source.matchAll(RETIRED_CLASS)) found.push(`${file}: ${m[0]}`)
    }
    expect(found).toEqual([])
  })

  it('no colour value of a retired family in the console (inline styles, charts, terminal, SVG)', () => {
    const found = []
    for (const [file, source] of Object.entries(sources)) {
      const text = source.toLowerCase()
      for (const hex of RETIRED_HEX) {
        if (ALLOWED_HEX[file]?.includes(hex)) continue
        const [r, g, b] = tripletOf(hex)
        if (new RegExp(`${hex}(?![0-9a-f])`).test(text) || new RegExp(`(?<!\\d)${r}[ ,]+${g}[ ,]+${b}(?!\\d)`).test(text)) {
          found.push(`${file}: ${hex}`)
        }
      }
    }
    expect(found).toEqual([])
  })

  it('main.css has no light-theme rule left for a retired family', () => {
    const selectors = lightRules.map(r => r.selector).join('\n')
    expect(selectors).not.toMatch(new RegExp(`-(?:${RETIRED})-\\d`))
  })

  it('warnings read apart from the brand on the light theme (yellow text, not orange)', () => {
    const warn = parseRgb(lightValuesFor('text-yellow-300').color)
    // RunR 700 (the light theme's brand text) sits at ~17°, the warning text at ~44°
    expect(hueOf(warn) - hueOf(BRAND['--brand-700'])).toBeGreaterThan(20)
    expect(badgeContrast(lightValuesFor('bg-yellow-900/40 text-yellow-300'))).toBeGreaterThanOrEqual(4.5)
  })
})

// ── The theme tones ───────────────────────────────────────────────────────────────────────────

/** The --<prefix>-* variables of the main.css rule with this selector. */
function cssVars(selector, prefix) {
  const out = {}
  postcss.parse(mainCss).walkRules((rule) => {
    if (rule.selector.split(',').map(s => s.trim()).includes(selector)) {
      rule.walkDecls(new RegExp(`^--${prefix}-\\d`), (d) => { out[d.prop.slice(prefix.length + 3)] = d.value.trim() })
    }
  })
  return out
}

describe('the theme tones of main.css', () => {
  const asTriplets = scale => Object.fromEntries(SURFACE_SHADES.map(s => [s, scale[s].join(' ')]))

  it("are RunR's dark and warm light scales, the ones the white-label code derives from", () => {
    expect(cssVars('[data-theme="fulldark"]', 'gray')).toEqual(asTriplets(RUNR_DARK))
    expect(cssVars('[data-theme="light"]', 'gray')).toEqual(asTriplets(RUNR_LIGHT))
    expect(cssVars(':root', 'surface-dark')).toEqual(asTriplets(RUNR_DARK))
    expect(cssVars(':root', 'surface-light')).toEqual(asTriplets(RUNR_LIGHT))
  })

  it('the dark theme is neutral: no tone with a blue cast (blue at most red + 2), R, G and B within 2', () => {
    const tones = { ...cssVars('[data-theme="fulldark"]', 'gray'), ...Object.fromEntries(Object.entries(cssVars(':root', 'surface-dark')).map(([s, v]) => [`surface ${s}`, v])) }
    for (const [name, rgb] of [...Object.entries(tones), ...SURFACE_SHADES.map(s => [`RUNR_DARK ${s}`, RUNR_DARK[s].join(' ')])]) {
      const [r, g, b] = rgb.split(' ').map(Number)
      expect(b, `${name}: ${rgb}`).toBeLessThanOrEqual(r + 2)
      expect(Math.max(r, g, b) - Math.min(r, g, b), `${name}: ${rgb}`).toBeLessThanOrEqual(2)
    }
    // a near-black page, Tailwind's neutral surfaces
    expect(RUNR_DARK[950]).toEqual([10, 10, 10])
    expect(RUNR_DARK[900]).toEqual([23, 23, 23])
  })

  it('no cool-gray hex of a dark surface in the console (the neutral tones are the variables)', () => {
    const COOL = ['#030712', '#111827', '#1f2937', '#374151', '#4b5563', '#6b7280', '#9ca3af', '#0f172a', '#1e293b']
    const found = []
    for (const [file, source] of Object.entries(sources)) {
      for (const hex of COOL) if (new RegExp(`${hex}(?![0-9a-f])`, 'i').test(source)) found.push(`${file}: ${hex}`)
      if (/(?<!\d)3 7 18(?!\d)|(?<!\d)17 24 39(?!\d)|(?<!\d)31 41 55(?!\d)/.test(source)) found.push(`${file}: cool-gray triplet`)
    }
    expect(found).toEqual([])
  })

  it('the light theme is warm (the RunR orange hue, low saturation) and keeps its contrast', () => {
    for (const shade of SURFACE_SHADES) {
      const [r, g, b] = RUNR_LIGHT[shade]
      const h = hueOf([r, g, b])
      expect(h, `light ${shade}`).toBeGreaterThanOrEqual(15)
      expect(h, `light ${shade}`).toBeLessThanOrEqual(40)
      // red over green over blue: warm, never a blue cast
      expect(r >= g && g >= b, `light ${shade}`).toBe(true)
    }
    const ratio = (a, b) => contrast(a, b)
    expect(ratio(RUNR_LIGHT[100], RUNR_LIGHT[950])).toBeGreaterThan(15)
    expect(ratio(RUNR_LIGHT[600], RUNR_LIGHT[900])).toBeGreaterThanOrEqual(7)
    expect(ratio(RUNR_LIGHT[600], RUNR_LIGHT[800])).toBeGreaterThanOrEqual(4.5)
  })
})
