import { baseHeaders, type ResolvedConfig } from './config.js'
import { errorFromResponse, RunrSandboxError } from './errors.js'

export type QueryValue = string | number | boolean | null | undefined
export type Query = Record<string, QueryValue>

export interface RequestOptions {
  /** JSON body (sent with `Content-Type: application/json`). */
  json?: unknown
  /** Raw body. */
  body?: Uint8Array | string
  /** Content type of a raw body. */
  contentType?: string
  query?: Query
  accept?: string
  /** Per-request timeout; `null` for none (streams). Default: the client timeout. */
  timeoutMs?: number | null
  signal?: AbortSignal
}

/** A response together with the cleanup of its timeout. */
export interface OpenResponse {
  response: Response
  done(): void
}

/** fetch against one RunR API, with error mapping and timeouts. */
export class HttpClient {
  constructor(readonly config: ResolvedConfig) {}

  url(path: string, query?: Query): string {
    let url = this.config.apiUrl + (path.startsWith('/') ? path : `/${path}`)
    if (query) {
      const params = new URLSearchParams()
      for (const [key, value] of Object.entries(query)) {
        if (value === undefined || value === null) continue
        params.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value))
      }
      const text = params.toString()
      if (text) url += `?${text}`
    }
    return url
  }

  /** Send a request; throws the mapped error for 4xx / 5xx answers. Call `done()` after reading the body. */
  async open(method: string, path: string, options: RequestOptions = {}): Promise<OpenResponse> {
    const headers = baseHeaders(this.config)
    let body: Uint8Array | string | undefined
    if (options.json !== undefined) {
      headers['Content-Type'] = 'application/json'
      body = JSON.stringify(options.json)
    } else if (options.body !== undefined) {
      headers['Content-Type'] = options.contentType ?? 'application/octet-stream'
      body = options.body
    }
    if (options.accept) headers.Accept = options.accept

    const controller = new AbortController()
    const timeoutMs = options.timeoutMs === undefined ? this.config.timeoutMs : options.timeoutMs
    let timedOut = false
    const timer = timeoutMs === null ? null : setTimeout(() => {
      timedOut = true
      controller.abort()
    }, timeoutMs)
    const onAbort = (): void => controller.abort()
    options.signal?.addEventListener('abort', onAbort, { once: true })
    const done = (): void => {
      if (timer) clearTimeout(timer)
      options.signal?.removeEventListener('abort', onAbort)
    }

    let response: Response
    try {
      response = await this.config.fetch(this.url(path, options.query), {
        method,
        headers,
        body,
        signal: controller.signal,
      })
    } catch (error) {
      done()
      if (timedOut) {
        throw new RunrSandboxError(null, `The RunR API did not answer in time (${method} ${path}).`, 'http_timeout')
      }
      if (options.signal?.aborted) throw error
      const cause = error instanceof Error ? (error.cause instanceof Error ? error.cause.message : error.message) : String(error)
      throw new RunrSandboxError(null, `Cannot reach the RunR API: ${cause}`, 'http_error')
    }

    if (response.status >= 400) {
      const text = await response.text().catch(() => '')
      done()
      let parsed: unknown = null
      try {
        parsed = text ? JSON.parse(text) : null
      } catch {
        parsed = null
      }
      throw errorFromResponse(response.status, parsed, parsed === null ? text : '', response.headers.get('Retry-After'))
    }

    return { response, done }
  }

  async json<T = unknown>(method: string, path: string, options: RequestOptions = {}): Promise<T> {
    const { response, done } = await this.open(method, path, options)
    try {
      const text = await response.text()
      if (!text) return {} as T
      try {
        return JSON.parse(text) as T
      } catch {
        throw new RunrSandboxError(response.status, 'The RunR API answered with a body that is not JSON.', 'bad_response', text)
      }
    } finally {
      done()
    }
  }

  async bytes(method: string, path: string, options: RequestOptions = {}): Promise<Uint8Array> {
    const { response, done } = await this.open(method, path, options)
    try {
      return new Uint8Array(await response.arrayBuffer())
    } finally {
      done()
    }
  }
}

/** The `data` member of an API response. */
export function unwrap<T>(payload: unknown): T {
  if (payload && typeof payload === 'object' && 'data' in (payload as Record<string, unknown>)) {
    return (payload as { data: T }).data
  }
  return payload as T
}
