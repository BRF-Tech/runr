import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { usePermissionsStore } from '@/stores/permissions'
import { useSettingsStore } from '@/stores/settings'
import { useToast } from '@/composables/useToast'
import { topicsApi } from '@/modules/triggers/api/topics'
import TopicsView from '@/modules/triggers/components/TopicsView.vue'
import { apiError } from '@/test-support/helpers'
import { withPlugins } from '../../helpers'
import { REGIONS, TOPIC } from './fixtures'

// Build & Run → Topics: list, create (region with Redis), detail with subscriptions, the refused
// delete (409 with the subscriptions), test publish; topics.manage / events.send.

vi.mock('@/modules/triggers/api/topics', () => ({
  topicsApi: { list: vi.fn(), get: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn(), publish: vi.fn() },
}))
const confirmMock = vi.fn()
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: confirmMock }) }))

const SUBSCRIPTION = { trigger_id: 't1', trigger: 'orders', enabled: true, project_id: 'p1', project_name: 'Orders', start_from: 'latest' }

async function mountView(permissions = null) {
  const plugins = withPlugins()
  const settings = useSettingsStore()
  settings.data = { ...settings.data, regions: REGIONS }
  settings.loaded = true
  if (permissions) {
    usePermissionsStore().apply({ edition: 'single', platform_admin: false, role: 'custom', roles: ['custom'], permissions, projects: {}, token_scoped: false })
  }
  const w = mount(TopicsView, { ...plugins, global: { ...plugins.global, stubs: { RouterLink: RouterLinkStub } } })
  await flushPromises()
  return w
}

async function openTopic(w) {
  await w.find(`[data-topic="${TOPIC.name}"]`).trigger('click')
  await flushPromises()
  return w.find('[data-test="topic-detail"]')
}

describe('TopicsView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useToast().toasts.value = []
    topicsApi.list.mockResolvedValue({ data: { data: [{ ...TOPIC, subscriptions: 1 }] } })
    topicsApi.get.mockResolvedValue({ data: { data: { ...TOPIC, subscriptions: [SUBSCRIPTION] } } })
  })

  it('lists the topics with region and subscription count', async () => {
    const w = await mountView()
    const row = w.find(`[data-topic="${TOPIC.name}"]`)
    expect(row.text()).toContain('eu-1')
    expect(row.text()).toContain('1')
    expect(row.text()).toContain('New orders')
  })

  it('opens a topic with its subscriptions linking to the projects', async () => {
    const w = await mountView()
    const detail = await openTopic(w)
    expect(topicsApi.get).toHaveBeenCalledWith(TOPIC.name)
    expect(detail.text()).toContain(TOPIC.stream)
    const link = detail.find('[data-subscription="Orders"]').findComponent(RouterLinkStub)
    expect(link.props('to')).toBe('/projects/p1#triggers')
  })

  it('creates a topic in a region with Redis', async () => {
    topicsApi.create.mockResolvedValue({ data: { data: { ...TOPIC, name: 'payments', subscriptions: 0 } } })
    topicsApi.get.mockResolvedValue({ data: { data: { ...TOPIC, name: 'payments', subscriptions: [] } } })
    const w = await mountView()
    await w.find('[data-action="create-topic"]').trigger('click')
    const modal = w.find('[data-test="create-topic-modal"]')
    const options = modal.findAll('[data-field="region"] option')
    expect(options.find(o => o.attributes('value') === 'r2').attributes('disabled')).toBeDefined()
    expect(options.find(o => o.attributes('value') === 'r2').text()).toBe('us-1 (no Redis)')
    expect(modal.find('[data-field="region"]').element.value).toBe('r1')

    await modal.find('[data-field="name"]').setValue('-bad')
    expect(modal.text()).toContain('starting with a letter or digit')
    expect(modal.find('[data-test="create-topic-submit"]').attributes('disabled')).toBeDefined()

    await modal.find('[data-field="name"]').setValue('payments')
    await modal.find('[data-field="description"]').setValue('Paid orders')
    await modal.find('form').trigger('submit')
    await flushPromises()
    expect(topicsApi.create).toHaveBeenCalledWith({ name: 'payments', knative_region_id: 'r1', description: 'Paid orders' })
    expect(w.find('[data-test="create-topic-modal"]').exists()).toBe(false)
    expect(useToast().toasts.value.at(-1).message).toBe('Topic "payments" created.')
    expect(w.find('[data-test="topic-detail"]').text()).toContain('payments')
    expect(w.find('[data-test="no-subscriptions"]').exists()).toBe(true)
  })

  it('shows the API refusal of a region without Redis', async () => {
    topicsApi.create.mockRejectedValue(apiError(422, { message: 'Topics need Redis on the region.' }))
    const w = await mountView()
    await w.find('[data-action="create-topic"]').trigger('click')
    const modal = w.find('[data-test="create-topic-modal"]')
    await modal.find('[data-field="name"]').setValue('payments')
    await modal.find('form').trigger('submit')
    await flushPromises()
    expect(modal.find('[data-test="create-topic-error"]').text()).toBe('Topics need Redis on the region.')
  })

  it('shows the subscriptions that block a delete', async () => {
    topicsApi.remove.mockRejectedValue(apiError(409, {
      message: '1 trigger(s) subscribe to this topic; remove them first.',
      subscriptions: [{ project_id: 'p1', trigger: 'orders' }],
    }))
    confirmMock.mockResolvedValue(true)
    const w = await mountView()
    const detail = await openTopic(w)
    await detail.find('[data-action="delete-topic"]').trigger('click')
    await flushPromises()
    const blocked = w.find('[data-test="topic-in-use"]')
    expect(blocked.text()).toContain('remove them first')
    expect(blocked.text()).toContain('Orders')
    expect(blocked.text()).toContain('orders')
    expect(w.find(`[data-topic="${TOPIC.name}"]`).exists()).toBe(true)
  })

  it('deletes a topic without subscriptions', async () => {
    topicsApi.remove.mockResolvedValue({ status: 204 })
    topicsApi.get.mockResolvedValue({ data: { data: { ...TOPIC, subscriptions: [] } } })
    confirmMock.mockResolvedValue(true)
    const w = await mountView()
    const detail = await openTopic(w)
    await detail.find('[data-action="delete-topic"]').trigger('click')
    await flushPromises()
    expect(topicsApi.remove).toHaveBeenCalledWith(TOPIC.id)
    expect(w.find('[data-test="topic-detail"]').exists()).toBe(false)
    expect(w.find(`[data-topic="${TOPIC.name}"]`).exists()).toBe(false)
  })

  it('publishes a test event with JSON data', async () => {
    topicsApi.publish.mockResolvedValue({ data: { data: { id: 'evt-9', stream_id: '1-0' } } })
    const w = await mountView()
    const detail = await openTopic(w)
    await detail.find('[data-action="publish"]').trigger('click')
    const modal = w.find('[data-test="publish-topic-modal"]')
    await modal.find('[data-field="data"]').setValue('{"id": 1')
    expect(modal.find('[data-test="publish-submit"]').attributes('disabled')).toBeDefined()
    await modal.find('[data-field="data"]').setValue('{"id": 1}')
    await modal.find('[data-field="subject"]').setValue('orders/1')
    await modal.find('form').trigger('submit')
    await flushPromises()
    expect(topicsApi.publish).toHaveBeenCalledWith(TOPIC.name, { data: { id: 1 }, subject: 'orders/1' })
    expect(useToast().toasts.value.at(-1).message).toBe('Event published (evt-9).')
    expect(w.find('[data-test="publish-topic-modal"]').exists()).toBe(false)
  })

  it('hides create / delete without topics.manage and publish without events.send', async () => {
    const w = await mountView(['projects.view'])
    expect(w.find('[data-action="create-topic"]').exists()).toBe(false)
    const detail = await openTopic(w)
    expect(detail.find('[data-action="delete-topic"]').exists()).toBe(false)
    expect(detail.find('[data-action="publish"]').exists()).toBe(false)
    expect(detail.find('[data-action="edit-description"]').exists()).toBe(false)

    const sender = await mountView(['projects.view', 'events.send'])
    expect((await openTopic(sender)).find('[data-action="publish"]').exists()).toBe(true)
  })

  it('edits the description', async () => {
    topicsApi.update.mockResolvedValue({ data: { data: { ...TOPIC, description: 'All orders' } } })
    const w = await mountView()
    const detail = await openTopic(w)
    await detail.find('[data-action="edit-description"]').trigger('click')
    await detail.find('[data-field="description"]').setValue('All orders')
    await detail.find('[data-action="save-description"]').trigger('click')
    await flushPromises()
    expect(topicsApi.update).toHaveBeenCalledWith(TOPIC.id, { description: 'All orders' })
    expect(w.find('[data-test="topic-detail"]').text()).toContain('All orders')
  })
})
