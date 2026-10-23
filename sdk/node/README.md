# @runr/workflows

Durable workflows for [RunR](../../docs/workflows.md) in TypeScript / JavaScript: every
`step` is memoized, retried with backoff and survives sleeps of days.

```bash
npm install @runr/workflows
```

Node.js 18 or newer, no runtime dependencies. Deploy the function in a project with the trigger
**Workflow**; the SDK speaks the [workflow protocol v1](../../docs/workflows-protocol.md).

```ts
import { Workflows, NonRetriableError } from '@runr/workflows'

const wf = new Workflows() // signing key from RUNR_WORKFLOW_SIGNING_KEY (set by RunR on deploy)

wf.workflow<{ orderId: string; customerId: string }>({
  name: 'process-order',
  triggers: [{ event: 'order.created' }],
  retries: 3,
  timeout: '7d',
  concurrency: { limit: 5, key: 'event.data.customerId' },
  idempotency: 'event.data.orderId',
  cancelOn: [{ event: 'order.cancelled', match: 'data.orderId' }],
}, async ({ event, step }) => {
  const charge = await step.run('charge-card', () => chargeCard(event.data))
  await step.sleep('cool-off', '1h')
  const shipped = await step.waitForEvent('shipped', { event: 'order.shipped', match: 'data.orderId', timeout: '3d' })
  if (!shipped) {
    await step.run('refund', () => refund(charge))
    return { status: 'refunded' }
  }
  await step.sendEvent('notify', { name: 'order.completed', data: { orderId: event.data.orderId } })
  return { status: 'done' }
})

export const handler = wf.handler()   // RunR nodejs runtime: handler(event, context)
// http.createServer(wf.httpHandler())  // node:http, Express-compatible (req, res)
// export default { fetch: wf.fetchHandler() } // Web-standard Request → Response
// wf.serve(8080)                       // standalone server (container projects)
```

## Step API

| Call | Resolves with |
|------|---------------|
| `step.run(id, fn)` | `fn`'s result (JSON round trip); retried on errors |
| `step.sleep(id, '1h')` / `step.sleepUntil(id, date)` | `undefined` once the time passed |
| `step.waitForEvent(id, { event, match?, if?, timeout? })` | the event `{ id, name, data, ts }` or `null` |
| `step.invoke(id, { workflow, data?, timeout? })` | the invoked workflow's output |
| `step.sendEvent(id, event \| events)` | `{ ids }` |

Throw `NonRetriableError` to fail without retries, `new RetryAfterError(msg, '30s')` to pick
the delay. A step that failed for good rejects with `StepError` — catch it to compensate.
Steps passed to `Promise.all` run one per invocation, in call order.

`new EventsClient({ baseUrl, token }).send(name, data)` sends events to `POST /api/events`
(`sendMany(events)` for several); without options it reads `RUNR_API_URL`, `RUNR_API_TOKEN`
and, on multi-tenant installations, `RUNR_TENANT_ID` (sent as `X-Tenant-ID`).

## Publishing to topics

A [topic](../../docs/triggers.md#topics) delivers each event to every project with a topic
trigger on it. Any function (not only workflows) publishes with `publish`:

```ts
import { publish, PublishError } from '@runr/workflows'

const { id, stream_id } = await publish('orders', { orderId: 'o-1' }, { type: 'order.created', subject: 'o-1' })
await publish('logs', 'plain text', { contentType: 'text/plain' }) // a string or bytes, sent as is
```

`publish(topic, data, options?)` POSTs to `${RUNR_TOPICS_URL}/v1/topics/{topic}/events` with
`Authorization: Bearer ${RUNR_TOPICS_TOKEN}`; RunR sets both for functions in a region where
the organization has a topic. Data is sent as JSON; with `contentType` a string, `Uint8Array`,
`Buffer` or `ArrayBuffer` goes as is. `type`, `subject` and `id` become the CloudEvents headers
`ce-type`, `ce-subject` and `ce-id`. It resolves with `{ id, stream_id }` and rejects with
`PublishError` (`status`, `body`, `topic`) on an answer that is not 2xx: 401 a bad token, 403 a
topic the token does not allow, 413 an event that is too large (256 KiB by default).
`new Topics({ url, token, fetch })` takes the settings explicitly.

Development needs Node.js 20+ and npm 11 (`devEngines`): the SDK keeps no lockfile, and npm 10
fails to resolve Vitest 4. Scripts: `npm run build`, `npm run typecheck`, `npm test`.
