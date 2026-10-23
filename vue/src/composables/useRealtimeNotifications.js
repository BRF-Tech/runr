import { onUnmounted } from 'vue'
import { useNotificationStore } from '@/stores/notifications'
import { useSettingsStore } from '@/stores/settings'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { dispatchPlatformEvent } from '@/composables/useJobEvents'
import { setWsConnected } from '@/composables/useWsConnection'
import { isPromotionNotification, notificationTitle } from '@/utils/notifications'
import { useApprovalsStore } from '@/stores/approvals'
import i18n from '@/i18n'

let echoInstance = null
let subscribed = false
// The subscribe in flight (its number), null when none: it waits for the socket client to load,
// and a second subscribe meanwhile (the sign-in watcher and the tenancy login hook both start
// one) must not listen a second time; an unsubscribe meanwhile cancels it
let subscribing = null
let subscribeSeq = 0
let echoModule = null   // the socket client, loaded once
let channelPrefix = ''
let activeChannels = []
let wsStateHandled = false

// Module-level refs to the subscribe/unsubscribe closures (set by useRealtimeNotifications)
let _subscribe = null
let _unsubscribe = null

/**
 * Set a prefix for broadcast channel names.
 * E.g. setBroadcastChannelPrefix('tenant.abc') → channels become 'tenant.abc.platform'.
 * Called by tenancy module on login/tenant switch.
 */
export function setBroadcastChannelPrefix(prefix) {
  channelPrefix = prefix || ''
}

/**
 * Force unsubscribe from current channels and resubscribe with current prefix.
 * Called by tenancy module when tenant context changes at runtime.
 */
export function resubscribeBroadcast() {
  if (_unsubscribe) _unsubscribe()
  if (_subscribe) _subscribe()
}

export function getChannel(base) {
  return channelPrefix ? `${channelPrefix}.${base}` : base
}

export function useRealtimeNotifications() {
  const notifStore = useNotificationStore()
  const settingsStore = useSettingsStore()
  const toast = useToast()

  async function subscribe() {
    if (subscribed || subscribing !== null) return
    // Every channel is private: without a session each authorization answers 401
    if (!useAuthStore().token) return

    const seq = ++subscribeSeq
    subscribing = seq
    let mod
    try {
      echoModule ??= import('@/plugins/echo')
      mod = await echoModule
    } catch {
      echoModule = null
      if (subscribing === seq) subscribing = null
      return // Echo/Pusher not available, polling fallback only
    }
    // Unsubscribed (or subscribed afresh by a resubscribe) while the client loaded
    if (subscribing !== seq) return
    subscribing = null
    echoInstance = mod.default

    const notifCh = getChannel('notifications')
    const platformCh = getChannel('platform')
    // Notifications addressed to the signed-in user arrive on their own channel
    const userId = useAuthStore().user?.id
    const ownNotifCh = userId ? getChannel(`notifications.${userId}`) : null

    // Every channel is private: subscriptions are authorized by POST /api/broadcasting/auth
    const onNotification = (e) => {
      const n    = e.notification ?? e
      const type = n.type || ''
      notifStore.addNotification(n)
      // A promotion waits for the user's approval (or theirs was decided): the header count follows
      if (isPromotionNotification(n)) useApprovalsStore().fetchCount()
      // DemoManager bileşeni kendi toast'ını gösteriyor, burada gösterme
      const demoSuccessTypes = ['demo.seeded', 'demo.deployed', 'demo.removed']
      if (!demoSuccessTypes.includes(type)) {
        const title = notificationTitle(n, i18n.global.t)
        if (n.level === 'critical') toast.error(title)
        else toast.show(title, 'warning', 5000)
      }
    }

    // Notifications channels (everyone's + the user's own)
    echoInstance.private(notifCh).listen('NotificationCreated', onNotification)
    if (ownNotifCh) echoInstance.private(ownNotifCh).listen('NotificationCreated', onNotification)

    // Platform-wide updates channel
    echoInstance.private(platformCh)
      .listen('.platform.event', (e) => handlePlatformEvent(e))

    activeChannels = [notifCh, platformCh, ...(ownNotifCh ? [ownNotifCh] : [])]

    // Track WS connection state (only bind once)
    if (!wsStateHandled && echoInstance.connector?.pusher?.connection) {
      echoInstance.connector.pusher.connection.bind('state_change', ({ current }) => {
        setWsConnected(current === 'connected')
      })
      wsStateHandled = true
    }
    setWsConnected(true)

    subscribed = true
  }

  function handlePlatformEvent(e) {
    const { topic, payload } = e
    if (!topic) return

    if (topic === 'settings.updated' && payload?.settings) {
      settingsStore.update(payload.settings)
    }

    if (topic === 'region.updated' && payload?.region) {
      const regions = [...(settingsStore.data.regions ?? [])]
      const idx = regions.findIndex(r => r.id === payload.region.id)
      if (idx !== -1) {
        regions[idx] = payload.region
        settingsStore.data = { ...settingsStore.data, regions }
      }
    }

    // A promotion was requested, decided or ended (docs/environments.md#notifications): the
    // header's approvals count follows; an open approvals inbox reloads (useJobEvents)
    if (topic === 'environment.promotion') {
      useApprovalsStore().fetchCount()
    }

    // Demo removed/seeded → refresh settings (regions, demo_status)
    if (topic === 'demo.removed' || topic === 'demo.seeded') {
      settingsStore.fetch()
    }

    // Session revoked → auto-logout if current token was revoked
    if (topic === 'session.revoked' && Array.isArray(payload?.token_ids)) {
      const rawToken = localStorage.getItem('token')
      const currentId = parseInt(rawToken?.split('|')[0])
      if (!isNaN(currentId) && payload.token_ids.includes(currentId)) {
        useAuthStore().forceLogout()
        window.location.href = '/login'
        return
      }
    }

    // Dispatch to registered job event handlers
    dispatchPlatformEvent(topic, payload)
  }

  function unsubscribe() {
    subscribing = null
    if (!subscribed || !echoInstance) return
    activeChannels.forEach(ch => echoInstance.leave(ch))
    activeChannels = []
    setWsConnected(false)
    subscribed = false
  }

  // Store refs for module-level resubscribe
  _subscribe = subscribe
  _unsubscribe = unsubscribe

  onUnmounted(unsubscribe)

  return { subscribe, unsubscribe }
}
