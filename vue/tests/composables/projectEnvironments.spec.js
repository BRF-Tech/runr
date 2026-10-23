import { describe, it, expect, beforeEach, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('vue-router', async (orig) => ({
  ...(await orig()),
  useRoute:            () => ({ params: { id: 'p-1' }, query: {}, hash: '' }),
  useRouter:           () => ({ replace: vi.fn(), push: vi.fn() }),
  onBeforeRouteLeave:  () => {},
}))

// Every API call resolves to { data: {} } unless a test says otherwise.
const { proxied } = vi.hoisted(() => ({
  proxied: () => new Proxy({}, { get: (target, key) => (target[key] ??= vi.fn(async () => ({ data: {} }))) }),
}))
vi.mock('@/api/projects', () => ({ projectsApi: proxied() }))
vi.mock('@/api/environments', () => ({ environmentsApi: proxied() }))

import { projectsApi as api } from '@/api/projects'
import { environmentsApi as envApi } from '@/api/environments'
import { useProjectDetail } from '@/composables/useProjectDetail'
import { withPlugins } from '../helpers'

// The project page follows one environment (docs/environments.md): its deployments, the deploy
// and the rollback with the config of a deployment (#626).

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

const environments = [
  { id: 'e-st', name: 'staging', is_primary: false, url: 'https://shop-staging.example', deployment: null },
  { id: 'e-pr', name: 'production', is_primary: true, url: 'https://shop.example', deployment: null },
]

beforeEach(() => {
  for (const key of Object.keys(api)) api[key].mockReset().mockResolvedValue({ data: {} })
  for (const key of Object.keys(envApi)) envApi[key].mockReset().mockResolvedValue({ data: {} })
})

describe('environments on the project page', () => {
  it('loads the selected environment\'s deployments and deploys there', async () => {
    const d = harness()
    d.project.value = { id: 'p-1', service_type: 'caas' }
    envApi.list.mockResolvedValue({ data: { data: environments, abilities: { manage: true, promote: true, approve: false } } })
    api.deployments.mockResolvedValue({ data: { data: [] } })

    d.activeTab.value = 'deployments'
    await flushPromises()
    expect(api.deployments).toHaveBeenLastCalledWith('p-1')
    expect(d.environments.value.map(e => e.name)).toEqual(['staging', 'production'])
    expect(d.environmentAbilities.value.promote).toBe(true)

    api.deployments.mockResolvedValueOnce({ data: { data: [{ id: 'd-st', environment: 'staging' }] } })
    await d.selectEnvironment('staging')
    await flushPromises()
    expect(api.deployments).toHaveBeenLastCalledWith('p-1', 'staging')
    expect(d.deployments.value.map(x => x.id)).toEqual(['d-st'])
    expect(d.currentEnvironment.value.url).toBe('https://shop-staging.example')

    await d.confirmDeploy({ versionId: 'v-2' })
    expect(api.deploy).toHaveBeenCalledWith('p-1', { version_id: 'v-2', environment: 'staging' })

    // The deploy modal names production: the list follows it
    await d.confirmDeploy({ versionId: 'v-1', environment: 'production', restoreConfig: true })
    expect(api.deploy).toHaveBeenLastCalledWith('p-1', { version_id: 'v-1', restore_config: true })
    expect(d.environment.value).toBe(null)
  })

  // Each environment rolls out with its own strategy (docs/deploy-strategies.md#environments)
  it('loads the rollout of the environment shown; an answer for the one left is dropped', async () => {
    const d = harness()
    d.project.value = { id: 'p-1', service_type: 'faas' }
    api.deployments.mockResolvedValue({ data: { data: [] } })
    api.rollout.mockResolvedValue({ data: { data: { id: 'r-pr', environment: 'production' } } })
    d.activeTab.value = 'deployments'
    await flushPromises()
    expect(api.rollout).toHaveBeenLastCalledWith('p-1')
    expect(d.rollout.value.id).toBe('r-pr')

    let answer
    api.rollout.mockImplementationOnce(() => new Promise((resolve) => { answer = resolve }))
    const switching = d.selectEnvironment('staging')
    expect(d.rollout.value).toBe(null)
    await flushPromises()
    expect(api.rollout).toHaveBeenLastCalledWith('p-1', 'staging')

    // back to production before staging's answer arrives
    api.rollout.mockResolvedValueOnce({ data: { data: { id: 'r-pr', environment: 'production' } } })
    await d.selectEnvironment('production')
    await flushPromises()
    answer({ data: { data: { id: 'r-st', environment: 'staging' } } })
    await switching
    await flushPromises()
    expect(d.rollout.value.id).toBe('r-pr')
  })

  it('rolls back to a deployment, keeping the current config when asked', async () => {
    const d = harness()
    d.project.value = { id: 'p-1', service_type: 'caas' }
    d.activeTab.value = 'deployments'
    await flushPromises()

    await d.rollbackTo({ id: 'd-1', version: { label: 'v1' } })
    expect(api.rollback).toHaveBeenCalledWith('p-1', 'd-1', false)

    await d.rollbackTo({ id: 'd-1', version: { label: 'v1' } }, true)
    expect(api.rollback).toHaveBeenLastCalledWith('p-1', 'd-1', true)
  })

  it('offers the environments tab next to the deployments', () => {
    const d = harness()
    d.project.value = { id: 'p-1', service_type: 'faas' }
    expect(d.tabs.value.map(t => t.id).slice(0, 3)).toEqual(['versions', 'deployments', 'environments'])
  })
})
