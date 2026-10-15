import type { EventPayload } from './types'

export interface EventsClientOptions {
  /** Default: process.env.RUNR_API_URL */
  baseUrl?: string
  /** API token. Default: process.env.RUNR_API_TOKEN */
  token?: string
  /** Default: process.env.RUNR_TENANT_ID */
  tenantId?: string
  fetch?: (url: string, init: { method: string; headers: Record<string, string>; body: string }) => Promise<{
    ok: boolean
    status: number
    json(): Promise<any>
    text(): Promise<string>
  }>
}

export interface SendResult {
  ids: string[]
  events: Array<{ id: string; name: string; duplicate: boolean; runs: string[]; resumed: number; cancelled: number }>
}

/** Send events to RunR (`POST /api/events`) from anywhere. */
export class EventsClient {
  private readonly baseUrl: string
  private readonly token: string
  private readonly tenantId?: string
  private readonly doFetch: NonNullable<EventsClientOptions['fetch']>

  constructor(options: EventsClientOptions = {}) {
    this.baseUrl = (options.baseUrl ?? process.env.RUNR_API_URL ?? '').replace(/\/+$/, '')
    this.token = options.token ?? process.env.RUNR_API_TOKEN ?? ''
    this.tenantId = options.tenantId ?? process.env.RUNR_TENANT_ID
    const globalFetch = (globalThis as { fetch?: EventsClientOptions['fetch'] }).fetch
    this.doFetch = options.fetch ?? (globalFetch as NonNullable<EventsClientOptions['fetch']>)
    if (!this.baseUrl || !this.token) throw new TypeError('EventsClient needs baseUrl (RUNR_API_URL) and token (RUNR_API_TOKEN)')
    if (!this.doFetch) throw new TypeError('EventsClient needs fetch (Node 18+) or options.fetch')
  }

  send(name: string, data: Record<string, unknown> = {}, extra: { id?: string; ts?: number } = {}): Promise<SendResult> {
    return this.sendMany([{ name, data, ...extra }])
  }

  async sendMany(events: EventPayload[]): Promise<SendResult> {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: `Bearer ${this.token}`,
    }
    if (this.tenantId) headers['X-Tenant-ID'] = this.tenantId

    const res = await this.doFetch(`${this.baseUrl}/api/events`, { method: 'POST', headers, body: JSON.stringify({ events }) })
    if (!res.ok) throw new Error(`RunR events API answered HTTP ${res.status}: ${await res.text()}`)
    return res.json()
  }
}
