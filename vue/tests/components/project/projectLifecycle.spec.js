import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, RouterLinkStub } from '@vue/test-utils'
import { projectsApi } from '@/api/projects'
import { rbacApi } from '@/api/rbac'
import { usePermissionsStore } from '@/stores/permissions'
import ProjectStopModal from '@/components/project/ProjectStopModal.vue'
import ProjectStopBanner from '@/components/project/ProjectStopBanner.vue'
import ProjectOwnerModal from '@/components/project/ProjectOwnerModal.vue'
import ProjectHeader from '@/components/ProjectHeader.vue'
import { useToast } from '@/composables/useToast'
import { withPlugins } from '../../helpers'

vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRouter: () => ({ push: vi.fn() }), useRoute: () => ({ query: {} }) }))

const find = (w, id) => w.find(`[data-test="${id}"]`)
const flush = async () => { for (let i = 0; i < 4; i++) await new Promise(r => setTimeout(r, 0)) }
const ok = (data) => Promise.resolve({ data })
const fail = (status, data) => Promise.reject(Object.assign(new Error('x'), { response: { status, data } }))

const project = (over = {}) => ({
  id: 'p1', name: 'orders-api', service_type: 'faas', runtime: 'nodejs', runtime_version: '22', config: {},
  owner_id: 'u1', owner: { id: 'u1', name: 'Ada', username: 'ada' }, stopped: null, ...over,
})

function grant(permissions, projects = {}) {
  usePermissionsStore().apply({ permissions, platform_admin: false, edition: 'single', role: null, roles: [], projects })
}

describe('ProjectStopModal', () => {
  afterEach(() => vi.restoreAllMocks())

  it('needs a reason and stops the project', async () => {
    const stop = vi.spyOn(projectsApi, 'stop').mockReturnValue(ok({ data: project({ stopped: { reason: 'Unpaid' } }) }))
    const w = mount(ProjectStopModal, { props: { project: project() }, global: withPlugins().global })
    expect(find(w, 'project-stop-submit').attributes('disabled')).toBeDefined()
    await w.vm.submit()
    expect(find(w, 'project-stop-error').text()).toContain('at least 3 characters')
    expect(stop).not.toHaveBeenCalled()

    await find(w, 'project-stop-reason').setValue('  Unpaid  ')
    await w.find('form').trigger('submit')
    await flush()
    expect(stop).toHaveBeenCalledWith('p1', 'Unpaid')
    expect(w.emitted('stopped')[0][0].stopped.reason).toBe('Unpaid')
  })

  it('shows the API refusal', async () => {
    vi.spyOn(projectsApi, 'stop').mockImplementation(() => fail(409, { message: 'The project is already stopped.', code: 'project_already_stopped' }))
    const w = mount(ProjectStopModal, { props: { project: project() }, global: withPlugins().global })
    await find(w, 'project-stop-reason').setValue('again')
    await w.vm.submit()
    await flush()
    expect(find(w, 'project-stop-error').text()).toBe('The project is already stopped.')
  })

  it('on a project its organization stopped it takes the stop over for the platform', async () => {
    const stop = vi.spyOn(projectsApi, 'stop').mockReturnValue(ok({ data: project({ stopped: { reason: 'Terms of service', level: 'platform' } }) }))
    const stopped = project({ stopped: { at: '2026-10-19T10:00:00Z', by: { id: 'u2', name: 'Bob' }, reason: 'Unpaid', level: 'organization' } })
    const w = mount(ProjectStopModal, { props: { project: stopped }, global: withPlugins().global })
    expect(w.vm.takeOver).toBe(true)
    expect(find(w, 'project-stop-title').text()).toBe('Stop "orders-api" for the platform')
    expect(find(w, 'project-stop-hint').text()).toContain('(reason: Unpaid)')
    expect(find(w, 'project-stop-hint').text()).toContain('only a platform administrator can resume it')
    expect(find(w, 'project-stop-submit').text()).toBe('Stop for the platform')

    await find(w, 'project-stop-reason').setValue('Terms of service')
    await w.find('form').trigger('submit')
    await flush()
    expect(stop).toHaveBeenCalledWith('p1', 'Terms of service')
    expect(w.emitted('stopped')[0][0].stopped.level).toBe('platform')
  })

  it('a running project is a plain stop', () => {
    const w = mount(ProjectStopModal, { props: { project: project() }, global: withPlugins().global })
    expect(w.vm.takeOver).toBe(false)
    expect(find(w, 'project-stop-title').text()).toBe('Stop "orders-api"')
    expect(find(w, 'project-stop-submit').text()).toBe('Stop')
  })
})

describe('ProjectStopBanner', () => {
  afterEach(() => vi.restoreAllMocks())

  it('shows the reason to every member; projects.stop resumes', async () => {
    const plugins = withPlugins()
    grant(['projects.view'])
    const stopped = project({ stopped: { at: '2026-10-19T10:00:00Z', by: { id: 'u2', name: 'Bob' }, reason: 'Unpaid since August' } })
    const w = mount(ProjectStopBanner, { props: { project: stopped }, global: plugins.global })
    expect(find(w, 'project-stopped-reason').text()).toBe('Reason: Unpaid since August')
    expect(w.text()).toContain('Stopped by Bob')
    expect(find(w, 'project-resume').exists()).toBe(false)

    grant(['projects.stop'])
    await flush()
    const resume = vi.spyOn(projectsApi, 'resume').mockReturnValue(ok({ data: project(), deployment_id: 'd1' }))
    await find(w, 'project-resume').trigger('click')
    await flush()
    expect(resume).toHaveBeenCalledWith('p1')
    expect(w.emitted('resumed')[0][0]).toMatchObject({ id: 'p1', stopped: null })

    vi.spyOn(projectsApi, 'resume').mockImplementation(() => fail(409, { message: 'Not stopped.' }))
    await w.vm.resume()
  })

  it('a platform stop names the platform; only a platform administrator resumes it', async () => {
    const plugins = withPlugins()
    grant(['projects.view', 'projects.stop'])
    const stopped = project({ stopped: { at: '2026-10-19T10:00:00Z', by: null, reason: 'Terms of service', level: 'platform' } })
    const w = mount(ProjectStopBanner, { props: { project: stopped }, global: plugins.global })
    expect(find(w, 'project-stopped-title').text()).toBe('This project was stopped by the platform')
    expect(find(w, 'project-stopped-by').text()).toContain('Stopped by the platform')
    expect(find(w, 'project-stopped-by').text()).toContain('only the platform can resume it')
    expect(find(w, 'project-resume').exists()).toBe(false)

    usePermissionsStore().apply({ permissions: ['projects.stop'], platform_admin: true, edition: 'single', role: null, roles: [], projects: {} })
    await flush()
    const resume = vi.spyOn(projectsApi, 'resume').mockReturnValue(ok({ data: project(), deployment_id: null, redeploy_error: 'The version is gone.' }))
    await find(w, 'project-resume').trigger('click')
    await flush()
    expect(resume).toHaveBeenCalledWith('p1')
    expect(w.emitted('resumed')).toHaveLength(1)
  })

  it('a resume whose version is built again for the region\'s registry says it deploys when ready', async () => {
    useToast().toasts.value = []
    grant(['projects.view', 'projects.stop'])
    const stopped = project({ stopped: { at: '2026-10-19T10:00:00Z', by: { id: 'u2', name: 'Bob' }, reason: 'Unpaid', level: 'organization' } })
    const w = mount(ProjectStopBanner, { props: { project: stopped }, global: withPlugins().global })
    vi.spyOn(projectsApi, 'resume').mockReturnValue(ok({ data: project(), deployment_id: null, redeploy_error: 'v1 is built again…', redeploy_code: 'version_rebuilding' }))

    await w.vm.resume()
    await flush()

    const toast = useToast().toasts.value.at(-1)
    expect([toast.type, toast.message]).toEqual(['success', "Project resumed; the version that ran is built again for this region's registry and deploys when the build is ready."])
    expect(w.emitted('resumed')).toHaveLength(1)
  })

  it('an organization stop names the administrator', () => {
    const plugins = withPlugins()
    grant(['projects.view'])
    const stopped = project({ stopped: { at: '2026-10-19T10:00:00Z', by: { id: 'u2', name: 'Bob' }, reason: 'Unpaid', level: 'organization' } })
    const w = mount(ProjectStopBanner, { props: { project: stopped }, global: plugins.global })
    expect(find(w, 'project-stopped-title').text()).toBe('This project is stopped')
    expect(find(w, 'project-stopped-by').text()).toContain('Stopped by Bob for the organization')
  })

  it('renders nothing for a running project', () => {
    const w = mount(ProjectStopBanner, { props: { project: project() }, global: withPlugins().global })
    expect(find(w, 'project-stopped-banner').exists()).toBe(false)
  })
})

describe('ProjectOwnerModal', () => {
  afterEach(() => vi.restoreAllMocks())

  it('transfers the project to a member', async () => {
    vi.spyOn(rbacApi, 'members').mockReturnValue(ok({ members: [{ id: 'u1', name: 'Ada' }, { id: 'u2', name: 'Bob', email: 'bob@example.com' }] }))
    const transfer = vi.spyOn(projectsApi, 'transferOwner').mockReturnValue(ok({ data: project({ owner_id: 'u2', owner: { id: 'u2', name: 'Bob' } }), previous_owner_id: 'u1' }))
    const w = mount(ProjectOwnerModal, { props: { project: project() }, global: withPlugins().global })
    await flush()
    const options = find(w, 'project-owner-select').findAll('option')
    // the current owner is not offered
    expect(options.map(o => o.text())).toEqual(['Choose a member', 'Bob (bob@example.com)'])
    await find(w, 'project-owner-select').setValue('u2')
    await w.find('form').trigger('submit')
    await flush()
    expect(transfer).toHaveBeenCalledWith('p1', 'u2')
    expect(w.emitted('transferred')[0][0].owner_id).toBe('u2')
  })

  it('falls back to a user id without members.view and shows refusals', async () => {
    vi.spyOn(rbacApi, 'members').mockImplementation(() => fail(403, {}))
    vi.spyOn(projectsApi, 'transferOwner').mockImplementation(() => fail(422, { errors: { user_id: ['The user cannot see this project.'] } }))
    const w = mount(ProjectOwnerModal, { props: { project: project() }, global: withPlugins().global })
    await flush()
    await find(w, 'project-owner-user-id').setValue('42')
    await w.vm.submit()
    await flush()
    expect(find(w, 'project-owner-error').text()).toBe('The user cannot see this project.')
  })
})

describe('ProjectHeader: owner and stop', () => {
  beforeEach(() => localStorage.clear())

  function mountHeader(p, permissions, projects = {}) {
    const plugins = withPlugins()
    grant(permissions, projects)
    return mount(ProjectHeader, { props: { project: p, tabs: [] }, global: { ...plugins.global, stubs: { RouterLink: RouterLinkStub } } })
  }

  it('shows the owner; the owner may transfer, projects.stop may stop', async () => {
    localStorage.setItem('user', JSON.stringify({ id: 'u1' }))
    const w = mountHeader(project(), ['projects.view', 'projects.stop'])
    expect(find(w, 'project-owner').text()).toContain('Owner: Ada')
    await find(w, 'project-owner-transfer').trigger('click')
    expect(w.emitted('transfer-owner')).toHaveLength(1)
    await find(w, 'project-stop').trigger('click')
    expect(w.emitted('stop')).toHaveLength(1)
  })

  it('others need projects.manage_access to transfer; a stopped project cannot deploy', () => {
    localStorage.setItem('user', JSON.stringify({ id: 'u9' }))
    const w = mountHeader(project({ stopped: { reason: 'x' } }), ['projects.view', 'projects.deploy'])
    expect(find(w, 'project-owner-transfer').exists()).toBe(false)
    expect(find(w, 'project-stop').exists()).toBe(false)
    expect(find(w, 'project-stopped-badge').exists()).toBe(true)
    const deploy = w.findAll('button').find(b => b.text() === 'Deploy')
    expect(deploy.attributes('disabled')).toBeDefined()
    expect(deploy.attributes('title')).toContain('resume it before deploying')

    const access = mountHeader(project(), ['projects.view'], { p1: ['projects.manage_access'] })
    expect(find(access, 'project-owner-transfer').exists()).toBe(true)
  })

  it('a platform administrator takes over the stop of an organization, not a platform stop', async () => {
    const byOrganization = project({ stopped: { reason: 'Unpaid', level: 'organization', by: { id: 'u2', name: 'Bob' } } })
    // an organization administrator (projects.stop) does not stop it again
    expect(find(mountHeader(byOrganization, ['projects.view', 'projects.stop']), 'project-stop').exists()).toBe(false)

    const plugins = withPlugins()
    usePermissionsStore().apply({ permissions: [], platform_admin: true, edition: 'single', role: null, roles: [], projects: {} })
    const mountAdmin = (p) => mount(ProjectHeader, { props: { project: p, tabs: [] }, global: { ...plugins.global, stubs: { RouterLink: RouterLinkStub } } })
    const w = mountAdmin(byOrganization)
    expect(find(w, 'project-stop').text()).toBe('Stop for the platform')
    await find(w, 'project-stop').trigger('click')
    expect(w.emitted('stop')).toHaveLength(1)

    // a stop before 0.5.8 (no level) is the organization's
    expect(find(mountAdmin(project({ stopped: { reason: 'Unpaid' } })), 'project-stop').text()).toBe('Stop for the platform')
    expect(find(mountAdmin(project({ stopped: { reason: 'Terms', level: 'platform' } })), 'project-stop').exists()).toBe(false)
    expect(find(mountAdmin(project()), 'project-stop').text()).toBe('Stop')
  })
})
