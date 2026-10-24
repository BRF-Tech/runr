import { describe, it, expect, afterEach, beforeAll } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { parse } from 'vue/compiler-sfc'
import postcss from 'postcss'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createTutorial } from '@/composables/useTutorial'
import { withPlugins } from '../helpers'

// Tour steps on controls that appear only on hover (opacity-0 group-hover:opacity-100) highlighted
// an invisible button. driver.js marks the active step's element with driver-active-element;
// main.css shows that element (and an opacity-0 ancestor) while it carries the class.

const ACTIVE = 'driver-active-element'
const vueSources = import.meta.glob('/src/**/*.vue', { query: '?raw', import: 'default', eager: true })
// Vitest hands CSS imports over empty: the stylesheet is read from disk
const mainCss = readFileSync(resolve(import.meta.dirname, '../../src/assets/main.css'), 'utf8')

// ── Which tour targets appear only on hover ───────────────────────────────────────────────────

/** Class tokens of an element: class="…" and the string literals of :class="…". */
function classTokens(node) {
  const tokens = []
  for (const p of node.props ?? []) {
    if (p.type === 6 && p.name === 'class' && p.value) tokens.push(...p.value.content.split(/\s+/))
    if (p.type === 7 && p.name === 'bind' && p.arg?.content === 'class') {
      for (const m of (p.exp?.content ?? '').matchAll(/'([^']*)'|"([^"]*)"/g)) tokens.push(...(m[1] ?? m[2]).split(/\s+/))
    }
  }
  return tokens.filter(Boolean)
}

/** data-tour values of an element: data-tour="x" and the string literals of :data-tour="…". */
function tourValues(node) {
  const values = []
  for (const p of node.props ?? []) {
    if (p.type === 6 && p.name === 'data-tour' && p.value) values.push(p.value.content)
    if (p.type === 7 && p.name === 'bind' && p.arg?.content === 'data-tour') {
      for (const m of (p.exp?.content ?? '').matchAll(/'([^']+)'|"([^"]+)"/g)) values.push(m[1] ?? m[2])
    }
  }
  return values
}

const HIDES = ['opacity-0', 'invisible', 'hidden']
const SHOWN_ON_HOVER = /^(?:[\w-]+:)*(?:(?:group|peer)-)?hover(?:\/[\w-]+)?:(?:opacity-\d+|visible|block|flex|inline|inline-flex|inline-block|grid)!?$/

/** The class that hides an element until it (or its group) is hovered, else null. */
function hoverHide(tokens) {
  if (!tokens.some(c => SHOWN_ON_HOVER.test(c))) return null
  return HIDES.find(c => tokens.includes(c)) ?? null
}

/** Every data-tour element hidden until hovered: { target, file, hiddenBy: [{ on, cls }] }. */
function hoverOnlyTargets() {
  const found = []
  for (const [file, source] of Object.entries(vueSources)) {
    const ast = parse(source, { filename: file }).descriptor.template?.ast
    if (!ast) continue
    const walk = (node, ancestors) => {
      if (node.type === 1) {
        const values = tourValues(node)
        if (values.length) {
          const hiddenBy = []
          const own = hoverHide(classTokens(node))
          if (own) hiddenBy.push({ on: 'element', cls: own })
          for (const a of ancestors) {
            const cls = hoverHide(classTokens(a))
            if (cls) hiddenBy.push({ on: 'ancestor', cls })
          }
          if (hiddenBy.length) for (const target of values) found.push({ target, file, hiddenBy })
        }
      }
      const next = node.type === 1 ? [...ancestors, node] : ancestors
      for (const child of node.children ?? []) walk(child, next)
    }
    walk(ast, [])
  }
  return found
}

// ── What main.css does with driver-active-element ─────────────────────────────────────────────

function tourRules() {
  const rules = []
  postcss.parse(mainCss).walkRules((rule) => {
    if (rule.selector.includes(ACTIVE)) rules.push(rule)
  })
  return rules
}

/** The declarations of the rules whose selector list contains `selector`. */
function declarationsFor(selector) {
  const decls = {}
  for (const rule of tourRules()) {
    if (!rule.selectors.includes(selector)) continue
    rule.walkDecls((d) => { decls[d.prop] = `${d.value}${d.important ? ' !important' : ''}` })
  }
  return decls
}

describe('tour steps on controls that appear only on hover', () => {
  // Parsing the templates of every .vue file takes seconds on a loaded host (the coverage run of
  // CI): once, outside the tests' 5 s limit
  let hoverTargets
  beforeAll(() => { hoverTargets = hoverOnlyTargets() }, 60_000)

  it('are found: the deployment table\'s log, rollback and delete buttons', () => {
    const targets = hoverTargets.map(t => t.target)
    expect(targets).toEqual(expect.arrayContaining(['deployment-log-btn', 'deployment-rollback-btn', 'deployment-delete-btn']))
  })

  it('are hidden in a way the tour can undo (opacity-0 or invisible, not display: none)', () => {
    const undoable = ({ cls }) => cls === 'opacity-0' || cls === 'invisible'
    const wrong = hoverTargets.filter(t => !t.hiddenBy.every(undoable))
    expect(wrong).toEqual([])
  })

  it('show while their step is active: main.css reveals the element driver.js marks', () => {
    // opacity-0 on the element or on an ancestor (a toolbar shown on hover)
    expect(declarationsFor(`.${ACTIVE}.opacity-0`)).toMatchObject({ opacity: '1 !important' })
    expect(declarationsFor(`.opacity-0:has(.${ACTIVE})`)).toMatchObject({ opacity: '1 !important' })
    // invisible on the element or inherited from an ancestor
    expect(declarationsFor(`.${ACTIVE}`)).toMatchObject({ visibility: 'visible !important' })
  })

  it('look as before outside the tour: every rule needs driver-active-element', () => {
    const rules = tourRules()
    expect(rules.length).toBeGreaterThan(0)
    expect(rules.flatMap(r => r.selectors).filter(s => !s.includes(ACTIVE))).toEqual([])
  })
})

// ── driver.js marks the step's element, and only while the step is active ────────────────────

describe('driver.js and the active step', () => {
  let wrapper

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    document.body.innerHTML = ''
  })

  function mountTour() {
    let tour
    const Page = defineComponent({
      setup() {
        tour = createTutorial(() => [
          { element: '[data-tour="deployment-table"]', popover: { title: 'Deployments', description: 'All of them' } },
          { element: '[data-tour="deployment-log-btn"]', popover: { title: 'Logs', description: 'Shown on hover' } },
        ])
        return {}
      },
      template: `
        <table data-tour="deployment-table"><tbody>
          <tr class="group"><td>
            <button class="opacity-0 group-hover:opacity-100" data-tour="deployment-log-btn">logs</button>
          </td></tr>
        </tbody></table>`,
    })
    wrapper = mount(Page, { attachTo: document.body, global: withPlugins('en').global })
    return tour
  }

  it('puts driver-active-element on the hover-only button during its step, and takes it off after', () => {
    const tour = mountTour()
    const button = document.querySelector('[data-tour="deployment-log-btn"]')
    const table = document.querySelector('[data-tour="deployment-table"]')

    tour.start(1)
    expect(button.classList.contains(ACTIVE)).toBe(true)

    tour.start(0)
    expect(table.classList.contains(ACTIVE)).toBe(true)
    expect(button.classList.contains(ACTIVE)).toBe(false)

    wrapper.unmount()
    wrapper = null
    expect(document.querySelectorAll(`.${ACTIVE}`)).toHaveLength(0)
  })
})
