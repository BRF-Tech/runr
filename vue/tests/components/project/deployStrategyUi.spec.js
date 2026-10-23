import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import DeploymentTable from '@/components/DeploymentTable.vue'
import DeployModal from '@/components/DeployModal.vue'
import ProjectHeader from '@/components/ProjectHeader.vue'
import { clockTime } from '@/utils/deployStrategy'
import { withPlugins } from '../../helpers'

vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRouter: () => ({ push: vi.fn() }), useRoute: () => ({ query: {} }) }))

// Where a project's deploy strategy shows outside its settings: the Deployments tab (strategy
// button, rollout chips of the deployments), the deploy modal (one-off override), the header badge.

const find = (w, id) => w.find(`[data-test="${id}"]`)
const inMinutes = (m) => new Date(Date.now() + m * 60_000).toISOString()

const project = (over = {}) => ({
  id: 'p1', name: 'orders-api', service_type: 'faas', runtime: 'nodejs', runtime_version: '22',
  config: { trigger_type: 'http' }, deploy_strategy: 'canary', ...over,
})

const rollout = (over = {}) => ({
  id: 'r2', project_id: 'p1', deployment_id: 'd2', stable_deployment_id: 'd1', strategy: 'canary', status: 'progressing',
  traffic: { candidate: 10, stable: 90 }, keep_until: null, released_at: null, finished_at: null, created_at: inMinutes(-5), ...over,
})

const deployment = (id, over = {}) => ({
  id, status: 'active', version: { id: `v-${id}`, label: id.replace('d', 'v') }, k8s_job_name: 'orders-api',
  deployed_by: 'Ada', created_at: inMinutes(-10), rollout: null, rollout_role: null, ...over,
})

function mountTable(props) {
  return mount(DeploymentTable, { props, global: withPlugins().global })
}

describe('DeploymentTable: strategy and rollout chips', () => {
  it('opens the deploy strategy from the header', async () => {
    const w = mountTable({ deployments: [], project: project() })
    expect(find(w, 'deploy-strategy-open').text()).toBe('Deploy strategy: Canary')
    await find(w, 'deploy-strategy-open').trigger('click')
    expect(w.emitted('configure-strategy')).toHaveLength(1)

    // queue / schedule functions deploy directly whatever is stored
    expect(find(mountTable({ deployments: [], project: project({ config: { trigger_type: 'queue' } }) }), 'deploy-strategy-open').text()).toBe('Deploy strategy: Direct')
    expect(find(mountTable({ deployments: [], project: project({ deploy_strategy: undefined }) }), 'deploy-strategy-open').text()).toBe('Deploy strategy: Direct')
    // retired AI types have no deploys to steer
    expect(find(mountTable({ deployments: [], project: project({ service_type: 'aaas' }) }), 'deploy-strategy-open').exists()).toBe(false)
  })

  it('marks the new deployment and the one serving next to it during a canary', () => {
    const r = rollout()
    const w = mountTable({
      project: project(),
      currentRollout: r,
      deployments: [
        deployment('d2', { status: 'running', rollout: r, rollout_role: 'candidate' }),
        deployment('d1', { rollout_role: 'stable' }),
      ],
    })
    const chips = w.findAll('[data-test="rollout-chip"]').map(c => c.text())
    expect(chips).toEqual(['New · canary 10 %', 'Current · 90 %'])
  })

  it('a blue-green waiting for promotion, then the previous revision kept warm', () => {
    const waiting = rollout({ strategy: 'blue_green', status: 'waiting_promotion', traffic: { candidate: 0, stable: 100 } })
    let w = mountTable({
      project: project({ deploy_strategy: 'blue_green' }),
      currentRollout: waiting,
      deployments: [deployment('d2', { status: 'running', rollout: waiting, rollout_role: 'candidate' }), deployment('d1', { rollout_role: 'stable' })],
    })
    expect(w.findAll('[data-test="rollout-chip"]').map(c => c.text())).toEqual(['New · waiting for promotion', 'Current · 100 %'])

    const keepUntil = inMinutes(14)
    const promoted = { ...waiting, status: 'promoted', traffic: { candidate: 100, stable: 0 }, keep_until: keepUntil }
    w = mountTable({
      project: project({ deploy_strategy: 'blue_green' }),
      currentRollout: promoted,
      deployments: [deployment('d2', { rollout: promoted, rollout_role: 'candidate' }), deployment('d1', { status: 'running', rollout_role: 'previous' })],
    })
    expect(w.findAll('[data-test="rollout-chip"]').map(c => c.text())).toEqual(['New · promoted', `Previous · warm until ${clockTime(keepUntil, 'en')}`])
  })

  it('a finished rollout says how it ended; an API without roles derives them', () => {
    const ended = rollout({ status: 'rolled_back', traffic: { candidate: 0, stable: 100 }, finished_at: inMinutes(-1) })
    let w = mountTable({
      project: project(),
      currentRollout: ended,
      deployments: [deployment('d2', { status: 'shut_down', rollout: ended }), deployment('d1')],
    })
    expect(w.findAll('[data-test="rollout-chip"]').map(c => c.text())).toEqual(['Canary · Rolled back'])

    const r = rollout()
    const { rollout_role: _a, ...candidate } = deployment('d2', { status: 'running', rollout: r })
    const { rollout_role: _b, ...stable } = deployment('d1')
    w = mountTable({ project: project(), currentRollout: r, deployments: [candidate, stable] })
    expect(w.findAll('[data-test="rollout-chip"]').map(c => c.text())).toEqual(['New · canary 10 %', 'Current · 90 %'])
  })

  it('shows no chips without rollouts', () => {
    const w = mountTable({ project: project({ deploy_strategy: 'direct' }), deployments: [deployment('d1')] })
    expect(w.findAll('[data-test="rollout-chip"]')).toHaveLength(0)
  })
})

describe('DeployModal: strategy of the deploy', () => {
  const versions = [{ id: 'v2', label: 'v2', build_status: 'ready' }, { id: 'v1', label: 'v1', build_status: 'ready' }]
  const deployButton = (w) => w.findAll('button').find(b => b.text() === 'Deploy')

  it('a direct project deploys as before', async () => {
    const w = mount(DeployModal, { props: { projectName: 'orders-api', versions }, global: withPlugins().global })
    expect(find(w, 'deploy-strategy-choice').exists()).toBe(false)
    await deployButton(w).trigger('click')
    expect(w.emitted('deploy')[0][0]).toEqual({ versionId: 'v2', strategy: null })
  })

  it('names the project strategy and overrides it once', async () => {
    const w = mount(DeployModal, { props: { projectName: 'orders-api', versions, strategy: 'canary' }, global: withPlugins().global })
    expect(find(w, 'deploy-strategy-choice').text()).toContain('Rolls out as: Canary')
    const options = find(w, 'deploy-strategy-override').findAll('option').map(o => [o.element.value, o.text()])
    expect(options).toEqual([['', 'Project default (Canary)'], ['direct', 'Direct'], ['blue_green', 'Blue-green']])

    await deployButton(w).trigger('click')
    expect(w.emitted('deploy')[0][0]).toEqual({ versionId: 'v2', strategy: null })

    await find(w, 'deploy-strategy-override').setValue('direct')
    expect(find(w, 'deploy-strategy-choice').text()).toContain('Rolls out as: Direct')
    expect(find(w, 'deploy-strategy-choice').text()).toContain('e.g. for an emergency fix')
    await deployButton(w).trigger('click')
    expect(w.emitted('deploy')[1][0]).toEqual({ versionId: 'v2', strategy: 'direct' })
  })

  // Each environment rolls out with its own strategy (docs/deploy-strategies.md#environments)
  it('a deploy to an environment rolls out with the environment\'s strategy', async () => {
    const environments = [
      { id: 'e-dev', name: 'dev', is_primary: false, deploy_strategy: 'direct' },
      { id: 'e-st', name: 'staging', is_primary: false, deploy_strategy: 'blue_green' },
      { id: 'e-pr', name: 'production', is_primary: true, deploy_strategy: 'canary' },
    ]
    const w = mount(DeployModal, { props: { projectName: 'orders-api', versions, strategy: 'canary', environments, environment: 'staging' }, global: withPlugins().global })
    expect(find(w, 'deploy-strategy-choice').text()).toContain('Rolls out as: Blue-green')
    expect(find(w, 'deploy-strategy-override').findAll('option')[0].text()).toBe('staging default (Blue-green)')

    await find(w, 'deploy-strategy-override').setValue('canary')
    await deployButton(w).trigger('click')
    expect(w.emitted('deploy')[0][0]).toEqual({ versionId: 'v2', strategy: 'canary', environment: 'staging' })

    // dev deploys directly: no choice, and the one-off choice made for staging is gone
    await w.find('[data-testid="deploy-environment"]').setValue('dev')
    expect(find(w, 'deploy-strategy-choice').exists()).toBe(false)
    await deployButton(w).trigger('click')
    expect(w.emitted('deploy')[1][0]).toEqual({ versionId: 'v2', strategy: null, environment: 'dev' })

    await w.find('[data-testid="deploy-environment"]').setValue('production')
    expect(find(w, 'deploy-strategy-choice').text()).toContain('Rolls out as: Canary')
  })
})

describe('DeploymentTable: the strategy of the environment shown', () => {
  it('names the environment\'s strategy instead of the project\'s', () => {
    const w = mountTable({ deployments: [], project: project({ deploy_strategy: 'direct' }), strategy: 'blue_green' })
    expect(find(w, 'deploy-strategy-open').text()).toBe('Deploy strategy: Blue-green')
    const queue = mountTable({ deployments: [], project: project({ config: { trigger_type: 'queue' } }), strategy: 'canary' })
    expect(find(queue, 'deploy-strategy-open').text()).toBe('Deploy strategy: Direct')
  })
})

describe('ProjectHeader: strategy badge', () => {
  const mountHeader = (p) => mount(ProjectHeader, { props: { project: p, tabs: [] }, global: withPlugins().global })

  it('shows blue-green and canary, not the default', () => {
    expect(find(mountHeader(project()), 'project-strategy-badge').text()).toBe('Canary')
    expect(find(mountHeader(project({ deploy_strategy: 'blue_green' })), 'project-strategy-badge').text()).toBe('Blue-green')
    expect(find(mountHeader(project({ deploy_strategy: 'direct' })), 'project-strategy-badge').exists()).toBe(false)
    expect(find(mountHeader(project({ config: { trigger_type: 'schedule' } })), 'project-strategy-badge').exists()).toBe(false)
  })
})
