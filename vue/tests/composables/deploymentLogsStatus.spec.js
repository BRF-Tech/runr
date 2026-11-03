import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { useDeploymentLogs } from '@/composables/useDeploymentLogs'
import { projectsApi } from '@/api/projects'
import { usePermissionsStore } from '@/stores/permissions'
import { withPlugins } from '../helpers'

vi.mock('@/api/projects', () => ({
  projectsApi: {
    deploymentLogs: vi.fn(),
    updateDeploymentStatus: vi.fn(() => Promise.resolve({ data: {} })),
    deploymentMetrics: vi.fn(() => Promise.resolve({ data: {} })),
  },
}))

// A shutting-down deployment whose pods are gone: the logs answer suggests its status
// (suggest_status: shut_down), and the panel sets it. PATCH …/status needs projects.deploy: a
// read-only role (the public demo's) sent it anyway and got a 403 in the network log.

const project = { id: 'p1', name: 'orders-api' }
const deployment = { id: 'd1', status: 'shutting_down', created_at: '2026-11-01T10:00:00Z' }

let wrapper
function mountLogs(permissions) {
  const plugins = withPlugins('en')
  usePermissionsStore().apply({ permissions, platform_admin: false, edition: 'single', role: null, roles: [], projects: {} })
  const emitted = vi.fn()
  const Host = defineComponent({
    setup() {
      useDeploymentLogs({ project, deployment }, emitted)
      return () => h('div')
    },
  })
  wrapper = mount(Host, plugins)
  return emitted
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
  vi.clearAllMocks()
  projectsApi.deploymentLogs.mockResolvedValue({ data: { pods: [], logs: '', suggest_status: 'shut_down' } })
})
afterEach(() => {
  wrapper?.unmount()
  vi.useRealTimers()
  localStorage.clear()
})

describe('useDeploymentLogs: the suggested status', () => {
  it('is set for a role that may deploy', async () => {
    const emitted = mountLogs(['projects.view', 'logs.view', 'projects.deploy'])
    await flushPromises()
    expect(projectsApi.updateDeploymentStatus).toHaveBeenCalledWith('p1', 'd1', 'shut_down')
    expect(emitted).toHaveBeenCalledWith('status-changed', 'shut_down')
  })

  it('is not sent for a read-only role', async () => {
    const emitted = mountLogs(['projects.view', 'logs.view'])
    await flushPromises()
    expect(projectsApi.deploymentLogs).toHaveBeenCalled()
    expect(projectsApi.updateDeploymentStatus).not.toHaveBeenCalled()
    expect(emitted).not.toHaveBeenCalledWith('status-changed', expect.anything())
  })
})
