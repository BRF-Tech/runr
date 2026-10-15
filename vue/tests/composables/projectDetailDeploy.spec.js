import { describe, it, expect, beforeEach, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('vue-router', async (orig) => ({
  ...(await orig()),
  useRoute:            () => ({ params: { id: 'p-1' }, query: {}, hash: '' }),
  useRouter:           () => ({ replace: vi.fn(), push: vi.fn() }),
  onBeforeRouteLeave:  () => {},
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
import { withPlugins } from '../helpers'

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
})
