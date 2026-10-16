/**
 * The exec WebSocket of the Sandbox API: a command that reads stdin while it runs, optionally on a
 * terminal (TTY) whose size follows `resize()`.
 *
 *  1. `POST /sandboxes/{id}/exec/ws` answers a one-time token (60 s) and the WebSocket's path;
 *  2. the client connects to `/ws/sandbox-exec?token=…` on the console host and exchanges JSON
 *     text frames — server: `start`, `stdout` / `stderr` (base64), `exit`, `error`; client:
 *     `stdin` (base64), `stdin_close`, `kill`, `resize`;
 *  3. the server closes the connection after `exit`; a client that disconnects stops the command.
 */
import { VERSION } from './version.js'
import { decodeText, fromBase64, toBase64 } from './convert.js'
import { RunrSandboxError, ValidationError } from './errors.js'
import { connectWebSocket, type WebSocketTransport, type WsConnection } from './websocket.js'
import type { ExecWsResult, ExitEvent, OutputEvent } from './types.js'

type Json = Record<string, unknown>

/** Grace for the exit frame after an abort's kill, and for the closing handshake. */
const KILL_GRACE_MS = 3000

/** The answer of `POST /sandboxes/{id}/exec/ws`. */
export interface ExecWsTicket {
  token?: string
  url?: string
  exec_id?: string
  expires_in?: number
  tty?: boolean
}

/**
 * The WebSocket URL of a ticket's path: on the console host (the API URL without `/api`), `wss`
 * for https and `ws` for http. A full ws(s):// URL is kept.
 */
export function webSocketUrl(apiUrl: string, path: string): string {
  if (/^wss?:\/\//i.test(path)) return path
  const base = new URL(apiUrl)
  const scheme = base.protocol === 'http:' ? 'ws:' : 'wss:'
  return `${scheme}//${base.host}${path.startsWith('/') ? path : `/${path}`}`
}

/** A terminal dimension: an integer from 1 to 1000. */
export function terminalSize(name: string, value: number): number {
  if (!Number.isInteger(value) || value < 1 || value > 1000) {
    throw new ValidationError(null, `${name} must be an integer from 1 to 1000 (got ${String(value)}).`, 'invalid')
  }
  return value
}

export interface OpenExecSessionOptions {
  apiUrl: string
  transport?: WebSocketTransport
  signal?: AbortSignal
}

interface Waiter {
  resolve(event: OutputEvent | ExitEvent | null): void
  reject(error: unknown): void
}

/**
 * A command running over the exec WebSocket.
 *
 * ```ts
 * await using session = await sandbox.execWs(['python3', '-i'])
 * session.sendStdin('print(6 * 7)\n')
 * session.closeStdin()
 * for await (const event of session) {
 *   if (event.type === 'stdout') process.stdout.write(event.data)
 * }
 * ```
 *
 * Iterating yields `stdout` / `stderr` events and then one `exit` event; `wait()` resolves with the
 * exit status and the output no iteration has taken.
 */
export class ExecSession implements AsyncIterable<OutputEvent | ExitEvent>, AsyncDisposable {
  /** The command's id (`exec_id`). */
  execId: string
  /** Whether the command runs on a terminal: stdout and stderr then both arrive as `stdout`. */
  tty: boolean
  private connection: WsConnection | null = null
  private readonly queue: Array<OutputEvent | ExitEvent> = []
  private readonly waiters: Waiter[] = []
  private exitEvent: ExitEvent | null = null
  private exitTaken = false
  private failure: unknown = null
  private closed = false
  private stdinClosed = false
  private started: { resolve(): void; reject(error: unknown): void } | null = null
  private closeWaiters: Array<() => void> = []
  private graceTimer: ReturnType<typeof setTimeout> | null = null

  /** @internal Use `sandbox.execWs()` / `sandbox.runCodeWs()`. */
  constructor(ticket: ExecWsTicket) {
    this.execId = ticket.exec_id ? String(ticket.exec_id) : ''
    // A server without the tty option answers no `tty`: the command then has no terminal
    this.tty = ticket.tty === true
  }

  /** @internal Connect and wait for the `start` frame. */
  static async open(ticket: ExecWsTicket, options: OpenExecSessionOptions): Promise<ExecSession> {
    const path = ticket.url || (ticket.token ? `/ws/sandbox-exec?token=${encodeURIComponent(ticket.token)}` : '')
    if (!path) throw new RunrSandboxError(null, 'The RunR API answered no exec WebSocket session.', 'bad_response', ticket)
    const session = new ExecSession(ticket)
    const url = webSocketUrl(options.apiUrl, path)
    const started = new Promise<void>((resolve, reject) => {
      session.started = { resolve, reject }
    })
    session.connection = await connectWebSocket(url, {
      message: (text) => session.onMessage(text),
      close: (code, reason, error) => session.onClose(code, reason, error),
    }, {
      transport: options.transport,
      signal: options.signal,
      headers: { 'User-Agent': `runr-sandbox-node/${VERSION}`, Origin: new URL(options.apiUrl).origin },
    })
    const signal = options.signal
    const onAbort = (): void => session.abort(signal?.reason)
    try {
      if (signal) {
        signal.throwIfAborted()
        signal.addEventListener('abort', onAbort, { once: true })
      }
      await started
    } catch (error) {
      signal?.removeEventListener('abort', onAbort)
      session.connection.close()
      throw error
    }
    if (signal) session.closeWaiters.push(() => signal.removeEventListener('abort', onAbort))
    return session
  }

  // ── Input ─────────────────────────────────────────────────────────────────

  /** Send bytes (or UTF-8 text) to the command's stdin. False once the session has ended. */
  sendStdin(data: string | Uint8Array): boolean {
    if (this.stdinClosed) return false
    return this.send({ type: 'stdin', data: toBase64(data) })
  }

  /**
   * Close the command's stdin: it reads end of file. On a terminal the server sends Ctrl+D
   * instead, which a program reading the terminal line by line takes as the end of input.
   */
  closeStdin(): boolean {
    if (this.stdinClosed) return false
    this.stdinClosed = true
    return this.send({ type: 'stdin_close' })
  }

  /** Set the terminal size (columns × rows, 1–1000); ignored by the server without a TTY. */
  resize(cols: number, rows: number): boolean {
    return this.send({ type: 'resize', cols: terminalSize('cols', cols), rows: terminalSize('rows', rows) })
  }

  /** Stop the command (SIGKILL); the server answers with the `exit` event (`killed: true`, 137). */
  kill(): boolean {
    return this.send({ type: 'kill' })
  }

  private send(frame: Json): boolean {
    if (!this.connection || this.closed || this.exitEvent || this.failure) return false
    return this.connection.send(JSON.stringify(frame))
  }

  // ── Output ────────────────────────────────────────────────────────────────

  async *[Symbol.asyncIterator](): AsyncGenerator<OutputEvent | ExitEvent> {
    for (;;) {
      const event = await this.next()
      if (!event) return
      yield event
      if (event.type === 'exit') return
    }
  }

  /** Wait for the command to end: exit status and the output no iteration has taken. */
  async wait(): Promise<ExecWsResult> {
    const stdout: Uint8Array[] = []
    const stderr: Uint8Array[] = []
    let exit: ExitEvent | null = null
    for (;;) {
      const event = await this.next()
      if (!event) break
      if (event.type === 'exit') {
        exit = event
        break
      }
      ;(event.type === 'stdout' ? stdout : stderr).push(event.data)
    }
    exit ??= this.exitEvent
    if (!exit) throw new RunrSandboxError(null, 'The exec session ended without an exit status.', 'websocket_closed')
    const stdoutBytes = concat(stdout)
    const stderrBytes = concat(stderr)
    return {
      exitCode: exit.exitCode,
      stdout: decodeText(stdoutBytes),
      stderr: decodeText(stderrBytes),
      stdoutBytes,
      stderrBytes,
      timedOut: exit.timedOut,
      durationMs: exit.durationMs,
      truncated: false,
      killed: exit.killed ?? false,
      ok: exit.exitCode === 0 && !exit.timedOut,
      execId: this.execId,
      tty: this.tty,
    }
  }

  /** The exit event once the command ended (null while it runs). */
  get exit(): ExitEvent | null {
    return this.exitEvent
  }

  private next(): Promise<OutputEvent | ExitEvent | null> {
    const event = this.queue.shift()
    if (event) {
      if (event.type === 'exit') this.exitTaken = true
      return Promise.resolve(event)
    }
    if (this.failure) return Promise.reject(this.failure)
    if (this.exitTaken || this.closed) return Promise.resolve(null)
    return new Promise((resolve, reject) => this.waiters.push({ resolve, reject }))
  }

  private push(event: OutputEvent | ExitEvent): void {
    const waiter = this.waiters.shift()
    if (waiter) {
      if (event.type === 'exit') this.exitTaken = true
      waiter.resolve(event)
    } else {
      this.queue.push(event)
    }
  }

  private fail(error: unknown): void {
    if (this.failure || this.exitEvent) return
    this.failure = error
    this.started?.reject(error)
    this.started = null
    for (const waiter of this.waiters.splice(0)) waiter.reject(error)
  }

  private onMessage(text: string): void {
    let frame: Json
    try {
      const parsed: unknown = JSON.parse(text)
      if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return
      frame = parsed as Json
    } catch {
      return // not a frame of the exec protocol
    }
    switch (frame.type) {
      case 'start':
        if (frame.exec_id) this.execId = String(frame.exec_id)
        if (typeof frame.tty === 'boolean') this.tty = frame.tty
        this.started?.resolve()
        this.started = null
        return
      case 'stdout':
      case 'stderr': {
        if (this.exitEvent || this.failure) return
        const data = fromBase64(typeof frame.data === 'string' ? frame.data : '')
        this.push({ type: frame.type, data, text: decodeText(data), execId: this.execId || null })
        return
      }
      case 'exit': {
        if (this.exitEvent || this.failure) return
        const code = Number(frame.exit_code)
        const exit: ExitEvent = {
          type: 'exit',
          exitCode: frame.exit_code === undefined || frame.exit_code === null || !Number.isFinite(code) ? -1 : code,
          timedOut: Boolean(frame.timed_out),
          durationMs: Number.isFinite(Number(frame.duration_ms)) ? Number(frame.duration_ms) : 0,
          truncated: false,
          killed: Boolean(frame.killed),
          execId: this.execId || null,
        }
        this.exitEvent = exit
        this.push(exit)
        // The server closes the connection now; one that does not is closed after the grace
        this.graceTimer = setTimeout(() => this.connection?.close(), KILL_GRACE_MS)
        this.graceTimer.unref?.()
        return
      }
      case 'error':
        this.fail(new RunrSandboxError(null, typeof frame.message === 'string' && frame.message ? frame.message : 'The exec WebSocket failed.', 'exec_error', frame))
        return
      default:
        // Unknown frames are left out for forward compatibility
    }
  }

  private onClose(_code: number | null, _reason: string, error: Error | null): void {
    this.closed = true
    if (this.graceTimer) clearTimeout(this.graceTimer)
    if (!this.exitEvent) {
      this.fail(new RunrSandboxError(
        null,
        `The exec WebSocket closed before the command exited${error ? ` (${error.message})` : ''}.`,
        'websocket_closed',
      ))
    }
    for (const resolve of this.closeWaiters.splice(0)) resolve()
    // Waiters left (exit already taken by someone else): nothing more comes
    for (const waiter of this.waiters.splice(0)) waiter.resolve(null)
  }

  private abort(reason: unknown): void {
    if (this.exitEvent || this.closed) return
    this.kill()
    this.fail(reason instanceof Error ? reason : new Error('aborted'))
    // The command gets KILL_GRACE_MS to answer the kill before the connection ends
    this.graceTimer = setTimeout(() => this.connection?.close(), KILL_GRACE_MS)
    this.graceTimer.unref?.()
  }

  // ── End ───────────────────────────────────────────────────────────────────

  /**
   * End the session: the connection closes, which stops a command that still runs. Safe to call
   * more than once.
   */
  async close(): Promise<void> {
    if (this.closed || !this.connection) return
    const done = new Promise<void>((resolve) => this.closeWaiters.push(resolve))
    if (!this.exitEvent) this.fail(new RunrSandboxError(null, 'The exec session was closed.', 'session_closed'))
    this.connection.close(1000)
    await done
  }

  async [Symbol.asyncDispose](): Promise<void> {
    await this.close()
  }
}

function concat(chunks: Uint8Array[]): Uint8Array {
  if (chunks.length === 1) return chunks[0]!
  const out = new Uint8Array(chunks.reduce((n, c) => n + c.length, 0))
  let offset = 0
  for (const chunk of chunks) {
    out.set(chunk, offset)
    offset += chunk.length
  }
  return out
}
