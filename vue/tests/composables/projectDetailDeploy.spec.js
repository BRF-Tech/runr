import { describe, it, expect, beforeEach, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('vue-router', async (orig) => ({
  ...(await orig()),
  useRoute:            () => ({ params: { id: 'p-1' }, query: {}, hash: '' }),
  useRouter:           () => ({ replace: vi.fn(), push: vi.fn() }),
  onBeforeRouteLeave:  () => {},
  onBeforeRouteUpdate: () => {},
}))

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
import { useToast } from '@/composables/useToast'
import { deferred, withPlugins } from '../helpers'

function harness() {
  let exposed
  const Comp = defineComponent({
    setup() {
      exposed = useProjectDetail()
      return () => h('div')
    },
  })
  mount(Comp, { global: withPlugins('en').global })
  return exposed
}

beforeEach(() => {
  for (const key of Object.keys(api)) api[key].mockReset().mockResolvedValue({ data: {} })
})

describe('confirmDeploy', () => {
  it('loads the deployments list again after a deploy, including the new deployment', async () => {
    const d = harness()
    d.project.value = { id: 'p-1', service_type: 'faas' }

    api.deployments.mockResolvedValueOnce({ data: { data: [{ id: 'd-old' }] } })
    d.activeTab.value = 'deployments'
    await flushPromises()
    expect(d.deployments.value.map(x => x.id)).toEqual(['d-old'])

    api.deployments.mockResolvedValueOnce({ data: { data: [{ id: 'd-new' }, { id: 'd-old' }] } })
    await d.confirmDeploy({ versionId: 'v-2' })
    await flushPromises()

    expect(api.deploy).toHaveBeenCalledWith('p-1', { version_id: 'v-2' })
    expect(d.deployments.value.map(x => x.id)).toEqual(['d-new', 'd-old'])
    expect(d.deploymentsLoaded.value).toBe(true)
  })

  it('switches to the deployments tab and fetches the list when it was never loaded', async () => {
    const d = harness()
    d.project.value = { id: 'p-1', service_type: 'faas' }
    d.activeTab.value = 'versions'
    await flushPromises()

    api.deployments.mockResolvedValueOnce({ data: { data: [{ id: 'd-new' }] } })
    await d.confirmDeploy({ versionId: 'v-1' })
    await flushPromises()

    expect(d.activeTab.value).toBe('deployments')
    expect(api.deployments).toHaveBeenCalledTimes(1)
    expect(d.deployments.value.map(x => x.id)).toEqual(['d-new'])
  })

  it('a version built again for the region\'s registry says it deploys when the build is ready', async () => {
    useToast().toasts.value = []
    const d = harness()
    d.project.value = { id: 'p-1', service_type: 'faas' }
    d.activeTab.value = 'versions'
    await flushPromises()

    api.deploy.mockResolvedValueOnce({ data: { message: 'v1 is built again…', queued_deploy: { id: 'q-1', version: 1, status: 'waiting' } } })
    api.versions.mockResolvedValueOnce({ data: { data: [{ id: 'v-1', number: 1, build_status: 'pending' }] } })
    await d.confirmDeploy({ versionId: 'v-1' })
    await flushPromises()

    const toast = useToast().toasts.value.at(-1)
    expect(toast.type).toBe('success')
    expect(toast.message).toBe("v1 is built again for this region's registry (it was built before the region moved to another cluster); it deploys when the build is ready.")
    expect(d.versions.value.map(v => v.build_status)).toEqual(['pending'])
    expect(d.showDeployModal.value).toBe(false)
    expect(d.activeTab.value).toBe('deployments')
  })
})

describe('deploy strategies and rollouts', () => {
  const rollout = { id: 'r-1', project_id: 'p-1', deployment_id: 'd-new', stable_deployment_id: 'd-old', strategy: 'canary', status: 'progressing', traffic: { candidate: 10, stable: 90 }, created_at: '2026-10-21T10:00:00Z' }

  it('sends a one-off strategy with the deploy, and keeps it for a policy retry', async () => {
    const d = harness()
    d.project.value = { id: 'p-1', service_type: 'faas' }
    d.activeTab.value = 'deployments'
    await flushPromises()

    await d.confirmDeploy({ versionId: 'v-2', strategy: 'direct' })
    expect(api.deploy).toHaveBeenCalledWith('p-1', { version_id: 'v-2', strategy: 'direct' })

    api.deploy.mockRejectedValueOnce(Object.assign(new Error('x'), { response: { status: 422, data: { code: 'supply_chain_policy', message: 'Blocked' } } }))
    await d.confirmDeploy({ versionId: 'v-3', strategy: 'blue_green' })
    expect(d.policyBlock.value).toMatchObject({ versionId: 'v-3', strategy: 'blue_green', code: 'supply_chain_policy' })
  })

  it('loads the project\'s current rollout with the deployments', async () => {
    const d = harness()
    d.project.value = { id: 'p-1', service_type: 'faas' }
    api.deployments.mockResolvedValueOnce({ data: { data: [{ id: 'd-new', status: 'running', rollout, rollout_role: 'candidate' }, { id: 'd-old', status: 'active', rollout_role: 'stable' }] } })
    api.rollout.mockResolvedValueOnce({ data: { data: rollout } })
    d.activeTab.value = 'deployments'
    await flushPromises()

    expect(api.rollout).toHaveBeenCalledWith('p-1')
    expect(d.rollout.value).toEqual(rollout)

    // an action's answer: the current rollout and the new deployment follow it
    d.applyRollout({ ...rollout, status: 'promoted', traffic: { candidate: 100, stable: 0 }, keep_until: '2026-10-21T10:30:00Z' })
    expect(d.rollout.value.status).toBe('promoted')
    expect(d.deployments.value[0].rollout.traffic.candidate).toBe(100)
    expect(d.deployments.value[1].rollout_role).toBe('previous')
  })

  it('an API without rollouts leaves none and still lists the deployments', async () => {
    const d = harness()
    d.project.value = { id: 'p-1', service_type: 'faas' }
    api.deployments.mockResolvedValueOnce({ data: { data: [{ id: 'd-1' }] } })
    api.rollout.mockRejectedValueOnce(Object.assign(new Error('x'), { response: { status: 404, data: {} } }))
    d.activeTab.value = 'deployments'
    await flushPromises()

    expect(d.rollout.value).toBeNull()
    expect(d.deployments.value.map(x => x.id)).toEqual(['d-1'])
  })
})

// Answers arriving late or failing after the action succeeded (deferred promises)
describe('project detail: answers arriving late', () => {
  it('a deploy whose follow-up reload fails still closes the modal and does not throw', async () => {
    const d = harness()
    d.project.value = { id: 'p-1', service_type: 'faas' }
    d.activeTab.value = 'deployments'
    await flushPromises()
    d.showDeployModal.value = true

    api.deployments.mockRejectedValueOnce(Object.assign(new Error('HTTP 500'), { response: { status: 500 } }))
    await expect(d.confirmDeploy({ versionId: 'v-2' })).resolves.toBeUndefined()
    expect(api.deploy).toHaveBeenCalledWith('p-1', { version_id: 'v-2' })
    expect(d.showDeployModal.value).toBe(false)
  })

  it('a deployments list read before the deploy does not replace the one read after it', async () => {
    const d = harness()
    d.project.value = { id: 'p-1', service_type: 'faas' }
    const before = deferred()
    api.deployments.mockReturnValueOnce(before.promise)
    d.activeTab.value = 'deployments'          // the tab's first load: still in flight
    await flushPromises()

    api.deployments.mockResolvedValueOnce({ data: { data: [{ id: 'd-new' }, { id: 'd-old' }] } })
    await d.confirmDeploy({ versionId: 'v-2' })
    before.resolve({ data: { data: [{ id: 'd-old' }] } })
    await flushPromises()
    expect(d.deployments.value.map(x => x.id)).toEqual(['d-new', 'd-old'])
  })

  it('keeps the files of the version picked last when an older pick answers late', async () => {
    const d = harness()
    d.project.value = { id: 'p-1', service_type: 'xaas', runtime: 'python', config: { filename: 'main.py' } }
    d.versions.value = [{ id: 'v-2', number: 2 }, { id: 'v-1', number: 1 }]
    const older = deferred()
    api.versionFiles.mockReturnValueOnce(older.promise)
    api.getCode.mockResolvedValueOnce({ data: { code: 'latest' } })

    const pickOld = d.loadVersionFiles(d.versions.value[1])
    await d.loadVersionFiles(d.versions.value[0])
    older.resolve({ data: { files: { 'main.py': 'old' } } })
    await pickOld
    await flushPromises()

    expect(d.selectedVersion.value.id).toBe('v-2')
    expect(d.selectedVersionFiles.value).toEqual({ 'main.py': 'latest' })
    expect(d.isDirty.value).toBe(false)
    expect(d.versionFilesLoading.value).toBe(false)
  })

  it('keeps what is typed while a save runs, as unsaved changes on the new version', async () => {
    const d = harness()
    d.project.value = { id: 'p-1', service_type: 'xaas', runtime: 'python', config: { filename: 'main.py' } }
    d.versions.value = [{ id: 'v-1', number: 1 }]
    api.getCode.mockResolvedValueOnce({ data: { code: 'def handler(event, context): return 1' } })
    await d.loadVersionFiles(d.versions.value[0])

    d.selectedVersionFiles.value = { 'main.py': 'def handler(event, context): return 2' }
    const saved = deferred()
    api.saveCode.mockReturnValueOnce(saved.promise)
    api.versions.mockResolvedValueOnce({ data: { data: [{ id: 'v-2', number: 2 }, { id: 'v-1', number: 1 }] } })
    const saving = d.save()
    // typed on while the request runs
    d.selectedVersionFiles.value = { 'main.py': 'def handler(event, context): return 3' }
    saved.resolve({ data: {} })
    await saving
    await flushPromises()

    expect(api.saveCode).toHaveBeenCalledWith('p-1', 'def handler(event, context): return 2', undefined)
    expect(d.selectedVersion.value.id).toBe('v-2')
    expect(d.selectedVersionFiles.value['main.py']).toContain('return 3')
    expect(d.isDirty.value).toBe(true)
  })
})
