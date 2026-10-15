import { afterEach, describe, expect, it } from 'vitest'
import { request as httpRequest } from 'http'
import type { AddressInfo } from 'net'
import { EventsClient, Workflows, envelope, sign } from '../src'
import { KEY } from './fakeEngine'

function make() {
  return new Workflows({ signingKey: KEY })
    .workflow({
      name: 'orders',
      description: 'Order flow',
      triggers: [{ event: 'order.created' }, { cron: '0 9 * * *', timezone: 'Europe/Istanbul' }, { queue: 'orders' }],
      retries: 2,
      timeout: 3600,
      concurrency: { limit: 3, key: 'event.data.customer' },
      idempotency: 'event.data.order_id',
      cancelOn: [{ event: 'order.cancelled', match: 'data.order_id' }],
    }, async ({ step, ctx }) => step.run('a', () => ctx.runId))
    .workflow('plain', async ({ ctx }) => ({ workflow: ctx.workflow, source: ctx.source, replay: ctx.replay }))
}

const executeRequest = (workflow = 'plain') => ({
  type: 'execute', protocol: 'v1', ts: 0, workflow,
  run: { id: 'r-1', attempt: 0, source: 'event', replay: true },
  event: { id: 'e', name: 'x', data: {}, ts: 1 }, steps: {}, step_order: [],
})

describe('entry points', () => {
  const envBackup = { ...process.env }
  afterEach(() => { process.env = { ...envBackup } })

  it('answers introspection with the definitions', async () => {
    const env = envelope({ type: 'introspect', protocol: 'v1', ts: 0 }, [KEY])
    const res = await make().handler()(env, { headers: { 'x-runr-signature': env.signature! } })

    expect(res.statusCode).toBe(200)
    expect(res.headers['X-RunR-Workflow-Protocol']).toBe('v1')
    const body: any = res.body
    expect(body.sdk).toMatchObject({ name: '@runr/workflows', language: 'typescript' })
    expect(body.workflows.map((w: any) => w.name)).toEqual(['orders', 'plain'])
    expect(body.workflows[0]).toEqual({
      name: 'orders',
      description: 'Order flow',
      triggers: [{ event: 'order.created' }, { cron: '0 9 * * *', timezone: 'Europe/Istanbul' }, { queue: 'orders' }],
      retries: 2,
      timeout: '3600s',
      concurrency: { limit: 3, key: 'event.data.customer' },
      idempotency: 'event.data.order_id',
      cancel_on: [{ event: 'order.cancelled', match: 'data.order_id' }],
    })
    expect(body.workflows[1]).toEqual({ name: 'plain', triggers: [] })
  })

  it('executes with the run context', async () => {
    const env = envelope(executeRequest(), [KEY])
    const res = await make().handler()(env, { headers: { 'X-RunR-Signature': env.signature! } })
    expect((res.body as any).ops).toEqual([{ op: 'run_completed', output: { workflow: 'plain', source: 'event', replay: true } }])
  })

  it('reads the signature from the envelope when headers are missing', async () => {
    const env = envelope(executeRequest(), [KEY])
    expect((await make().handler()(env)).statusCode).toBe(200)
  })

  it('rejects unsigned, wrongly signed, stale and tampered requests', async () => {
    const handler = make().handler()
    const raw = JSON.stringify(executeRequest())
    expect((await handler({ runr_workflow: 'v1', request: raw }, {})).statusCode).toBe(401)
    expect((await handler(envelope(executeRequest(), ['rwk_other']), {})).statusCode).toBe(401)
    const stale = { runr_workflow: 'v1', request: raw, signature: sign(raw, [KEY], Math.floor(Date.now() / 1000) - 600) }
    expect((await handler(stale, {})).statusCode).toBe(401)
    const tampered = envelope(executeRequest(), [KEY])
    tampered.request = tampered.request.replace('"plain"', '"orders"')
    expect((await handler(tampered, {})).statusCode).toBe(401)
  })

  it('answers 400 to non-protocol bodies and 500 without a key', async () => {
    const handler = make().handler()
    expect((await handler({ hello: 'world' }, {})).statusCode).toBe(400)
    expect((await handler(envelope({ type: 'teleport' }, [KEY]), {})).statusCode).toBe(400)

    delete process.env.RUNR_WORKFLOW_SIGNING_KEY
    delete process.env.RUNR_WORKFLOW_SIGNING_KEY_FALLBACK
    const keyless = new Workflows().workflow('plain', async () => 1).handler()
    const res = await keyless(envelope(executeRequest(), [KEY]), {})
    expect(res.statusCode).toBe(500)
    expect((res.body as any).error).toContain('RUNR_WORKFLOW_SIGNING_KEY')
  })

  it('takes keys from the environment, with a fallback', async () => {
    process.env.RUNR_WORKFLOW_SIGNING_KEY = 'rwk_new'
    process.env.RUNR_WORKFLOW_SIGNING_KEY_FALLBACK = KEY
    const handler = new Workflows().workflow('plain', async () => 'ok').handler()
    expect((await handler(envelope(executeRequest(), [KEY]), {})).statusCode).toBe(200)
  })

  it('serves node:http requests', async () => {
    const server = make().serve(0, '127.0.0.1')
    await new Promise((r) => server.once('listening', r))
    const { port } = server.address() as AddressInfo
    const env = envelope(executeRequest(), [KEY])

    const reply = await new Promise<{ status: number; body: any; protocol: string }>((resolve, reject) => {
      const req = httpRequest({ host: '127.0.0.1', port, method: 'POST', path: '/api/runr', headers: { 'Content-Type': 'application/json', 'X-RunR-Signature': env.signature! } }, (res) => {
        let data = ''
        res.on('data', (c) => (data += c))
        res.on('end', () => resolve({ status: res.statusCode!, body: JSON.parse(data), protocol: String(res.headers['x-runr-workflow-protocol']) }))
      })
      req.on('error', reject)
      req.end(JSON.stringify(env))
    })
    server.close()

    expect(reply.status).toBe(200)
    expect(reply.protocol).toBe('v1')
    expect(reply.body.ops[0].op).toBe('run_completed')
  })

  it('offers a fetch-style handler', async () => {
    const env = envelope(executeRequest(), [KEY])
    const res: any = await make().fetchHandler()({
      text: async () => JSON.stringify(env),
      headers: { get: (name: string) => (name.toLowerCase() === 'x-runr-signature' ? env.signature! : null) },
    })
    expect(res.status).toBe(200)
    expect((await res.json()).ops[0].op).toBe('run_completed')
  })
})

describe('EventsClient', () => {
  it('posts events with the token and tenant', async () => {
    const calls: any[] = []
    const client = new EventsClient({
      baseUrl: 'https://runr.example.com/',
      token: 'tok',
      tenantId: 't-1',
      fetch: async (url, init) => {
        calls.push({ url, init })
        return { ok: true, status: 200, json: async () => ({ ids: ['01J'], events: [] }), text: async () => '' }
      },
    })

    expect(await client.send('order.created', { order_id: 1 }, { id: 'o-1' })).toEqual({ ids: ['01J'], events: [] })
    expect(calls[0].url).toBe('https://runr.example.com/api/events')
    expect(calls[0].init.headers).toMatchObject({ Authorization: 'Bearer tok', 'X-Tenant-ID': 't-1' })
    expect(JSON.parse(calls[0].init.body)).toEqual({ events: [{ name: 'order.created', data: { order_id: 1 }, id: 'o-1' }] })
  })

  it('throws on errors and missing configuration', async () => {
    const client = new EventsClient({
      baseUrl: 'https://runr.example.com', token: 't',
      fetch: async () => ({ ok: false, status: 422, json: async () => ({}), text: async () => 'bad' }),
    })
    await expect(client.send('x')).rejects.toThrow('HTTP 422')
    expect(() => new EventsClient({ baseUrl: '', token: '' })).toThrow()
  })
})
