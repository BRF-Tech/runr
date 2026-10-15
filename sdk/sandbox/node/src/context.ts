import type { HttpClient, Query } from './http.js'
import type { ExecEvent } from './types.js'

/** What the file and process helpers need from their sandbox. */
export interface SandboxContext {
  readonly http: HttpClient
  path(suffix?: string): string
  stream(method: string, path: string, body?: unknown, query?: Query, signal?: AbortSignal): AsyncGenerator<ExecEvent>
}
