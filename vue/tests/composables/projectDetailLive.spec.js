import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'
import { deferred, withPlugins } from '../helpers'

// The project page with its websocket sync (usePlatformSync) while reloads are in flight: what
// a broadcast changes during a reload is applied to the reload's answer, which may have been
// read before it.

const push = vi.hoisted(() => vi.fn())
const confirm = vi.hoisted(() => vi.fn())

vi.mock('vue-router', async (orig) => ({
  ...(await orig()),
  useRoute:            () => ({ params: { id: 'p-1' }, query: {}, hash: '' }),
  useRouter:           () => ({ replace: vi.fn(), push }),
  onBeforeRouteLeave:  () => {},
  onBeforeRouteUpdate: () => {},
}))
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm }) }))

// Every project API call resolves to { data: {} } unless a test says otherwise.
vi.mock('@/api/projects', () => {
  const fns = {}
  return {
    projectsApi: new Proxy(fns, {
      get: (target, key) => (target[key] ??= vi.fn(async () => ({ data: {} }))),
    }),
  }
})

import { projectsApi as api } from '@/api/projects'
import { useProjectDetail } from '@/composables/useProjectDetail'
import { usePlatformSync } from '@/composables/usePlatformSync'
import { dispatchPlatformEvent } from '@/composables/useJobEvents'
import { useToast } from '@/composables/useToast'

function harness() {
  let exposed
  const Comp = defineComponent({
    setup() {
      exposed = useProjectDetail()
      usePlatformSync({
        project: exposed.project, versions: exposed.versions, deployments: exposed.deployments,
        journals: exposed.journals, onProjectDeleted: exposed.onProjectDeleted,
      })
      return () => h('div')
    },
  })
  const wrapper = mount(Comp, { global: withPlugins('en').global })
  mounted.push(wrapper)
  return { d: exposed, wrapper }
}

// The pages' event handlers are global (useJobEvents) until they unmount
const mounted = []
afterEach(() => mounted.splice(0).forEach(w => w.unmount()))

beforeEach(() => {
  for (const key of Object.keys(api)) api[key].mockReset().mockResolvedValue({ data: {} })
  api.deployments.mockResolvedValue({ data: { data: [] } })
  api.versions.mockResolvedValue({ data: { data: [] } })
  push.mockReset()
  confirm.mockReset().mockResolvedValue(true)
  useToast().toasts.value = []
})

describe('project page: broadcasts during a reload', () => {
  it('keeps a deployment status that changed while the list reloaded after a deploy', async () => {
    const { d } = harness()
    d.project.value = { id: 'p-1', name: 'demo', service_type: 'faas' }
    d.activeTab.value = 'deployments'
    await flushPromises()

    const list = deferred()
    api.deployments.mockReturnValueOnce(list.promise)
    const deploying = d.confirmDeploy({ versionId: 'v-2' })
    await flushPromises()                                        // the list GET is in flight

    dispatchPlatformEvent('deployment.created', { project_id: 'p-1', deployment: { id: 'd-new', status: 'pending' } })
    dispatchPlatformEvent('deployment.status', { project_id: 'p-1', deployment_id: 'd-new', status: 'active' })
    dispatchPlatformEvent('deployment.status', { project_id: 'p-1', deployment_id: 'd-old', status: 'shut_down' })

    // read before the events: the new deployment pending, the old one still active
    list.resolve({ data: { data: [{ id: 'd-new', status: 'pending' }, { id: 'd-old', status: 'active' }] } })
    await deploying
    await flushPromises()

    expect(d.deployments.value.map(x => [x.id, x.status])).toEqual([['d-new', 'active'], ['d-old', 'shut_down']])
  })

  it('keeps a deployment broadcast while the list reloaded and the answer does not have yet', async () => {
    const { d } = harness()
    d.project.value = { id: 'p-1', name: 'demo', service_type: 'faas' }
    const list = deferred()
    api.deployments.mockReturnValueOnce(list.promise)
    d.activeTab.value = 'deployments'
    await flushPromises()

    dispatchPlatformEvent('deployment.created', { project_id: 'p-1', deployment: { id: 'd-9', status: 'pending' } })
    list.resolve({ data: { data: [{ id: 'd-1', status: 'active' }] } })
    await flushPromises()
    expect(d.deployments.value.map(x => x.id)).toEqual(['d-9', 'd-1'])
  })

  it('keeps a finished build that was broadcast while the versions reloaded', async () => {
    const { d } = harness()
    d.project.value = { id: 'p-1', name: 'demo', service_type: 'faas' }
    const list = deferred()
    api.versions.mockReturnValueOnce(list.promise)
    d.versionsLoaded.value = false
    const loading = d.openDeployModal()          // loads the versions first
    await flushPromises()

    dispatchPlatformEvent('build.completed', { project_id: 'p-1', version: 3 })
    list.resolve({ data: { data: [{ id: 'v-3', number: 3, build_status: 'building' }] } })
    await loading
    expect(d.versions.value[0].build_status).toBe('ready')
  })
})

describe('project page: deleted', () => {
  it('keeps the page while its own delete runs, then opens the projects list', async () => {
    const { d } = harness()
    d.project.value = { id: 'p-1', name: 'demo', service_type: 'faas' }
    const destroyed = deferred()
    api.destroy.mockReturnValueOnce(destroyed.promise)

    const deleting = d.deleteProject()
    await flushPromises()
    dispatchPlatformEvent('project.deleted', { project_id: 'p-1' })   // before the DELETE answers
    expect(d.project.value).toMatchObject({ id: 'p-1' })
    expect(push).not.toHaveBeenCalled()

    destroyed.resolve({ data: {} })
    await deleting
    expect(push).toHaveBeenCalledWith('/projects')
    expect(useToast().toasts.value).toHaveLength(0)
  })

  it('leaves for the projects list with a note when the project was deleted elsewhere', async () => {
    const { d } = harness()
    d.project.value = { id: 'p-1', name: 'demo', service_type: 'faas' }

    dispatchPlatformEvent('project.deleted', { project_id: 'p-1' })
    expect(push).toHaveBeenCalledWith('/projects')
    expect(useToast().toasts.value.at(-1).message).toBe('"demo" was deleted.')
    expect(d.project.value).toMatchObject({ id: 'p-1' })   // no blank page before the navigation
  })
})
