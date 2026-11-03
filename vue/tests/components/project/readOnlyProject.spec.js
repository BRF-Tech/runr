import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import ProjectCard from '@/components/ProjectCard.vue'
import ProjectModels from '@/components/project/ProjectModels.vue'
import DeploymentTable from '@/components/DeploymentTable.vue'
import { aiApi } from '@/api/ai'
import { usePermissionsStore } from '@/stores/permissions'
import { useProjectDetail } from '@/composables/useProjectDetail'
import { modelService } from '@/test-support/helpers'
import { withPlugins } from '../../helpers'

// The public demo runs with a read-only custom role. The project pages showed it controls the
// API refuses with 403 (the card's edit pencil, the Models tab's "No models bound" over a 403),
// and tabs whose data it may not read. They now follow the role.

vi.mock('vue-router', async (orig) => ({
  ...(await orig()),
  useRoute:            () => ({ params: { id: 'p1' }, query: {}, hash: '' }),
  useRouter:           () => ({ replace: vi.fn(), push: vi.fn() }),
  onBeforeRouteLeave:  () => {},
  onBeforeRouteUpdate: () => {},
}))
vi.mock('@/api/ai', () => ({
  aiApi: { projectModels: vi.fn(), services: vi.fn(), bindModel: vi.fn(), updateBinding: vi.fn(), unbindModel: vi.fn() },
}))
vi.mock('@/api/projects', () => {
  const fns = {}
  return { projectsApi: new Proxy(fns, { get: (target, key) => (target[key] ??= vi.fn(async () => ({ data: {} }))) }) }
})

const READ_ONLY = ['projects.view', 'git.pull', 'env.view', 'secret_stores.view', 'logs.view', 'metrics.view', 'traces.view',
  'workflows.view', 'sandboxes.view', 'regions.view', 'images.view', 'bindings.view', 'members.view']

const project = { id: 'p1', name: 'orders-api', slug: 'orders-api', service_type: 'faas', runtime: 'python', runtime_version: '3.12', knative_region_id: 'r1', config: {} }

function grant(permissions, projects = {}) {
  usePermissionsStore().apply({ permissions, platform_admin: false, edition: 'single', role: null, roles: [], projects })
}

const mounted = []
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

function mountWith(component, props, permissions, projects) {
  const plugins = withPlugins('en')
  grant(permissions, projects)
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  const w = mount(component, { props, global: { plugins: [...plugins.global.plugins, router] } })
  mounted.push(w)
  return w
}

describe('the project card', () => {
  it('has no edit pencil for a read-only role', () => {
    const w = mountWith(ProjectCard, { project }, READ_ONLY)
    expect(w.find('[data-test="project-card-edit"]').exists()).toBe(false)
  })

  it('has it with projects.update or env.edit, organization-wide or on the project', async () => {
    expect(mountWith(ProjectCard, { project }, [...READ_ONLY, 'projects.update']).find('[data-test="project-card-edit"]').exists()).toBe(true)
    expect(mountWith(ProjectCard, { project }, [...READ_ONLY, 'env.edit']).find('[data-test="project-card-edit"]').exists()).toBe(true)
    expect(mountWith(ProjectCard, { project }, READ_ONLY, { p1: ['projects.update'] }).find('[data-test="project-card-edit"]').exists()).toBe(true)
    expect(mountWith(ProjectCard, { project }, READ_ONLY, { other: ['projects.update'] }).find('[data-test="project-card-edit"]').exists()).toBe(false)
  })

  it('emits edit from the pencil', async () => {
    const w = mountWith(ProjectCard, { project }, ['projects.update'])
    await w.get('[data-test="project-card-edit"]').trigger('click')
    expect(w.emitted('edit')[0]).toEqual([project])
  })
})

describe('Project → Models', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    aiApi.services.mockResolvedValue({ data: { data: [modelService()], abilities: { view: true, deploy: true, manage: false } } })
  })

  it('says the role may not see the bindings instead of "No models bound" on a 403', async () => {
    aiApi.projectModels.mockRejectedValue({ response: { status: 403, data: { message: 'This action is unauthorized.' } } })
    const w = mountWith(ProjectModels, { project }, READ_ONLY)
    await flushPromises()
    expect(w.get('[data-testid="project-models-error"]').text()).toBe('You do not have permission for this.')
    expect(w.text()).not.toContain('No models')
    expect(w.find('[data-testid="bind-open"]').exists()).toBe(false)
  })

  it('binds only with models.deploy and projects.update on the project', async () => {
    aiApi.projectModels.mockResolvedValue({ data: { data: [] } })
    const viewer = mountWith(ProjectModels, { project }, [...READ_ONLY, 'models.view', 'models.deploy'])
    await flushPromises()
    expect(viewer.find('[data-testid="bind-open"]').exists()).toBe(false)

    const editor = mountWith(ProjectModels, { project }, [...READ_ONLY, 'models.view', 'models.deploy'], { p1: ['projects.update'] })
    await flushPromises()
    expect(editor.find('[data-testid="bind-open"]').exists()).toBe(true)
  })
})

describe('the deployment table', () => {
  const deployments = [{ id: 'd1', status: 'active', version: { label: 'v2' }, created_at: '2026-11-01T10:00:00Z' }]

  it('opens a deployment\'s logs only with logs.view', async () => {
    const reader = mountWith(DeploymentTable, { project, deployments }, READ_ONLY)
    expect(reader.find('[data-testid="deployment-logs"]').exists()).toBe(true)
    await reader.get('tbody tr').trigger('click')
    expect(reader.emitted('view-logs')).toHaveLength(1)

    const noLogs = mountWith(DeploymentTable, { project, deployments }, ['projects.view'])
    expect(noLogs.find('[data-testid="deployment-logs"]').exists()).toBe(false)
    expect(noLogs.find('[data-tour="deployment-log-btn"]').exists()).toBe(false)
    await noLogs.get('tbody tr').trigger('click')
    expect(noLogs.emitted('view-logs')).toBeUndefined()
    // the read-only role has no roll back or delete: their tour steps drop
    expect(reader.find('[data-tour="deployment-rollback-btn"]').exists()).toBe(false)
    expect(reader.find('[data-tour="deployment-delete-btn"]').exists()).toBe(false)
  })
})

describe('the project tabs', () => {
  function tabsFor(permissions) {
    let detail
    const Host = defineComponent({ setup() { detail = useProjectDetail(); return () => h('div') } })
    const plugins = withPlugins('en')
    grant(permissions)
    mounted.push(mount(Host, plugins))
    detail.project.value = project
    return detail.tabs.value.map(tab => tab.id)
  }

  it('leave out Models without models.view and Metrics without metrics.view', () => {
    const demo = tabsFor(READ_ONLY)
    expect(demo).not.toContain('models')
    expect(demo).toContain('metrics')

    expect(tabsFor([...READ_ONLY, 'models.view'])).toContain('models')
    expect(tabsFor(['projects.view'])).not.toContain('metrics')
  })
})
