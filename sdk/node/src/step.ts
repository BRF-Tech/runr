/**
 * Deterministic replay: the `step` tools of one invocation.
 *
 * Every invocation re-runs the workflow from the top. A step whose result the engine sent
 * back (memoized) resolves with that result; the first step without a result runs
 * (`run`) or is reported (`sleep`, `waitForEvent`, `invoke`, `sendEvent`) and the invocation
 * ends with that op — every later step call stays pending and is discarded. Steps called in
 * parallel (Promise.all) therefore run one per invocation, in call order. A repeated step id
 * gets a counter suffix ("add", "add:1", …).
 */
import { AsyncLocalStorage } from 'async_hooks'
import { NonRetriableError, RetryAfterError, StepError, errorPayload } from './errors'
import type { Duration, EventPayload, InvokeOptions, MemoizedStep, Op, StepTools, WaitForEventOptions, WorkflowEvent } from './types'

const insideStep = new AsyncLocalStorage<string>()

const never = <T>(): Promise<T> => new Promise<T>(() => undefined)

export function durationString(value: Duration): string {
  if (typeof value === 'number') {
    if (!Number.isFinite(value) || value < 0) throw new TypeError('duration must be a non-negative number of seconds')
    return `${Math.round(value * 1000)}ms`
  }
  if (typeof value === 'string' && value.trim() !== '') return value.trim()
  throw new TypeError('duration must be a string like "30s" or a number of seconds')
}

export function instantString(value: Date | string | number): string {
  if (value instanceof Date) {
    if (Number.isNaN(value.getTime())) throw new TypeError('invalid date')
    return value.toISOString()
  }
  if (typeof value === 'number') return new Date(value).toISOString()
  if (typeof value === 'string' && value.trim() !== '') return value.trim()
  throw new TypeError('until must be a Date or an ISO-8601 string')
}

function assertJson(value: unknown, what: string): void {
  if (value === undefined) return
  try {
    JSON.stringify(value)
  } catch (err) {
    throw new NonRetriableError(`${what} is not JSON serializable: ${(err as Error).message}`)
  }
}

/** JSON round trip: what later invocations will see for this value. */
function jsonValue(value: unknown): unknown {
  return value === undefined ? null : JSON.parse(JSON.stringify(value))
}

export class Invocation implements StepTools {
  private readonly seen = new Map<string, number>()
  private claimed = false
  private resolveOp!: (op: Op) => void
  /** Resolves with the op that ends this invocation. */
  readonly interrupted: Promise<Op>

  constructor(private readonly steps: Record<string, MemoizedStep> = {}) {
    this.interrupted = new Promise<Op>((resolve) => {
      this.resolveOp = resolve
    })
  }

  get isClaimed(): boolean {
    return this.claimed
  }

  private key(id: string): string {
    if (typeof id !== 'string' || id === '') throw new TypeError('step id must be a non-empty string')
    if (Buffer.byteLength(id, 'utf8') > 200) throw new TypeError('step id must be at most 200 bytes')
    if (insideStep.getStore() !== undefined) throw new Error('steps cannot be nested inside step.run')
    const n = this.seen.get(id) ?? 0
    this.seen.set(id, n + 1)
    return n === 0 ? id : `${id}:${n}`
  }

  private memo(key: string): MemoizedStep | undefined {
    const m = this.steps[key]
    return m && typeof m === 'object' ? m : undefined
  }

  private unwrap<T>(key: string, memo: MemoizedStep): Promise<T> {
    if (memo.status === 'failed') {
      return Promise.reject(new StepError(key, memo.error?.name ?? 'Error', memo.error?.message ?? ''))
    }
    return Promise.resolve((memo.output ?? null) as T)
  }

  /** End the invocation with `op` (first claim wins) and never resolve. */
  private report<T>(op: Op): Promise<T> {
    if (!this.claimed) {
      this.claimed = true
      this.resolveOp(op)
    }
    return never<T>()
  }

  run<T>(id: string, fn: () => T | Promise<T>): Promise<T> {
    const key = this.key(id)
    const memo = this.memo(key)
    if (memo) return this.unwrap<T>(key, memo)
    if (this.claimed) return never<T>()

    this.claimed = true
    const started = Date.now()
    void insideStep.run(key, async () => {
      try {
        const output = await fn()
        assertJson(output, `step ${key} output`)
        this.resolveOp({ op: 'step_completed', id: key, name: id, output: jsonValue(output), duration_ms: Date.now() - started })
      } catch (err) {
        if (err instanceof NonRetriableError) {
          this.resolveOp({ op: 'step_failed', id: key, name: id, error: errorPayload(err), retriable: false })
        } else if (err instanceof RetryAfterError) {
          const after = err.retryAfter
          this.resolveOp({
            op: 'step_failed', id: key, name: id, error: errorPayload(err),
            retry_after: after instanceof Date ? instantString(after) : durationString(after),
          })
        } else {
          this.resolveOp({ op: 'step_failed', id: key, name: id, error: errorPayload(err) })
        }
      }
    })

    return never<T>()
  }

  sleep(id: string, duration: Duration): Promise<void> {
    const key = this.key(id)
    if (this.memo(key)) return Promise.resolve()
    return this.report({ op: 'sleep', id: key, name: id, duration: durationString(duration) })
  }

  sleepUntil(id: string, until: Date | string): Promise<void> {
    const key = this.key(id)
    if (this.memo(key)) return Promise.resolve()
    return this.report({ op: 'sleep', id: key, name: id, until: instantString(until) })
  }

  waitForEvent<TData = Record<string, any>>(id: string, options: WaitForEventOptions): Promise<WorkflowEvent<TData> | null> {
    const key = this.key(id)
    const memo = this.memo(key)
    if (memo) return this.unwrap(key, memo)
    if (!options || typeof options.event !== 'string' || options.event === '') {
      throw new TypeError('waitForEvent needs { event }')
    }
    const op: Extract<Op, { op: 'wait_for_event' }> = { op: 'wait_for_event', id: key, name: id, event: options.event }
    if (options.match) op.match = options.match
    if (options.if) op.if = options.if
    if (options.timeout !== undefined) op.timeout = durationString(options.timeout)
    return this.report(op)
  }

  invoke<T = unknown>(id: string, options: InvokeOptions): Promise<T> {
    const key = this.key(id)
    const memo = this.memo(key)
    if (memo) return this.unwrap<T>(key, memo)
    if (!options || typeof options.workflow !== 'string' || options.workflow === '') {
      throw new TypeError('invoke needs { workflow }')
    }
    const data = options.data ?? {}
    assertJson(data, 'invoke data')
    const op: Extract<Op, { op: 'invoke' }> = { op: 'invoke', id: key, name: id, workflow: options.workflow, data: jsonValue(data) }
    if (options.timeout !== undefined) op.timeout = durationString(options.timeout)
    return this.report(op)
  }

  sendEvent(id: string, events: EventPayload | EventPayload[]): Promise<{ ids: string[] }> {
    const key = this.key(id)
    const memo = this.memo(key)
    if (memo) return this.unwrap(key, memo)
    const batch = Array.isArray(events) ? events : [events]
    assertJson(batch, 'events')
    return this.report({ op: 'send_event', id: key, name: id, events: jsonValue(batch) as EventPayload[] })
  }
}
