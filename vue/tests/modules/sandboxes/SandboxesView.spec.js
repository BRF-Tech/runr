import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SandboxesView from '@/modules/sandboxes/views/SandboxesView.vue'
import { sandboxesApi, sandboxAdminApi } from '@/modules/sandboxes/api/sandboxes'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { freshPinia } from '@/test-support/helpers'
import { shortId } from '@/modules/sandboxes/utils'
import { sandboxI18n, sandbox, regionRows } from './fixtures'

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

const i18n = sandboxI18n()
let pinia
const mounted = []

const STUBS = {
  AppHeader:          true,
  ShellTerminalModal: true,
  CreateSandboxDialog: true,
  SandboxDetailPanel: true,
}

async function mountView(list = [sandbox('01jb0000000000000000aaaa01'), sandbox('01jb0000000000000000bbbb02', { status: 'paused', isolation: 'kata', sandbox_class: 'kata-fc', from_pool: true })]) {
  sandboxesApi.list.mockResolvedValue({ data: { data: list, meta: { total: list.length, page: 1, per_page: 50 } } })
  const wrapper = mount(SandboxesView, { global: { plugins: [pinia, i18n], stubs: STUBS } })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

const row = (wrapper, id) => wrapper.find(`[data-sandbox-row="${id}"]`)
const button = (scope, title) => scope.find(`button[title="${title}"]`)

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
  sandboxAdminApi.regions.mockResolvedValue({ data: { data: regionRows } })
  useToast().toasts.value = []
})

afterEach(() => {
  while (mounted.length) mounted.pop().unmount()
})

describe('SandboxesView', () => {
  it('lists the active sandboxes with runtime, isolation, region and pool marker', async () => {
    const wrapper = await mountView()

    expect(sandboxesApi.list).toHaveBeenCalledWith({ status: 'active' })
    const first = row(wrapper, '01jb0000000000000000aaaa01')
    expect(first.exists()).toBe(true)
    expect(first.text()).toContain('Running')
    expect(first.text()).toContain('python 3.14')
    expect(first.text()).toContain('gvisor')
    expect(first.text()).toContain('eu-1')
    expect(first.text()).toContain('agent=planner')
    expect(first.text()).toContain('aaaa01')

    const second = row(wrapper, '01jb0000000000000000bbbb02')
    expect(second.text()).toContain('Paused')
    expect(second.text()).toContain('kata-fc')
    expect(second.text()).toContain('warm pool')
    expect(button(second, 'Resume').exists()).toBe(true)
    expect(button(first, 'Pause').exists()).toBe(true)
  })

  it('reloads with the status filter and the region filter', async () => {
    const wrapper = await mountView()

    await wrapper.find('[data-status-filter="ended"]').trigger('click')
    await flushPromises()
    expect(sandboxesApi.list).toHaveBeenLastCalledWith({ status: 'ended' })

    await wrapper.find('select[data-filter="region"]').setValue('r1')
    await flushPromises()
    expect(sandboxesApi.list).toHaveBeenLastCalledWith({ status: 'ended', region: 'r1' })
  })

  it('kills a sandbox after the confirmation', async () => {
    sandboxesApi.destroy.mockResolvedValue({ data: { data: sandbox('01jb0000000000000000aaaa01', { status: 'terminated', end_reason: 'deleted' }) } })
    const wrapper = await mountView()

    await button(row(wrapper, '01jb0000000000000000aaaa01'), 'Kill').trigger('click')
    await flushPromises()
    expect(useConfirm().current.value?.title).toBe('Kill Sandbox')
    expect(useConfirm().current.value?.message).toContain('aaaa01')
    useConfirm().accept()
    await flushPromises()

    expect(sandboxesApi.destroy).toHaveBeenCalledWith('01jb0000000000000000aaaa01')
    expect(useToast().toasts.value.map(t => t.message)).toContain(`Sandbox ${shortId('01jb0000000000000000aaaa01')} killed.`)
  })

  it('does not kill when the confirmation is cancelled', async () => {
    const wrapper = await mountView()

    await button(row(wrapper, '01jb0000000000000000aaaa01'), 'Kill').trigger('click')
    await flushPromises()
    useConfirm().cancel()
    await flushPromises()

    expect(sandboxesApi.destroy).not.toHaveBeenCalled()
  })

  it('extends the lifetime by 15 minutes on top of what is left', async () => {
    sandboxesApi.setTimeout.mockImplementation((id, ttl) => Promise.resolve({ data: { data: sandbox(id, { expires_at: new Date(Date.now() + ttl * 1000).toISOString() }) } }))
    const wrapper = await mountView()

    await button(row(wrapper, '01jb0000000000000000aaaa01'), 'Extend').trigger('click')
    await flushPromises()

    expect(sandboxesApi.setTimeout).toHaveBeenCalledTimes(1)
    const [id, ttl] = sandboxesApi.setTimeout.mock.calls[0]
    expect(id).toBe('01jb0000000000000000aaaa01')
    // ~10 minutes left + 15 minutes
    expect(ttl).toBeGreaterThanOrEqual(900 + 590)
    expect(ttl).toBeLessThanOrEqual(900 + 600)
  })

  it('pauses a running sandbox', async () => {
    sandboxesApi.pause.mockResolvedValue({ data: { data: sandbox('01jb0000000000000000aaaa01', { status: 'paused' }) } })
    const wrapper = await mountView()

    await button(row(wrapper, '01jb0000000000000000aaaa01'), 'Pause').trigger('click')
    await flushPromises()

    expect(sandboxesApi.pause).toHaveBeenCalledWith('01jb0000000000000000aaaa01')
    expect(row(wrapper, '01jb0000000000000000aaaa01').text()).toContain('Paused')
  })

  it('shows the empty state with a create button', async () => {
    const wrapper = await mountView([])

    expect(wrapper.find('[data-empty="sandboxes"]').text()).toContain('No sandboxes yet')
    expect(wrapper.findAll('button').filter(b => b.text().includes('Create Sandbox')).length).toBeGreaterThan(0)
  })
})
