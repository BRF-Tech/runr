/**
 * A minimal engine for SDK tests: drives a Workflows handler through the real protocol
 * (signed envelopes, memoized steps, one op per invocation) until the run ends.
 */
import { envelope } from '../src/protocol'
import type { HandlerResult, MemoizedStep } from '../src/types'

export const KEY = 'rwk_test_key'

type Handler = (event: unknown, context?: { headers?: Record<string, string> }) => Promise<HandlerResult>

export interface RunResult {
  status: 'completed' | 'failed'
  output?: unknown
  error?: { name: string; message: string }
  steps: Record<string, MemoizedStep>
}

export class FakeEngine {
  invocations = 0
  ops: any[] = []
  sentEvents: any[] = []
  events: Record<string, unknown> = {}
  children: Record<string, unknown> = {}

  constructor(private readonly handler: Handler, private readonly maxAttempts = 4, private readonly key = KEY) {}

  async call(request: unknown): Promise<any> {
    const env = envelope(request, [this.key])
    const res = await this.handler(JSON.parse(JSON.stringify(env)), { headers: { 'x-runr-signature': env.signature! } })
    if (res.statusCode !== 200) throw new Error(`handler answered ${res.statusCode}: ${JSON.stringify(res.body)}`)
    return JSON.parse(JSON.stringify(res.body))
  }

  async run(workflow: string, data: Record<string, unknown> = {}, maxInvocations = 50): Promise<RunResult> {
    const steps: Record<string, MemoizedStep> = {}
    const attempts: Record<string, number> = {}
    let runAttempt = 0
    const event = { id: 'ev1', name: 'test/start', data, ts: 1700000000000 }

    for (let i = 0; i < maxInvocations; i++) {
      this.invocations++
      const retrying = Object.keys(attempts).filter((k) => !(k in steps))
      const attempt = retrying.length ? attempts[retrying[0]] : runAttempt
      const body = await this.call({
        type: 'execute', protocol: 'v1', ts: 0, workflow,
        run: { id: 'run-1', attempt, started_at: null, source: 'api', replay: false, parent_run_id: null },
        event, steps, step_order: Object.keys(steps),
      })
      if (body.runr_workflow !== 'v1' || body.ops.length !== 1) throw new Error('bad response ' + JSON.stringify(body))
      const op = body.ops[0]
      this.ops.push(op)

      switch (op.op) {
        case 'run_completed':
          return { status: 'completed', output: op.output, steps }
        case 'run_failed':
          runAttempt++
          if (op.retriable !== false && runAttempt < this.maxAttempts) continue
          return { status: 'failed', error: op.error, steps }
        case 'step_completed':
          steps[op.id] = { status: 'completed', output: op.output }
          runAttempt = 0
          break
        case 'step_failed':
          attempts[op.id] = (attempts[op.id] ?? 0) + 1
          if (op.retriable !== false && attempts[op.id] < this.maxAttempts) continue
          steps[op.id] = { status: 'failed', error: op.error }
          break
        case 'sleep':
          steps[op.id] = { status: 'completed', output: null }
          break
        case 'wait_for_event':
          steps[op.id] = { status: 'completed', output: this.events[op.event] ?? null }
          break
        case 'invoke': {
          const child = this.children[op.workflow]
          steps[op.id] = child instanceof Error
            ? { status: 'failed', error: { name: 'InvokeFailed', message: child.message } }
            : { status: 'completed', output: child ?? null }
          break
        }
        case 'send_event':
          this.sentEvents.push(...op.events)
          steps[op.id] = { status: 'completed', output: { ids: op.events.map((_: unknown, n: number) => `e${n}`) } }
          break
        default:
          throw new Error('unknown op ' + op.op)
      }
    }
    throw new Error('run did not finish')
  }
}
