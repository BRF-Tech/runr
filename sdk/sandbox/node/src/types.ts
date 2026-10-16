import type { ClientOptions } from './config.js'

export type SandboxStatus = 'pending' | 'running' | 'paused' | 'terminating' | 'terminated' | 'failed'

/** A sandbox as the API returns it. */
export interface SandboxInfo {
  id: string
  status: SandboxStatus | string
  region: { id: string; name: string } | null
  runtime: string | null
  runtime_version: string | null
  image: string | null
  sandbox_class: string
  isolation: 'kata' | 'gvisor' | 'runc' | string
  resources: { cpu: string; cpu_m: number; memory_mb: number; disk_mb: number; pids_limit: number }
  network: { mode: 'none' | 'allowlist' | string; egress_allow: string[] }
  read_only_root: boolean
  env_keys: string[]
  metadata: Record<string, unknown>
  ttl_seconds: number
  idle_timeout_seconds: number
  expires_at: string | null
  idle_expires_at: string | null
  last_activity_at: string | null
  created_at: string | null
  started_at: string | null
  ended_at: string | null
  end_reason: string | null
  from_pool: boolean
  start_ms: number | null
  error: string | null
  created_by: string | null
  tenant_id: string | null
  [key: string]: unknown
}

/** `'none'`, a list of allowed egress destinations (`'pypi.org:443'`, `'10.0.0.0/8'`), or the API shape. */
export type NetworkSpec = 'none' | string[] | { egress_allow: string[] }

export interface FileSpec {
  path: string
  content?: string | Uint8Array
  contentBase64?: string
  /** `0o755` or `'0755'`. */
  mode?: number | string
}

export interface CreateOptions extends ClientOptions {
  /** A runtime of the catalog (default `python`). */
  runtime?: string
  version?: string
  /** Any image instead of a runtime (it needs `/bin/sh`; `tar` for directory transfer). */
  image?: string
  /** `'500m'`, `0.5`, `'1'`. */
  cpu?: string | number
  /** MiB, or a quantity like `'512Mi'`. */
  memory?: string | number
  /** MiB, or a quantity like `'1Gi'`. */
  disk?: string | number
  ttlSeconds?: number
  idleTimeout?: number
  network?: NetworkSpec
  env?: Record<string, string>
  /** `{path: content}` or a list of file specs, written before the sandbox is returned. */
  files?: Record<string, string | Uint8Array> | FileSpec[]
  /** Region id or name. */
  region?: string
  /** `auto`, `kata`, `kata-fc`, `kata-clh`, `gvisor`, `runc`, … */
  sandboxClass?: string
  readOnlyRoot?: boolean
  pidsLimit?: number
  metadata?: Record<string, unknown>
  /** Wait until the sandbox runs (default true). */
  wait?: boolean
  /** How long to wait (default 180000 ms); a sandbox that does not start in time is removed. */
  startTimeoutMs?: number
}

export interface ListFilters {
  /** `active` (default), `running`, `paused`, `ended` or `all`. */
  status?: string
  region?: string
  runtime?: string
  metadata?: Record<string, string>
  page?: number
  perPage?: number
  /** Every organization's sandboxes (platform administrators). */
  allTenants?: boolean
}

export interface ExecOptions {
  stdin?: string | Uint8Array
  /** Seconds (default 60). */
  timeout?: number
  env?: Record<string, string>
  cwd?: string
  signal?: AbortSignal
}

/** Options of `execWs()` / `runCodeWs()`: the command's options plus its terminal. */
export interface ExecWsOptions extends ExecOptions {
  /** Run the command on a terminal (TTY): stdout and stderr then both arrive as `stdout`. */
  tty?: boolean
  /** Initial terminal size with `tty` (1–1000; default 80 × 24). */
  cols?: number
  rows?: number
  /**
   * Which WebSocket client connects: the runtime's global `WebSocket` (`'global'`), the SDK's own
   * on node:http / node:https (`'builtin'`), or the global one when the runtime has it (`'auto'`,
   * the default).
   */
  transport?: 'auto' | 'global' | 'builtin'
}

export interface RunWsOptions extends ExecWsOptions {
  /** `python`, `javascript`, `typescript`, `bash`, `sh`, `php`, `ruby`, `go` or an exec runtime id. */
  language?: string
}

export interface RunOptions {
  /** `python`, `javascript`, `typescript`, `bash`, `sh`, `php`, `ruby`, `go` or an exec runtime id. */
  language?: string
  timeout?: number
  env?: Record<string, string>
  cwd?: string
  signal?: AbortSignal
}

export interface ExecResult {
  exitCode: number
  stdout: string
  stderr: string
  stdoutBytes: Uint8Array
  stderrBytes: Uint8Array
  timedOut: boolean
  durationMs: number
  truncated: boolean
  /** `exitCode === 0` and not timed out. */
  ok: boolean
}

/** The end of a command run over the exec WebSocket. */
export interface ExecWsResult extends ExecResult {
  /** Stopped by `kill()`. */
  killed: boolean
  execId: string
  /** The command ran on a terminal. */
  tty: boolean
}

export interface FileChange {
  path: string
  size: number
}

/** An image the code saved under `/output`. */
export interface RichOutput {
  path: string
  mime: string
  size: number
  data: Uint8Array
  dataBase64: string
}

export interface RunResult extends ExecResult {
  language: string
  filesChanged: FileChange[]
  outputs: RichOutput[]
}

export interface OutputEvent {
  type: 'stdout' | 'stderr'
  data: Uint8Array
  /** `data` decoded as UTF-8. */
  text: string
  execId: string | null
}

export interface ExitEvent {
  type: 'exit'
  exitCode: number
  timedOut: boolean
  durationMs: number
  truncated: boolean
  /** Exec WebSocket only: the command was stopped by `kill()` (or a client that went away). */
  killed?: boolean
  execId: string | null
}

/** Only from `runCodeStream()`: the structured result (stdout / stderr were streamed). */
export interface ResultEvent {
  type: 'result'
  result: RunResult
  execId: string | null
}

export type ExecEvent = OutputEvent | ExitEvent | ResultEvent

export interface FileEntry {
  name: string
  path: string
  type: 'file' | 'dir' | 'symlink' | 'other' | string
  size: number
  mode: string | null
  modifiedAt: string | null
}

export interface SandboxProcess {
  /** RunR's process id (used in the API paths). */
  id: string
  sandboxId: string
  /** The OS process id inside the sandbox. */
  pid: number | null
  command: string
  cwd: string | null
  status: 'running' | 'exited' | 'killed' | string
  exitCode: number | null
  startedAt: string | null
  endedAt: string | null
}

export interface ProcessLogs {
  stdout: string
  stderr: string
  truncated: boolean
}

export interface StartProcessOptions {
  env?: Record<string, string>
  cwd?: string
}

export type Signal = 'TERM' | 'KILL' | 'INT' | 'HUP' | 'QUIT' | 'USR1' | 'USR2'

export interface SandboxLogs {
  events: Array<{ id: string; type: string; message: string; data: unknown; user_id: string | null; created_at: string }>
  pod?: { phase: string; conditions: unknown[]; events: unknown[]; log: string }
}

export interface TerminalSession {
  session_id: string
  url: string
}
