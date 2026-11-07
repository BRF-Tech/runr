import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, reactive } from 'vue'
import RuntimeSelector from '@/components/project/RuntimeSelector.vue'
import RuntimeLifecycleBanner from '@/components/project/RuntimeLifecycleBanner.vue'
import ProjectHeader from '@/components/ProjectHeader.vue'
import { useRuntimeData } from '@/composables/useProjectForm'
import { useSettingsStore } from '@/stores/settings'
import { runtimesApi } from '@/api/runtimes'
import { settingsApi } from '@/api/settings'
import {
  pickableVersions, projectLifecycleNotice, projectRuntimeEnded, versionEndsAt, versionState,
} from '@/utils/runtimeLifecycle'
import { notificationTitle } from '@/utils/notifications'
import { makeI18n, freshPinia, catalog, regions } from '@/test-support/helpers'

// The lifecycle of runtimes and versions (docs/runtimes.md#lifecycle) in the project screens: the
// pickers leave out versions past their end (the earlier of end of life and sunset), mark
// deprecated ones and do not offer a sunset or orphaned runtime; the project page tells with a
// badge and a banner how long the project can still be saved and deployed.

vi.mock('@/api/runtimes', () => ({
  runtimesApi: {
    list: vi.fn(), get: vi.fn(), validate: vi.fn(), create: vi.fn(), update: vi.fn(), destroy: vi.fn(),
    testDeploy: vi.fn(), builds: vi.fn(), build: vi.fn(), template: vi.fn(),
  },
}))
vi.mock('@/api/settings', () => ({
  settingsApi: { get: vi.fn(), update: vi.fn(), seedDemo: vi.fn(), removeDemo: vi.fn() },
}))
vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRouter: () => ({ push: vi.fn() }), useRoute: () => ({ query: {} }) }))

const i18n = makeI18n()
const t = i18n.global.t
let pinia
const mounted = []

const NOW = new Date('2026-11-07T12:00:00Z')

// Python: 3.14 active, 3.12 deprecated (sunset 2027-01-31), 3.11 sunset passed (eol later), 3.9 eol passed
const python = {
  ...catalog.python,
  versions: [
    { version: '3.14', is_default: true,  eol_at: '2030-10-31', deprecated_at: null,         sunset_at: null,         ends_at: '2030-10-31', lifecycle: 'active' },
    { version: '3.12', is_default: false, eol_at: '2028-10-31', deprecated_at: '2026-10-01', sunset_at: '2027-01-31', ends_at: '2027-01-31', lifecycle: 'deprecated' },
    { version: '3.11', is_default: false, eol_at: '2027-10-31', deprecated_at: '2026-06-01', sunset_at: '2026-11-01', ends_at: '2026-11-01', lifecycle: 'sunset' },
    { version: '3.9',  is_default: false, eol_at: '2025-10-07', deprecated_at: null,         sunset_at: null,         ends_at: '2025-10-07', lifecycle: 'eol' },
  ],
}
const deprecatedRuntime = {
  ...catalog.c, id: 'zig', name: 'Zig', source: 'platform',
  lifecycle: { state: 'deprecated', deprecated_at: '2026-10-01', sunset_at: '2027-03-01', orphaned_at: null, replacement: 'nodejs', notice: 'Zig moves to Node.js.' },
}
const sunsetRuntime = {
  ...catalog.c, id: 'cobol', name: 'COBOL', source: 'platform',
  lifecycle: { state: 'sunset', deprecated_at: '2026-01-01', sunset_at: '2026-10-01', orphaned_at: null, replacement: null, notice: null },
}

const Picker = defineComponent({
  props: { runtime: { type: String, default: 'python' }, version: { type: String, default: null }, edit: { type: Boolean, default: false } },
  setup(props) {
    const form = reactive({ runtime: props.runtime, runtime_version: props.version, knative_region_id: 'r1', config: { trigger_type: 'http' } })
    const { runtimes, runtimeVersions, selectableRuntimes } = useRuntimeData(form, { includeCurrent: props.edit })
    return () => h('div', [
      h(RuntimeSelector, {
        runtimes: runtimes.value, runtimeVersions: runtimeVersions.value, selectedRuntime: form.runtime, selectedVersion: form.runtime_version,
        readonly: props.edit, triggerType: 'http', 'onUpdate:runtime': (v) => { form.runtime = v },
      }),
      h('p', { 'data-selectable': selectableRuntimes.value.map(r => r.id).join(',') }),
    ])
  },
})

async function mountPicker(props = {}) {
  const w = mount(Picker, { props, global: { plugins: [pinia, i18n] } })
  mounted.push(w)
  await flushPromises()
  return w
}

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(NOW)
  runtimesApi.list.mockResolvedValue({ data: { data: [python, catalog.nodejs, deprecatedRuntime, sunsetRuntime], abilities: { manage: false, use_custom: false }, custom_enabled: false } })
  settingsApi.get.mockResolvedValue({ data: { regions, executor_images: [] } })
})

afterEach(() => {
  vi.useRealTimers()
  while (mounted.length) mounted.pop().unmount()
})

const versions = (w) => w.findAll('[data-version]').map(b => b.attributes('data-version'))

describe('runtime picker', () => {
  it('hides versions past their end of life or sunset and badges deprecated ones', async () => {
    const w = await mountPicker()

    expect(versions(w)).toEqual(['3.14', '3.12'])
    const deprecated = w.find('[data-version="3.12"] .version-lifecycle')
    expect(deprecated.text()).toBe('Deprecated')
    expect(w.find('[data-version="3.12"]').attributes('title')).toContain('Sunset on 2027-01-31')
    expect(w.find('[data-version="3.14"] .version-lifecycle').exists()).toBe(false)
  })

  it('falls back to eol_at and sunset_at of the settings images (no ends_at)', async () => {
    runtimesApi.list.mockResolvedValue({ data: { data: [], abilities: {}, custom_enabled: false } })
    useSettingsStore().data.executor_images = [
      { id: 1, runtime: 'php', version: '8.4', is_default: true, eol_at: null, sunset_at: null },
      { id: 2, runtime: 'php', version: '8.2', is_default: false, eol_at: '2026-12-31', sunset_at: '2026-11-01' },
      { id: 3, runtime: 'php', version: '8.3', is_default: false, eol_at: '2027-12-31', deprecated_at: '2026-09-01' },
    ]
    const w = await mountPicker({ runtime: 'php' })

    expect(versions(w)).toEqual(['8.4', '8.3'])
    expect(w.find('[data-version="8.3"] .version-lifecycle').text()).toBe('Deprecated')
  })

  it('marks a deprecated runtime and does not offer a sunset one for a new project', async () => {
    const w = await mountPicker()

    const zig = w.find('[data-runtime="zig"]')
    expect(zig.find('.runtime-lifecycle').text()).toBe('Deprecated')
    expect(zig.attributes('disabled')).toBeUndefined()
    expect(zig.attributes('title')).toContain('Sunset on 2027-03-01')
    expect(zig.attributes('title')).toContain('Use Node.js instead')

    const cobol = w.find('[data-runtime="cobol"]')
    expect(cobol.attributes('disabled')).toBeDefined()
    expect(cobol.attributes('title')).toBe('Sunset on 2026-10-01: not available for new projects')
    expect(w.find('[data-selectable]').attributes('data-selectable').split(',')).not.toContain('cobol')
  })

  it('keeps the project\'s own ended version visible, struck through, in the edit form', async () => {
    const w = await mountPicker({ version: '3.11', edit: true })

    expect(versions(w)).toEqual(['3.14', '3.12', '3.11'])
    const own = w.find('[data-version="3.11"]')
    expect(own.attributes('disabled')).toBeDefined()
    expect(own.find('.line-through').exists()).toBe(true)
    expect(own.find('.version-lifecycle').text()).toBe('Sunset')
  })
})

describe('lifecycle helpers', () => {
  it('ends a version at the earlier of end of life and sunset', () => {
    expect(versionEndsAt({ eol_at: '2027-10-31', sunset_at: '2026-11-01' })).toBe('2026-11-01')
    expect(versionEndsAt({ eol_at: '2025-10-07' })).toBe('2025-10-07')
    expect(versionEndsAt({ ends_at: '2026-01-01', eol_at: '2027-01-01' })).toBe('2026-01-01')
    expect(versionState({ eol_at: '2027-10-31', sunset_at: '2026-11-01' }, NOW)).toBe('sunset')
    expect(versionState({ eol_at: '2025-10-07' }, NOW)).toBe('eol')
    expect(versionState({ deprecated_at: '2026-12-01' }, NOW)).toBe('active')
    expect(versionState({ deprecated_at: '2026-10-01' }, NOW)).toBe('deprecated')
    expect(pickableVersions(python.versions, NOW).map(v => v.version)).toEqual(['3.14', '3.12'])
  })

  it('titles sunset reminders with their date, end-of-life ones with theirs', () => {
    const sunset = { type: 'runtime_eol', title: 'stored', data: { runtime: 'python', version: '3.12', event: 'sunset', eol_at: '2028-10-31', sunset_at: '2027-01-31', ends_at: '2027-01-31' } }
    expect(notificationTitle(sunset, t)).toBe('python 3.12 is sunset on 2027-01-31')
    const eol = { type: 'runtime_eol', title: 'stored', data: { runtime: 'nodejs', version: '20', event: 'eol', eol_at: '2026-04-30', ends_at: '2026-04-30' } }
    expect(notificationTitle(eol, t)).toBe('nodejs 20 reaches its end of life on 2026-04-30')
    const orphaned = { type: 'runtime_eol', title: 'zig is no longer offered by its source', data: { runtime: 'zig', event: 'orphaned', date: '2027-01-30' } }
    expect(notificationTitle(orphaned, t)).toBe('zig is no longer offered by its source')
  })
})

describe('project lifecycle', () => {
  const project = (lc, extra = {}) => ({
    id: 'p1', name: 'orders', service_type: 'faas', runtime: 'python', runtime_version: lc?.version ?? '3.12', config: {},
    runtime_eol: lc?.ends_at ?? null, runtime_lifecycle: lc, ...extra,
  })
  const lifecycle = (over) => ({
    state: 'active', runtime_state: 'active', version: '3.12', eol_at: '2028-10-31', deprecated_at: null, sunset_at: null,
    ends_at: '2028-10-31', ends_by: 'eol', orphaned_at: null, replacement: null, notice: null, ...over,
  })

  function mountBanner(p) {
    const w = mount(RuntimeLifecycleBanner, { props: { project: p }, global: { plugins: [pinia, i18n] } })
    mounted.push(w)
    return w
  }

  it('says until when a deprecated version works and what to move to', () => {
    const w = mountBanner(project(lifecycle({ state: 'deprecated', deprecated_at: '2026-10-01', sunset_at: '2027-01-31', ends_at: '2027-01-31', ends_by: 'sunset', replacement: 'nodejs', notice: 'Python 3.12 builds stop.' })))

    expect(w.attributes('data-lifecycle')).toBe('deprecated')
    expect(w.find('[data-test="runtime-lifecycle-title"]').text()).toBe('Python 3.12 is deprecated — sunset on 2027-01-31')
    expect(w.find('[data-test="runtime-lifecycle-body"]').text()).toBe('It keeps working until then. Move the project to Node.js. Python 3.12 builds stop.')
  })

  it('says that a runtime left its source and when deploys stop', () => {
    const w = mountBanner(project(lifecycle({ state: 'orphaned', runtime_state: 'orphaned', orphaned_at: '2026-11-01T10:00:00Z', sunset_at: '2027-01-30', ends_at: '2027-01-30', ends_by: 'sunset' })))

    expect(w.find('[data-test="runtime-lifecycle-title"]').text()).toBe('Python 3.12 left its runtime source')
    expect(w.find('[data-test="runtime-lifecycle-body"]').text()).toBe('It is no longer offered for new projects; this project can be saved and deployed until 2027-01-30. Move the project to another runtime.')
  })

  it('says that saving and deploying are refused once the version ended', () => {
    const ended = project(lifecycle({ state: 'sunset', sunset_at: '2026-11-01', ends_at: '2026-11-01', ends_by: 'sunset', version: '3.11' }))
    const w = mountBanner(ended)

    expect(w.attributes('data-lifecycle')).toBe('sunset')
    expect(w.find('[data-test="runtime-lifecycle-title"]').text()).toBe('Python 3.11 was sunset on 2026-11-01')
    expect(w.find('[data-test="runtime-lifecycle-body"]').text()).toContain('Saving and deploying this project are refused')
    expect(projectRuntimeEnded(ended, NOW)).toBe(true)
    expect(projectLifecycleNotice(project(lifecycle()), t)).toBeNull()
    expect(mountBanner(project(lifecycle())).find('[data-test="runtime-lifecycle-banner"]').exists()).toBe(false)
  })

  it('badges the header: deprecated, or Sunset instead of End of Life', () => {
    const mountHeader = (p) => {
      const w = mount(ProjectHeader, { props: { project: p, isRuntimeEol: projectRuntimeEnded(p, NOW) }, global: { plugins: [pinia, i18n], stubs: { RouterLink: true } } })
      mounted.push(w)
      return w
    }
    const deprecated = mountHeader(project(lifecycle({ state: 'deprecated', deprecated_at: '2026-10-01', ends_at: '2028-10-31', ends_by: 'eol' })))
    expect(deprecated.find('[data-test="project-runtime-lifecycle"]').text()).toBe('Deprecated')
    expect(deprecated.find('[data-test="project-runtime-lifecycle"]').attributes('title')).toBe('Python 3.12 is deprecated — end of life on 2028-10-31')

    const sunset = mountHeader(project(lifecycle({ state: 'sunset', sunset_at: '2026-11-01', ends_at: '2026-11-01', ends_by: 'sunset' })))
    expect(sunset.find('[data-test="project-runtime-ended"]').text()).toBe('Sunset')
    expect(sunset.find('[data-test="project-runtime-lifecycle"]').exists()).toBe(false)
  })
})
