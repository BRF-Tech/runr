import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { mount } from '@vue/test-utils'
import router from '@/router'
import { usePermissionsStore } from '@/stores/permissions'
import plugin from '@/modules/observability/plugin'
import ReplayDiff from '@/modules/observability/components/ReplayDiff.vue'
import UptimeBars from '@/modules/observability/components/UptimeBars.vue'
import en from '@/modules/observability/locales/en.js'
import { makeI18n } from './fixtures'

vi.mock('@/router', () => ({ default: { addRoute: vi.fn() } }))

function install() {
  const registry = { projectTabs: [], settingsTabs: [], localeMessages: [] }
  plugin(registry)
  return registry
}

describe('observability module plugin', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(router.addRoute).mockClear()
  })

  it('registers the project tabs, settings tabs, public routes and messages', () => {
    const registry = install()

    expect(registry.projectTabs.map((t) => t.id)).toEqual(['traces', 'replay', 'uptime', 'load-test'])
    expect(registry.settingsTabs.map((t) => t.key)).toEqual(['alerts', 'status-page', 'tracing'])
    expect(router.addRoute).toHaveBeenCalledWith(expect.objectContaining({ path: '/status/:slug', meta: { public: true } }))
    expect(router.addRoute).toHaveBeenCalledWith(expect.objectContaining({ path: '/status', meta: { public: true } }))
    expect(registry.localeMessages).toHaveLength(1)
    expect(registry.localeMessages[0].en).toBe(en)
  })

  it('shows tabs by permission and project type', () => {
    const registry = install()
    const [traces, replay, uptime, loadTest] = registry.projectTabs

    expect(traces.visibleIf({ id: 'p1', service_type: 'maas' })).toBe(false)

    usePermissionsStore().apply({ permissions: ['projects.view', 'metrics.view'], projects: { p2: ['traces.view', 'replay.manage'] } })
    expect(traces.visibleIf({ id: 'p1', service_type: 'xaas' })).toBe(false)
    expect(traces.visibleIf({ id: 'p2', service_type: 'xaas' })).toBe(true)
    expect(replay.visibleIf({ id: 'p2', service_type: 'faas' })).toBe(true)
    expect(uptime.visibleIf({ id: 'p1', service_type: 'caas' })).toBe(true)
    // load tests: HTTP projects only
    expect(loadTest.visibleIf({ id: 'p1', service_type: 'xaas', config: { trigger_type: 'http' } })).toBe(true)
    expect(loadTest.visibleIf({ id: 'p1', service_type: 'xaas', config: { trigger_type: 'queue' } })).toBe(false)
    expect(loadTest.visibleIf({ id: 'p1', service_type: 'maas', config: { trigger_type: 'http' } })).toBe(false)

    const visible = registry.settingsTabs.filter((t) => t.visibleIf()).map((t) => t.key)
    expect(visible).toEqual([])

    usePermissionsStore().apply({ permissions: ['alerts.manage', 'status_page.manage', 'regions.view'] })
    expect(registry.settingsTabs.filter((t) => t.visibleIf()).map((t) => t.key)).toEqual(['alerts', 'status-page', 'tracing'])
  })
})

describe('ReplayDiff', () => {
  it('shows status, header and body changes', () => {
    const diff = {
      changed: true,
      status: { before: 500, after: 201, changed: true },
      headers: { added: { 'x-version': '4' }, removed: { 'x-old': '1' }, changed: { 'content-type': ['text/plain', 'application/json'] } },
      body: { changed: true, truncated: false, lines: [{ op: ' ', text: '{' }, { op: '-', text: '  "error": "boom"' }, { op: '+', text: '  "ok": true' }, { op: ' ', text: '}' }] },
    }
    const w = mount(ReplayDiff, { props: { diff, replay: { replayed_at: '2026-10-10T10:00:00Z', version: 4, error: null } }, global: { plugins: [makeI18n()] } })

    expect(w.find('[data-test="diff-after"]').text()).toBe('201')
    expect(w.text()).toContain('+ x-version: 4')
    expect(w.text()).toContain('- x-old: 1')
    expect(w.text()).toContain('~ content-type: text/plain → application/json')
    const lines = w.findAll('[data-test="diff-line"]').map((l) => l.text())
    expect(lines).toEqual(['{', '-   "error": "boom"', '+   "ok": true', '}'])
    expect(w.text()).toContain('version 4')
    expect(w.find('[data-test="diff-unchanged"]').exists()).toBe(false)
  })

  it('an unchanged replay says so', () => {
    const diff = { changed: false, status: { before: 500, after: 500, changed: false }, headers: { added: {}, removed: {}, changed: {} }, body: { changed: false, truncated: false, lines: [] } }
    const w = mount(ReplayDiff, { props: { diff, replay: { replayed_at: '2026-10-10T10:00:00Z', error: null } }, global: { plugins: [makeI18n()] } })
    expect(w.find('[data-test="diff-unchanged"]').exists()).toBe(true)
  })
})

describe('UptimeBars', () => {
  it('colours days by uptime', () => {
    const w = mount(UptimeBars, {
      props: { days: [{ date: '2026-10-08', uptime: 100 }, { date: '2026-10-09', uptime: 97 }, { date: '2026-10-10', uptime: 80 }, { date: '2026-10-11', uptime: null }] },
      global: { plugins: [makeI18n()] },
    })
    const classes = w.findAll('[data-day]').map((d) => d.classes().find((c) => c.startsWith('bg-')))
    expect(classes).toEqual(['bg-emerald-500', 'bg-amber-400', 'bg-red-500', 'bg-gray-700'])
    expect(w.find('[data-day="2026-10-11"]').attributes('title')).toContain('no data')
  })
})
