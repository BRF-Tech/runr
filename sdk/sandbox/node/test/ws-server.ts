/**
 * The server side of RFC 6455 for the fake API's exec WebSocket (no dependencies): the handshake,
 * unmasking of client frames, text / ping / close frames, fragmented and long messages.
 */
import { createHash } from 'node:crypto'
import type { IncomingMessage } from 'node:http'
import type { Duplex } from 'node:stream'
import { Buffer } from 'node:buffer'

const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11'

export interface ClientFrame {
  opcode: number
  payload: Buffer
  masked: boolean
}

/** Answer the opening handshake; null (and a 400) for a request that is not a WebSocket upgrade. */
export function acceptUpgrade(req: IncomingMessage, socket: Duplex): boolean {
  const key = req.headers['sec-websocket-key']
  if (typeof key !== 'string' || String(req.headers.upgrade).toLowerCase() !== 'websocket') {
    socket.end('HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n')
    return false
  }
  const accept = createHash('sha1').update(key + GUID).digest('base64')
  socket.write(
    'HTTP/1.1 101 Switching Protocols\r\n'
    + 'Upgrade: websocket\r\n'
    + 'Connection: Upgrade\r\n'
    + `Sec-WebSocket-Accept: ${accept}\r\n\r\n`,
  )
  return true
}

/** An unmasked server frame. */
export function serverFrame(opcode: number, payload: Buffer, fin = true): Buffer {
  const length = payload.length
  let header: Buffer
  if (length < 126) {
    header = Buffer.from([(fin ? 0x80 : 0) | opcode, length])
  } else if (length < 0x10000) {
    header = Buffer.alloc(4)
    header[0] = (fin ? 0x80 : 0) | opcode
    header[1] = 126
    header.writeUInt16BE(length, 2)
  } else {
    header = Buffer.alloc(10)
    header[0] = (fin ? 0x80 : 0) | opcode
    header[1] = 127
    header.writeBigUInt64BE(BigInt(length), 2)
  }
  return Buffer.concat([header, payload])
}

export class ServerSocket {
  /** Every frame the client sent (control frames too). */
  readonly frames: ClientFrame[] = []
  /** The client answered (or started) the closing handshake. */
  clientClosed = false
  private buffer = Buffer.alloc(0)
  private closeSent = false
  private ended = false

  constructor(
    private readonly socket: Duplex,
    head: Buffer,
    private readonly onText: (text: string) => void,
    private readonly onEnd: () => void = () => undefined,
  ) {
    socket.on('data', (chunk: Buffer) => this.data(chunk))
    socket.on('close', () => this.end())
    socket.on('error', () => this.end())
    if (head.length) this.data(head)
  }

  get closed(): boolean {
    return this.ended
  }

  sendJson(value: unknown): void {
    this.sendText(JSON.stringify(value))
  }

  sendText(text: string): void {
    if (!this.closeSent) this.socket.write(serverFrame(0x1, Buffer.from(text, 'utf8')))
  }

  /** A text message split into `parts` frames (a first text frame, then continuations). */
  sendFragmented(text: string, parts: number): void {
    const data = Buffer.from(text, 'utf8')
    const size = Math.ceil(data.length / parts)
    for (let i = 0; i < parts; i++) {
      const chunk = data.subarray(i * size, (i + 1) * size)
      this.socket.write(serverFrame(i === 0 ? 0x1 : 0x0, chunk, i === parts - 1))
    }
  }

  ping(payload: string): void {
    this.socket.write(serverFrame(0x9, Buffer.from(payload)))
  }

  /** Send a close frame; the connection ends once the client answered (or after 2 s). */
  close(code = 1000): void {
    if (this.closeSent || this.ended) return
    this.closeSent = true
    const payload = Buffer.alloc(2)
    payload.writeUInt16BE(code, 0)
    this.socket.write(serverFrame(0x8, payload))
    setTimeout(() => this.socket.destroy(), 2000).unref()
  }

  /** End the TCP connection without a closing handshake. */
  drop(): void {
    this.socket.destroy()
  }

  private end(): void {
    if (this.ended) return
    this.ended = true
    this.onEnd()
  }

  private data(chunk: Buffer): void {
    this.buffer = Buffer.concat([this.buffer, chunk])
    for (;;) {
      const buf = this.buffer
      if (buf.length < 2) return
      const opcode = buf[0]! & 0x0f
      const masked = (buf[1]! & 0x80) !== 0
      let length = buf[1]! & 0x7f
      let offset = 2
      if (length === 126) {
        if (buf.length < 4) return
        length = buf.readUInt16BE(2)
        offset = 4
      } else if (length === 127) {
        if (buf.length < 10) return
        length = Number(buf.readBigUInt64BE(2))
        offset = 10
      }
      const maskLength = masked ? 4 : 0
      if (buf.length < offset + maskLength + length) return
      const mask = buf.subarray(offset, offset + maskLength)
      const payload = Buffer.from(buf.subarray(offset + maskLength, offset + maskLength + length))
      if (masked) for (let i = 0; i < payload.length; i++) payload[i] = payload[i]! ^ mask[i & 3]!
      this.buffer = buf.subarray(offset + maskLength + length)
      this.frames.push({ opcode, payload, masked })
      if (opcode === 0x8) {
        this.clientClosed = true
        if (!this.closeSent) {
          this.closeSent = true
          this.socket.write(serverFrame(0x8, payload.subarray(0, 2)))
        }
        this.socket.end()
        return
      }
      if (opcode === 0x9) {
        this.socket.write(serverFrame(0xa, payload))
        continue
      }
      if (opcode === 0x1) this.onText(payload.toString('utf8'))
    }
  }
}
