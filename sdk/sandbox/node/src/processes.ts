import type { SandboxContext } from './context.js'
import { toProcess, toProcessLogs } from './convert.js'
import { unwrap } from './http.js'
import type { ExecEvent, ProcessLogs, SandboxProcess, Signal, StartProcessOptions } from './types.js'

/** Background processes of a sandbox (`sandbox.processes`). */
export class Processes {
  constructor(private readonly sandbox: SandboxContext) {}

  private processPath(id: string, suffix = ''): string {
    return this.sandbox.path(`/processes/${encodeURIComponent(id)}${suffix}`)
  }

  /** Start a command in the background; it keeps running after the call returns. */
  async start(cmd: string | string[], options: StartProcessOptions = {}): Promise<SandboxProcess> {
    const body: Record<string, unknown> = { cmd: typeof cmd === 'string' ? cmd : cmd.map(String) }
    if (options.env !== undefined) body.env = options.env
    if (options.cwd !== undefined) body.cwd = options.cwd
    return toProcess(unwrap(await this.sandbox.http.json('POST', this.sandbox.path('/processes'), { json: body })))
  }

  async list(): Promise<SandboxProcess[]> {
    const data = unwrap<Record<string, unknown>[]>(await this.sandbox.http.json('GET', this.sandbox.path('/processes')))
    return (data ?? []).map(toProcess)
  }

  async get(id: string): Promise<SandboxProcess> {
    return toProcess(unwrap(await this.sandbox.http.json('GET', this.processPath(id))))
  }

  /** Output written so far (the tail of `tailBytes`, default 64 KiB). */
  async logs(id: string, options: { tailBytes?: number } = {}): Promise<ProcessLogs> {
    return toProcessLogs(unwrap(await this.sandbox.http.json('GET', this.processPath(id, '/logs'), {
      query: { tail_bytes: options.tailBytes },
    })))
  }

  /** Stream the output until the process ends (`stdout` / `stderr` events, then `exit`). */
  follow(id: string, options: { signal?: AbortSignal } = {}): AsyncIterable<ExecEvent> {
    return this.sandbox.stream('GET', this.processPath(id, '/logs'), undefined, { follow: 1 }, options.signal)
  }

  async kill(id: string, signal: Signal | string = 'TERM'): Promise<SandboxProcess> {
    return toProcess(unwrap(await this.sandbox.http.json('DELETE', this.processPath(id), {
      query: { signal: signal.toUpperCase() },
    })))
  }
}
