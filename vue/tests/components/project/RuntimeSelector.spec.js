import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, reactive } from 'vue'
import RuntimeSelector from '@/components/project/RuntimeSelector.vue'
import { useRuntimeData } from '@/composables/useProjectForm'
import { useSettingsStore } from '@/stores/settings'
import { runtimesApi } from '@/api/runtimes'
import { settingsApi } from '@/api/settings'
import { makeI18n, freshPinia, catalog, regions, Q_ICON } from '@/test-support/helpers'

vi.mock('@/api/runtimes', () => ({
  runtimesApi: {
    list: vi.fn(), get: vi.fn(), validate: vi.fn(), create: vi.fn(), update: vi.fn(), destroy: vi.fn(),
    testDeploy: vi.fn(), builds: vi.fn(), build: vi.fn(), template: vi.fn(),
  },
}))
vi.mock('@/api/settings', () => ({
  settingsApi: { get: vi.fn(), update: vi.fn(), seedDemo: vi.fn(), removeDemo: vi.fn() },
}))

const i18n = makeI18n()
let pinia

function catalogResponse(list) {
  return { data: { data: list, abilities: { manage: false, use_custom: false }, custom_enabled: false } }
}

/**
 * The picker as the create form uses it: useRuntimeData (catalog, region filter, trigger check,
 * EOL filter) feeding RuntimeSelector.
 */
const Picker = defineComponent({
  props: {
    regionId: { type: String, default: 'r1' },
    trigger:  { type: String, default: 'http' },
    runtime:  { type: String, default: 'python' },
  },
  setup(props) {
    const form = reactive({
      runtime: props.runtime,
      runtime_version: null,
      knative_region_id: props.regionId,
      config: { trigger_type: props.trigger },
    })
    const { runtimes, runtimeVersions } = useRuntimeData(form)
    return () => h(RuntimeSelector, {
      runtimes:        runtimes.value,
      runtimeVersions: runtimeVersions.value,
      selectedRuntime: form.runtime,
      selectedVersion: form.runtime_version,
      triggerType:     form.config.trigger_type,
      'onUpdate:runtime': (v) => { form.runtime = v },
      'onUpdate:version': (v) => { form.runtime_version = v },
    })
  },
})

async function mountPicker(props = {}) {
  const wrapper = mount(Picker, { props, global: { plugins: [pinia, i18n] } })
  await flushPromises()
  return wrapper
}

const runtimeIds = (wrapper) => wrapper.findAll('[data-runtime]').map(b => b.attributes('data-runtime'))
const nameOf     = (wrapper, id) => wrapper.find(`[data-runtime="${id}"] span.runtime-name`).text()

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
  runtimesApi.list.mockResolvedValue(catalogResponse(Object.values(catalog)))
  settingsApi.get.mockResolvedValue({ data: { regions, executor_images: [] } })
})

describe('RuntimeSelector (catalog-driven)', () => {
  it('lists the enabled catalog runtimes of the region with their names', async () => {
    const wrapper = await mountPicker({ regionId: 'r1' })

    expect(runtimesApi.list).toHaveBeenCalledTimes(1)
    // rust is offered in r2 only
    expect(runtimeIds(wrapper)).toEqual(['python', 'nodejs', 'c', 'q'])
    expect(nameOf(wrapper, 'python')).toBe('Python')
    expect(nameOf(wrapper, 'nodejs')).toBe('Node.js')
    expect(nameOf(wrapper, 'q')).toBe('Q')
  })

  it('shows the manifest icon, the bundled icon of built-ins and a generic icon otherwise', async () => {
    const wrapper = await mountPicker()

    expect(wrapper.find('[data-runtime="q"] img').attributes('src')).toBe(Q_ICON)
    expect(wrapper.find('[data-runtime="python"] img').attributes('src')).toMatch(/python/)
    // C has no icon in its manifest and no bundled one: lucide icon instead of an <img>
    expect(wrapper.find('[data-runtime="c"] img').exists()).toBe(false)
    expect(wrapper.find('[data-runtime="c"] svg').exists()).toBe(true)
  })

  it('marks platform and custom runtimes with their source', async () => {
    const wrapper = await mountPicker()

    expect(wrapper.find('[data-runtime="c"]').text()).toContain('Platform')
    expect(wrapper.find('[data-runtime="q"]').text()).toContain('Custom')
    expect(wrapper.find('[data-runtime="python"]').text()).not.toContain('Built-in')
  })

  it('lists the versions of the selected runtime with the default badge and without EOL ones', async () => {
    const wrapper = await mountPicker({ runtime: 'python' })
    const versions = wrapper.findAll('[data-version]').map(b => b.attributes('data-version'))

    expect(versions).toEqual(['3.14', '3.12'])
    expect(wrapper.find('[data-version="3.14"]').text()).toContain('Default')
    expect(wrapper.find('[data-version="3.12"]').text()).not.toContain('Default')
  })

  it('filters by region availability', async () => {
    const wrapper = await mountPicker({ regionId: 'r2' })

    expect(runtimeIds(wrapper)).toContain('rust')
    expect(runtimeIds(wrapper)).not.toContain('c')
  })

  it('greys out runtimes that do not support the trigger', async () => {
    const wrapper = await mountPicker({ trigger: 'schedule' })
    const q = wrapper.find('[data-runtime="q"]')

    expect(q.attributes('disabled')).toBeDefined()
    expect(q.attributes('title')).toBe('Does not support the Schedule trigger')
    expect(wrapper.find('[data-runtime="python"]').attributes('disabled')).toBeUndefined()
  })

  it('switches runtime and version through the picker', async () => {
    const wrapper = await mountPicker({ runtime: 'python' })

    const selector = wrapper.findComponent(RuntimeSelector)

    await wrapper.find('[data-runtime="c"]').trigger('click')
    expect(selector.props('selectedRuntime')).toBe('c')
    expect(wrapper.findAll('[data-version]').map(b => b.attributes('data-version'))).toEqual(['17'])

    await wrapper.find('[data-version="17"]').trigger('click')
    expect(selector.props('selectedVersion')).toBe('17')
  })

  it('falls back to settings.executor_images while the catalog is empty', async () => {
    runtimesApi.list.mockResolvedValue(catalogResponse([]))
    useSettingsStore().data.executor_images = [
      { id: 1, runtime: 'python', version: '3.14', is_default: true, eol_at: null },
      { id: 2, runtime: 'go', version: '1.26', is_default: true, eol_at: null },
    ]
    const wrapper = await mountPicker({ runtime: 'python' })

    expect(runtimeIds(wrapper)).toEqual(['python', 'go'])
    expect(nameOf(wrapper, 'go')).toBe('Go')
    expect(wrapper.find('[data-version="3.14"]').text()).toContain('Default')
  })

  it('falls back to settings.executor_images when the catalog request fails', async () => {
    runtimesApi.list.mockRejectedValue(new Error('offline'))
    useSettingsStore().data.executor_images = [{ id: 1, runtime: 'php', version: '8.4', is_default: true, eol_at: null }]
    const wrapper = await mountPicker({ runtime: 'php' })

    expect(runtimeIds(wrapper)).toEqual(['php'])
    expect(nameOf(wrapper, 'php')).toBe('PHP')
  })
})

describe('RuntimeSelector (events)', () => {
  function mountSelector(props) {
    return mount(RuntimeSelector, { props, global: { plugins: [pinia, i18n] } })
  }

  const items = [
    { id: 'python', name: 'Python' },
    { id: 'q', name: 'Q', source: 'custom', unsupported: true },
    { id: 'c', name: 'C', source: 'platform' },
  ]
  const versions = [{ version: '17', is_default: true }, { version: '11', is_default: false }]

  it('emits update:runtime for a selectable runtime and update:version for a version', async () => {
    const wrapper = mountSelector({ runtimes: items, runtimeVersions: versions, selectedRuntime: 'python', triggerType: 'schedule' })

    await wrapper.find('[data-runtime="c"]').trigger('click')
    await wrapper.find('[data-version="11"]').trigger('click')

    expect(wrapper.emitted('update:runtime')).toEqual([['c']])
    expect(wrapper.emitted('update:version')).toEqual([['11']])
  })

  it('does not emit for an unsupported runtime or in read-only mode', async () => {
    const wrapper = mountSelector({ runtimes: items, runtimeVersions: versions, selectedRuntime: 'python', triggerType: 'schedule' })
    await wrapper.find('[data-runtime="q"]').trigger('click')
    expect(wrapper.emitted('update:runtime')).toBeUndefined()

    const readonly = mountSelector({ runtimes: items, runtimeVersions: versions, selectedRuntime: 'python', readonly: true })
    await readonly.find('[data-runtime="c"]').trigger('click')
    await readonly.find('[data-version="11"]').trigger('click')
    expect(readonly.emitted('update:runtime')).toBeUndefined()
    expect(readonly.emitted('update:version')).toBeUndefined()
  })

  it('accepts plain runtime ids and shows an empty state', () => {
    const ids = mountSelector({ runtimes: ['python'] })
    expect(ids.find('[data-runtime="python"] span.runtime-name').text()).toBe('Python')

    const empty = mountSelector({ runtimes: [] })
    expect(empty.text()).toContain('No runtime is available for this region.')
  })
})
