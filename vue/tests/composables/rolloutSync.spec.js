import { describe, it, expect, vi } from 'vitest'
import { ref } from 'vue'

// deployment.rollout / deployment.status platform events on the project page: the new deployment
// carries the rollout and its status, the project's current rollout follows the newest one,
// and the deployment.* fallback still updates the other statuses.

vi.mock('@/composables/useJobEvents', () => ({ useJobEvents: vi.fn() }))
vi.mock('@/api/projects', () => ({ projectsApi: { get: vi.fn() } }))

import { useJobEvents } from '@/composables/useJobEvents'
import { usePlatformSync } from '@/composables/usePlatformSync'
import { withPlugins } from '../helpers'

const rollout = (over = {}) => ({
  id: '01JR0000000000000000000002', project_id: 'p1', deployment_id: 'd2', stable_deployment_id: 'd1',
  strategy: 'canary', status: 'progressing', traffic: { candidate: 10, stable: 90 },
  keep_until: null, released_at: null, finished_at: null, created_at: '2026-10-21T10:00:00Z', ...over,
})

function sync(environment = undefined) {
  withPlugins()
  const project = ref({ id: 'p1' })
  const deployments = ref([
    { id: 'd2', status: 'pending', rollout: null, rollout_role: null },
    { id: 'd1', status: 'active', rollout: null, rollout_role: null },
  ])
  const current = ref(null)
  usePlatformSync({ project, versions: ref([]), deployments, rollout: current, environment })
  return { handlers: useJobEvents.mock.calls.at(-1)[0], deployments, current }
}

describe('rollout events', () => {
  it('deployment.rollout updates the new deployment, its status and the current rollout', () => {
    const { handlers, deployments, current } = sync()
    handlers['deployment.rollout']({ project_id: 'p1', deployment_id: 'd2', status: 'running', rollout: rollout() })

    expect(current.value.id).toBe(rollout().id)
    expect(deployments.value[0]).toMatchObject({ status: 'running', rollout_role: 'candidate', rollout: { traffic: { candidate: 10 } } })
    expect(deployments.value[1]).toMatchObject({ status: 'active', rollout_role: 'stable' })

    // the next step
    handlers['deployment.rollout']({ project_id: 'p1', deployment_id: 'd2', status: 'running', rollout: rollout({ traffic: { candidate: 50, stable: 50 } }) })
    expect(current.value.traffic.candidate).toBe(50)
    expect(deployments.value[0].rollout.traffic.candidate).toBe(50)
  })

  it('ignores another project and an empty rollout', () => {
    const { handlers, deployments, current } = sync()
    handlers['deployment.rollout']({ project_id: 'p9', deployment_id: 'd2', status: 'running', rollout: rollout() })
    handlers['deployment.rollout']({ project_id: 'p1', deployment_id: 'd2', status: 'running', rollout: null })
    expect(current.value).toBeNull()
    expect(deployments.value[0].status).toBe('pending')
  })

  // Each environment rolls out on its own (docs/deploy-strategies.md#environments)
  it('follows the rollouts of the environment shown only', () => {
    const shown = ref('staging')
    const { handlers, current } = sync(shown)
    handlers['deployment.rollout']({ project_id: 'p1', deployment_id: 'd9', status: 'running', rollout: rollout({ id: '01JR0000000000000000000009', environment: 'production' }) })
    expect(current.value).toBeNull()
    handlers['deployment.rollout']({ project_id: 'p1', deployment_id: 'd2', status: 'running', rollout: rollout({ environment: 'staging' }) })
    expect(current.value.environment).toBe('staging')

    shown.value = null
    handlers['deployment.rollout']({ project_id: 'p1', deployment_id: 'd2', status: 'running', rollout: rollout({ environment: 'staging', traffic: { candidate: 50, stable: 50 } }) })
    expect(current.value.traffic.candidate).toBe(10)
  })

  it('a late event of an older rollout leaves the current one', () => {
    const { handlers, deployments, current } = sync()
    handlers['deployment.rollout']({ project_id: 'p1', deployment_id: 'd2', status: 'running', rollout: rollout() })
    const older = rollout({ id: '01JR0000000000000000000001', deployment_id: 'd1', stable_deployment_id: 'd0', status: 'promoted', released_at: '2026-10-21T10:01:00Z', created_at: '2026-10-21T09:00:00Z' })
    handlers['deployment.rollout']({ project_id: 'p1', deployment_id: 'd1', status: 'active', rollout: older })

    expect(current.value.id).toBe(rollout().id)
    expect(deployments.value.map(d => d.rollout_role)).toEqual(['candidate', 'stable'])
    expect(deployments.value[1].rollout.id).toBe(older.id)
  })

  it('deployment.status changes the status of the deployment that served before', () => {
    const { handlers, deployments } = sync()
    handlers['deployment.status']({ project_id: 'p1', deployment_id: 'd1', status: 'running' })
    expect(deployments.value[1].status).toBe('running')
  })

  it('the deployment.* fallback still updates statuses, not twice for the rollout topics', () => {
    const { handlers, deployments } = sync()
    handlers['deployment.*']({ project_id: 'p1', deployment_id: 'd2', status: 'failed' }, 'deployment.failed')
    expect(deployments.value[0].status).toBe('failed')

    // the rollout topic is handled by its own handler only
    handlers['deployment.*']({ project_id: 'p1', deployment_id: 'd1', status: 'shutting_down' }, 'deployment.rollout')
    expect(deployments.value[1].status).toBe('active')
  })
})
