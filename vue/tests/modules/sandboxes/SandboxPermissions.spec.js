import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SandboxesView from '@/modules/sandboxes/views/SandboxesView.vue'
import SandboxDetailPanel from '@/modules/sandboxes/components/SandboxDetailPanel.vue'
import { sandboxesApi, sandboxAdminApi } from '@/modules/sandboxes/api/sandboxes'
import { usePermissionsStore } from '@/stores/permissions'
import { freshPinia } from '@/test-support/helpers'
import { sandboxI18n, sandbox, regionRows } from './fixtures'

// A role without sandboxes.create / sandboxes.exec (the read-only demo role, Viewer) sees the
// sandboxes and their activity log, but no button or tab whose endpoint answers 403
// (api/config/route_permissions.php: POST/DELETE sandboxes, timeout → create; terminal, pause,
// resume, files, processes, run → exec).

vi.mock('@/modules/sandboxes/api/sandboxes', () => ({
  sandboxesApi: {
    list: vi.fn(), get: vi.fn(), create: vi.fn(), destroy: vi.fn(), setTimeout: vi.fn(), keepalive: vi.fn(),
    pause: vi.fn(), resume: vi.fn(), logs: vi.fn(), terminal: vi.fn(), exec: vi.fn(), run: vi.fn(),
    files: { list: vi.fn(), read: vi.fn(), download: vi.fn(), write: vi.fn(), remove: vi.fn(), mkdir: vi.fn(), archive: vi.fn() },
    processes: { list: vi.fn(), kill: vi.fn() },
  },
  sandboxAdminApi: {
    regions: vi.fn(), updateRegion: vi.fn(), pools: vi.fn(), createPool: vi.fn(), updatePool: vi.fn(), deletePool: vi.fn(),
    quota: vi.fn(), tenantQuotas: vi.fn(), updateQuota: vi.fn(), resetQuota: vi.fn(), metrics: vi.fn(), usage: vi.fn(),
  },
}))

const READ_ONLY = [
  'projects.view', 'git.pull', 'env.view', 'secret_stores.view', 'logs.view', 'metrics.view', 'traces.view',
  'workflows.view', 'sandboxes.view', 'regions.view', 'images.view', 'bindings.view', 'members.view',
]

const i18n = sandboxI18n()
let pinia
const mounted = []

function grant(permissions) {
  usePermissionsStore().apply({ permissions, platform_admin: false, edition: 'single', role: null, roles: [], projects: {} })
}

const RUNNING = '01jb0000000000000000aaaa01'
const PAUSED  = '01jb0000000000000000bbbb02'

async function mountView(list = [sandbox(RUNNING), sandbox(PAUSED, { status: 'paused' })]) {
  sandboxesApi.list.mockResolvedValue({ data: { data: list, meta: { total: list.length, page: 1, per_page: 50 } } })
  const wrapper = mount(SandboxesView, {
    global: { plugins: [pinia, i18n], stubs: { AppHeader: true, ShellTerminalModal: true, CreateSandboxDialog: true, SandboxDetailPanel: true } },
  })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

async function mountPanel(props = {}) {
  const wrapper = mount(SandboxDetailPanel, {
    props: { sandbox: sandbox(RUNNING), ...props },
    global: { plugins: [pinia, i18n], stubs: { SandboxFileBrowser: true, SandboxProcesses: true, SandboxRunCode: true, SandboxLogs: true } },
  })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

const row = (wrapper, id) => wrapper.find(`[data-sandbox-row="${id}"]`)
const button = (scope, title) => scope.find(`button[title="${title}"]`)
const tabs = (wrapper) => wrapper.findAll('[data-tab]').map(b => b.attributes('data-tab'))

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
  sandboxAdminApi.regions.mockResolvedValue({ data: { data: regionRows } })
  sandboxesApi.get.mockImplementation((id) => Promise.resolve({ data: { data: sandbox(id) } }))
})

afterEach(() => {
  while (mounted.length) mounted.pop().unmount()
})

describe('SandboxesView permissions', () => {
  it('shows a read-only role the list without create, terminal, extend, pause, resume or kill', async () => {
    grant(READ_ONLY)
    const wrapper = await mountView()

    expect(wrapper.find('[data-action="create-sandbox"]').exists()).toBe(false)
    for (const id of [RUNNING, PAUSED]) {
      const r = row(wrapper, id)
      expect(r.exists()).toBe(true)
      expect(button(r, 'Details').exists()).toBe(true)
      for (const title of ['Terminal', 'Extend', 'Pause', 'Resume', 'Kill']) expect(button(r, title).exists()).toBe(false)
    }
  })

  it('leaves the create button out of the empty state, with a text that does not offer it', async () => {
    grant(READ_ONLY)
    const wrapper = await mountView([])

    const empty = wrapper.find('[data-empty="sandboxes"]')
    expect(empty.exists()).toBe(true)
    expect(empty.findAll('button')).toHaveLength(0)
    expect(empty.text()).not.toContain('start one here')
  })

  it('shows extend and kill with sandboxes.create, terminal and pause / resume with sandboxes.exec', async () => {
    grant([...READ_ONLY, 'sandboxes.create'])
    let wrapper = await mountView()
    expect(wrapper.find('[data-action="create-sandbox"]').exists()).toBe(true)
    expect(button(row(wrapper, RUNNING), 'Extend').exists()).toBe(true)
    expect(button(row(wrapper, RUNNING), 'Kill').exists()).toBe(true)
    expect(button(row(wrapper, RUNNING), 'Terminal').exists()).toBe(false)
    expect(button(row(wrapper, RUNNING), 'Pause').exists()).toBe(false)
    expect(button(row(wrapper, PAUSED), 'Resume').exists()).toBe(false)
    wrapper.unmount()
    mounted.pop()

    pinia = freshPinia()
    grant([...READ_ONLY, 'sandboxes.exec'])
    wrapper = await mountView()
    expect(wrapper.find('[data-action="create-sandbox"]').exists()).toBe(false)
    expect(button(row(wrapper, RUNNING), 'Terminal').exists()).toBe(true)
    expect(button(row(wrapper, RUNNING), 'Pause').exists()).toBe(true)
    expect(button(row(wrapper, PAUSED), 'Resume').exists()).toBe(true)
    expect(button(row(wrapper, RUNNING), 'Extend').exists()).toBe(false)
    expect(button(row(wrapper, RUNNING), 'Kill').exists()).toBe(false)
  })

  it('shows every action while the permissions are not loaded (the API decides)', async () => {
    const wrapper = await mountView()

    expect(wrapper.find('[data-action="create-sandbox"]').exists()).toBe(true)
    for (const title of ['Terminal', 'Extend', 'Pause', 'Kill']) expect(button(row(wrapper, RUNNING), title).exists()).toBe(true)
  })
})

describe('SandboxDetailPanel permissions', () => {
  it('shows a read-only role the overview and the activity log only, without actions', async () => {
    grant(READ_ONLY)
    const wrapper = await mountPanel()

    expect(tabs(wrapper)).toEqual(['overview', 'logs'])
    for (const action of ['extend', 'pause', 'resume', 'kill', 'open-terminal']) {
      expect(wrapper.find(`[data-action="${action}"]`).exists()).toBe(false)
    }
    expect(wrapper.find('select[name="extend_by"]').exists()).toBe(false)
    expect(wrapper.find('[data-fields]').exists()).toBe(true)
  })

  it('opens on the overview when it was asked for a tab the role may not use', async () => {
    grant(READ_ONLY)
    const wrapper = await mountPanel({ initialTab: 'files' })

    expect(wrapper.find('[data-panel="overview"]').exists()).toBe(true)
    expect(wrapper.findComponent({ name: 'SandboxFileBrowser' }).exists()).toBe(false)
    expect(sandboxesApi.files.list).not.toHaveBeenCalled()
  })

  it('shows the files, processes, run and terminal tabs and pause with sandboxes.exec', async () => {
    grant([...READ_ONLY, 'sandboxes.exec'])
    const wrapper = await mountPanel()

    expect(tabs(wrapper)).toEqual(['overview', 'files', 'processes', 'run', 'logs', 'terminal'])
    expect(wrapper.find('[data-action="pause"]').exists()).toBe(true)
    expect(wrapper.find('[data-action="extend"]').exists()).toBe(false)
    expect(wrapper.find('[data-action="kill"]').exists()).toBe(false)

    await wrapper.find('[data-tab="terminal"]').trigger('click')
    expect(wrapper.find('[data-action="open-terminal"]').exists()).toBe(true)
  })

  it('shows extend and kill with sandboxes.create', async () => {
    grant([...READ_ONLY, 'sandboxes.create'])
    const wrapper = await mountPanel()

    expect(tabs(wrapper)).toEqual(['overview', 'logs'])
    expect(wrapper.find('[data-action="extend"]').exists()).toBe(true)
    expect(wrapper.find('select[name="extend_by"]').exists()).toBe(true)
    expect(wrapper.find('[data-action="kill"]').exists()).toBe(true)
    expect(wrapper.find('[data-action="pause"]').exists()).toBe(false)
  })

  it('goes back to the overview when the permissions arrive without sandboxes.exec', async () => {
    const wrapper = await mountPanel({ initialTab: 'processes' })
    expect(tabs(wrapper)).toContain('processes')

    grant(READ_ONLY)
    await flushPromises()

    expect(tabs(wrapper)).toEqual(['overview', 'logs'])
    expect(wrapper.find('[data-panel="overview"]').exists()).toBe(true)
  })
})
