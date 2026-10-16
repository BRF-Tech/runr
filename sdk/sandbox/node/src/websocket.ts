/**
 * A minimal WebSocket client for the exec WebSocket of the Sandbox API: text messages in both
 * directions, the closing handshake, nothing else (no subprotocols, no extensions).
 *
 * Two implementations behind one interface:
 *  - `global`: the runtime's own `WebSocket` (Node.js 22+, Bun, Deno, browsers);
 *  - `builtin`: RFC 6455 on node:http / node:https (Node.js 18 and 20, which have none), loaded
 *    on first use.
 * `auto` takes the global one when the runtime has it.
 */
import { RunrSandboxError } from './errors.js'

/** Which WebSocket client carries the exec WebSocket. */
export type WebSocketTransport = 'auto' | 'global' | 'builtin'

/** Callbacks of an open connection. `close` is called once, after the last `message`. */
export interface WsHandlers {
  message(text: string): void
  close(code: number | null, reason: string, error: Error | null): void
}

/** An open connection. */
export interface WsConnection {
  /** Send a text message; false when the connection is closing or closed. */
  send(text: string): boolean
  /** Start the closing handshake (the connection ends once the server answered, or after 2 s). */
  close(code?: number): void
}

export interface WsConnectOptions {
  transport?: WebSocketTransport
  /** Headers of the built-in client's handshake (the global WebSocket sends its own). */
  headers?: Record<string, string>
  /** Handshake timeout (default 30 s). */
  timeoutMs?: number
  signal?: AbortSignal
}

/** The URL without its query (the exec token is in the query). */
export function redactUrl(url: string): string {
  const q = url.indexOf('?')
  return q === -1 ? url : `${url.slice(0, q)}?…`
}

interface GlobalWebSocket {
  readonly readyState: number
  binaryType: string
  onopen: ((event: unknown) => void) | null
  onmessage: ((event: { data: unknown }) => void) | null
  onerror: ((event: unknown) => void) | null
  onclose: ((event: { code: number; reason: string }) => void) | null
  send(data: string): void
  close(code?: number, reason?: string): void
}

type GlobalWebSocketConstructor = new (url: string) => GlobalWebSocket

function globalWebSocket(): GlobalWebSocketConstructor | null {
  const ctor = (globalThis as { WebSocket?: unknown }).WebSocket
  return typeof ctor === 'function' ? (ctor as GlobalWebSocketConstructor) : null
}

/** Open a WebSocket. Rejects when the handshake fails; afterwards the handlers report everything. */
export async function connectWebSocket(url: string, handlers: WsHandlers, options: WsConnectOptions = {}): Promise<WsConnection> {
  const transport = options.transport ?? 'auto'
  options.signal?.throwIfAborted()
  if (transport === 'global' || (transport === 'auto' && globalWebSocket())) {
    const ctor = globalWebSocket()
    if (!ctor) {
      throw new RunrSandboxError(null, 'This runtime has no global WebSocket; use the built-in client (transport "builtin").', 'config')
    }
    return connectGlobal(ctor, url, handlers, options)
  }
  const { connectBuiltin } = await import('./websocket-node.js')
  return connectBuiltin(url, handlers, options)
}

const textDecoder = new TextDecoder('utf-8')

function messageText(data: unknown): string {
  if (typeof data === 'string') return data
  if (data instanceof ArrayBuffer) return textDecoder.decode(new Uint8Array(data))
  if (ArrayBuffer.isView(data)) return textDecoder.decode(new Uint8Array(data.buffer, data.byteOffset, data.byteLength))
  return String(data)
}

function connectGlobal(ctor: GlobalWebSocketConstructor, url: string, handlers: WsHandlers, options: WsConnectOptions): Promise<WsConnection> {
  return new Promise<WsConnection>((resolve, reject) => {
    let ws: GlobalWebSocket
    try {
      ws = new ctor(url)
    } catch (error) {
      reject(new RunrSandboxError(null, `Cannot open the exec WebSocket ${redactUrl(url)}: ${error instanceof Error ? error.message : String(error)}`, 'websocket_error'))
      return
    }
    ws.binaryType = 'arraybuffer'
    let open = false
    let closed = false
    let closeTimer: ReturnType<typeof setTimeout> | null = null
    const timer = setTimeout(() => {
      if (open) return
      fail(new RunrSandboxError(null, `The exec WebSocket ${redactUrl(url)} did not open in time.`, 'websocket_error'))
      ws.close()
    }, options.timeoutMs ?? 30_000)
    const onAbort = (): void => {
      if (open) return
      fail(options.signal!.reason instanceof Error ? options.signal!.reason : new Error('aborted'))
      ws.close()
    }
    options.signal?.addEventListener('abort', onAbort, { once: true })
    let settled = false
    const fail = (error: Error): void => {
      if (settled) return
      settled = true
      clearTimeout(timer)
      options.signal?.removeEventListener('abort', onAbort)
      reject(error)
    }

    ws.onopen = () => {
      if (settled) return
      open = true
      settled = true
      clearTimeout(timer)
      options.signal?.removeEventListener('abort', onAbort)
      resolve({
        send(text: string): boolean {
          if (closed || ws.readyState !== 1) return false
          ws.send(text)
          return true
        },
        close(code = 1000): void {
          if (closed || closeTimer) return
          try {
            ws.close(code)
          } catch {
            ws.close()
          }
          // Some runtimes wait long for the server's answer: report the close after 2 s anyway
          closeTimer = setTimeout(() => finish(code, '', null), 2000)
        },
      })
    }
    ws.onmessage = (event) => {
      if (!closed) handlers.message(messageText(event.data))
    }
    ws.onerror = () => {
      if (!open) fail(new RunrSandboxError(null, `Cannot open the exec WebSocket ${redactUrl(url)}.`, 'websocket_error'))
    }
    const finish = (code: number | null, reason: string, error: Error | null): void => {
      if (closed) return
      closed = true
      if (closeTimer) clearTimeout(closeTimer)
      handlers.close(code, reason, error)
    }
    ws.onclose = (event) => {
      if (!open) {
        fail(new RunrSandboxError(null, `The exec WebSocket ${redactUrl(url)} closed during the handshake (code ${event.code}).`, 'websocket_error'))
        return
      }
      finish(event.code === 1005 ? null : event.code, event.reason ?? '', event.code === 1006 ? new Error('connection lost') : null)
    }
  })
}
