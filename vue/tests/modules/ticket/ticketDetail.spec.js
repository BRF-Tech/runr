import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import coreEn from '@/locales/en'
import ticketEn from '@/modules/ticket/locales/en'

const client = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), defaults: { baseURL: '/api' } }))
const realtime = vi.hoisted(() => ({ handlers: null, subscribe: vi.fn() }))

vi.mock('@/api/client', () => ({ default: client }))
vi.mock('@/modules/ticket/composables/useTicketRealtime', () => ({
  useTicketListRealtime: () => ({ subscribe: vi.fn() }),
  useTicketDetailRealtime: (id, onReply, onRead, onNote, onStatus) => {
    realtime.handlers = { id, onReply, onRead, onNote, onStatus }
    return { subscribe: realtime.subscribe }
  },
}))

import TicketDetailModal from '@/modules/ticket/components/TicketDetailModal.vue'

const ok = (data) => Promise.resolve({ data, status: 200 })
const blob = new Blob(['x'], { type: 'image/png' })

function plugins() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const i18n = createI18n({
    legacy: false, locale: 'en', fallbackLocale: 'en', missingWarn: false, fallbackWarn: false,
    messages: { en: { ...coreEn, ...ticketEn, account: { ...coreEn.account, ...ticketEn.account } } },
  })
  return { plugins: [i18n, pinia] }
}

const ticket = (extra = {}) => ({
  id: 7, subject: 'Broken build', status: 'open', priority: 'high', category: 'deployment',
  created_at: '2026-10-01T10:00:00Z', description: 'The build fails', user: { name: 'Dev', email: 'dev@acme.test' }, ...extra,
})

const detail = (extra = {}) => ({
  id: 7, status: 'open', tags: ['billing'], assigned_to: null, attachments: ['tickets/t1/7/screen.png', 'tickets/t1/7/log.pdf'],
  replies: [
    { id: 1, message: 'Can you share the log?', is_staff: true, created_at: '2026-10-01T11:00:00Z', attachments: ['tickets/t1/7/hint.webp'] },
    { id: 2, message: 'Here it is', is_staff: false, user: { email: 'dev@acme.test', name: 'Dev' }, created_at: '2026-10-01T12:00:00Z', attachments: ['tickets/t1/7/build.txt'] },
    { id: 3, message: 'Looks like a quota issue', is_internal: true, is_staff: true, created_at: '2026-10-01T12:30:00Z' },
  ],
  ...extra,
})

function routes(detailData = detail()) {
  client.get.mockImplementation((url) => {
    if (url === '/support-tickets/7' || url === '/admin/support-tickets/7') return ok(detailData)
    if (url === '/admin/support-tickets/staff') return ok({ data: [{ id: 1, name: 'Olivia Owner', email: 'o@x' }] })
    if (url.includes('/attachments/')) return ok(blob)
    return ok({})
  })
  client.post.mockImplementation(() => ok({}))
  client.patch.mockImplementation(() => ok({}))
}

const mountedWrappers = []
async function mountModal(props) {
  const wrapper = mount(TicketDetailModal, { props: { ticket: ticket(), ...props }, global: plugins(), attachTo: document.body })
  mountedWrappers.push(wrapper)
  await flushPromises()
  return wrapper
}

const getUrls = () => client.get.mock.calls.map(([url]) => url)

describe('TicketDetailModal', () => {
  beforeEach(() => {
    for (const fn of [client.get, client.post, client.patch, realtime.subscribe]) fn.mockReset()
    realtime.handlers = null
    URL.createObjectURL = vi.fn((b) => `blob:${b.size}-${Math.random()}`)
    URL.revokeObjectURL = vi.fn()
    routes()
  })
  afterEach(() => { mountedWrappers.splice(0).forEach(w => w.unmount()) })

  it('shows a member their ticket: the conversation, the tags, images fetched with the token, read receipt sent', async () => {
    const wrapper = await mountModal()

    expect(getUrls()).toContain('/support-tickets/7')
    expect(client.post).toHaveBeenCalledWith('/support-tickets/7/read')
    expect(realtime.subscribe).toHaveBeenCalled()
    expect(realtime.handlers.id).toBe(7)

    const text = wrapper.text()
    expect(text).toContain('Broken build')
    expect(text).toContain('The build fails')
    expect(text).toContain('Can you share the log?')
    expect(text).toContain('Here it is')
    expect(text).not.toContain('Looks like a quota issue') // internal note: staff only
    expect(text).toContain('billing')
    // Images are prefetched through the API (auth-protected), other files are download buttons
    expect(getUrls()).toEqual(expect.arrayContaining(['/support-tickets/7/attachments/screen.png', '/support-tickets/7/attachments/hint.webp']))
    expect(getUrls()).not.toContain('/support-tickets/7/attachments/log.pdf')
    expect(wrapper.findAll('img').length).toBe(2)
    expect(text).toContain('log.pdf')
    expect(text).toContain('build.txt')
    // Member view: no staff controls or status buttons
    expect(text).not.toContain('Olivia Owner')
  })

  it('opens an image in the lightbox and downloads a file once fetched', async () => {
    const wrapper = await mountModal()
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    await wrapper.findAll('img')[0].element.closest('button').click()
    await flushPromises()
    const lightbox = wrapper.find('.z-60')
    expect(lightbox.exists()).toBe(true)
    await lightbox.trigger('click')
    expect(wrapper.find('.z-60').exists()).toBe(false)

    const download = wrapper.findAll('button').find(b => b.text().includes('log.pdf'))
    await download.trigger('click')
    await flushPromises()
    expect(getUrls()).toContain('/support-tickets/7/attachments/log.pdf')
    expect(click).toHaveBeenCalledTimes(1)

    // The second download comes from the cache
    const calls = client.get.mock.calls.length
    await download.trigger('click')
    await flushPromises()
    expect(client.get.mock.calls.length).toBe(calls)
    expect(click).toHaveBeenCalledTimes(2)
    click.mockRestore()
  })

  it('does not download when the attachment cannot be fetched', async () => {
    const wrapper = await mountModal()
    client.get.mockImplementation(() => Promise.reject(new Error('HTTP 404')))
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    await wrapper.findAll('button').find(b => b.text().includes('build.txt')).trigger('click')
    await flushPromises()
    expect(click).not.toHaveBeenCalled()
    click.mockRestore()
  })

  it('sends a reply with attachments (at most three) and clears the form', async () => {
    const wrapper = await mountModal()
    const input = wrapper.find('input[type="file"]')
    const files = [1, 2, 3, 4].map(i => new File(['x'], `f${i}.png`, { type: 'image/png' }))
    Object.defineProperty(input.element, 'files', { value: files, configurable: true })
    await input.trigger('change')
    expect(wrapper.text()).toContain('f3.png')
    expect(wrapper.text()).not.toContain('f4.png')

    await wrapper.find('input.input').setValue('  Thanks!  ')
    await wrapper.find('button.btn-primary').trigger('click')
    await flushPromises()

    const [url, fd] = client.post.mock.calls.find(([u]) => u === '/support-tickets/7/replies')
    expect(url).toBe('/support-tickets/7/replies')
    expect(fd.get('message')).toBe('Thanks!')
    expect(fd.getAll('attachments[]')).toHaveLength(3)
    expect(wrapper.find('input.input').element.value).toBe('')
  })

  it('removes a chosen file and ignores an empty reply', async () => {
    const wrapper = await mountModal()
    const input = wrapper.find('input[type="file"]')
    Object.defineProperty(input.element, 'files', { value: [new File(['x'], 'only.pdf', { type: 'application/pdf' })], configurable: true })
    await input.trigger('change')
    expect(wrapper.text()).toContain('only.pdf')

    await wrapper.findAll('button').find(b => b.attributes('class')?.includes('hover:text-red')).trigger('click')
    await flushPromises()
    expect(wrapper.text()).not.toContain('only.pdf')

    await wrapper.find('input.input').setValue('   ')
    await wrapper.find('input.input').trigger('keyup.enter')
    await wrapper.find('button.btn-primary').trigger('click')
    expect(client.post.mock.calls.some(([u]) => u.endsWith('/replies'))).toBe(false)
  })

  it('adds replies, notes, read receipts and status changes as they arrive', async () => {
    const wrapper = await mountModal({ ticket: ticket({ user: undefined }) })

    realtime.handlers.onReply({ id: 9, message: 'We fixed it', is_staff: true, created_at: '2026-10-02T09:00:00Z', attachments: ['tickets/t1/7/fix.jpg', 'tickets/t1/7/notes.txt'] })
    realtime.handlers.onNote({ id: 10, message: 'a staff-only remark', is_internal: true })
    realtime.handlers.onRead({ admin_read_at: '2026-10-02T09:01:00Z', user_read_at: null })
    realtime.handlers.onStatus('resolved')
    await flushPromises()

    expect(wrapper.text()).toContain('We fixed it')
    expect(wrapper.text()).not.toContain('a staff-only remark')
    expect(getUrls()).toContain('/support-tickets/7/attachments/fix.jpg')
    expect(getUrls()).not.toContain('/support-tickets/7/attachments/notes.txt')
    expect(wrapper.find('h2').element.closest('.border-b').textContent).toContain(ticketEn.account.st_resolved)
  })

  it('shows a ticket without replies and closes from the backdrop or the close button', async () => {
    routes(detail({ replies: [], attachments: [], tags: [] }))
    const wrapper = await mountModal()
    expect(wrapper.text()).toContain(ticketEn.account.ticketNoReplies)

    await wrapper.find('.fixed.inset-0').trigger('click')
    wrapper.find('h2').element.closest('.justify-between').querySelector('button').click()
    expect(wrapper.emitted('close')).toHaveLength(2)
  })

  it('gives staff the ticket of another organization: tenant-scoped calls, status, staff controls and internal notes', async () => {
    const wrapper = await mountModal({ admin: true, ticket: ticket({ tenant_id: 'acme' }) })

    expect(client.get).toHaveBeenCalledWith('/admin/support-tickets/7', { params: { tenant_id: 'acme' } })
    expect(client.post).toHaveBeenCalledWith('/admin/support-tickets/7/read', {}, { params: { tenant_id: 'acme' } })
    expect(getUrls()).toContain('/admin/support-tickets/7/attachments/screen.png?tenant_id=acme')
    expect(wrapper.text()).toContain('Dev')
    expect(wrapper.text()).toContain('Olivia Owner')

    // Status change
    const resolved = wrapper.findAll('button').find(b => b.text() === ticketEn.account.st_resolved)
    await resolved.trigger('click')
    await flushPromises()
    expect(client.patch).toHaveBeenCalledWith('/admin/support-tickets/7/status', { status: 'resolved' }, { params: { tenant_id: 'acme' } })
    expect(wrapper.emitted('updated')).toHaveLength(1)

    // Staff controls keep the detail in step and ask the list to reload
    const controls = wrapper.findComponent({ name: 'TicketStaffControls' })
    controls.vm.$emit('assigned', { assigned_to: 1, assignee: { id: 1, name: 'Olivia Owner' } })
    controls.vm.$emit('tags', ['billing', 'urgent'])
    await flushPromises()
    expect(wrapper.emitted('changed')).toHaveLength(2)

    // Staff reply goes to the organization of the ticket
    // (the staff controls have an input of their own: the tag field)
    await wrapper.find('input.h-11').setValue('On it')
    await wrapper.find('input.h-11').trigger('keyup.enter')
    await flushPromises()
    const reply = client.post.mock.calls.find(([u]) => u === '/admin/support-tickets/7/replies')
    expect(reply[2]).toMatchObject({ params: { tenant_id: 'acme' } })

    // Internal notes tab
    const notesTab = wrapper.findAll('button').find(b => b.text() === ticketEn.account.tabNotes)
    await notesTab.trigger('click')
    expect(wrapper.text()).toContain('Looks like a quota issue')
    expect(wrapper.text()).not.toContain('Can you share the log?')
    expect(wrapper.find('input.h-11').exists()).toBe(false)
    const noteInput = wrapper.findAll('input').find(i => i.classes().includes('placeholder-amber-600'))
    await noteInput.setValue('Checked the quota')
    await noteInput.trigger('keyup.enter')
    await flushPromises()
    const note = client.post.mock.calls.filter(([u]) => u === '/admin/support-tickets/7/replies').at(-1)
    expect(note[1].get('is_internal')).toBe('1')
    expect(note[1].get('message')).toBe('Checked the quota')
    expect(note[2]).toMatchObject({ params: { tenant_id: 'acme' } })
  })

  it('gives staff a ticket of the own organization without a tenant parameter, and keeps going when the read receipt fails', async () => {
    client.post.mockImplementation(() => Promise.reject(new Error('HTTP 500')))
    await mountModal({ admin: true })
    expect(client.get).toHaveBeenCalledWith('/admin/support-tickets/7', { params: {} })
    expect(getUrls()).toContain('/admin/support-tickets/7/attachments/screen.png')
  })

  it('revokes the image URLs it created when closed', async () => {
    const wrapper = await mountModal()
    const created = URL.createObjectURL.mock.results.length
    expect(created).toBeGreaterThan(0)
    wrapper.unmount()
    mountedWrappers.splice(mountedWrappers.indexOf(wrapper), 1)
    expect(URL.revokeObjectURL).toHaveBeenCalledTimes(created)
  })
})
