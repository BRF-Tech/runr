import { ref } from 'vue'
import { defineStore } from 'pinia'
import { notificationsApi } from '@/api/notifications'

export const useNotificationStore = defineStore('notifications', () => {
  const notifications = ref([])
  const unreadCount   = ref(0)
  const loading       = ref(false)

  async function fetchUnreadCount() {
    try {
      const { data } = await notificationsApi.unreadCount()
      unreadCount.value = data.count ?? 0
    } catch { /* silent */ }
  }

  async function fetchAll(params = {}) {
    loading.value = true
    try {
      const { data } = await notificationsApi.list(params)
      notifications.value = data.data ?? []
      return data
    } finally {
      loading.value = false
    }
  }

  // Marked read once, however often it is clicked (the count drops once)
  const marking = new Set()
  async function markRead(id) {
    const n = notifications.value.find(x => x.id === id)
    if (marking.has(id) || n?.read_at) return
    marking.add(id)
    try {
      await notificationsApi.markRead(id)
      const now = notifications.value.find(x => x.id === id)
      if (now && !now.read_at) {
        now.read_at = new Date().toISOString()
        unreadCount.value = Math.max(0, unreadCount.value - 1)
      } else if (!now) {
        unreadCount.value = Math.max(0, unreadCount.value - 1)
      }
    } finally {
      marking.delete(id)
    }
  }

  async function markAllRead() {
    await notificationsApi.markAllRead()
    notifications.value.forEach(n => { n.read_at = n.read_at || new Date().toISOString() })
    unreadCount.value = 0
  }

  async function remove(id) {
    const n = notifications.value.find(x => x.id === id)
    if (n && !n.read_at) unreadCount.value = Math.max(0, unreadCount.value - 1)
    notifications.value = notifications.value.filter(x => x.id !== id)
    await notificationsApi.remove(id)
  }

  async function removeAll() {
    await notificationsApi.removeAll()
    notifications.value = []
    unreadCount.value = 0
  }

  function addNotification(n) {
    notifications.value.unshift(n)
    if (!n.read_at) unreadCount.value++
  }

  return { notifications, unreadCount, loading, fetchUnreadCount, fetchAll, markRead, markAllRead, remove, removeAll, addNotification }
})
