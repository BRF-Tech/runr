import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import RuntimesManager from '@/components/settings/RuntimesManager.vue'
import { useRuntimesStore } from '@/stores/runtimes'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { runtimesApi } from '@/api/runtimes'
import { settingsApi } from '@/api/settings'
import { makeI18n, freshPinia, apiError, catalog, regions } from '@/test-support/helpers'

vi.mock('@/api/runtimes', () => ({
  runtimesApi: {
    list: vi.fn(), get: vi.fn(), validate: vi.fn(), create: vi.fn(), update: vi.fn(), destroy: vi.fn(),
    testDeploy: vi.fn(), builds: vi.fn(), build: vi.fn(), template: vi.fn(),
  },
}))
vi.mock('@/api/settings', () => ({
  settingsApi: { get: vi.fn(), update: vi.fn(), seedDemo: vi.fn(), removeDemo: vi.fn() },
}))
// Monaco does not run in jsdom: a textarea with the same v-model / readonly / language props
vi.mock('@/components/MonacoEditor.vue', async () => {
  const { h } = await import('vue')
  return {
    default: {
      name: 'MonacoEditor',
      props: ['modelValue', 'filename', 'height', 'readonly', 'runtime', 'language'],
      emits: ['update:modelValue'],
      setup(props, { emit }) {
        return () => h('textarea', {
          class:            'monaco-stub',
          'data-filename':  props.filename ?? '',
          'data-language':  props.language ?? '',
          readonly:         props.readonly ? true : undefined,
          value:            props.modelValue ?? '',
          onInput:          (e) => emit('update:modelValue', e.target.value),
        })
      },
    },
  }
})

const i18n = makeI18n()
let pinia
let router
const mounted = []

const Q_MANIFEST = `apiVersion: runr.sh/v1
kind: Runtime
id: q
name: Q
extensions: [q]
versions:
  - version: "1.0"
    dockerfile: Dockerfile
run:
  mode: persistent
  command: ["q", "/app/code/{file}"]
`

const adminList = () => [catalog.python, { ...catalog.nodejs, is_enabled: false }, catalog.c, catalog.q]

function listResponse(list, abilities = { manage: true, use_custom: true }) {
  return { data: { data: list, abilities, custom_enabled: true } }
}

async function mountManager(abilities = { manage: true, use_custom: true }) {
  const store = useRuntimesStore()
  store.abilities = abilities
  runtimesApi.list.mockResolvedValue(listResponse(abilities.manage ? adminList() : [catalog.python, catalog.q], abilities))

  const wrapper = mount(RuntimesManager, { global: { plugins: [pinia, i18n, router] } })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

const row      = (wrapper, id) => wrapper.find(`[data-runtime-row="${id}"]`)
const button   = (scope, title) => scope.find(`button[title="${title}"]`)
const byText   = (wrapper, text) => wrapper.findAll('button').filter(b => b.text().includes(text))
const debounce = () => new Promise(resolve => setTimeout(resolve, 700))

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { render: () => null } },
      { path: '/projects/:id', name: 'project-detail', component: { render: () => null } },
    ],
  })
  settingsApi.get.mockResolvedValue({ data: { regions, executor_images: [] } })
  useToast().toasts.value = []
})

afterEach(() => {
  while (mounted.length) mounted.pop().unmount()
})

describe('RuntimesManager list', () => {
  it('lists every runtime for a runtime manager, with source badges', async () => {
    const wrapper = await mountManager()

    expect(runtimesApi.list).toHaveBeenCalledWith({ all: 1 })
    expect(wrapper.findAll('[data-runtime-row]').map(r => r.attributes('data-runtime-row'))).toEqual(['nodejs', 'python', 'c', 'q'])
    expect(row(wrapper, 'python').text()).toContain('Built-in')
    expect(row(wrapper, 'c').text()).toContain('Platform')
    expect(row(wrapper, 'c').text()).toContain('compiled')
    expect(row(wrapper, 'q').text()).toContain('Custom')
    expect(row(wrapper, 'q').text()).toContain('persistent')
    expect(row(wrapper, 'nodejs').text()).toContain('Disabled')
    expect(row(wrapper, 'c').text()).toContain('eu-1')        // regions ['r1'] by name
    expect(row(wrapper, 'python').text()).toContain('All regions')
  })

  it('toggles a runtime through PUT /runtimes/{id}', async () => {
    runtimesApi.update.mockResolvedValue({ data: { data: { ...catalog.c, is_enabled: false } } })
    const wrapper = await mountManager()

    await button(row(wrapper, 'c'), 'Disable').trigger('click')
    await flushPromises()

    expect(runtimesApi.update).toHaveBeenCalledWith('c', { is_enabled: false })
    expect(row(wrapper, 'c').text()).toContain('Disabled')
    expect(useToast().toasts.value.map(t => t.message)).toContain('C disabled.')
  })

  it('offers no delete for built-ins and opens their manifest read-only', async () => {
    runtimesApi.get.mockResolvedValue({ data: { data: {
      ...catalog.python, manifest: { id: 'python' }, manifest_source: 'apiVersion: runr.sh/v1\nkind: Runtime\nid: python\n', files: [],
    } } })
    const wrapper = await mountManager()

    expect(button(row(wrapper, 'python'), 'Delete').exists()).toBe(false)
    expect(button(row(wrapper, 'q'), 'Delete').exists()).toBe(true)

    await button(row(wrapper, 'python'), 'Edit').trigger('click')
    await flushPromises()

    const manifest = wrapper.find('textarea.monaco-stub[data-filename="runtime.yaml"]')
    expect(manifest.attributes('readonly')).toBeDefined()
    expect(manifest.element.value).toContain('id: python')
    expect(wrapper.text()).toContain('Built-in runtime: the manifest ships with RunR.')
    expect(wrapper.text()).not.toContain('Upload files')
    expect(runtimesApi.validate).not.toHaveBeenCalled()
  })

  it('shows only the actions a custom-runtime user may take', async () => {
    const wrapper = await mountManager({ manage: false, use_custom: true })

    expect(runtimesApi.list).toHaveBeenCalledWith({})
    expect(button(row(wrapper, 'python'), 'Edit').exists()).toBe(false)
    expect(button(row(wrapper, 'q'), 'Edit').exists()).toBe(true)
    expect(button(row(wrapper, 'q'), 'Delete').exists()).toBe(true)
    expect(byText(wrapper, 'Add Runtime')).toHaveLength(1)
  })
})

describe('RuntimesManager add / edit', () => {
  it('validates the manifest live and lists problems with their JSON paths', async () => {
    runtimesApi.validate
      .mockRejectedValueOnce(apiError(422, {
        message: 'The runtime manifest is invalid.',
        problems: [
          { path: '/versions/0/image', message: 'Must be a container image reference.' },
          { path: '/run/mode', message: 'Must be one of: http, exec, persistent.' },
        ],
      }))
      .mockResolvedValue({ data: { valid: true, manifest: { id: 'q', name: 'Q', versions: [{ version: '1.0', dockerfile: 'Dockerfile' }] } } })
    const wrapper = await mountManager()

    await byText(wrapper, 'Add Runtime')[0].trigger('click')
    await debounce()
    await flushPromises()

    // starter manifest was validated
    expect(runtimesApi.validate).toHaveBeenCalledTimes(1)
    expect(wrapper.text()).toContain('Manifest problems: 2')
    expect(wrapper.text()).toContain('/versions/0/image')
    expect(wrapper.text()).toContain('Must be a container image reference.')
    expect(wrapper.text()).toContain('/run/mode')

    await wrapper.find('textarea.monaco-stub[data-filename="runtime.yaml"]').setValue(Q_MANIFEST)
    await debounce()
    await flushPromises()

    expect(runtimesApi.validate).toHaveBeenLastCalledWith(Q_MANIFEST, undefined)
    expect(wrapper.text()).toContain('Valid manifest: Q (q), versions: 1')
    // the dockerfile version references a file that is not added yet
    expect(wrapper.text()).toContain('Version 1.0 builds from Dockerfile: add the file below.')
  })

  it('saves {manifest, files, regions} and lists the new runtime', async () => {
    runtimesApi.validate.mockResolvedValue({ data: { valid: true, manifest: { id: 'q2', name: 'Q2', versions: [{ version: '1.0', dockerfile: 'Dockerfile' }] } } })
    runtimesApi.create.mockResolvedValue({ data: { data: { ...catalog.q, id: 'q2', name: 'Q2', source: 'platform' } } })
    const wrapper = await mountManager()

    await byText(wrapper, 'Add Runtime')[0].trigger('click')
    await flushPromises()
    const manifest = Q_MANIFEST.replace('id: q', 'id: q2').replace('name: Q', 'name: Q2')
    await wrapper.find('textarea.monaco-stub[data-filename="runtime.yaml"]').setValue(manifest)

    await byText(wrapper, 'New file')[0].trigger('click')
    await wrapper.find('textarea.monaco-stub[data-filename="Dockerfile"]').setValue('FROM alpine:3.21\n')
    await debounce()
    await flushPromises()
    expect(wrapper.text()).not.toContain('builds from Dockerfile: add the file below.')

    await byText(wrapper, 'Selected regions')[0].trigger('click')
    await wrapper.findAll('input[type="checkbox"]')[0].setValue(true)   // eu-1

    await byText(wrapper, 'Add Runtime').at(-1).trigger('click')        // modal footer
    await flushPromises()

    expect(runtimesApi.create).toHaveBeenCalledWith({
      manifest,
      regions: ['r1'],
      files:   { Dockerfile: 'FROM alpine:3.21\n' },
    })
    expect(row(wrapper, 'q2').exists()).toBe(true)
    expect(wrapper.find('textarea.monaco-stub').exists()).toBe(false)   // modal closed
    expect(useToast().toasts.value.map(t => t.message)).toContain('Q2 added.')
  })

  it('shows save problems of a 422 from POST /runtimes', async () => {
    runtimesApi.validate.mockResolvedValue({ data: { valid: true, manifest: { id: 'q', name: 'Q', versions: [] } } })
    runtimesApi.create.mockRejectedValue(apiError(422, {
      message: 'The runtime manifest is invalid.',
      problems: [{ path: '/id', message: 'The runtime id "q" is taken.' }],
    }))
    const wrapper = await mountManager()

    await byText(wrapper, 'Add Runtime')[0].trigger('click')
    await flushPromises()
    await byText(wrapper, 'Add Runtime').at(-1).trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('/id')
    expect(wrapper.text()).toContain('The runtime id "q" is taken.')
    expect(wrapper.text()).toContain('The manifest is not valid; see the problems above.')
  })
})

describe('RuntimesManager actions', () => {
  it('shows the 409 message when projects still use the runtime', async () => {
    runtimesApi.destroy.mockRejectedValue(apiError(409, { message: 'Projects still use the runtime q (2).' }))
    const wrapper = await mountManager()

    await button(row(wrapper, 'q'), 'Delete').trigger('click')
    expect(useConfirm().current.value?.title).toBe('Delete Runtime')
    useConfirm().accept()
    await flushPromises()

    expect(runtimesApi.destroy).toHaveBeenCalledWith('q')
    expect(useToast().toasts.value).toContainEqual(expect.objectContaining({ message: 'Projects still use the runtime q (2).', type: 'error' }))
    expect(row(wrapper, 'q').exists()).toBe(true)
  })

  it('removes a runtime after a confirmed delete', async () => {
    runtimesApi.destroy.mockResolvedValue({ status: 204 })
    const wrapper = await mountManager()

    await button(row(wrapper, 'q'), 'Delete').trigger('click')
    useConfirm().accept()
    await flushPromises()

    expect(row(wrapper, 'q').exists()).toBe(false)
  })

  it('test-deploys into the chosen region and opens the created project', async () => {
    runtimesApi.testDeploy.mockResolvedValue({ data: { data: { id: 'p-123', name: 'rt-q-abc' } } })
    const push = vi.spyOn(router, 'push')
    const wrapper = await mountManager()

    await button(row(wrapper, 'q'), 'Test deploy').trigger('click')
    await flushPromises()
    // ready regions only: eu-1 and us-1 (lab has no cluster)
    expect(byText(wrapper, 'lab')).toHaveLength(0)
    await byText(wrapper, 'eu-1')[0].trigger('click')
    await byText(wrapper, 'Create and deploy')[0].trigger('click')
    await flushPromises()

    expect(runtimesApi.testDeploy).toHaveBeenCalledWith('q', { knative_region_id: 'r1', version: '1.0' })
    expect(push).toHaveBeenCalledWith({ name: 'project-detail', params: { id: 'p-123' } })
  })

  it('starts a region image build of a version built from source', async () => {
    runtimesApi.builds
      .mockResolvedValueOnce({ data: { data: [] } })
      .mockResolvedValue({ data: { data: [{ version: '1.0', knative_region_id: 'r1', region_name: 'eu-1', status: 'building', image: null }] } })
    runtimesApi.build.mockResolvedValue({ data: { data: [{ version: '1.0', knative_region_id: 'r1', region_name: 'eu-1', status: 'pending', image: null }] } })
    const wrapper = await mountManager()

    // only q has a version built from source
    expect(button(row(wrapper, 'c'), 'Image builds').exists()).toBe(false)
    await button(row(wrapper, 'q'), 'Image builds').trigger('click')
    await flushPromises()

    expect(runtimesApi.builds).toHaveBeenCalledWith('q')
    expect(wrapper.text()).toContain('not built')
    await byText(wrapper, 'Build')[0].trigger('click')
    await flushPromises()

    expect(runtimesApi.build).toHaveBeenCalledWith('q', { knative_region_id: 'r1', version: '1.0' })
    expect(wrapper.text()).toContain('building')
  })
})
