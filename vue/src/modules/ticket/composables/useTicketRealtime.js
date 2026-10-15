import { onUnmounted } from 'vue'
import { getChannel } from '@/composables/useRealtimeNotifications'

let echo = null

async function getEcho() {
  if (echo) return echo
  try {
    const mod = await import('@/plugins/echo')
    echo = mod.default
    return echo
  } catch { return null }
}

/**
 * Subscribe to the support-tickets list channel.
 * Calls onNewTicket(ticket) when a new ticket is broadcast.
 */
export function useTicketListRealtime(onNewTicket, onStatusChanged = null) {
  let channel = null

  async function subscribe() {
    const e = await getEcho()
    if (!e) return
    channel = getChannel('support-tickets')
    // Private channel: only ticket managers are authorized (routes/channels.php)
    const ch = e.private(channel).listen('.SupportTicketCreated', (ev) => {
      const ticket = ev.ticket ?? ev
      if (ticket) onNewTicket(ticket)
    })

    if (onStatusChanged) {
      ch.listen('.SupportTicketStatusChanged', (ev) => {
        if (ev.ticket_id && ev.status) onStatusChanged(ev.ticket_id, ev.status)
      })
    }
  }

  function unsubscribe() {
    if (echo && channel) { echo.leave(channel); channel = null }
  }

  onUnmounted(unsubscribe)
  return { subscribe, unsubscribe }
}

/**
 * Subscribe to a single ticket's channel.
 * onReply(reply)         — new public reply
 * onRead(data)           — read status change
 * onInternalNote(reply)  — new internal note (admin-only, optional)
 */
export function useTicketDetailRealtime(ticketId, onReply, onRead, onInternalNote = null, onStatusChanged = null) {
  let channel = null
  let internalChannel = null

  async function subscribe() {
    const e = await getEcho()
    if (!e) return
    channel = getChannel(`support-ticket.${ticketId}`)
    const ch = e.private(channel)
      .listen('.SupportTicketReplied', (ev) => {
        const reply = ev.reply ?? ev
        if (reply) onReply(reply)
      })
      .listen('.SupportTicketRead', (ev) => {
        onRead({ admin_read_at: ev.admin_read_at, user_read_at: ev.user_read_at })
      })

    // Internal notes: a separate private channel only ticket managers may join (no tenant prefix)
    if (onInternalNote) {
      internalChannel = `support-ticket.${ticketId}.internal`
      e.private(internalChannel).listen('.SupportTicketInternalNote', (ev) => {
        const reply = ev.reply ?? ev
        if (reply) onInternalNote(reply)
      })
    }

    if (onStatusChanged) {
      ch.listen('.SupportTicketStatusChanged', (ev) => {
        if (ev.status) onStatusChanged(ev.status)
      })
    }
  }

  function unsubscribe() {
    if (echo && channel) { echo.leave(channel); channel = null }
    if (echo && internalChannel) { echo.leave(internalChannel); internalChannel = null }
  }

  onUnmounted(unsubscribe)
  return { subscribe, unsubscribe }
}
