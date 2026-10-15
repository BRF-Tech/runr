import type { IncomingMessage, ServerResponse } from 'http'
import { createServer } from 'http'
import { NonRetriableError, StepError, errorPayload } from './errors'
import { DEFAULT_TOLERANCE, HEADER_PROTOCOL, HEADER_SIGNATURE, VERSION, verify } from './protocol'
import { Invocation } from './step'
import type { ExecuteRequest, HandlerResult, Op, RunContext, WorkflowFunction, WorkflowOptions } from './types'

export const SDK_VERSION = '0.1.0'
export const SIGNING_KEY_ENV = 'RUNR_WORKFLOW_SIGNING_KEY'
export const FALLBACK_KEY_ENV = 'RUNR_WORKFLOW_SIGNING_KEY_FALLBACK'

const NAME_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/

export interface WorkflowsOptions {
  /** Default: process.env.RUNR_WORKFLOW_SIGNING_KEY (set by RunR on deploy). */
  signingKey?: string
  /** A second accepted key during a manual rotation. Default: RUNR_WORKFLOW_SIGNING_KEY_FALLBACK. */
  signingKeyFallback?: string
  /** Accepted clock difference of a signature, seconds. */
  tolerance?: number
}

interface Registered {
  options: WorkflowOptions
  fn: WorkflowFunction<any, any>
}

type Headers = Record<string, string | string[] | undefined> | undefined | null

function header(headers: Headers, name: string): string | undefined {
  if (!headers) return undefined
  const lower = name.toLowerCase()
  for (const [k, v] of Object.entries(headers)) {
    if (k.toLowerCase() === lower) return Array.isArray(v) ? v[0] : v
  }
  return undefined
}

/**
 * A set of workflows served by one function.
 *
 * ```ts
 * const wf = new Workflows()
 * wf.workflow({ name: 'hello', triggers: [{ event: 'user.signup' }] }, async ({ event, step }) =>
 *   step.run('greet', () => `hi ${event.data.name}`))
 * export const handler = wf.handler()
 * ```
 */
export class Workflows {
  private readonly registry = new Map<string, Registered>()
  private readonly tolerance: number

  constructor(private readonly options: WorkflowsOptions = {}) {
    this.tolerance = options.tolerance ?? DEFAULT_TOLERANCE
  }

  workflow<TData = Record<string, any>, TOutput = unknown>(
    options: WorkflowOptions | string,
    fn: WorkflowFunction<TData, TOutput>,
  ): this {
    const opts = typeof options === 'string' ? { name: options } : options
    if (!NAME_PATTERN.test(opts.name ?? '')) throw new TypeError(`invalid workflow name ${JSON.stringify(opts.name)}`)
    if (this.registry.has(opts.name)) throw new TypeError(`workflow ${opts.name} is registered twice`)
    this.registry.set(opts.name, { options: opts, fn })
    return this
  }

  get names(): string[] {
    return [...this.registry.keys()].sort()
  }

  /** Definitions as reported to the engine (introspection). */
  definitions(): Array<Record<string, unknown>> {
    return this.names.map((name) => {
      const o = this.registry.get(name)!.options
      const def: Record<string, unknown> = { name, triggers: o.triggers ?? [] }
      if (o.description !== undefined) def.description = o.description
      if (o.retries !== undefined) def.retries = o.retries
      if (o.timeout !== undefined) def.timeout = typeof o.timeout === 'number' ? `${o.timeout}s` : o.timeout
      if (o.concurrency !== undefined) def.concurrency = o.concurrency
      if (o.idempotency !== undefined) def.idempotency = o.idempotency
      if (o.cancelOn !== undefined) def.cancel_on = o.cancelOn
      return def
    })
  }

  private keys(): string[] {
    return [
      this.options.signingKey ?? process.env[SIGNING_KEY_ENV],
      this.options.signingKeyFallback ?? process.env[FALLBACK_KEY_ENV],
    ].filter((k): k is string => typeof k === 'string' && k !== '')
  }

  /** Process one engine request (decoded envelope) → HTTP status + JSON body. */
  async handle(envelope: unknown, headerSignature?: string): Promise<{ status: number; body: Record<string, unknown> }> {
    const keys = this.keys()
    if (keys.length === 0) return { status: 500, body: { error: `runr-workflows: ${SIGNING_KEY_ENV} is not set` } }

    const env = envelope as Record<string, unknown> | null
    if (!env || typeof env !== 'object' || env.runr_workflow !== VERSION || typeof env.request !== 'string') {
      return { status: 400, body: { error: 'runr-workflows: not a workflow protocol v1 request' } }
    }

    const raw = env.request
    const signature = headerSignature || (typeof env.signature === 'string' ? env.signature : '')
    if (!verify(raw, signature, keys, this.tolerance)) {
      return { status: 401, body: { error: 'runr-workflows: invalid signature' } }
    }

    let request: Record<string, unknown>
    try {
      request = JSON.parse(raw)
    } catch {
      return { status: 400, body: { error: 'runr-workflows: request is not JSON' } }
    }

    if (request.type === 'introspect') {
      return {
        status: 200,
        body: {
          runr_workflow: VERSION,
          sdk: { name: '@runr/workflows', language: 'typescript', version: SDK_VERSION },
          workflows: this.definitions(),
        },
      }
    }
    if (request.type === 'execute') {
      return { status: 200, body: { runr_workflow: VERSION, ops: [await this.execute(request as unknown as ExecuteRequest)] } }
    }
    return { status: 400, body: { error: `runr-workflows: unknown request type ${JSON.stringify(request.type)}` } }
  }

  /** Run the workflow of an execute request once; resolves with the single op. */
  async execute(request: ExecuteRequest): Promise<Op> {
    const registered = this.registry.get(request.workflow)
    if (!registered) {
      return {
        op: 'run_failed',
        retriable: false,
        error: { name: 'WorkflowNotFound', message: `workflow ${JSON.stringify(request.workflow)} is not served by this function` },
      }
    }

    const run = request.run ?? ({} as ExecuteRequest['run'])
    const event = request.event ?? { id: '', name: '', data: {}, ts: 0 }
    const ctx: RunContext = {
      event,
      runId: String(run.id ?? ''),
      workflow: request.workflow,
      attempt: Number(run.attempt ?? 0),
      replay: Boolean(run.replay),
      source: String(run.source ?? 'api'),
      parentRunId: run.parent_run_id ?? null,
    }
    const step = new Invocation(request.steps ?? {})

    const finished: Promise<Op> = (async () => {
      try {
        const output = await registered.fn({ event, step, ctx })
        try {
          return { op: 'run_completed', output: output === undefined ? null : JSON.parse(JSON.stringify(output)) } as Op
        } catch (err) {
          return { op: 'run_failed', retriable: false, error: errorPayload(err) } as Op
        }
      } catch (err) {
        if (step.isClaimed && !(err instanceof StepError)) {
          // An error after a step claimed the invocation (e.g. a parallel branch) — the claim wins
          return step.interrupted
        }
        const retriable = !(err instanceof StepError || err instanceof NonRetriableError)
        return { op: 'run_failed', retriable, error: errorPayload(err) } as Op
      }
    })()

    return Promise.race([step.interrupted, finished])
  }

  /** Process a raw HTTP body + headers. */
  async handleRaw(body: string | Buffer, headers?: Headers): Promise<{ status: number; body: Record<string, unknown> }> {
    let envelope: unknown
    try {
      envelope = JSON.parse(typeof body === 'string' ? body : body.toString('utf8'))
    } catch {
      return { status: 400, body: { error: 'runr-workflows: body is not JSON' } }
    }
    return this.handle(envelope, header(headers, HEADER_SIGNATURE))
  }

  /** `handler(event, context)` for the RunR nodejs runtime (event = parsed JSON body). */
  handler(): (event: unknown, context?: { headers?: Headers }) => Promise<HandlerResult> {
    return async (event, context) => {
      const { status, body } = await this.handle(event, header(context?.headers, HEADER_SIGNATURE))
      return { statusCode: status, headers: { 'Content-Type': 'application/json', [HEADER_PROTOCOL]: VERSION }, body }
    }
  }

  /** `(req, res)` listener for node:http servers and frameworks built on them. */
  httpHandler(): (req: IncomingMessage, res: ServerResponse) => void {
    return (req, res) => {
      if (req.method !== 'POST') {
        res.writeHead(req.method === 'GET' ? 200 : 405, { 'Content-Type': 'application/json' })
        res.end(JSON.stringify(req.method === 'GET' ? { ok: true, workflows: this.names } : { error: 'runr-workflows: POST only' }))
        return
      }
      const chunks: Buffer[] = []
      req.on('data', (c: Buffer) => chunks.push(c))
      req.on('end', () => {
        this.handleRaw(Buffer.concat(chunks), req.headers as Headers)
          .then(({ status, body }) => {
            res.writeHead(status, { 'Content-Type': 'application/json', [HEADER_PROTOCOL]: VERSION })
            res.end(JSON.stringify(body))
          })
          .catch((err) => {
            res.writeHead(500, { 'Content-Type': 'application/json' })
            res.end(JSON.stringify({ error: errorPayload(err) }))
          })
      })
    }
  }

  /** Web-standard handler: `(request: Request) => Promise<Response>` (Node 18+ and other Fetch API runtimes). */
  fetchHandler(): (request: { text(): Promise<string>; headers: { get(name: string): string | null } }) => Promise<unknown> {
    return async (request) => {
      const { status, body } = await this.handleRaw(await request.text(), {
        [HEADER_SIGNATURE]: request.headers.get(HEADER_SIGNATURE) ?? undefined,
      })
      const ResponseCtor = (globalThis as unknown as { Response: new (body: string, init: unknown) => unknown }).Response
      return new ResponseCtor(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json', [HEADER_PROTOCOL]: VERSION },
      })
    }
  }

  /** Standalone HTTP server (e.g. a container project). */
  serve(port = Number(process.env.PORT ?? 8080), host = '0.0.0.0'): ReturnType<typeof createServer> {
    const server = createServer(this.httpHandler())
    server.listen(port, host, () => {
      console.log(`@runr/workflows serving ${this.names.join(', ') || 'no workflows'} on :${port}`)
    })
    return server
  }
}
