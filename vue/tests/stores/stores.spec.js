import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/api/notifications', () => ({
  notificationsApi: {
    unreadCount: vi.fn(), list: vi.fn(), markRead: vi.fn(), markAllRead: vi.fn(), remove: vi.fn(), removeAll: vi.fn(),
  },
}))
vi.mock('@/api/settings', () => ({ settingsApi: { get: vi.fn() } }))

import { notificationsApi } from '@/api/notifications'
import { settingsApi } from '@/api/settings'
import { useNotificationStore } from '@/stores/notifications'
import { useSettingsStore } from '@/stores/settings'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'

beforeEach(() => {
  setActivePinia(createPinia())
  for (const fn of Object.values(notificationsApi)) fn.mockReset().mockResolvedValue({ data: {} })
  settingsApi.get.mockReset()
})

describe('notification store', () => {
  function seeded() {
    const store = useNotificationStore()
    store.notifications = [
      { id: 1, read_at: null },
      { id: 2, read_at: '2026-01-01T00:00:00Z' },
      { id: 3, read_at: null },
    ]
    store.unreadCount = 2
    return store
  }

  it('loads the unread count and tolerates API failures', async () => {
    const store = useNotificationStore()
    notificationsApi.unreadCount.mockResolvedValueOnce({ data: { count: 7 } })
    await store.fetchUnreadCount()
    expect(store.unreadCount).toBe(7)

    notificationsApi.unreadCount.mockRejectedValueOnce(new Error('offline'))
    await expect(store.fetchUnreadCount()).resolves.toBeUndefined()
    expect(store.unreadCount).toBe(7)
  })

  it('fetchAll fills the list and resets loading even on failure', async () => {
    const store = useNotificationStore()
    notificationsApi.list.mockResolvedValueOnce({ data: { data: [{ id: 1 }] } })
    await store.fetchAll({ page: 1 })
    expect(notificationsApi.list).toHaveBeenCalledWith({ page: 1 })
    expect(store.notifications).toEqual([{ id: 1 }])

    notificationsApi.list.mockRejectedValueOnce(new Error('500'))
    await expect(store.fetchAll()).rejects.toThrow('500')
    expect(store.loading).toBe(false)
  })

  it('markRead stamps the notification and decrements the count', async () => {
    const store = seeded()
    await store.markRead(1)
    expect(notificationsApi.markRead).toHaveBeenCalledWith(1)
    expect(store.notifications[0].read_at).toBeTruthy()
    expect(store.unreadCount).toBe(1)
  })

  it('markAllRead keeps existing read times', async () => {
    const store = seeded()
    await store.markAllRead()
    expect(store.unreadCount).toBe(0)
    expect(store.notifications.every(n => n.read_at)).toBe(true)
    expect(store.notifications[1].read_at).toBe('2026-01-01T00:00:00Z')
  })

  it('remove only decrements the count for unread notifications', async () => {
    const store = seeded()
    await store.remove(2)
    expect(store.unreadCount).toBe(2)
    await store.remove(3)
    expect(store.unreadCount).toBe(1)
    expect(store.notifications.map(n => n.id)).toEqual([1])
  })

  it('never lets the unread count go negative', async () => {
    const store = useNotificationStore()
    await store.markRead(99)
    expect(store.unreadCount).toBe(0)
  })

  it('addNotification prepends and counts unread ones', () => {
    const store = seeded()
    store.addNotification({ id: 4, read_at: null })
    store.addNotification({ id: 5, read_at: 'x' })
    expect(store.notifications.map(n => n.id).slice(0, 2)).toEqual([5, 4])
    expect(store.unreadCount).toBe(3)
  })

  it('removeAll empties everything', async () => {
    const store = seeded()
    await store.removeAll()
    expect(store.notifications).toEqual([])
    expect(store.unreadCount).toBe(0)
  })
})

describe('settings store', () => {
  it('fills defaults for fields the API leaves out', async () => {
    settingsApi.get.mockResolvedValue({ data: { base_domain: 'runr.example', extra: 1 } })
    const store = useSettingsStore()
    await store.fetch()
    expect(store.loaded).toBe(true)
    expect(store.data).toEqual({
      base_domain: 'runr.example', extra: 1, total_cpu_m: 0, total_memory_mb: 0,
      regions: [], executor_images: [], gpu_demo_enabled: false, custom_runtimes_enabled: false,
      timezone: 'Europe/Istanbul',
    })
  })

  it('keeps the organization time zone, Europe/Istanbul when the API sends none', async () => {
    settingsApi.get.mockResolvedValue({ data: { timezone: 'Europe/Berlin' } })
    const store = useSettingsStore()
    await store.fetch()
    expect(store.data.timezone).toBe('Europe/Berlin')

    // a settings.updated broadcast without the zone keeps it; one with a new zone takes it
    store.update({ base_domain: 'b' })
    expect(store.data.timezone).toBe('Europe/Berlin')
    store.update({ timezone: 'America/New_York' })
    expect(store.data.timezone).toBe('America/New_York')
  })

  it('update keeps regions and images when the payload has none', () => {
    const store = useSettingsStore()
    store.data.regions = [{ id: 'r1' }]
    store.data.executor_images = [{ runtime: 'python' }]
    store.update({ base_domain: 'b' })
    expect(store.data.regions).toEqual([{ id: 'r1' }])
    expect(store.data.executor_images).toEqual([{ runtime: 'python' }])
    expect(store.data.base_domain).toBe('b')

    store.update({ regions: [] })
    expect(store.data.regions).toEqual([])
  })

  it('invalidate marks the data stale', async () => {
    settingsApi.get.mockResolvedValue({ data: {} })
    const store = useSettingsStore()
    await store.fetch()
    store.invalidate()
    expect(store.loaded).toBe(false)
  })
})

// Fetches answering out of order, and an organization switch (invalidate) while one is in flight
describe('settings store: answers arriving late', () => {
  beforeEach(() => { setActivePinia(createPinia()) })

  it('keeps the newest fetch and drops the one an invalidate overtook', async () => {
    let first
    settingsApi.get
      .mockReturnValueOnce(new Promise((resolve) => { first = resolve }))
      .mockResolvedValueOnce({ data: { base_domain: 'new.example', regions: [{ id: 'r2' }] } })
    const store = useSettingsStore()
    const older = store.fetch()
    store.invalidate()
    await store.fetch()
    first({ data: { base_domain: 'old.example', regions: [{ id: 'r1' }] } })
    await older
    expect(store.data.base_domain).toBe('new.example')
    expect(store.data.regions).toEqual([{ id: 'r2' }])
    expect(store.loaded).toBe(true)
  })
})

describe('useToast', () => {
  it('queues toasts with unique ids and removes them by id', () => {
    const { toasts, success, error, remove } = useToast()
    toasts.value = []
    success('Saved')
    error('Failed', 8000)
    expect(toasts.value.map(t => [t.message, t.type, t.duration])).toEqual([['Saved', 'success', 3000], ['Failed', 'error', 8000]])
    const [a, b] = toasts.value
    expect(a.id).not.toBe(b.id)
    remove(a.id)
    expect(toasts.value.map(t => t.message)).toEqual(['Failed'])
  })

  it('shares one queue across callers', () => {
    const one = useToast()
    const two = useToast()
    one.toasts.value = []
    two.show('hi')
    expect(one.toasts.value).toHaveLength(1)
  })
})

describe('useConfirm', () => {
  it('resolves true on accept and false on cancel', async () => {
    const { current, confirm, accept, cancel } = useConfirm()
    const yes = confirm({ title: 'Delete?', message: 'Sure?', destructive: true })
    expect(current.value).toMatchObject({ title: 'Delete?', destructive: true, confirmLabel: null })
    accept()
    await expect(yes).resolves.toBe(true)
    expect(current.value).toBeNull()

    const no = confirm({ title: 'Leave?' })
    cancel()
    await expect(no).resolves.toBe(false)
  })
})
