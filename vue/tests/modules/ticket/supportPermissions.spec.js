import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import coreEn from '@/locales/en'
import ticketEn from '@/modules/ticket/locales/en'

// The support page with the permissions of docs/permissions.md: GET /support-tickets and its
// stats need tickets.view, a new ticket and a reply tickets.create. A role without tickets.view
// (the read-only demo role) does not see the page; a 403 is not shown as an empty list.

const client = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), defaults: { baseURL: '/api' } }))
const rbacApi = vi.hoisted(() => ({ myPermissions: vi.fn() }))
const tutorial = vi.hoisted(() => ({ options: null, start: vi.fn() }))

vi.mock('@/api/client', () => ({ default: client }))
vi.mock('@/api/rbac', () => ({ rbacApi }))
vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRouter: () => ({ push: vi.fn(), replace: vi.fn() }), useRoute: () => ({ query: {} }) }))
vi.mock('@/composables/useTutorial', () => ({
  createTutorial: (steps, options) => { tutorial.options = options; return { start: tutorial.start } },
}))
vi.mock('@/modules/ticket/composables/useTicketRealtime', () => ({
  useTicketListRealtime: () => ({ subscribe: vi.fn() }),
  useTicketDetailRealtime: () => ({ subscribe: vi.fn() }),
}))

import registerTicketModule from '@/modules/ticket/plugin'
import AccountSupportTab from '@/modules/ticket/components/AccountSupportTab.vue'
import TicketDetailModal from '@/modules/ticket/components/TicketDetailModal.vue'
import { usePermissionsStore } from '@/stores/permissions'

const READ_ONLY = [
  'projects.view', 'git.pull', 'env.view', 'secret_stores.view', 'logs.view', 'metrics.view', 'traces.view',
  'workflows.view', 'sandboxes.view', 'regions.view', 'images.view', 'bindings.view', 'members.view',
]

const ok = (data) => Promise.resolve({ data, status: 200 })
const fail = (status) => Promise.reject(Object.assign(new Error(`HTTP ${status}`), { response: { status, data: {} } }))

function plugins() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const i18n = createI18n({
    legacy: false, locale: 'en', fallbackLocale: 'en', missingWarn: false, fallbackWarn: false,
    messages: { en: { ...coreEn, ...ticketEn, account: { ...coreEn.account, ...ticketEn.account } } },
  })
  return { plugins: [i18n, pinia] }
}

function grant(permissions) {
  usePermissionsStore().apply({ permissions, platform_admin: false, edition: 'single', role: null, roles: [], projects: {} })
}

const ticket = { id: 7, subject: 'Broken build', status: 'open', priority: 'high', category: 'deployment', created_at: '2026-10-01T10:00:00Z', user: { name: 'Dev' } }

function routes({ list = [], status = 200 } = {}) {
  client.get.mockImplementation((url) => {
    if (status !== 200 && url.startsWith('/support-tickets')) return fail(status)
    if (url === '/support-tickets') return ok({ data: list, last_page: 1 })
    if (url === '/support-tickets/stats') return ok({ open: list.length, in_progress: 0, resolved: 0 })
    if (url === '/support-tickets/7' || url === '/admin/support-tickets/7') return ok({ id: 7, status: 'open', tags: [], replies: [], attachments: [] })
    if (url === '/admin/support-tickets/staff') return ok({ data: [] })
    return ok({})
  })
  client.post.mockImplementation(() => ok({}))
}

const mounted = []
async function mountTab(permissions) {
  const global = plugins()
  if (permissions) grant(permissions)
  const wrapper = mount(AccountSupportTab, { global: { ...global, stubs: { CreateTicketModal: true, TicketDetailModal: true } } })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  for (const fn of [client.get, client.post, client.patch, rbacApi.myPermissions, tutorial.start]) fn.mockReset()
  tutorial.options = null
  localStorage.clear()
  routes()
})
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('support page in the menu', () => {
  function supportEntry() {
    const registry = { settingsTabs: [], localeMessages: [] }
    registerTicketModule(registry)
    return registry.settingsTabs.find(tab => tab.key === 'support-tickets')
  }

  it('is left out for a role without tickets.view or tickets.manage', () => {
    plugins()
    grant(READ_ONLY)
    expect(supportEntry().visibleIf()).toBe(false)
  })

  it('shows for tickets.view, for tickets.manage and while the permissions are not loaded', () => {
    plugins()
    expect(supportEntry().visibleIf()).toBe(true)
    grant(['tickets.view'])
    expect(supportEntry().visibleIf()).toBe(true)
    grant(['tickets.manage'])
    expect(supportEntry().visibleIf()).toBe(true)
  })
})

describe('AccountSupportTab permissions', () => {
  it('leaves New ticket out without tickets.create, and the empty list does not offer one', async () => {
    const wrapper = await mountTab(['tickets.view'])

    expect(wrapper.find('[data-tour="support-new-ticket"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('No tickets yet.')
    expect(wrapper.text()).not.toContain('Create one')
  })

  it('shows New ticket with tickets.create', async () => {
    const wrapper = await mountTab(['tickets.view', 'tickets.create'])

    expect(wrapper.find('[data-tour="support-new-ticket"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Create one to get help')
  })

  it('says the role may not see tickets when the API answers 403, not "No tickets"', async () => {
    routes({ status: 403 })
    const wrapper = await mountTab(['tickets.create'])

    const state = wrapper.find('[data-state="tickets-error"]')
    expect(state.exists()).toBe(true)
    expect(state.text()).toBe('Your role does not allow viewing support tickets.')
    expect(wrapper.text()).not.toContain('No tickets yet')
  })

  it('says the tickets could not be loaded on another error', async () => {
    routes({ status: 500 })
    const wrapper = await mountTab(['tickets.view'])

    expect(wrapper.find('[data-state="tickets-error"]').text()).toContain('could not be loaded')
    expect(wrapper.text()).not.toContain('No tickets yet')
  })

  it('ends the tour without opening the new-ticket form for a role without tickets.create', async () => {
    const wrapper = await mountTab(['tickets.view'])

    tutorial.options.onComplete()
    await flushPromises()

    expect(wrapper.findComponent({ name: 'CreateTicketModal' }).exists()).toBe(false)
  })

  it('goes on in the new-ticket form for a role with tickets.create', async () => {
    const wrapper = await mountTab(['tickets.view', 'tickets.create'])

    tutorial.options.onComplete()
    await flushPromises()

    expect(wrapper.findComponent({ name: 'CreateTicketModal' }).exists()).toBe(true)
  })
})

describe('TicketDetailModal permissions', () => {
  async function mountModal(props, permissions) {
    const global = plugins()
    grant(permissions)
    const wrapper = mount(TicketDetailModal, { props: { ticket, ...props }, global })
    mounted.push(wrapper)
    await flushPromises()
    return wrapper
  }

  it('leaves the reply form out for a member without tickets.create', async () => {
    const wrapper = await mountModal({}, ['tickets.view'])
    expect(wrapper.find('[data-reply-form]').exists()).toBe(false)
  })

  it('shows the reply form with tickets.create and to the staff', async () => {
    expect((await mountModal({}, ['tickets.view', 'tickets.create'])).find('[data-reply-form]').exists()).toBe(true)
    expect((await mountModal({ admin: true }, ['tickets.manage'])).find('[data-reply-form]').exists()).toBe(true)
  })
})
