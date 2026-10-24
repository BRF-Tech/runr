import { describe, it, expect } from 'vitest'
import { parse } from 'vue/compiler-sfc'
import en from '@/locales/en'
import tr from '@/locales/tr'
import * as tours from '@/composables/tutorialSteps'
import regionWizardSource from '@/composables/useRegionWizardTutorial.js?raw'

// The tours (driver.js) point at [data-tour="…"] elements, and useTutorial's filterSteps() drops
// a step whose element is not in the DOM without a word: a renamed or removed target made steps
// vanish unnoticed. Every step's target has to exist in the console, every text in both languages.

const vueSources = import.meta.glob('/src/**/*.vue', { query: '?raw', import: 'default', eager: true })
// The step files name the targets themselves: they are not where a target exists
const jsSources = import.meta.glob(
  ['/src/**/*.js', '!/src/composables/tutorialSteps.js', '!/src/composables/useRegionWizardTutorial.js'],
  { query: '?raw', import: 'default', eager: true },
)

function flatten(obj, prefix = '', out = {}) {
  for (const [k, v] of Object.entries(obj ?? {})) {
    const key = prefix ? `${prefix}.${k}` : k
    if (v && typeof v === 'object' && !Array.isArray(v)) flatten(v, key, out)
    else out[key] = v
  }
  return out
}

const messages = { en: flatten(en), tr: flatten(tr) }

/** data-tour values of the templates: data-tour="x" and the string literals of :data-tour="…". */
function templateTargets() {
  const found = new Set()
  for (const [file, source] of Object.entries(vueSources)) {
    const ast = parse(source, { filename: file }).descriptor.template?.ast
    if (!ast) continue
    const walk = (node) => {
      for (const p of node.type === 1 ? node.props : []) {
        if (p.type === 6 && p.name === 'data-tour' && p.value) found.add(p.value.content)
        if (p.type === 7 && p.name === 'bind' && p.arg?.content === 'data-tour') {
          for (const m of (p.exp?.content ?? '').matchAll(/'([^']+)'|"([^"]+)"/g)) found.add(m[1] ?? m[2])
        }
      }
      for (const child of node.children ?? []) walk(child)
    }
    walk(ast)
  }
  return found
}

/** data-tour values registered in code (settings tabs of the modules, the menu's core links). */
function registeredTargets() {
  const found = new Set()
  for (const source of Object.values(jsSources)) {
    for (const m of source.matchAll(/\bdataTour:\s*'([^']+)'/g)) found.add(m[1])
  }
  return found
}

const targets = new Set([...templateTargets(), ...registeredTargets()])

/** The steps of a tour of tutorialSteps.js with the i18n keys its texts use. */
function tour(fn) {
  const keys = []
  const steps = fn((key) => { keys.push(key); return key })
  return { steps, keys }
}

const flows = Object.entries(tours).filter(([, fn]) => typeof fn === 'function')
const flowNames = flows.map(([name]) => name)

// The region wizard's tour lives in its composable (it moves the wizard along)
const regionTargets = [...regionWizardSource.matchAll(/element:\s*'\[data-tour="([^"]+)"\]'/g)].map(m => m[1])
const regionKeys = [...regionWizardSource.matchAll(/\bt\(\s*'(tutorial\.[^']+)'/g)].map(m => m[1])

const SELECTOR = /^\[data-tour="([^"]+)"\]$/

describe('tutorial steps', () => {
  it('are found, and so are the targets of the console', () => {
    expect(flowNames.length).toBeGreaterThan(10)
    expect(regionTargets.length).toBe(7)
    expect(targets.size).toBeGreaterThan(80)
  })

  it.each(flowNames)('%s: every step points at a data-tour the console has', (name) => {
    const { steps } = tour(tours[name])
    expect(steps.length).toBeGreaterThan(0)
    const wrong = steps
      .filter(step => step.element)
      .map(step => step.element)
      .filter(element => !SELECTOR.test(element) || !targets.has(element.match(SELECTOR)[1]))
    expect(wrong).toEqual([])
  })

  it('the region wizard: every step points at a data-tour the console has', () => {
    expect(regionTargets.filter(target => !targets.has(target))).toEqual([])
  })

  it.each(['en', 'tr'])('%s: every text a tour uses exists', (lang) => {
    const used = [...new Set([...flows.flatMap(([, fn]) => tour(fn).keys), ...regionKeys])]
    expect(used.every(key => key.startsWith('tutorial.'))).toBe(true)
    expect(used.filter(key => typeof messages[lang][key] !== 'string' || !messages[lang][key].trim())).toEqual([])
  })

  it('every tutorial text of the locales is used', () => {
    const used = new Set([...flows.flatMap(([, fn]) => tour(fn).keys), ...regionKeys])
    // the engine's buttons, the restart buttons
    for (const source of [...Object.values(vueSources), ...Object.values(jsSources)]) {
      for (const m of source.matchAll(/'(tutorial\.[\w.]+)'/g)) used.add(m[1])
    }
    expect(Object.keys(messages.en).filter(key => key.startsWith('tutorial.') && !used.has(key))).toEqual([])
  })
})
