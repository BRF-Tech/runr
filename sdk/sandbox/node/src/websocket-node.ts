/**
 * RFC 6455 client on node:http / node:https for runtimes without a global WebSocket (Node.js 18
 * and 20): the opening handshake with its Sec-WebSocket-Accept check, masked client frames, text
 * and binary messages (fragmented too), ping / pong and the closing handshake.
 */
import { createHash, randomBytes } from 'node:crypto'
import { request as httpRequest, type IncomingMessage } from 'node:http'
import { request as httpsRequest } from 'node:https'
import type { Socket } from 'node:net'
import { Buffer } from 'node:buffer'

import { RunrSandboxError } from './errors.js'
import { redactUrl, type WsConnection, type WsConnectOptions, type WsHandlers } from './websocket.js'

const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11'
/** Largest message accepted (the server's exec frames are at most 64 KiB of base64 output). */
const MAX_MESSAGE = 64 * 1024 * 1024

const OP_CONTINUATION = 0x0
const OP_TEXT = 0x1
const OP_BINARY = 0x2
const OP_CLOSE = 0x8
const OP_PING = 0x9
const OP_PONG = 0xa

/** The Sec-WebSocket-Accept value the server must answer for `key`. */
export function acceptKey(key: string): string {
  return createHash('sha1').update(key + GUID).digest('base64')
}

/** One client frame: FIN set, payload masked with a random key. */
export function encodeFrame(opcode: number, payload: Uint8Array, mask: Uint8Array = randomBytes(4)): Buffer {
  const length = payload.length
  const header: number[] = [0x80 | opcode]
  if (length < 126) {
    header.push(0x80 | length)
  } else if (length < 0x10000) {
    header.push(0x80 | 126, length >> 8, length & 0xff)
  } else {
    header.push(0x80 | 127, 0, 0, 0, 0)
    header.push((length >>> 24) & 0xff, (length >>> 16) & 0xff, (length >>> 8) & 0xff, length & 0xff)
  }
  const frame = Buffer.alloc(header.length + 4 + length)
  Buffer.from(header).copy(frame, 0)
  Buffer.from(mask).copy(frame, header.length)
  const offset = header.length + 4
  for (let i = 0; i < length; i++) frame[offset + i] = payload[i]! ^ mask[i & 3]!
  return frame
}

/** A protocol violation of the server: the connection is failed with `code`. */
class ProtocolError extends Error {
  constructor(readonly code: number, message: string) {
    super(message)
  }
}

export function connectBuiltin(url: string, handlers: WsHandlers, options: WsConnectOptions = {}): Promise<WsConnection> {
  const target = new URL(url)
  if (target.protocol !== 'ws:' && target.protocol !== 'wss:') {
    return Promise.reject(new RunrSandboxError(null, `Invalid WebSocket URL ${redactUrl(url)}: the scheme must be ws or wss.`, 'config'))
  }
  const key = randomBytes(16).toString('base64')
  const secure = target.protocol === 'wss:'
  const request = secure ? httpsRequest : httpRequest

  return new Promise<WsConnection>((resolve, reject) => {
    let settled = false
    const req = request({
      protocol: secure ? 'https:' : 'http:',
      hostname: target.hostname.replace(/^\[|\]$/g, ''),
      port: target.port || (secure ? 443 : 80),
      path: `${target.pathname || '/'}${target.search}`,
      method: 'GET',
      headers: {
        ...(options.headers ?? {}),
        Host: target.host,
        Connection: 'Upgrade',
        Upgrade: 'websocket',
        'Sec-WebSocket-Version': '13',
        'Sec-WebSocket-Key': key,
      },
    })
    const fail = (error: Error): void => {
      if (settled) return
      settled = true
      clearTimeout(timer)
      options.signal?.removeEventListener('abort', onAbort)
      reject(error)
    }
    const timer = setTimeout(() => {
      fail(new RunrSandboxError(null, `The exec WebSocket ${redactUrl(url)} did not open in time.`, 'websocket_error'))
      req.destroy()
    }, options.timeoutMs ?? 30_000)
    const onAbort = (): void => {
      fail(options.signal!.reason instanceof Error ? options.signal!.reason : new Error('aborted'))
      req.destroy()
    }
    options.signal?.addEventListener('abort', onAbort, { once: true })

    req.on('error', (error) => {
      fail(new RunrSandboxError(null, `Cannot open the exec WebSocket ${redactUrl(url)}: ${error.message}`, 'websocket_error'))
    })
    req.on('response', (res: IncomingMessage) => {
      // Not upgraded: an HTTP answer (404, 401, a proxy that does not pass WebSockets on)
      res.resume()
      fail(new RunrSandboxError(res.statusCode ?? null, `The exec WebSocket ${redactUrl(url)} was refused: HTTP ${res.statusCode}.`, 'websocket_error'))
    })
    req.on('upgrade', (res: IncomingMessage, socket: Socket, head: Buffer) => {
      const accept = String(res.headers['sec-websocket-accept'] ?? '')
      const upgrade = String(res.headers.upgrade ?? '').toLowerCase()
      if (res.statusCode !== 101 || upgrade !== 'websocket' || accept !== acceptKey(key)) {
        socket.destroy()
        fail(new RunrSandboxError(null, `The exec WebSocket ${redactUrl(url)} answered an invalid handshake.`, 'websocket_error'))
        return
      }
      if (settled) {
        socket.destroy()
        return
      }
      settled = true
      clearTimeout(timer)
      options.signal?.removeEventListener('abort', onAbort)
      resolve(new BuiltinConnection(socket, head, handlers))
    })
    req.end()
  })
}

class BuiltinConnection implements WsConnection {
  private buffer: Buffer
  private fragments: Buffer[] = []
  private fragmentOpcode = 0
  private fragmentSize = 0
  private closeSent = false
  private closed = false
  private closeTimer: ReturnType<typeof setTimeout> | null = null
  private readonly decoder = new TextDecoder('utf-8', { fatal: true })

  constructor(private readonly socket: Socket, head: Buffer, private readonly handlers: WsHandlers) {
    this.buffer = head && head.length ? Buffer.from(head) : Buffer.alloc(0)
    socket.setNoDelay(true)
    socket.on('data', (chunk: Buffer) => {
      this.buffer = this.buffer.length ? Buffer.concat([this.buffer, chunk]) : chunk
      this.parse()
    })
    socket.on('error', (error) => this.finish(1006, '', error))
    socket.on('close', () => this.finish(1006, '', this.closeSent ? null : new Error('connection lost')))
    if (this.buffer.length) setImmediate(() => this.parse())
  }

  send(text: string): boolean {
    if (this.closeSent || this.closed) return false
    this.socket.write(encodeFrame(OP_TEXT, Buffer.from(text, 'utf8')))
    return true
  }

  close(code = 1000): void {
    if (this.closed) return
    if (!this.closeSent) {
      this.closeSent = true
      const payload = Buffer.alloc(2)
      payload.writeUInt16BE(code, 0)
      this.socket.write(encodeFrame(OP_CLOSE, payload))
    }
    // The server answers with its close frame and ends the connection; a server that does not
    // gets 2 s
    if (!this.closeTimer) {
      this.closeTimer = setTimeout(() => {
        this.socket.destroy()
        this.finish(code, '', null)
      }, 2000)
    }
  }

  private finish(code: number | null, reason: string, error: Error | null): void {
    if (this.closed) return
    this.closed = true
    if (this.closeTimer) clearTimeout(this.closeTimer)
    this.handlers.close(code, reason, error)
  }

  private fail(code: number, message: string): void {
    if (!this.closeSent) {
      this.closeSent = true
      const payload = Buffer.alloc(2)
      payload.writeUInt16BE(code, 0)
      this.socket.write(encodeFrame(OP_CLOSE, payload))
    }
    this.socket.end()
    this.finish(code, message, new Error(message))
  }

  private parse(): void {
    try {
      while (!this.closed) {
        const frame = this.readFrame()
        if (!frame) return
        this.frame(frame.fin, frame.opcode, frame.payload)
      }
    } catch (error) {
      if (error instanceof ProtocolError) this.fail(error.code, error.message)
      else throw error
    }
  }

  private readFrame(): { fin: boolean; opcode: number; payload: Buffer } | null {
    const buf = this.buffer
    if (buf.length < 2) return null
    const fin = (buf[0]! & 0x80) !== 0
    if (buf[0]! & 0x70) throw new ProtocolError(1002, 'reserved bits set in a server frame')
    const opcode = buf[0]! & 0x0f
    if (buf[1]! & 0x80) throw new ProtocolError(1002, 'masked server frame')
    let length = buf[1]! & 0x7f
    let offset = 2
    if (length === 126) {
      if (buf.length < 4) return null
      length = buf.readUInt16BE(2)
      offset = 4
    } else if (length === 127) {
      if (buf.length < 10) return null
      const big = buf.readBigUInt64BE(2)
      if (big > BigInt(MAX_MESSAGE)) throw new ProtocolError(1009, 'message too large')
      length = Number(big)
      offset = 10
    }
    if (opcode >= 0x8 && (!fin || length > 125)) throw new ProtocolError(1002, 'invalid control frame')
    if (buf.length < offset + length) return null
    const payload = buf.subarray(offset, offset + length)
    this.buffer = buf.subarray(offset + length)
    return { fin, opcode, payload }
  }

  private frame(fin: boolean, opcode: number, payload: Buffer): void {
    switch (opcode) {
      case OP_PING:
        if (!this.closeSent) this.socket.write(encodeFrame(OP_PONG, payload))
        return
      case OP_PONG:
        return
      case OP_CLOSE: {
        const code = payload.length >= 2 ? payload.readUInt16BE(0) : null
        const reason = payload.length > 2 ? payload.subarray(2).toString('utf8') : ''
        if (!this.closeSent) {
          // Answer the server's close frame, then the connection ends
          this.closeSent = true
          const answer = Buffer.alloc(2)
          answer.writeUInt16BE(code ?? 1000, 0)
          this.socket.write(encodeFrame(OP_CLOSE, code === null ? Buffer.alloc(0) : answer))
        }
        this.socket.end()
        this.finish(code, reason, null)
        return
      }
      case OP_TEXT:
      case OP_BINARY:
        if (this.fragments.length) throw new ProtocolError(1002, 'a new message inside a fragmented one')
        if (fin) {
          this.deliver(opcode, payload)
          return
        }
        this.fragmentOpcode = opcode
        this.fragments = [Buffer.from(payload)]
        this.fragmentSize = payload.length
        return
      case OP_CONTINUATION: {
        if (!this.fragments.length) throw new ProtocolError(1002, 'a continuation frame without a message')
        this.fragmentSize += payload.length
        if (this.fragmentSize > MAX_MESSAGE) throw new ProtocolError(1009, 'message too large')
        this.fragments.push(Buffer.from(payload))
        if (!fin) return
        const message = Buffer.concat(this.fragments)
        this.fragments = []
        this.fragmentSize = 0
        this.deliver(this.fragmentOpcode, message)
        return
      }
      default:
        throw new ProtocolError(1002, `unknown opcode ${opcode}`)
    }
  }

  private deliver(opcode: number, payload: Buffer): void {
    let text: string
    try {
      text = this.decoder.decode(payload)
    } catch {
      if (opcode === OP_TEXT) throw new ProtocolError(1007, 'a text message that is not UTF-8')
      text = payload.toString('latin1')
    }
    this.handlers.message(text)
  }
}
