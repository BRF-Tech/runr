import { PublishError } from './errors'

/** Environment variables RunR sets for functions in a region where their organization has a topic. */
export const TOPICS_URL_ENV = 'RUNR_TOPICS_URL'
export const TOPICS_TOKEN_ENV = 'RUNR_TOPICS_TOKEN'

const JSON_TYPE = 'application/json'

export interface PublishOptions {
  /** CloudEvents type of the event (`ce-type`), e.g. "order.created". */
  type?: string
  /** CloudEvents subject (`ce-subject`). */
  subject?: string
  /** Event id (`ce-id`); the topic generates one when it is left out. */
  id?: string
  /**
   * Content type of the data. Without it, data is sent as JSON. With it, a string or bytes
   * (Uint8Array, Buffer, ArrayBuffer) are sent as is and anything else as JSON.
   */
  contentType?: string
}

export interface PublishResult {
  /** The event id. */
  id: string
  /** The entry of the event in the topic's stream. */
  stream_id: string
}

export type TopicsFetch = (
  url: string,
  init: { method: string; headers: Record<string, string>; body: string | Uint8Array },
) => Promise<{ ok: boolean; status: number; json(): Promise<any>; text(): Promise<string> }>

export interface TopicsOptions {
  /** The topic publisher. Default: process.env.RUNR_TOPICS_URL */
  url?: string
  /** Default: process.env.RUNR_TOPICS_TOKEN */
  token?: string
  fetch?: TopicsFetch
}

/**
 * Publishes events to the organization's topics from a function (docs/triggers.md#topics):
 * every project with a topic trigger on the topic receives the event as a CloudEvent.
 */
export class Topics {
  private readonly url: string
  private readonly token: string
  private readonly doFetch: TopicsFetch

  constructor(options: TopicsOptions = {}) {
    this.url = (options.url ?? process.env[TOPICS_URL_ENV] ?? '').replace(/\/+$/, '')
    this.token = options.token ?? process.env[TOPICS_TOKEN_ENV] ?? ''
    const globalFetch = (globalThis as { fetch?: TopicsFetch }).fetch
    this.doFetch = options.fetch ?? (globalFetch as TopicsFetch)
    if (!this.url || !this.token) {
      throw new TypeError(
        `Topics needs url (${TOPICS_URL_ENV}) and token (${TOPICS_TOKEN_ENV}); RunR sets them for functions in a region where the organization has a topic`,
      )
    }
    if (!this.doFetch) throw new TypeError('Topics needs fetch (Node 18+) or options.fetch')
  }

  /** Publish one event; rejects with PublishError when the publisher answers non-2xx. */
  async publish(topic: string, data: unknown, options: PublishOptions = {}): Promise<PublishResult> {
    if (!topic) throw new TypeError('publish needs a topic name')
    const [body, contentType] = encode(data, options.contentType)
    const headers: Record<string, string> = {
      'Content-Type': contentType,
      Accept: JSON_TYPE,
      Authorization: `Bearer ${this.token}`,
    }
    if (options.type) headers['ce-type'] = options.type
    if (options.subject) headers['ce-subject'] = options.subject
    if (options.id) headers['ce-id'] = options.id

    const res = await this.doFetch(`${this.url}/v1/topics/${encodeURIComponent(topic)}/events`, { method: 'POST', headers, body })
    if (!res.ok) {
      const text = await res.text().catch(() => '')
      throw new PublishError(topic, res.status, text)
    }
    return res.json()
  }
}

/** Publish one event with the environment's topic publisher (RUNR_TOPICS_URL, RUNR_TOPICS_TOKEN). */
export function publish(topic: string, data: unknown, options: PublishOptions = {}): Promise<PublishResult> {
  return new Topics().publish(topic, data, options)
}

function encode(data: unknown, contentType?: string): [string | Uint8Array, string] {
  if (contentType) {
    if (typeof data === 'string' || data instanceof Uint8Array) return [data, contentType]
    if (data instanceof ArrayBuffer) return [new Uint8Array(data), contentType]
    return [JSON.stringify(data ?? null), contentType]
  }
  return [JSON.stringify(data ?? null), JSON_TYPE]
}
