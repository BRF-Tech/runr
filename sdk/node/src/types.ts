import type { ErrorPayload } from './errors'

/** "500ms", "30s", "15m", "2h", "1d", "1w", "1h30m", ISO-8601 "PT1H", or a number of seconds. */
export type Duration = string | number

export type JsonValue = string | number | boolean | null | JsonValue[] | { [key: string]: JsonValue }

export interface WorkflowEvent<TData = Record<string, any>> {
  id: string
  name: string
  data: TData
  ts: number
}

export interface EventPayload<TData = Record<string, any>> {
  name: string
  data?: TData
  /** Deduplication id: an event with an id already received is not processed again. */
  id?: string
  /** Event time, epoch milliseconds. */
  ts?: number
}

export type Trigger =
  | { event: string; if?: string }
  | { cron: string; timezone?: string }
  | { queue: string }

export interface ConcurrencyLimit {
  limit: number
  /** Expression of the trigger event, e.g. "event.data.customer_id": one limit per value. */
  key?: string
}

export interface CancelOn {
  event: string
  match?: string
  if?: string
}

export interface WorkflowOptions {
  name: string
  description?: string
  triggers?: Trigger[]
  /** Retries per step (attempts = retries + 1). Engine default: 3. */
  retries?: number
  /** Run timeout. Engine default: 30d. */
  timeout?: Duration
  concurrency?: number | ConcurrencyLimit | ConcurrencyLimit[]
  /** Expression of the trigger event whose value deduplicates runs, e.g. "event.data.order_id". */
  idempotency?: string
  cancelOn?: CancelOn[]
}

export interface RunContext<TData = Record<string, any>> {
  event: WorkflowEvent<TData>
  runId: string
  workflow: string
  /** Attempts made so far of the step about to run (or of the function). */
  attempt: number
  replay: boolean
  source: string
  parentRunId: string | null
}

export interface WaitForEventOptions {
  event: string
  /** Field path that must equal the trigger event's, e.g. "data.order_id". */
  match?: string
  /** Expression over `event` (trigger) and `async` (incoming event). */
  if?: string
  timeout?: Duration
}

export interface InvokeOptions {
  workflow: string
  data?: Record<string, any>
  timeout?: Duration
}

export interface StepTools {
  /** Run `fn` once; later invocations get its memoized (JSON) result. Retried on errors. */
  run<T>(id: string, fn: () => T | Promise<T>): Promise<T>
  /** Pause the run, e.g. sleep("cool-off", "1h"). */
  sleep(id: string, duration: Duration): Promise<void>
  sleepUntil(id: string, until: Date | string): Promise<void>
  /** The matching event, or null when the timeout passed first. */
  waitForEvent<TData = Record<string, any>>(id: string, options: WaitForEventOptions): Promise<WorkflowEvent<TData> | null>
  /** Run another workflow of the project and return its output. */
  invoke<T = unknown>(id: string, options: InvokeOptions): Promise<T>
  sendEvent(id: string, events: EventPayload | EventPayload[]): Promise<{ ids: string[] }>
}

export interface HandlerArgs<TData> {
  event: WorkflowEvent<TData>
  step: StepTools
  ctx: RunContext<TData>
}

export type WorkflowFunction<TData = Record<string, any>, TOutput = unknown> = (
  args: HandlerArgs<TData>,
) => TOutput | Promise<TOutput>

export type Op =
  | { op: 'step_completed'; id: string; name: string; output: unknown; duration_ms: number }
  | { op: 'step_failed'; id: string; name: string; error: ErrorPayload; retriable?: boolean; retry_after?: string }
  | { op: 'sleep'; id: string; name: string; duration?: string; until?: string }
  | { op: 'wait_for_event'; id: string; name: string; event: string; match?: string; if?: string; timeout?: string }
  | { op: 'invoke'; id: string; name: string; workflow: string; data: unknown; timeout?: string }
  | { op: 'send_event'; id: string; name: string; events: EventPayload[] }
  | { op: 'run_completed'; output: unknown }
  | { op: 'run_failed'; error: ErrorPayload; retriable: boolean }

export interface MemoizedStep {
  status: 'completed' | 'failed'
  output?: unknown
  error?: ErrorPayload
}

export interface ExecuteRequest {
  type: 'execute'
  protocol?: string
  ts?: number
  workflow: string
  run: { id: string; attempt?: number; started_at?: string | null; source?: string; replay?: boolean; parent_run_id?: string | null }
  event: WorkflowEvent
  steps?: Record<string, MemoizedStep>
  step_order?: string[]
}

export interface IntrospectRequest {
  type: 'introspect'
  protocol?: string
  ts?: number
}

export interface HandlerResult {
  statusCode: number
  headers: Record<string, string>
  body: Record<string, unknown>
}
