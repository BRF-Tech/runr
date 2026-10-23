import { describe, it, expect, vi } from 'vitest'

vi.mock('@/api/client', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() } }))

import client from '@/api/client'
import { projectsApi } from '@/api/projects'

// Deploy strategy (its own endpoint: no version) and the rollout endpoints of a deployment.

describe('deploy strategy API', () => {
  it('reads and updates the strategy of a project', () => {
    projectsApi.deployStrategy('p1')
    expect(client.get).toHaveBeenCalledWith('/projects/p1/deploy-strategy')

    const body = { strategy: 'canary', canary: { steps: [10, 100] } }
    projectsApi.updateDeployStrategy('p1', body)
    expect(client.put).toHaveBeenCalledWith('/projects/p1/deploy-strategy', body)
  })

  it('reads, updates and resets the strategy of an environment', () => {
    projectsApi.deployStrategy('p1', 'staging')
    expect(client.get).toHaveBeenLastCalledWith('/projects/p1/deploy-strategy', { params: { environment: 'staging' } })

    projectsApi.updateDeployStrategy('p1', { strategy: 'canary' }, 'staging')
    expect(client.put).toHaveBeenLastCalledWith('/projects/p1/deploy-strategy', { strategy: 'canary', environment: 'staging' })
    projectsApi.updateDeployStrategy('p1', { reset: true }, 'staging')
    expect(client.put).toHaveBeenLastCalledWith('/projects/p1/deploy-strategy', { reset: true, environment: 'staging' })
  })

  it('reads the rollout of an environment', () => {
    projectsApi.rollout('p1', 'staging')
    expect(client.get).toHaveBeenLastCalledWith('/projects/p1/rollout', { params: { environment: 'staging' } })
  })

  it('a deploy may override the strategy once', () => {
    projectsApi.deploy('p1', { version_id: 'v2', strategy: 'direct' })
    expect(client.post).toHaveBeenCalledWith('/projects/p1/deploy', { version_id: 'v2', strategy: 'direct' })
  })

  it('reads the project rollout and a deployment rollout', () => {
    projectsApi.rollout('p1')
    expect(client.get).toHaveBeenCalledWith('/projects/p1/rollout')
    projectsApi.deploymentRollout('p1', 'd2')
    expect(client.get).toHaveBeenCalledWith('/projects/p1/deployments/d2/rollout')
  })

  it('promotes, aborts (with an optional reason) and rolls back', () => {
    projectsApi.promoteRollout('p1', 'd2')
    expect(client.post).toHaveBeenCalledWith('/projects/p1/deployments/d2/rollout/promote')

    projectsApi.abortRollout('p1', 'd2', 'Error spike')
    expect(client.post).toHaveBeenCalledWith('/projects/p1/deployments/d2/rollout/abort', { reason: 'Error spike' })
    projectsApi.abortRollout('p1', 'd2')
    expect(client.post).toHaveBeenLastCalledWith('/projects/p1/deployments/d2/rollout/abort', {})

    projectsApi.rollbackRollout('p1', 'd2')
    expect(client.post).toHaveBeenCalledWith('/projects/p1/deployments/d2/rollout/rollback')
  })
})
