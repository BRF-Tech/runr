/**
 * Incremental Server-Sent Events decoder. Chunks of any size (bytes or text) go in, complete
 * events come out. Lines may end with `\r\n`, `\n` or `\r` — also split across chunks — and
 * multi-byte UTF-8 characters may be split too. Comment lines (`: ping`) are ignored; several
 * `data:` lines of one event are joined with `\n`.
 */
export interface SseEvent {
  event: string
  data: string
  id?: string
  retry?: number
}

const LINE_END = /\r\n|\r|\n/g

export class SseDecoder {
  private readonly decoder = new TextDecoder('utf-8')
  private buffer = ''
  private eventName: string | null = null
  private dataLines: string[] = []
  private eventId: string | null = null
  private retryMs: number | null = null
  private lastId: string | null = null
  private first = true

  get lastEventId(): string | null {
    return this.lastId
  }

  /** Add a chunk; returns the events it completed. */
  push(chunk: Uint8Array | string): SseEvent[] {
    let text = typeof chunk === 'string' ? chunk : this.decoder.decode(chunk, { stream: true })
    if (!text) return []
    if (this.first) {
      if (text.charCodeAt(0) === 0xfeff) text = text.slice(1)
      this.first = false
    }
    this.buffer += text
    return this.drain(false)
  }

  /** End of stream: what is left, including an event without its closing blank line. */
  end(): SseEvent[] {
    this.buffer += this.decoder.decode()
    const events = this.drain(true)
    if (this.buffer) {
      const line = this.buffer
      this.buffer = ''
      const event = this.line(line)
      if (event) events.push(event)
    }
    const last = this.dispatch()
    if (last) events.push(last)
    return events
  }

  private drain(final: boolean): SseEvent[] {
    const events: SseEvent[] = []
    let position = 0
    const buffer = this.buffer
    LINE_END.lastIndex = 0
    for (;;) {
      LINE_END.lastIndex = position
      const match = LINE_END.exec(buffer)
      if (!match) break
      const end = match.index + match[0].length
      // A "\r" at the very end may be the first half of "\r\n": wait for the next chunk
      if (match[0] === '\r' && end === buffer.length && !final) break
      const event = this.line(buffer.slice(position, match.index))
      position = end
      if (event) events.push(event)
    }
    this.buffer = buffer.slice(position)
    return events
  }

  private line(line: string): SseEvent | null {
    if (line === '') return this.dispatch()
    if (line.startsWith(':')) return null
    const colon = line.indexOf(':')
    const field = colon === -1 ? line : line.slice(0, colon)
    let value = colon === -1 ? '' : line.slice(colon + 1)
    if (value.startsWith(' ')) value = value.slice(1)
    switch (field) {
      case 'event':
        this.eventName = value
        break
      case 'data':
        this.dataLines.push(value)
        break
      case 'id':
        if (!value.includes('\u0000')) {
          this.eventId = value
          this.lastId = value
        }
        break
      case 'retry':
        if (/^\d+$/.test(value)) this.retryMs = Number(value)
        break
      default:
        break
    }
    return null
  }

  private dispatch(): SseEvent | null {
    if (this.dataLines.length === 0) {
      this.eventName = null
      this.eventId = null
      this.retryMs = null
      return null
    }
    const event: SseEvent = { event: this.eventName ?? 'message', data: this.dataLines.join('\n') }
    const id = this.eventId ?? this.lastId
    if (id !== null) event.id = id
    if (this.retryMs !== null) event.retry = this.retryMs
    this.eventName = null
    this.dataLines = []
    this.eventId = null
    this.retryMs = null
    return event
  }
}

/** Events of a response body stream. */
export async function* readSse(body: ReadableStream<Uint8Array>): AsyncGenerator<SseEvent> {
  const decoder = new SseDecoder()
  const reader = body.getReader()
  try {
    for (;;) {
      const { done, value } = await reader.read()
      if (done) break
      if (value) yield* decoder.push(value)
    }
    yield* decoder.end()
  } finally {
    // Stops the download when the consumer leaves the loop early
    await reader.cancel().catch(() => undefined)
    reader.releaseLock()
  }
}
