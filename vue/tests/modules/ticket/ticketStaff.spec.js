import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import coreEn from '@/locales/en'
import ticketEn from '@/modules/ticket/locales/en'

const client = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), defaults: { baseURL: '/api' } }))
const rbacApi = vi.hoisted(() => ({ myPermissions: vi.fn() }))

vi.mock('@/api/client', () => ({ default: client }))
vi.mock('@/api/rbac', () => ({ rbacApi }))
vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRouter: () => ({ push: vi.fn(), replace: vi.fn() }), useRoute: () => ({ query: {} }) }))
vi.mock('@/composables/useTutorial', () => ({ createTutorial: () => ({ start: vi.fn() }) }))
vi.mock('@/modules/ticket/composables/useTicketRealtime', () => ({
  useTicketListRealtime: () => ({ subscribe: vi.fn() }),
  useTicketDetailRealtime: () => ({ subscribe: vi.fn() }),
}))

import AccountSupportTab from '@/modules/ticket/components/AccountSupportTab.vue'
import TicketStaffControls from '@/modules/ticket/components/TicketStaffControls.vue'
import { useAuthStore } from '@/stores/auth'
import { usePermissionsStore } from '@/stores/permissions'

const ok = (data) => Promise.resolve({ data, status: 200 })
const fail = (status, data = {}) => Promise.reject(Object.assign(new Error(`HTTP ${status}`), { response: { status, data } }))

function plugins() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const i18n = createI18n({
    legacy: false, locale: 'en', fallbackLocale: 'en', missingWarn: false, fallbackWarn: false,
    messages: { en: { ...coreEn, ...ticketEn, account: { ...coreEn.account, ...ticketEn.account } } },
  })
  return { plugins: [i18n, pinia] }
}

const ticket = { id: 7, subject: 'Broken build', status: 'open', priority: 'high', category: 'deployment', created_at: '2026-10-01T10:00:00Z', user: { name: 'Dev' } }

function routes() {
  client.get.mockImplementation((url) => {
    if (url === '/admin/support-tickets') return ok({ data: [ticket], last_page: 1 })
    if (url === '/admin/support-tickets/stats') return ok({ total: 1, open: 1, in_progress: 0, resolved: 0 })
    if (url === '/support-tickets') return ok({ data: [ticket], last_page: 1 })
    if (url === '/support-tickets/stats') return ok({ open: 1, in_progress: 0, resolved: 0 })
    if (url === '/admin/support-tickets/staff') return ok({ data: [{ id: 1, name: 'Olivia Owner', email: 'o@x' }, { id: 2, name: 'Adam Admin', email: 'a@x' }] })
    return ok({})
  })
}

const calledUrls = () => client.get.mock.calls.map(([url]) => url)

describe('AccountSupportTab staff view', () => {
  beforeEach(() => {
    for (const fn of [client.get, client.post, client.patch, rbacApi.myPermissions]) fn.mockReset()
    localStorage.clear()
    routes()
  })

  async function mountTab(setup) {
    const global = plugins()
    setup?.()
    const wrapper = mount(AccountSupportTab, { global: { ...global, stubs: { CreateTicketModal: true, TicketDetailModal: true } } })
    await flushPromises()
    return wrapper
  }

  it('shows members their own tickets when their role does not manage tickets', async () => {
    const wrapper = await mountTab(() => usePermissionsStore().apply({ permissions: ['tickets.view', 'tickets.create'] }))

    expect(calledUrls()).toContain('/support-tickets')
    expect(calledUrls()).not.toContain('/admin/support-tickets')
    expect(wrapper.find('[data-tour="support-new-ticket"]').exists()).toBe(true)
  })

  it('opens the staff view for members holding tickets.manage in a single-tenant install', async () => {
    const wrapper = await mountTab(() => usePermissionsStore().apply({ edition: 'single', permissions: ['tickets.view', 'tickets.manage'] }))

    expect(calledUrls()).toContain('/admin/support-tickets')
    expect(calledUrls()).toContain('/admin/support-tickets/stats')
    expect(calledUrls()).not.toContain('/support-tickets')
    expect(wrapper.find('[data-tour="support-new-ticket"]').exists()).toBe(false)
    // One organization: no tenant column
    expect(wrapper.text()).not.toContain('Tenant')
  })

  it('waits for the permissions before choosing the view', async () => {
    localStorage.setItem('token', 't')
    rbacApi.myPermissions.mockReturnValue(ok({ edition: 'single', permissions: ['tickets.view'] }))

    await mountTab()

    expect(rbacApi.myPermissions).toHaveBeenCalled()
    expect(calledUrls()).toContain('/support-tickets')
    expect(calledUrls()).not.toContain('/admin/support-tickets')
  })

  it('opens the staff view for platform administrators', async () => {
    await mountTab(() => {
      localStorage.setItem('user', JSON.stringify({ id: 1, is_admin: true }))
      usePermissionsStore().apply({ edition: 'multi', platform_admin: true, permissions: [] })
    })

    expect(useAuthStore().isAdmin).toBe(true)
    expect(calledUrls()).toContain('/admin/support-tickets')
  })
})

describe('TicketStaffControls', () => {
  beforeEach(() => {
    for (const fn of [client.get, client.post, client.patch]) fn.mockReset()
    routes()
  })

  async function mountControls(props = {}) {
    const wrapper = mount(TicketStaffControls, { props: { ticketId: 7, assignedTo: null, tags: ['billing'], ...props }, global: plugins() })
    await flushPromises()
    return wrapper
  }

  it('offers the support staff as assignees and assigns the ticket', async () => {
    client.patch.mockReturnValue(ok({ id: 7, assigned_to: 2, assignee: { id: 2, name: 'Adam Admin' } }))
    const wrapper = await mountControls({ tenantId: 't1' })

    const options = wrapper.findAll('[data-test="ticket-assignee"] option').map(o => o.text())
    expect(options).toEqual(['Unassigned', 'Olivia Owner', 'Adam Admin'])

    await wrapper.find('[data-test="ticket-assignee"]').setValue(2)
    await flushPromises()

    expect(client.patch).toHaveBeenCalledWith('/admin/support-tickets/7/assign', { assigned_to: 2 }, { params: { tenant_id: 't1' } })
    expect(wrapper.emitted('assigned')[0][0]).toEqual({ assigned_to: 2, assignee: { id: 2, name: 'Adam Admin' } })
  })

  it('unassigns with the empty choice', async () => {
    client.patch.mockReturnValue(ok({ id: 7, assigned_to: null, assignee: null }))
    const wrapper = await mountControls({ assignedTo: 1 })

    await wrapper.find('[data-test="ticket-assignee"]').setValue('')
    await flushPromises()

    expect(client.patch).toHaveBeenCalledWith('/admin/support-tickets/7/assign', { assigned_to: null }, { params: {} })
  })

  it('adds and removes tags', async () => {
    client.patch.mockImplementation((url, body) => ok({ id: 7, tags: body.tags }))
    const wrapper = await mountControls()

    const input = wrapper.find('[data-test="ticket-tag-input"]')
    await input.setValue('  urgent  ')
    await input.trigger('keydown', { key: 'Enter' })
    await flushPromises()

    expect(client.patch).toHaveBeenLastCalledWith('/admin/support-tickets/7/tags', { tags: ['billing', 'urgent'] }, { params: {} })
    expect(wrapper.find('[data-test="ticket-tag-urgent"]').exists()).toBe(true)
    expect(wrapper.emitted('tags').at(-1)[0]).toEqual(['billing', 'urgent'])

    // A duplicate is not sent
    await input.setValue('billing')
    await input.trigger('keydown', { key: 'Enter' })
    expect(client.patch).toHaveBeenCalledTimes(1)

    await wrapper.find('[data-test="ticket-tag-remove-billing"]').trigger('click')
    await flushPromises()
    expect(client.patch).toHaveBeenLastCalledWith('/admin/support-tickets/7/tags', { tags: ['urgent'] }, { params: {} })
    expect(wrapper.find('[data-test="ticket-tag-billing"]').exists()).toBe(false)
  })

  it('keeps the tags and shows the error when saving fails', async () => {
    client.patch.mockImplementation(() => fail(422, { message: 'The tags.0 field must not be greater than 30 characters.' }))
    const wrapper = await mountControls()

    await wrapper.find('[data-test="ticket-tag-remove-billing"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="ticket-tag-billing"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="ticket-staff-error"]').text()).toContain('30 characters')
  })
})
