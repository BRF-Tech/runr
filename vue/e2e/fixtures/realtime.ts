import type { Page, WebSocketRoute } from '@playwright/test'

// Fake Reverb/Pusher server for mock mode: answers the Pusher handshake and
// channel subscriptions of laravel-echo, and lets a spec push the broadcast
// events the API would send (PlatformEvent topics, notifications).
//
//   await realtime.platform('region.created', { region_id: 'r-1' })
//   await realtime.notification({ type: 'build.failed', data: { project_name: 'x' } })

interface Socket {
  ws: WebSocketRoute
  channels: Set<string>
}

export class FakeRealtime {
  private sockets: Socket[] = []

  async install(page: Page): Promise<void> {
    // Echo connects to ws(s)://<host>[:port][/ws]/app/<key>?protocol=7…
    await page.routeWebSocket(/\/app\/[^/?]+(\?|$)/, ws => {
      const socket: Socket = { ws, channels: new Set() }
      this.sockets.push(socket)
      ws.send(JSON.stringify({
        event: 'pusher:connection_established',
        data: JSON.stringify({ socket_id: '1000.2000', activity_timeout: 120 }),
      }))
      ws.onMessage(raw => {
        let msg: any
        try { msg = JSON.parse(String(raw)) } catch { return }
        const data = typeof msg.data === 'string' ? safeParse(msg.data) : msg.data
        if (msg.event === 'pusher:subscribe' && data?.channel) {
          socket.channels.add(data.channel)
          ws.send(JSON.stringify({ event: 'pusher_internal:subscription_succeeded', channel: data.channel, data: '{}' }))
        } else if (msg.event === 'pusher:unsubscribe' && data?.channel) {
          socket.channels.delete(data.channel)
        } else if (msg.event === 'pusher:ping') {
          ws.send(JSON.stringify({ event: 'pusher:pong', data: '{}' }))
        }
      })
      ws.onClose(() => { this.sockets = this.sockets.filter(s => s !== socket) })
    })
  }

  /** Channels currently subscribed by the page (tenant-prefixed names included). */
  channels(): string[] {
    return [...new Set(this.sockets.flatMap(s => [...s.channels]))]
  }

  /**
   * Broadcast `event` on every subscribed channel whose name ends with `channel` — the channels
   * are private (pusher name "private-<name>"), the prefix is ignored.
   */
  async emit(channel: string, event: string, payload: unknown, timeoutMs = 10_000): Promise<void> {
    const deadline = Date.now() + timeoutMs
    const bare = (c: string) => c.replace(/^(private|presence)-/, '')
    for (;;) {
      const targets = this.sockets.flatMap(s =>
        [...s.channels].filter(c => bare(c) === channel || bare(c).endsWith(`.${channel}`)).map(c => ({ s, c })))
      if (targets.length) {
        for (const { s, c } of targets) {
          s.ws.send(JSON.stringify({ event, channel: c, data: JSON.stringify(payload) }))
        }
        return
      }
      if (Date.now() > deadline) throw new Error(`No page subscription on channel "${channel}" (have: ${this.channels().join(', ') || 'none'})`)
      await new Promise(r => setTimeout(r, 100))
    }
  }

  /** A PlatformEvent (the "platform" channel, topic + payload), e.g. job completions. */
  platform(topic: string, payload: Record<string, unknown> = {}): Promise<void> {
    return this.emit('platform', 'platform.event', { topic, payload })
  }

  /** A user notification (the "notifications" channel). */
  notification(notification: Record<string, unknown>): Promise<void> {
    // Echo's default namespace turns .listen('NotificationCreated') into App\Events\NotificationCreated
    return this.emit('notifications', 'App\\Events\\NotificationCreated', {
      notification: { id: `n-${Date.now()}`, read_at: null, created_at: new Date().toISOString(), level: 'info', ...notification },
    })
  }
}

function safeParse(s: string): any {
  try { return JSON.parse(s) } catch { return null }
}
