import { RunrSandboxError } from './errors.js'
import { VERSION } from './version.js'

/** Connection settings; every field falls back to the environment. */
export interface ClientOptions {
  /** Your RunR address, e.g. `https://runr.example.com` (`/api` is appended). Env: `RUNR_API_URL`. */
  apiUrl?: string
  /** An API token. Env: `RUNR_API_KEY`. */
  apiKey?: string
  /** The organization on multi-tenant installations (sent as `X-Tenant-ID`). Env: `RUNR_TENANT_ID`. */
  tenantId?: string
  /** HTTP timeout per request in milliseconds (default 300000). Streams have none. */
  timeoutMs?: number
  /** A fetch implementation (default: the global fetch). */
  fetch?: typeof fetch
  /** Extra headers sent with every request. */
  headers?: Record<string, string>
}

export interface ResolvedConfig {
  apiUrl: string
  apiKey: string | null
  tenantId: string | null
  timeoutMs: number
  fetch: typeof fetch
  headers: Record<string, string>
}

export const DEFAULT_TIMEOUT_MS = 300_000

/** `https://runr.example.com` → `https://runr.example.com/api`; a URL ending in `/api` is kept. */
export function apiBaseUrl(url: string): string {
  let base = url.trim().replace(/\/+$/, '')
  if (!base) throw new RunrSandboxError(null, 'The RunR API URL is empty.', 'config')
  if (!base.endsWith('/api')) base += '/api'
  return base
}

function env(name: string): string | undefined {
  const value = (globalThis as { process?: { env?: Record<string, string | undefined> } }).process?.env?.[name]
  return value === undefined || value === '' ? undefined : value
}

export function resolveConfig(options: ClientOptions = {}): ResolvedConfig {
  const url = options.apiUrl ?? env('RUNR_API_URL')
  if (!url) {
    throw new RunrSandboxError(
      null,
      'No RunR API URL: pass apiUrl or set RUNR_API_URL (e.g. https://runr.example.com).',
      'config',
    )
  }
  const fetchImpl = options.fetch ?? globalThis.fetch
  if (typeof fetchImpl !== 'function') {
    throw new RunrSandboxError(null, 'No fetch implementation: use Node.js 18 or newer, or pass options.fetch.', 'config')
  }
  return {
    apiUrl: apiBaseUrl(url),
    apiKey: options.apiKey ?? env('RUNR_API_KEY') ?? null,
    tenantId: options.tenantId ?? env('RUNR_TENANT_ID') ?? null,
    timeoutMs: options.timeoutMs ?? DEFAULT_TIMEOUT_MS,
    fetch: fetchImpl,
    headers: { ...(options.headers ?? {}) },
  }
}

/** Headers of every request. */
export function baseHeaders(config: ResolvedConfig): Record<string, string> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'User-Agent': `runr-sandbox-node/${VERSION}`,
    ...config.headers,
  }
  if (config.apiKey) headers.Authorization = `Bearer ${config.apiKey}`
  if (config.tenantId) headers['X-Tenant-ID'] = config.tenantId
  return headers
}
