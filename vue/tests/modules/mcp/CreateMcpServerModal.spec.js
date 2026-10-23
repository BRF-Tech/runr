import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { deferred, withPlugins } from '../../helpers'

const push = vi.hoisted(() => vi.fn())
const mcpApi = vi.hoisted(() => ({ templates: vi.fn(), saveServer: vi.fn() }))
const projectsApi = vi.hoisted(() => ({ create: vi.fn(), saveFiles: vi.fn() }))
const runtimesApi = vi.hoisted(() => ({ create: vi.fn() }))

vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRouter: () => ({ push, replace: vi.fn() }), useRoute: () => ({ query: {} }) }))
vi.mock('@/modules/mcp/api/mcp', () => ({ mcpApi }))
vi.mock('@/api/projects', () => ({ projectsApi }))
vi.mock('@/api/runtimes', () => ({ runtimesApi }))
vi.mock('@/stores/runtimes', () => ({ useRuntimesStore: () => ({ fetch: vi.fn() }) }))

import CreateMcpServerModal from '@/modules/mcp/components/CreateMcpServerModal.vue'
import NewMcpServerButton from '@/modules/mcp/components/NewMcpServerButton.vue'
import { useSettingsStore } from '@/stores/settings'
import { usePermissionsStore } from '@/stores/permissions'

const MANIFEST = '{"apiVersion":"runr.sh/v1","kind":"Runtime","id":"mcp-python"}'

function templates({ pythonAvailable = true, canRegister = true } = {}) {
  return [
    { id: 'python', name: 'Python (FastMCP)', description: 'Official MCP Python SDK', service_type: 'faas', runtime: 'mcp-python',
      runtime_available: pythonAvailable, can_register_runtime: canRegister, runtime_manifest: MANIFEST,
      files: { 'server.py': 'print("mcp")\n', 'requirements.txt': 'mcp\n' }, path: '/mcp' },
    { id: 'node', name: 'TypeScript', description: 'Official MCP TypeScript SDK', service_type: 'faas', runtime: 'mcp-node',
      runtime_available: true, can_register_runtime: true, runtime_manifest: '{}', files: { 'server.ts': '//', 'package.json': '{}' }, path: '/mcp' },
    { id: 'image', name: 'Container image', description: 'Bring your own', service_type: 'caas', runtime: null,
      runtime_available: true, can_register_runtime: false, runtime_manifest: null, files: null, path: '/mcp', port: 8080 },
  ]
}

// RegionSelector reads residency from the API: a stub that picks region r1
const RegionSelector = { name: 'RegionSelector', emits: ['select'], template: '<button data-testid="region-pick" @click="$emit(\'select\', \'r1\')">region</button>' }

let plugins
async function mountModal(props = {}) {
  const w = mount(CreateMcpServerModal, { props, global: { ...plugins.global, stubs: { RegionSelector } } })
  await flushPromises()
  return w
}

beforeEach(() => {
  vi.clearAllMocks()
  plugins = withPlugins('en')
  const settings = useSettingsStore()
  settings.data.regions = [{ id: 'r1', name: 'eu-1', is_active: true, is_synced: true }]
  settings.loaded = true
  mcpApi.templates.mockResolvedValue({ data: { data: templates() } })
  mcpApi.saveServer.mockResolvedValue({ data: { data: { handle: 'weather' } } })
  projectsApi.create.mockResolvedValue({ data: { id: 'p9', name: 'weather' } })
  projectsApi.saveFiles.mockResolvedValue({ data: { version: { id: 'v1' } } })
  runtimesApi.create.mockResolvedValue({ data: { data: { id: 'mcp-python' } } })
})

async function fill(w, name = 'weather') {
  await w.find('[data-testid="mcp-create-name"]').setValue(name)
  await w.find('[data-testid="region-pick"]').trigger('click')
}

describe('New MCP server', () => {
  it('creates a Python MCP server: project, starter files, gateway', async () => {
    const w = await mountModal()
    await fill(w)
    await w.find('[data-testid="mcp-access-oauth"]').trigger('click')
    await w.find('[data-testid="mcp-create-submit"]').trigger('click')
    await flushPromises()

    expect(projectsApi.create).toHaveBeenCalledWith({
      name: 'weather', service_type: 'faas', knative_region_id: 'r1', runtime: 'mcp-python', config: { trigger_type: 'http' },
    })
    expect(projectsApi.saveFiles).toHaveBeenCalledWith('p9', [
      { name: 'server.py', content: 'print("mcp")\n' },
      { name: 'requirements.txt', content: 'mcp\n' },
    ], 'MCP server starter')
    expect(mcpApi.saveServer).toHaveBeenCalledWith('p9', { access_mode: 'oauth', path: '/mcp' })
    expect(push).toHaveBeenCalledWith({ path: '/projects/p9', hash: '#mcp' })
    expect(w.emitted('created')[0][0]).toMatchObject({ id: 'p9' })
  })

  it('creates a server from a container image', async () => {
    const w = await mountModal()
    await fill(w, 'legacy-tools')
    await w.find('[data-testid="mcp-template-image"]').trigger('click')
    expect(w.find('[data-testid="mcp-create-submit"]').attributes('disabled')).toBeDefined()
    await w.find('[data-testid="mcp-create-image"]').setValue('ghcr.io/acme/tools:2')
    await w.find('[data-testid="mcp-create-port"]').setValue(3000)
    await w.find('[data-testid="mcp-create-submit"]').trigger('click')
    await flushPromises()

    expect(projectsApi.create).toHaveBeenCalledWith({
      name: 'legacy-tools', service_type: 'caas', knative_region_id: 'r1',
      config: { image: 'ghcr.io/acme/tools:2', ports: [3000], trigger_type: 'http' },
    })
    expect(projectsApi.saveFiles).not.toHaveBeenCalled()
    expect(mcpApi.saveServer).toHaveBeenCalledWith('p9', { access_mode: 'token', path: '/mcp' })
  })

  it('enables a missing runtime, then creates', async () => {
    mcpApi.templates
      .mockResolvedValueOnce({ data: { data: templates({ pythonAvailable: false }) } })
      .mockResolvedValueOnce({ data: { data: templates() } })
    const w = await mountModal()
    await fill(w)
    expect(w.find('[data-testid="mcp-runtime-missing"]').exists()).toBe(true)
    expect(w.find('[data-testid="mcp-create-submit"]').attributes('disabled')).toBeDefined()

    await w.find('[data-testid="mcp-enable-runtime"]').trigger('click')
    await flushPromises()
    expect(runtimesApi.create).toHaveBeenCalledWith({ manifest: MANIFEST })
    expect(w.find('[data-testid="mcp-runtime-missing"]').exists()).toBe(false)
    expect(w.find('[data-testid="mcp-create-submit"]').attributes('disabled')).toBeUndefined()
  })

  it('tells members without the right to register runtimes to ask an administrator', async () => {
    mcpApi.templates.mockResolvedValue({ data: { data: templates({ pythonAvailable: false, canRegister: false }) } })
    const w = await mountModal()
    expect(w.find('[data-testid="mcp-enable-runtime"]').exists()).toBe(false)
    expect(w.find('[data-testid="mcp-runtime-missing"]').text()).toContain('An administrator enables it')
  })

  it('refuses a taken name and an invalid slug', async () => {
    const w = await mountModal({ existingNames: ['weather'] })
    await fill(w, 'weather')
    expect(w.text()).toContain('already')
    expect(w.find('[data-testid="mcp-create-submit"]').attributes('disabled')).toBeDefined()
  })

  it('reports a setup step that failed after the project was created', async () => {
    mcpApi.saveServer.mockRejectedValue({ response: { status: 422, data: { message: 'MCP servers need the HTTP trigger.' } } })
    const w = await mountModal()
    await fill(w)
    await w.find('[data-testid="mcp-create-submit"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-testid="mcp-create-error"]').text()).toContain('weather')
    expect(w.find('[data-testid="mcp-create-error"]').text()).toContain('MCP servers need the HTTP trigger.')
    expect(push).not.toHaveBeenCalled()
    expect(w.emitted('done')).toBeUndefined()
  })

  it('continues the setup of the created project on retry, without a second project', async () => {
    mcpApi.saveServer
      .mockRejectedValueOnce({ response: { status: 503, data: { message: 'Try again.' } } })
      .mockResolvedValueOnce({ data: { data: { handle: 'weather' } } })
    const w = await mountModal()
    await fill(w)
    await w.find('[data-testid="mcp-create-submit"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-testid="mcp-create-submit"]').text()).toBe('Continue the setup')
    expect(w.find('[data-testid="mcp-create-fields"]').attributes('disabled')).toBeDefined()

    await w.find('[data-testid="mcp-create-submit"]').trigger('click')
    await flushPromises()
    expect(projectsApi.create).toHaveBeenCalledTimes(1)
    expect(projectsApi.saveFiles).toHaveBeenCalledTimes(1)
    expect(mcpApi.saveServer).toHaveBeenCalledTimes(2)
    expect(w.emitted('done')[0][0]).toMatchObject({ id: 'p9' })
    expect(push).toHaveBeenCalledWith({ path: '/projects/p9', hash: '#mcp' })
  })
})

// The orderings a slow API produces: a request of the setup is held with a deferred promise
// while the projects list (existingNames) or the user acts.
describe('New MCP server: answers arriving late', () => {
  it('shows no "name taken" error when the project.created broadcast lists the project during the setup', async () => {
    const files = deferred()
    projectsApi.saveFiles.mockReturnValueOnce(files.promise)
    const w = await mountModal({ existingNames: ['other'] })
    await fill(w)
    await w.find('[data-testid="mcp-create-submit"]').trigger('click')
    await flushPromises()
    expect(projectsApi.saveFiles).toHaveBeenCalled()
    expect(w.emitted('created')[0][0]).toMatchObject({ id: 'p9' })

    // The projects page lists the new project (broadcast, or the created event) while the
    // starter files are still being saved
    await w.setProps({ existingNames: ['weather', 'other'] })
    expect(w.text()).not.toContain('already exists')
    expect(w.find('[data-testid="mcp-create-error"]').exists()).toBe(false)

    files.resolve({ data: { version: { id: 'v1' } } })
    await flushPromises()
    expect(w.text()).not.toContain('already exists')
    expect(w.find('[data-testid="mcp-create-error"]').exists()).toBe(false)
    expect(push).toHaveBeenCalledWith({ path: '/projects/p9', hash: '#mcp' })
  })

  it('shows no error when the broadcast lists the project before the POST answer', async () => {
    const created = deferred()
    projectsApi.create.mockReturnValueOnce(created.promise)
    const w = await mountModal()
    await fill(w)
    await w.find('[data-testid="mcp-create-submit"]').trigger('click')
    await w.setProps({ existingNames: ['weather'] })
    expect(w.text()).not.toContain('already exists')

    created.resolve({ data: { id: 'p9', name: 'weather' } })
    await flushPromises()
    expect(w.text()).not.toContain('already exists')
    expect(push).toHaveBeenCalledTimes(1)
  })

  it('creates one project however often the button is clicked', async () => {
    const created = deferred()
    projectsApi.create.mockReturnValueOnce(created.promise)
    const w = await mountModal()
    await fill(w)
    const submit = w.find('[data-testid="mcp-create-submit"]')
    await submit.trigger('click')
    await submit.trigger('click')
    await submit.trigger('click')
    created.resolve({ data: { id: 'p9', name: 'weather' } })
    await flushPromises()
    expect(projectsApi.create).toHaveBeenCalledTimes(1)
    expect(push).toHaveBeenCalledTimes(1)
  })

  it('cannot be closed while the setup runs', async () => {
    const saved = deferred()
    mcpApi.saveServer.mockReturnValueOnce(saved.promise)
    const w = await mountModal()
    await fill(w)
    await w.find('[data-testid="mcp-create-submit"]').trigger('click')
    await flushPromises()
    await w.find('.absolute.inset-0').trigger('click')
    expect(w.emitted('close')).toBeUndefined()

    saved.resolve({ data: { data: { handle: 'weather' } } })
    await flushPromises()
    await w.find('.absolute.inset-0').trigger('click')
    expect(w.emitted('close')).toHaveLength(1)
  })

  it('does not navigate when the page was left before the setup ended', async () => {
    const saved = deferred()
    mcpApi.saveServer.mockReturnValueOnce(saved.promise)
    const w = await mountModal()
    await fill(w)
    await w.find('[data-testid="mcp-create-submit"]').trigger('click')
    await flushPromises()
    w.unmount()
    saved.resolve({ data: { data: { handle: 'weather' } } })
    await flushPromises()
    expect(push).not.toHaveBeenCalled()
  })
})

describe('New MCP server button', () => {
  it('needs projects.create and a ready region', async () => {
    const perms = usePermissionsStore()
    perms.apply({ permissions: ['projects.view'], projects: {} })
    let w = mount(NewMcpServerButton, { props: { hasReadyRegion: true }, global: plugins.global })
    expect(w.find('[data-testid="new-mcp-server-btn"]').exists()).toBe(false)

    perms.apply({ permissions: ['projects.create'], projects: {} })
    w = mount(NewMcpServerButton, { props: { hasReadyRegion: false }, global: plugins.global })
    expect(w.find('[data-testid="new-mcp-server-btn"]').attributes('disabled')).toBeDefined()
  })

  it('opens the modal', async () => {
    const w = mount(NewMcpServerButton, { props: { hasReadyRegion: true }, global: { ...plugins.global, stubs: { CreateMcpServerModal: { template: '<div data-testid="modal-stub" />' } } } })
    await w.find('[data-testid="new-mcp-server-btn"]').trigger('click')
    expect(w.find('[data-testid="modal-stub"]').exists()).toBe(true)
  })

  it('passes the created project on and stays open until the setup is done', async () => {
    const Modal = { name: 'CreateMcpServerModal', emits: ['created', 'done', 'close'], template: '<div data-testid="modal-stub" />' }
    const w = mount(NewMcpServerButton, { props: { hasReadyRegion: true }, global: { ...plugins.global, stubs: { CreateMcpServerModal: Modal } } })
    await w.find('[data-testid="new-mcp-server-btn"]').trigger('click')
    const modal = w.findComponent({ name: 'CreateMcpServerModal' })

    modal.vm.$emit('created', { id: 'p9' })
    await flushPromises()
    expect(w.emitted('created')[0][0]).toEqual({ id: 'p9' })
    expect(w.find('[data-testid="modal-stub"]').exists()).toBe(true)

    modal.vm.$emit('done', { id: 'p9' })
    await flushPromises()
    expect(w.find('[data-testid="modal-stub"]').exists()).toBe(false)
  })
})
