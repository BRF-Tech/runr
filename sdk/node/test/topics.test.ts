import { afterEach, describe, expect, it } from 'vitest'
import { PublishError, Topics, publish } from '../src'
import type { TopicsFetch } from '../src'

interface Sent {
  url: string
  method: string
  headers: Record<string, string>
  body: string | Uint8Array
}

/** A fake topic publisher: records requests, answers 202 {id, stream_id} or the given status. */
function fakePublisher(status = 202, answer = '') {
  const sent: Sent[] = []
  const fetch: TopicsFetch = async (url, init) => {
    sent.push({ url, ...init })
    const ok = status >= 200 && status < 300
    const id = init.headers['ce-id'] ?? 'evt-generated'
    return {
      ok,
      status,
      json: async () => ({ id, stream_id: `1700000000000-${sent.length}` }),
      text: async () => answer,
    }
  }
  return { sent, fetch }
}

const ENV = { RUNR_TOPICS_URL: process.env.RUNR_TOPICS_URL, RUNR_TOPICS_TOKEN: process.env.RUNR_TOPICS_TOKEN }

afterEach(() => {
  for (const [k, v] of Object.entries(ENV)) {
    if (v === undefined) delete process.env[k]
    else process.env[k] = v
  }
})

describe('topics', () => {
  it('publishes JSON with the CloudEvents headers', async () => {
    const { sent, fetch } = fakePublisher()
    const topics = new Topics({ url: 'http://runr-topics.ns.svc.cluster.local:8080/', token: 'tok', fetch })
    const res = await topics.publish('orders', { order_id: 'o-1' }, { type: 'order.created', subject: 'o-1', id: 'evt-1' })

    expect(res).toEqual({ id: 'evt-1', stream_id: '1700000000000-1' })
    expect(sent).toHaveLength(1)
    expect(sent[0].url).toBe('http://runr-topics.ns.svc.cluster.local:8080/v1/topics/orders/events')
    expect(sent[0].method).toBe('POST')
    expect(sent[0].body).toBe('{"order_id":"o-1"}')
    expect(sent[0].headers).toMatchObject({
      Authorization: 'Bearer tok',
      'Content-Type': 'application/json',
      'ce-type': 'order.created',
      'ce-subject': 'o-1',
      'ce-id': 'evt-1',
    })
  })

  it('leaves out the headers that are not given and encodes the topic name', async () => {
    const { sent, fetch } = fakePublisher()
    await new Topics({ url: 'http://pub', token: 'tok', fetch }).publish('team.a/b', 'hello')
    expect(sent[0].url).toBe('http://pub/v1/topics/team.a%2Fb/events')
    expect(sent[0].body).toBe('"hello"') // JSON without a content type
    expect(Object.keys(sent[0].headers).filter((h) => h.startsWith('ce-'))).toEqual([])
  })

  it('sends strings and bytes as is with an explicit content type', async () => {
    const { sent, fetch } = fakePublisher()
    const topics = new Topics({ url: 'http://pub', token: 'tok', fetch })
    await topics.publish('logs', 'plain text', { contentType: 'text/plain' })
    await topics.publish('blobs', new Uint8Array([1, 2, 3]), { contentType: 'application/octet-stream' })
    await topics.publish('blobs', new Uint8Array([4]).buffer, { contentType: 'application/octet-stream' })
    await topics.publish('cloud', { a: 1 }, { contentType: 'application/cloudevents+json' })

    expect(sent[0].body).toBe('plain text')
    expect(sent[0].headers['Content-Type']).toBe('text/plain')
    expect(sent[1].body).toEqual(new Uint8Array([1, 2, 3]))
    expect(sent[2].body).toEqual(new Uint8Array([4]))
    expect(sent[3].body).toBe('{"a":1}')
    expect(sent[3].headers['Content-Type']).toBe('application/cloudevents+json')
  })

  it('rejects with PublishError and the status on a non-2xx answer', async () => {
    const { fetch } = fakePublisher(403, '{"message":"topic not allowed"}')
    const topics = new Topics({ url: 'http://pub', token: 'tok', fetch })
    const err = await topics.publish('secret-topic', {}).catch((e) => e)
    expect(err).toBeInstanceOf(PublishError)
    expect(err.status).toBe(403)
    expect(err.topic).toBe('secret-topic')
    expect(err.body).toContain('topic not allowed')
    expect(err.message).toContain('HTTP 403')
  })

  it('reads the publisher and token from the environment', async () => {
    const { sent, fetch } = fakePublisher()
    process.env.RUNR_TOPICS_URL = 'http://env-pub:8080'
    process.env.RUNR_TOPICS_TOKEN = 'env-token'
    const original = (globalThis as { fetch?: unknown }).fetch
    ;(globalThis as { fetch?: unknown }).fetch = fetch
    try {
      const res = await publish('orders', [1, 2])
      expect(res.id).toBe('evt-generated')
    } finally {
      ;(globalThis as { fetch?: unknown }).fetch = original
    }
    expect(sent[0].url).toBe('http://env-pub:8080/v1/topics/orders/events')
    expect(sent[0].headers.Authorization).toBe('Bearer env-token')
    expect(sent[0].body).toBe('[1,2]')
  })

  it('needs the publisher URL, the token and a topic', async () => {
    delete process.env.RUNR_TOPICS_URL
    delete process.env.RUNR_TOPICS_TOKEN
    expect(() => new Topics()).toThrow(/RUNR_TOPICS_URL/)
    expect(() => new Topics({ url: 'http://pub' })).toThrow(/RUNR_TOPICS_TOKEN/)
    const { sent, fetch } = fakePublisher()
    await expect(new Topics({ url: 'http://pub', token: 'tok', fetch }).publish('', {})).rejects.toThrow(/topic/)
    expect(sent).toHaveLength(0)
  })
})
