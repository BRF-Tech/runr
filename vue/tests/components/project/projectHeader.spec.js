import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, RouterLinkStub } from '@vue/test-utils'
import { defineComponent, h, nextTick } from 'vue'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import ProjectHeader from '@/components/ProjectHeader.vue'
import { createTutorial } from '@/composables/useTutorial'
import { detailVersionsSteps } from '@/composables/tutorialSteps'
import { usePermissionsStore } from '@/stores/permissions'
import { withPlugins } from '../../helpers'

vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRouter: () => ({ push: vi.fn() }), useRoute: () => ({ query: {} }) }))

// The project header sticks below the sticky AppHeader, and its tabs stay in one row that
// scrolls sideways: at 1440 px the tabs after Traces (Replay, Uptime, Security, Access) were
// cut off and out of reach, and the header slid under the AppHeader when the page scrolled.

const mainCss = readFileSync(resolve(import.meta.dirname, '../../../src/assets/main.css'), 'utf8')

const project = { id: 'p1', name: 'orders-api', service_type: 'faas', runtime: 'python', runtime_version: '3.12', config: {} }
const IDS = ['versions', 'deployments', 'environments', 'metrics', 'domains', 'network', 'traces', 'replay', 'uptime', 'security', 'access']
const tabs = IDS.map(id => ({ id, label: id[0].toUpperCase() + id.slice(1) }))

// A fake layout: the row is 300 px wide at x = 0, each tab 100 px (1100 px of tabs)
const rect = (left, width) => ({ left, right: left + width, width, top: 0, bottom: 40, height: 40, x: left, y: 0 })
const isRow = (el) => el.hasAttribute?.('data-scroll-row')

beforeEach(() => {
  vi.spyOn(Element.prototype, 'clientWidth', 'get').mockImplementation(function () { return isRow(this) ? 300 : 0 })
  vi.spyOn(Element.prototype, 'scrollWidth', 'get').mockImplementation(function () {
    return isRow(this) ? this.querySelectorAll('[data-tab]').length * 100 : 0
  })
  vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function () {
    if (isRow(this)) return rect(0, 300)
    if (this.dataset?.tab) {
      const row = this.closest('[data-scroll-row]')
      return rect([...row.querySelectorAll('[data-tab]')].indexOf(this) * 100 - row.scrollLeft, 100)
    }
    return rect(0, 0)
  })
  Element.prototype.scrollTo = vi.fn(function ({ left }) {
    this.scrollLeft = left
    this.dispatchEvent(new Event('scroll'))
  })
})

afterEach(() => {
  delete Element.prototype.scrollTo
  document.body.innerHTML = ''
})

function mountHeader(props = {}) {
  return mount(ProjectHeader, {
    props: { project, tabs, activeTab: 'versions', ...props },
    attachTo: document.body,
    global: { ...withPlugins('en').global, stubs: { RouterLink: RouterLinkStub } },
  })
}

const row = (w) => w.get('[data-test="project-tabs-row"]')

describe('ProjectHeader: sticky below the app header', () => {
  it('sticks at the app header\'s height, not at the top of the window', () => {
    const w = mountHeader()
    const cls = w.get('[data-test="project-header"]').classes()
    // from lg: narrower, the header wraps to twice its height and scrolls with the page
    expect(cls).toContain('lg:sticky')
    expect(cls).not.toContain('sticky')
    expect(cls).toContain('top-[var(--app-header-height)]')
    expect(cls).not.toContain('top-0')
    expect(cls).toContain('z-10') // under the AppHeader (z-40) and its menus
    // the default: AppHeader's h-14 row and its 1px border (AppHeader keeps it measured)
    expect(mainCss).toMatch(/:root\s*\{\s*--app-header-height:\s*calc\(3\.5rem \+ 1px\);\s*\}/)
  })

  it('puts the actions below the title on narrow screens, not past the window\'s edge', () => {
    const w = mountHeader()
    expect(w.get('[data-tour="project-header"]').classes()).toEqual(expect.arrayContaining(['flex-col', 'lg:flex-row']))
    const actions = w.get('[data-tour="action-buttons"]').classes()
    expect(actions).toEqual(expect.arrayContaining(['flex-wrap', 'lg:shrink-0']))
    expect(actions).not.toContain('shrink-0')
  })
})

describe('ProjectHeader: the tab row', () => {
  it('keeps every tab in one row that scrolls sideways', () => {
    const w = mountHeader()
    const r = row(w)
    expect(r.attributes()).toHaveProperty('data-scroll-row')
    expect(r.classes()).toEqual(expect.arrayContaining(['flex', 'overflow-x-auto', 'scrollbar-none']))
    expect(r.classes()).not.toContain('flex-wrap')
    const buttons = r.findAll('[data-tab]')
    expect(buttons.map(b => b.attributes('data-tab'))).toEqual(IDS)
    for (const b of buttons) expect(b.classes()).toEqual(expect.arrayContaining(['shrink-0', 'whitespace-nowrap']))
    // the tour's target holds the row and its arrows
    expect(w.get('[data-tour="project-tabs"]').find('[data-test="project-tabs-row"]').exists()).toBe(true)
  })

  it('opens with the active tab scrolled into view (a page opened on #access)', async () => {
    const w = mountHeader({ activeTab: 'access' })
    await nextTick()
    // the last tab (1000-1100 px) at the row's right end
    expect(row(w).element.scrollLeft).toBe(800)
    expect(Element.prototype.scrollTo).toHaveBeenCalledWith({ left: 800, behavior: 'auto' })
    expect(row(w).get('[data-tab="access"]').attributes('aria-current')).toBe('true')
  })

  it('scrolls the tab that becomes active into view, smoothly', async () => {
    const w = mountHeader({ activeTab: 'access' })
    await w.setProps({ activeTab: 'domains' }) // e.g. Deploy strategy → open tracing, a hash change
    await nextTick()
    // domains (400-500 px) back in view from 800
    expect(Element.prototype.scrollTo).toHaveBeenLastCalledWith({ left: 400, behavior: 'smooth' })
    expect(row(w).element.scrollLeft).toBe(400)
  })

  it('marks the ends that hide tabs with a fade and an arrow', async () => {
    const w = mountHeader()
    await nextTick()
    // at the start: only the end hides tabs
    expect(w.find('[data-test="project-tabs-start"]').exists()).toBe(false)
    expect(w.find('[data-test="project-tabs-end"]').exists()).toBe(true)

    row(w).element.scrollLeft = 400
    await row(w).trigger('scroll')
    expect(w.find('[data-test="project-tabs-start"]').exists()).toBe(true)
    expect(w.find('[data-test="project-tabs-end"]').exists()).toBe(true)

    row(w).element.scrollLeft = 800
    await row(w).trigger('scroll')
    expect(w.find('[data-test="project-tabs-start"]').exists()).toBe(true)
    expect(w.find('[data-test="project-tabs-end"]').exists()).toBe(false)
  })

  it('shows no fade or arrow when every tab fits', async () => {
    const w = mountHeader({ tabs: tabs.slice(0, 3) })
    await nextTick()
    expect(w.find('[data-test="project-tabs-start"]').exists()).toBe(false)
    expect(w.find('[data-test="project-tabs-end"]').exists()).toBe(false)
  })

  it('the arrows page through the row', async () => {
    const w = mountHeader()
    await nextTick()
    await w.get('[data-test="project-tabs-forward"]').trigger('click')
    expect(Element.prototype.scrollTo).toHaveBeenLastCalledWith({ left: 240, behavior: 'smooth' })
    await w.get('[data-test="project-tabs-back"]').trigger('click')
    expect(Element.prototype.scrollTo).toHaveBeenLastCalledWith({ left: 0, behavior: 'smooth' })
  })

  it('Tab reaches every tab; the arrows (a pointer\'s help) stay out of the tab order', async () => {
    const w = mountHeader({ activeTab: 'network' })
    await nextTick()
    for (const b of row(w).findAll('[data-tab]')) {
      expect(b.element.tagName).toBe('BUTTON')
      expect(b.attributes('tabindex')).toBeUndefined()
    }
    for (const id of ['project-tabs-back', 'project-tabs-forward']) {
      const arrow = w.get(`[data-test="${id}"]`)
      expect(arrow.attributes('tabindex')).toBe('-1')
      expect(arrow.attributes('aria-hidden')).toBe('true')
    }
    await row(w).get('[data-tab="security"]').trigger('click')
    expect(w.emitted('update:activeTab')).toEqual([['security']])
  })

  it('a refresh that keeps the tabs leaves the row where the user scrolled it', async () => {
    const w = mountHeader({ activeTab: 'access' })
    await nextTick()
    row(w).element.scrollLeft = 0 // the user scrolls back to the start
    Element.prototype.scrollTo.mockClear()
    await w.setProps({ tabs: tabs.map(tab => ({ ...tab })), project: { ...project } })
    await nextTick()
    expect(Element.prototype.scrollTo).not.toHaveBeenCalled()
    expect(row(w).element.scrollLeft).toBe(0)

    // another tab list (a module tab came): the active tab is brought back into view
    await w.setProps({ tabs: [...tabs, { id: 'workflows', label: 'Workflows' }] })
    await nextTick()
    expect(Element.prototype.scrollTo).toHaveBeenCalledWith({ left: 800, behavior: 'auto' })
  })
})

describe('ProjectHeader: a tour step on a tab out of view', () => {
  it('scrolls the row to the step\'s tab before driver.js highlights it', async () => {
    let tour
    const Page = defineComponent({
      setup() {
        tour = createTutorial(() => [
          { element: '[data-tour="project-tabs"]', popover: { title: 'Tabs', description: 'All of them' } },
          { element: '[data-tour="deployments-tab"]', popover: { title: 'Deployments', description: 'Scrolled away' } },
        ])
        return () => h(ProjectHeader, { project, tabs, activeTab: 'access' })
      },
    })
    const w = mount(Page, { attachTo: document.body, global: { ...withPlugins('en').global, stubs: { RouterLink: RouterLinkStub } } })
    await nextTick()
    expect(row(w).element.scrollLeft).toBe(800) // the page opened on #access: Deployments is out of view

    tour.start(1)
    const tab = row(w).get('[data-tab="deployments"]').element
    expect(tab.classList.contains('driver-active-element')).toBe(true)
    // Deployments (100-200 px) at the row's start again
    expect(row(w).element.scrollLeft).toBe(100)
    expect(tab.getBoundingClientRect().left).toBeGreaterThanOrEqual(0)

    // the row itself (a step on the whole row) moves nothing
    tour.start(0)
    expect(row(w).element.scrollLeft).toBe(100)
    w.unmount()
  })
})

// The project tour's "Actions" step points at the row of action buttons. A read-only role (the
// public demo's, a Viewer) sees none of them: the row then is no target and the tour leaves the
// step out, instead of highlighting an empty box that talks about edit, deploy and delete.
describe('ProjectHeader: the tour\'s Actions step', () => {
  function grant(permissions) {
    usePermissionsStore().apply({ permissions, platform_admin: false, edition: 'single', role: null, roles: [], projects: {} })
  }
  function mountAs(permissions) {
    const plugins = withPlugins('en')
    grant(permissions)
    return mount(ProjectHeader, {
      props: { project, tabs, activeTab: 'versions' },
      attachTo: document.body,
      global: { ...plugins.global, stubs: { RouterLink: RouterLinkStub } },
    })
  }

  it('is no target while the role has no action on the project', () => {
    const w = mountAs(['projects.view', 'logs.view', 'metrics.view'])
    const actions = w.get('[data-test="project-actions"]')
    expect(actions.findAll('button')).toHaveLength(0)
    expect(actions.attributes('data-tour')).toBeUndefined()
    expect(document.querySelector('[data-tour="action-buttons"]')).toBeNull()
    w.unmount()
  })

  it.each(['projects.update', 'env.edit', 'projects.deploy', 'projects.delete', 'projects.create', 'projects.stop'])(
    'is the target once an action shows (%s)',
    (permission) => {
      const w = mountAs(['projects.view', permission])
      const actions = w.get('[data-test="project-actions"]')
      expect(actions.findAll('button').length).toBeGreaterThan(0)
      expect(actions.attributes('data-tour')).toBe('action-buttons')
      w.unmount()
    },
  )

  it('the project tour goes from the header to the tabs for a read-only role, through the actions for a developer', async () => {
    const secondStep = async (permissions) => {
      const plugins = withPlugins('en')
      grant(permissions)
      let tour
      // the page around the header: createTutorial needs a component's setup (useI18n)
      const page = {
        setup() {
          tour = createTutorial(detailVersionsSteps)
          return () => h(ProjectHeader, { project, tabs, activeTab: 'versions' })
        },
      }
      const w = mount(page, { attachTo: document.body, global: { ...plugins.global, stubs: { RouterLink: RouterLinkStub } } })
      await nextTick()
      tour.start(1)
      const active = document.querySelector('.driver-active-element')?.getAttribute('data-tour')
      w.unmount()
      return active
    }
    expect(await secondStep(['projects.view'])).toBe('project-tabs')
    expect(await secondStep(['projects.view', 'projects.deploy'])).toBe('action-buttons')
  })
})
