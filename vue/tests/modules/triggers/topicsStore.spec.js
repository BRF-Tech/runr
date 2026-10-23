import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import client from '@/api/client'
import { topicsApi } from '@/modules/triggers/api/topics'
import { triggersApi } from '@/modules/triggers/api/triggers'
import { useTopicsStore } from '@/modules/triggers/stores/topics'
import { apiError } from '@/test-support/helpers'
import { TOPIC } from './fixtures'

// Topics store (Build & Run → Topics, the topic trigger's select) and the API modules' routes.

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
  clearEtagCache: vi.fn(),
}))

const PAYMENTS = { ...TOPIC, id: '01JTOP000000000000PAYMENTS', name: 'payments', knative_region_id: 'r2', region_name: 'us-1' }

describe('topics API', () => {
  beforeEach(() => vi.clearAllMocks())

  it('calls the topic routes with the name or id encoded', () => {
    topicsApi.list()
    topicsApi.get('orders.created')
    topicsApi.create({ name: 'a', knative_region_id: 'r1' })
    topicsApi.update('a b', { description: 'x' })
    topicsApi.remove('a/b')
    topicsApi.publish('orders.created', { data: {} })
    expect(client.get.mock.calls).toEqual([['/topics'], ['/topics/orders.created']])
    expect(client.post.mock.calls).toEqual([['/topics', { name: 'a', knative_region_id: 'r1' }], ['/topics/orders.created/publish', { data: {} }]])
    expect(client.patch).toHaveBeenCalledWith('/topics/a%20b', { description: 'x' })
    expect(client.delete).toHaveBeenCalledWith('/topics/a%2Fb')
  })
})

describe('triggers API', () => {
  beforeEach(() => vi.clearAllMocks())

  it('calls the trigger and inbox routes of a project', () => {
    triggersApi.types()
    triggersApi.list('p1')
    triggersApi.create('p1', { type: 'http' })
    triggersApi.replace('p1', [{ type: 'http' }])
    triggersApi.update('p1', 'hooks', { enabled: false })
    triggersApi.remove('p1', 'hooks')
    triggersApi.events('p1', 'hooks', { status: 'failed' })
    triggersApi.event('p1', 'hooks', 'e1')
    triggersApi.replayEvent('p1', 'hooks', 'e1')
    triggersApi.replayEvents('p1', 'hooks')
    expect(client.get.mock.calls).toEqual([
      ['/trigger-types'],
      ['/projects/p1/triggers'],
      ['/projects/p1/triggers/hooks/events', { params: { status: 'failed' } }],
      ['/projects/p1/triggers/hooks/events/e1'],
    ])
    expect(client.post.mock.calls).toEqual([
      ['/projects/p1/triggers', { type: 'http' }],
      ['/projects/p1/triggers/hooks/events/e1/replay'],
      ['/projects/p1/triggers/hooks/events/replay', { status: 'failed' }],
    ])
    expect(client.put).toHaveBeenCalledWith('/projects/p1/triggers', { triggers: [{ type: 'http' }] })
    expect(client.patch).toHaveBeenCalledWith('/projects/p1/triggers/hooks', { enabled: false })
    expect(client.delete).toHaveBeenCalledWith('/projects/p1/triggers/hooks')
  })
})

describe('topics store', () => {
  let store
  beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    store = useTopicsStore()
  })

  it('loads the list once and shares a running request', async () => {
    client.get.mockResolvedValue({ data: { data: [TOPIC, PAYMENTS] } })
    await Promise.all([store.load(), store.load()])
    await store.load()
    expect(client.get).toHaveBeenCalledTimes(1)
    expect(store.topics.map(x => x.name)).toEqual(['orders.created', 'payments'])
    expect(store.inRegion('r2').map(x => x.name)).toEqual(['payments'])

    await store.load(true)
    expect(client.get).toHaveBeenCalledTimes(2)
  })

  it('keeps the error of a failed load and tries again next time', async () => {
    client.get.mockRejectedValueOnce(apiError(500))
    await expect(store.load()).rejects.toThrow('HTTP 500')
    expect(store.loaded).toBe(false)
    expect(store.error).toBeTruthy()
    client.get.mockResolvedValueOnce({ data: { data: [TOPIC] } })
    await store.load()
    expect(store.topics).toHaveLength(1)
    expect(store.error).toBeNull()
  })

  it('creates a topic with a trimmed name, sorted into the list', async () => {
    store.topics = [PAYMENTS]
    client.post.mockResolvedValue({ data: { data: { ...TOPIC, subscriptions: 0 } } })
    const created = await store.create({ name: ' orders.created ', knative_region_id: 'r1', description: '  ' })
    expect(client.post).toHaveBeenCalledWith('/topics', { name: 'orders.created', knative_region_id: 'r1' })
    expect(created.name).toBe('orders.created')
    expect(store.topics.map(x => x.name)).toEqual(['orders.created', 'payments'])
  })

  it('answers a refused delete (409) with the subscriptions instead of throwing', async () => {
    store.topics = [TOPIC]
    const subscriptions = [{ project_id: 'p1', trigger: 'orders' }]
    client.delete.mockRejectedValueOnce(apiError(409, { message: '1 trigger(s) subscribe to this topic; remove them first.', subscriptions }))
    expect(await store.remove(TOPIC)).toEqual({ ok: false, message: '1 trigger(s) subscribe to this topic; remove them first.', subscriptions })
    expect(store.topics).toHaveLength(1)

    client.delete.mockResolvedValueOnce({ status: 204 })
    expect(await store.remove(TOPIC)).toEqual({ ok: true })
    expect(store.topics).toEqual([])

    client.delete.mockRejectedValueOnce(apiError(403))
    await expect(store.remove(TOPIC)).rejects.toThrow('HTTP 403')
  })

  it('publishes data with the optional CloudEvent fields only when set', async () => {
    client.post.mockResolvedValue({ data: { data: { id: 'evt-1', stream_id: '1-0' } } })
    expect(await store.publish(TOPIC, { data: [1, 2], type: ' order.created ', subject: '' })).toEqual({ id: 'evt-1', stream_id: '1-0' })
    expect(client.post).toHaveBeenCalledWith('/topics/orders.created/publish', { data: [1, 2], type: 'order.created' })
  })

  it('reads a topic with its subscriptions and saves its description', async () => {
    client.get.mockResolvedValue({ data: { data: { ...TOPIC, subscriptions: [{ trigger_id: 't1', project_id: 'p1' }] } } })
    expect((await store.show('orders.created')).subscriptions).toHaveLength(1)

    store.topics = [TOPIC]
    client.patch.mockResolvedValue({ data: { data: { ...TOPIC, description: null } } })
    await store.updateDescription(TOPIC, '   ')
    expect(client.patch).toHaveBeenCalledWith(`/topics/${TOPIC.id}`, { description: null })
    expect(store.topics[0].description).toBeNull()
  })
})
