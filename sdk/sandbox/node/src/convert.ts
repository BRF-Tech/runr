import { Buffer } from 'node:buffer'

import { RunrSandboxError } from './errors.js'
import type { SseEvent } from './sse.js'
import type {
  CreateOptions,
  ExecEvent,
  ExecOptions,
  ExecResult,
  FileEntry,
  FileSpec,
  ListFilters,
  NetworkSpec,
  ProcessLogs,
  RichOutput,
  RunOptions,
  RunResult,
  SandboxProcess,
} from './types.js'
import type { Query } from './http.js'

type Json = Record<string, unknown>

const textDecoder = new TextDecoder('utf-8')
const textEncoder = new TextEncoder()

export function toBase64(data: string | Uint8Array): string {
  return (typeof data === 'string' ? Buffer.from(data, 'utf8') : Buffer.from(data.buffer, data.byteOffset, data.byteLength)).toString('base64')
}

export function fromBase64(data: string): Uint8Array {
  return new Uint8Array(Buffer.from(data, 'base64'))
}

export function decodeText(data: Uint8Array): string {
  return textDecoder.decode(data)
}

export function encodeText(text: string): Uint8Array {
  return textEncoder.encode(text)
}

function str(value: unknown, fallback = ''): string {
  return value === undefined || value === null ? fallback : String(value)
}

function num(value: unknown, fallback = 0): number {
  const n = typeof value === 'number' ? value : Number(value)
  return Number.isFinite(n) ? n : fallback
}

function nullableNum(value: unknown): number | null {
  return value === undefined || value === null ? null : num(value)
}

function nullableStr(value: unknown): string | null {
  return value === undefined || value === null ? null : String(value)
}

/** [text, bytes] of an output field: UTF-8 text in `key`, or base64 in `key_base64`. */
function output(data: Json, key: string): [string, Uint8Array] {
  const encoded = data[`${key}_base64`]
  if (typeof encoded === 'string' && encoded !== '') {
    const bytes = fromBase64(encoded)
    return [decodeText(bytes), bytes]
  }
  const text = str(data[key])
  return [text, encodeText(text)]
}

export function toExecResult(data: Json): ExecResult {
  const [stdout, stdoutBytes] = output(data, 'stdout')
  const [stderr, stderrBytes] = output(data, 'stderr')
  const exitCode = data.exit_code === undefined || data.exit_code === null ? -1 : num(data.exit_code, -1)
  const timedOut = Boolean(data.timed_out)
  return {
    exitCode,
    stdout,
    stderr,
    stdoutBytes,
    stderrBytes,
    timedOut,
    durationMs: num(data.duration_ms),
    truncated: Boolean(data.truncated),
    ok: exitCode === 0 && !timedOut,
  }
}

export function toRichOutput(data: Json): RichOutput {
  const dataBase64 = str(data.data_base64)
  const bytes = fromBase64(dataBase64)
  return {
    path: str(data.path),
    mime: str(data.mime, 'application/octet-stream'),
    size: data.size === undefined || data.size === null ? bytes.length : num(data.size),
    data: bytes,
    dataBase64,
  }
}

export function toRunResult(data: Json): RunResult {
  return {
    ...toExecResult(data),
    language: str(data.language),
    filesChanged: (Array.isArray(data.files_changed) ? data.files_changed : []).map((f: Json) => ({ path: str(f.path), size: num(f.size) })),
    outputs: (Array.isArray(data.outputs) ? data.outputs : []).map((o: Json) => toRichOutput(o)),
  }
}

export function toFileEntry(data: Json): FileEntry {
  return {
    name: str(data.name),
    path: str(data.path),
    type: str(data.type, 'other'),
    size: num(data.size),
    mode: nullableStr(data.mode),
    modifiedAt: nullableStr(data.modified_at),
  }
}

export function toProcess(data: Json): SandboxProcess {
  return {
    id: str(data.id),
    sandboxId: str(data.sandbox_id),
    pid: nullableNum(data.pid),
    command: str(data.command),
    cwd: nullableStr(data.cwd),
    status: str(data.status),
    exitCode: nullableNum(data.exit_code),
    startedAt: nullableStr(data.started_at),
    endedAt: nullableStr(data.ended_at),
  }
}

export function toProcessLogs(data: Json): ProcessLogs {
  return { stdout: output(data, 'stdout')[0], stderr: output(data, 'stderr')[0], truncated: Boolean(data.truncated) }
}

// ── Request bodies ──────────────────────────────────────────────────────────

export function normalizeMode(mode: number | string): string {
  if (typeof mode === 'number') return mode.toString(8).padStart(4, '0')
  const text = mode.trim()
  return text.startsWith('0') ? text : `0${text}`
}

export function normalizeNetwork(network: NetworkSpec | undefined): unknown {
  if (network === undefined) return undefined
  if (typeof network === 'string') return network
  if (Array.isArray(network)) return { egress_allow: network.map(String) }
  return { ...network }
}

export function normalizeFiles(files: CreateOptions['files']): Json[] | undefined {
  if (files === undefined) return undefined
  if (!Array.isArray(files)) {
    return Object.entries(files).map(([path, content]) => ({ path, content_base64: toBase64(content) }))
  }
  return files.map((file: FileSpec) => {
    const entry: Json = {
      path: file.path,
      content_base64: file.contentBase64 ?? toBase64(file.content ?? ''),
    }
    if (file.mode !== undefined) entry.mode = normalizeMode(file.mode)
    return entry
  })
}

function compact(body: Json): Json {
  const out: Json = {}
  for (const [key, value] of Object.entries(body)) {
    if (value !== undefined) out[key] = value
  }
  return out
}

export function createBody(options: CreateOptions): Json {
  return compact({
    runtime: options.runtime,
    version: options.version,
    image: options.image,
    cpu: options.cpu,
    memory: options.memory,
    disk: options.disk,
    ttl_seconds: options.ttlSeconds,
    idle_timeout: options.idleTimeout,
    network: normalizeNetwork(options.network),
    env: options.env,
    files: normalizeFiles(options.files),
    region: options.region,
    sandbox_class: options.sandboxClass,
    read_only_root: options.readOnlyRoot,
    pids_limit: options.pidsLimit,
    metadata: options.metadata,
    wait: options.wait ?? true,
  })
}

export function execBody(cmd: string | string[], options: ExecOptions = {}, stream = false): Json {
  const body: Json = { cmd: typeof cmd === 'string' ? cmd : cmd.map(String) }
  if (options.stdin instanceof Uint8Array) body.stdin_base64 = toBase64(options.stdin)
  else if (options.stdin !== undefined) body.stdin = options.stdin
  Object.assign(body, compact({ timeout: options.timeout, env: options.env, cwd: options.cwd }))
  if (stream) body.stream = true
  return body
}

export function runBody(code: string, options: RunOptions = {}, stream = false): Json {
  const body = compact({ code, language: options.language, timeout: options.timeout, env: options.env, cwd: options.cwd })
  if (stream) body.stream = true
  return body
}

export function listQuery(filters: ListFilters): Query {
  const query: Query = {
    status: filters.status,
    region: filters.region,
    runtime: filters.runtime,
    page: filters.page,
    per_page: filters.perPage,
    all_tenants: filters.allTenants ? 1 : undefined,
  }
  for (const [key, value] of Object.entries(filters.metadata ?? {})) {
    query[`metadata[${key}]`] = String(value)
  }
  return query
}

/** An HTTP timeout that outlasts a command's own timeout (seconds). */
export function execHttpTimeout(baseMs: number, commandTimeout: number | undefined): number {
  return Math.max(baseMs, ((commandTimeout ?? 60) + 60) * 1000)
}

// ── Streams ─────────────────────────────────────────────────────────────────

function eventJson(event: SseEvent): Json {
  if (!event.data) return {}
  try {
    const parsed: unknown = JSON.parse(event.data)
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? (parsed as Json) : { data: parsed }
  } catch {
    return { data: event.data }
  }
}

/** Turns the SSE events of an exec / run stream into ExecEvents (throws on `error`). */
export class ExecStreamConverter {
  execId: string | null = null

  convert(event: SseEvent): ExecEvent | null {
    switch (event.event) {
      case 'start': {
        const payload = eventJson(event)
        this.execId = payload.exec_id ? String(payload.exec_id) : null
        return null
      }
      case 'stdout':
      case 'stderr': {
        const payload = eventJson(event)
        const data = typeof payload.data_base64 === 'string' && payload.data_base64 !== ''
          ? fromBase64(payload.data_base64)
          : encodeText(str(payload.data))
        return { type: event.event, data, text: decodeText(data), execId: this.execId }
      }
      case 'exit': {
        const payload = eventJson(event)
        return {
          type: 'exit',
          exitCode: payload.exit_code === undefined || payload.exit_code === null ? -1 : num(payload.exit_code, -1),
          timedOut: Boolean(payload.timed_out),
          durationMs: num(payload.duration_ms),
          truncated: Boolean(payload.truncated),
          execId: this.execId,
        }
      }
      case 'result':
        return { type: 'result', result: toRunResult(eventJson(event)), execId: this.execId }
      case 'error': {
        const payload = eventJson(event)
        throw new RunrSandboxError(
          null,
          str(payload.message, 'The sandbox stream failed.'),
          payload.reason ? String(payload.reason) : null,
          payload,
        )
      }
      default:
        // Unknown events are left out for forward compatibility
        return null
    }
  }
}
