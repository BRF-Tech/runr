import './polyfill.js'

import { resolveConfig, type ClientOptions } from './config.js'
import type { SandboxContext } from './context.js'
import {
  createBody,
  execBody,
  execHttpTimeout,
  ExecStreamConverter,
  listQuery,
  normalizeNetwork,
  runBody,
  toBase64,
  toExecResult,
  ttyFields,
  toRunResult,
} from './convert.js'
import { ConflictError, NotFoundError, RunrSandboxError, SandboxStartTimeoutError } from './errors.js'
import { ExecSession, terminalSize, type ExecWsTicket } from './exec-ws.js'
import { Files } from './files.js'
import { HttpClient, unwrap, type Query } from './http.js'
import { Processes } from './processes.js'
import { readSse } from './sse.js'
import type {
  CreateOptions,
  ExecEvent,
  ExecOptions,
  ExecResult,
  ExecWsOptions,
  ListFilters,
  NetworkSpec,
  RunOptions,
  RunResult,
  RunWsOptions,
  SandboxInfo,
  SandboxLogs,
  TerminalSession,
} from './types.js'

const LIVE = new Set(['running', 'paused'])
const ENDED = new Set(['terminating', 'terminated', 'failed'])

const sleep = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms))

/**
 * An isolated sandbox on a RunR region.
 *
 * ```ts
 * await using sandbox = await Sandbox.create({ runtime: 'python' })
 * const result = await sandbox.runCode('print(6 * 7)')
 * ```
 */
export class Sandbox implements AsyncDisposable {
  readonly files: Files
  readonly processes: Processes
  private data: SandboxInfo
  private closed = false
  private readonly context: SandboxContext

  private constructor(info: SandboxInfo, private readonly http: HttpClient) {
    this.data = info
    this.context = {
      http,
      path: (suffix = '') => this.path(suffix),
      stream: (method, path, body, query, signal) => this.stream(method, path, body, query, signal),
    }
    this.files = new Files(this.context)
    this.processes = new Processes(this.context)
  }

  // ── Construction ──────────────────────────────────────────────────────────

  /** Create a sandbox and (unless `wait: false`) resolve once it runs. */
  static async create(options: CreateOptions = {}): Promise<Sandbox> {
    const http = new HttpClient(resolveConfig(options))
    const startTimeoutMs = options.startTimeoutMs ?? 180_000
    const payload = await http.json('POST', '/sandboxes', {
      json: createBody(options),
      timeoutMs: Math.max(http.config.timeoutMs, startTimeoutMs + 30_000),
    })
    const sandbox = new Sandbox(unwrap<SandboxInfo>(payload), http)
    if (options.wait ?? true) {
      try {
        await sandbox.waitRunning(startTimeoutMs)
      } catch (error) {
        // A sandbox that did not come up is removed (best effort) instead of left running
        await sandbox.close().catch(() => undefined)
        throw error
      }
    }
    return sandbox
  }

  /** Attach to an existing sandbox. */
  static async connect(id: string, options: ClientOptions = {}): Promise<Sandbox> {
    const http = new HttpClient(resolveConfig(options))
    const payload = await http.json('GET', `/sandboxes/${encodeURIComponent(id)}`)
    return new Sandbox(unwrap<SandboxInfo>(payload), http)
  }

  /** Sandboxes of the organization (default: the active ones). */
  static async list(filters: ListFilters = {}, options: ClientOptions = {}): Promise<Sandbox[]> {
    const http = new HttpClient(resolveConfig(options))
    const payload = await http.json('GET', '/sandboxes', { query: listQuery(filters) })
    return (unwrap<SandboxInfo[]>(payload) ?? []).map((info) => new Sandbox(info, http))
  }

  // ── State ─────────────────────────────────────────────────────────────────

  get id(): string {
    return String(this.data.id)
  }

  /** The sandbox as the API last returned it. */
  get info(): SandboxInfo {
    return { ...this.data }
  }

  get status(): string {
    return String(this.data.status)
  }

  /** Reload the sandbox from the API. */
  async refresh(): Promise<this> {
    this.update(await this.http.json('GET', this.path()))
    return this
  }

  private update(payload: unknown): void {
    const data = unwrap<SandboxInfo | null>(payload)
    if (data && typeof data === 'object' && data.id) this.data = data
  }

  private path(suffix = ''): string {
    return `/sandboxes/${encodeURIComponent(this.id)}${suffix}`
  }

  private async waitRunning(timeoutMs: number): Promise<void> {
    const deadline = Date.now() + timeoutMs
    let delay = 250
    for (;;) {
      if (LIVE.has(this.status)) return
      if (ENDED.has(this.status)) {
        throw new RunrSandboxError(
          null,
          this.data.error ? String(this.data.error) : `Sandbox ${this.id} did not start (status ${this.status}).`,
          'start_failed',
          this.info,
        )
      }
      if (Date.now() >= deadline) {
        throw new SandboxStartTimeoutError(
          null,
          `Sandbox ${this.id} did not start within ${timeoutMs} ms (status ${this.status}); it is removed.`,
          'start_timeout',
          this.info,
        )
      }
      await sleep(Math.min(delay, Math.max(0, deadline - Date.now())))
      delay = Math.min(delay * 1.5, 2000)
      await this.refresh()
    }
  }

  // ── Commands ──────────────────────────────────────────────────────────────

  /** Run a command and wait for it: a shell string (`/bin/sh -c`) or an argv array. */
  async exec(cmd: string | string[], options: ExecOptions = {}): Promise<ExecResult> {
    const payload = await this.http.json('POST', this.path('/exec'), {
      json: execBody(cmd, options),
      timeoutMs: execHttpTimeout(this.http.config.timeoutMs, options.timeout),
      signal: options.signal,
    })
    return toExecResult(unwrap(payload))
  }

  /** Run a command and stream its output: `stdout` / `stderr` events, then one `exit` event. */
  execStream(cmd: string | string[], options: ExecOptions = {}): AsyncIterable<ExecEvent> {
    return this.stream('POST', this.path('/exec'), execBody(cmd, options, true), undefined, options.signal)
  }

  /** Run code with the sandbox's interpreter: output, exit status, changed files and images under `/output`. */
  async runCode(code: string, options: RunOptions = {}): Promise<RunResult> {
    const payload = await this.http.json('POST', this.path('/run'), {
      json: runBody(code, options),
      timeoutMs: execHttpTimeout(this.http.config.timeoutMs, options.timeout),
      signal: options.signal,
    })
    return toRunResult(unwrap(payload))
  }

  /** Like `runCode`, streamed: output events, `exit`, then a `result` event. */
  runCodeStream(code: string, options: RunOptions = {}): AsyncIterable<ExecEvent> {
    return this.stream('POST', this.path('/run'), runBody(code, options, true), undefined, options.signal)
  }

  /**
   * Run a command over the exec WebSocket: send stdin while it runs (`sendStdin`, `closeStdin`),
   * optionally on a terminal (`tty`, `resize`), and read its output as it arrives. Resolves once
   * the command started; `wait()` or iterating the session reads the rest.
   *
   * ```ts
   * await using session = await sandbox.execWs(['python3', '-i'], { tty: true, cols: 120, rows: 40 })
   * session.sendStdin('print(6 * 7)\n')
   * session.closeStdin()
   * const result = await session.wait()
   * ```
   */
  async execWs(cmd: string | string[], options: ExecWsOptions = {}): Promise<ExecSession> {
    return this.openExecWs({ ...execBody(cmd, options), ...ttyFields(options, terminalSize) }, options)
  }

  /** Like `execWs`, for code run with the language's interpreter (up to 120 KB of code). */
  async runCodeWs(code: string, options: RunWsOptions = {}): Promise<ExecSession> {
    const body = runBody(code, options)
    if (options.stdin instanceof Uint8Array) body.stdin_base64 = toBase64(options.stdin)
    else if (options.stdin !== undefined) body.stdin = options.stdin
    return this.openExecWs({ ...body, ...ttyFields(options, terminalSize) }, options)
  }

  private async openExecWs(body: Record<string, unknown>, options: ExecWsOptions): Promise<ExecSession> {
    const payload = await this.http.json('POST', this.path('/exec/ws'), { json: body, signal: options.signal })
    return ExecSession.open(unwrap<ExecWsTicket>(payload) ?? {}, {
      apiUrl: this.http.config.apiUrl,
      transport: options.transport,
      signal: options.signal,
    })
  }

  private async *stream(method: string, path: string, body?: unknown, query?: Query, signal?: AbortSignal): AsyncGenerator<ExecEvent> {
    const { response, done } = await this.http.open(method, path, {
      json: body,
      query,
      accept: 'text/event-stream',
      timeoutMs: null,
      signal,
    })
    try {
      if (!response.body) return
      const converter = new ExecStreamConverter()
      for await (const event of readSse(response.body)) {
        const converted = converter.convert(event)
        if (converted) yield converted
      }
    } finally {
      done()
    }
  }

  // ── Lifecycle ─────────────────────────────────────────────────────────────

  /** Let the sandbox live `ttlSeconds` from now (capped by the region's maximum). */
  async setTimeout(ttlSeconds: number): Promise<this> {
    this.update(await this.http.json('POST', this.path('/timeout'), { json: { ttl_seconds: Math.floor(ttlSeconds) } }))
    return this
  }

  /** Reset the idle timer. */
  async keepalive(): Promise<this> {
    this.update(await this.http.json('POST', this.path('/keepalive')))
    return this
  }

  /** Freeze every process (commands are refused until `resume()`). */
  async pause(): Promise<this> {
    this.update(await this.http.json('POST', this.path('/pause')))
    return this
  }

  async resume(): Promise<this> {
    this.update(await this.http.json('POST', this.path('/resume')))
    return this
  }

  /** Replace the egress allowlist (`'none'` or a list of hosts / CIDRs). */
  async setNetwork(network: NetworkSpec): Promise<this> {
    this.update(await this.http.json('PUT', this.path('/network'), { json: { network: normalizeNetwork(network) } }))
    return this
  }

  /** The sandbox's event log (`pod: true` adds the pod's status and events). */
  async logs(options: { pod?: boolean } = {}): Promise<SandboxLogs> {
    return unwrap(await this.http.json('GET', this.path('/logs'), { query: { pod: options.pod ? 1 : undefined } }))
  }

  /** A one-time session for the interactive terminal WebSocket. */
  async terminal(options: { cols?: number; rows?: number } = {}): Promise<TerminalSession> {
    return unwrap(await this.http.json('POST', this.path('/terminal'), { json: { cols: options.cols ?? 80, rows: options.rows ?? 24 } }))
  }

  /** Remove the sandbox. A sandbox that is already gone is not an error. */
  async kill(): Promise<void> {
    try {
      this.update(await this.http.json('DELETE', this.path()))
    } catch (error) {
      if (error instanceof NotFoundError || error instanceof ConflictError) return
      throw error
    }
  }

  /** Remove the sandbox; safe to call more than once. */
  async close(): Promise<void> {
    if (this.closed) return
    this.closed = true
    await this.kill()
  }

  async [Symbol.asyncDispose](): Promise<void> {
    await this.close()
  }

  toJSON(): SandboxInfo {
    return this.info
  }
}
