/**
 * An in-memory fake of the RunR Sandbox API for the SDK tests (node:http on 127.0.0.1, random
 * port). Same behaviour as the Python SDK's fake:
 *  - create: 201 running; metadata.slow = "<n>" → 202 pending, running after n GETs;
 *    metadata.never stays pending; metadata.fail fails; runtime "quota" → 422 quota_concurrent.
 *  - exec: "echo X", "exit N", "binary", "cat" (stdin), "printenv K", "sleep" (timeout), "error"
 *    (stream error event), "multi" (stream with a comment and a data_base64 chunk).
 *  - files: an in-memory file system per sandbox; archives are real tar files.
 *  - exec WebSocket (POST …/exec/ws, then /ws/sandbox-exec?token=…): "cat" echoes each stdin
 *    frame and exits at stdin_close; "sleep" runs until kill (exit 137, killed); "fail" answers an
 *    error frame; "drop" ends the connection without exit; "fragmented" sends a ping, a stdout
 *    message in three frames and one of 70000 bytes; anything else answers like exec. Every
 *    client frame is recorded in `execSockets`.
 */
import { createServer, type IncomingMessage, type Server, type ServerResponse } from 'node:http'
import type { AddressInfo } from 'node:net'
import type { Duplex } from 'node:stream'
import { Buffer } from 'node:buffer'

import { createTar, readTar } from '../src/tar.js'
import { acceptUpgrade, ServerSocket } from './ws-server.js'

export const TOKEN = 'test-token'
export const PNG = new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0, 0, 0, 0, 0, 0, 0, 0])

type Json = Record<string, any>

export interface RecordedRequest {
  method: string
  path: string
  query: Record<string, string>
  headers: Record<string, string>
  json: Json | null
  raw: Buffer
}

const now = (): string => new Date().toISOString().replace(/\.\d+Z$/, 'Z')

function sandboxObject(id: string, body: Json, status: string): Json {
  const network = body.network ?? 'none'
  return {
    id,
    status,
    region: { id: 'r1', name: 'eu-1' },
    runtime: body.runtime ?? 'python',
    runtime_version: body.version ?? '3.14',
    image: body.image ?? 'ghcr.io/brf-tech/runr/exec-python:3.14',
    sandbox_class: 'gvisor',
    isolation: 'gvisor',
    resources: { cpu: '500m', cpu_m: 500, memory_mb: 512, disk_mb: 1024, pids_limit: 256 },
    network: typeof network === 'object'
      ? { mode: 'allowlist', egress_allow: [...(network.egress_allow ?? [])] }
      : { mode: 'none', egress_allow: [] },
    read_only_root: true,
    env_keys: Object.keys(body.env ?? {}).sort(),
    metadata: body.metadata ?? {},
    ttl_seconds: body.ttl_seconds ?? 900,
    idle_timeout_seconds: body.idle_timeout ?? 600,
    expires_at: now(),
    idle_expires_at: now(),
    last_activity_at: now(),
    created_at: now(),
    started_at: status === 'running' ? now() : null,
    ended_at: null,
    end_reason: null,
    from_pool: false,
    start_ms: 100,
    error: null,
    created_by: '1',
    tenant_id: null,
  }
}

/** One exec WebSocket the fake served. */
export interface ExecSocketLog {
  token: string
  body: Json
  /** The client's text frames, decoded. */
  messages: Json[]
  socket: ServerSocket
  /** The connection's URL as requested (path and query). */
  url: string
  headers: Record<string, string | string[] | undefined>
}

export class FakeApi {
  readonly sandboxes = new Map<string, Json>()
  /** Exec WebSocket tickets not used yet (token → request body). */
  readonly execTickets = new Map<string, Json>()
  readonly execSockets: ExecSocketLog[] = []
  /** Answer like a server without the tty option: no `tty` in the ticket and the start frame. */
  legacyExecWs = false
  /** Answer the ticket's url as a full ws:// URL instead of a path. */
  absoluteExecWsUrl = false
  /** Hand out tickets the WebSocket then refuses as expired. */
  expireExecTickets = false
  readonly fs = new Map<string, Map<string, Uint8Array>>()
  readonly processes = new Map<string, Map<string, Json>>()
  readonly pendingPolls = new Map<string, number>()
  readonly requests: RecordedRequest[] = []
  private counter = 0
  private server: Server | null = null
  private readonly upgraded = new Set<Duplex>()
  url = ''

  async start(): Promise<this> {
    this.server = createServer((req, res) => {
      void this.handle(req, res).catch((error: unknown) => {
        if (!res.headersSent) this.error(res, 500, String(error))
        else res.end()
      })
    })
    this.server.on('upgrade', (req: IncomingMessage, socket: Duplex, head: Buffer) => {
      // Upgraded connections are no longer the HTTP server's: stop() ends them itself
      this.upgraded.add(socket)
      socket.on('close', () => this.upgraded.delete(socket))
      this.upgrade(req, socket, head)
    })
    await new Promise<void>((resolve) => this.server!.listen(0, '127.0.0.1', resolve))
    const address = this.server.address() as AddressInfo
    this.url = `http://127.0.0.1:${address.port}`
    return this
  }

  async stop(): Promise<void> {
    if (!this.server) return
    for (const socket of this.upgraded) socket.destroy()
    this.server.closeAllConnections?.()
    await new Promise<void>((resolve) => this.server!.close(() => resolve()))
  }

  calls(method?: string, path?: RegExp): RecordedRequest[] {
    return this.requests.filter((r) => (!method || r.method === method) && (!path || path.test(r.path)))
  }

  private nextId(prefix = 'sbx'): string {
    this.counter += 1
    return `${prefix}${String(this.counter).padStart(4, '0')}`
  }

  // ── plumbing ──────────────────────────────────────────────────────────────

  private json(res: ServerResponse, payload: unknown, status = 200): void {
    const raw = Buffer.from(JSON.stringify(payload))
    res.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': raw.length })
    res.end(raw)
  }

  private bytes(res: ServerResponse, raw: Uint8Array, type: string): void {
    res.writeHead(200, { 'Content-Type': type, 'Content-Length': raw.length })
    res.end(Buffer.from(raw))
  }

  private error(res: ServerResponse, status: number, message: string, reason?: string, extra: Json = {}): void {
    this.json(res, { message, ...(reason ? { reason } : {}), ...extra }, status)
  }

  private sseStart(res: ServerResponse): void {
    res.writeHead(200, { 'Content-Type': 'text/event-stream', 'Cache-Control': 'no-cache' })
  }

  /** One event, written in several small pieces so the client must reassemble lines. */
  private async sse(res: ServerResponse, event: string, data: unknown, split = true): Promise<void> {
    const frame = Buffer.from(`event: ${event}\ndata: ${JSON.stringify(data)}\n\n`)
    if (!split) {
      res.write(frame)
      return
    }
    for (let i = 0; i < frame.length; i += 7) {
      res.write(frame.subarray(i, i + 7))
      await new Promise((resolve) => setImmediate(resolve))
    }
  }

  private async readBody(req: IncomingMessage): Promise<Buffer> {
    const chunks: Buffer[] = []
    for await (const chunk of req) chunks.push(chunk as Buffer)
    return Buffer.concat(chunks)
  }

  private async handle(req: IncomingMessage, res: ServerResponse): Promise<void> {
    const url = new URL(req.url ?? '/', 'http://fake')
    const query: Record<string, string> = {}
    url.searchParams.forEach((value, key) => { query[key] = value })
    const raw = await this.readBody(req)
    const contentType = String(req.headers['content-type'] ?? '').split(';')[0]!.trim()
    const body: Json = raw.length && contentType === 'application/json' ? JSON.parse(raw.toString('utf8')) : {}
    const headers: Record<string, string> = {}
    for (const [key, value] of Object.entries(req.headers)) headers[key] = Array.isArray(value) ? value.join(', ') : String(value)
    this.requests.push({
      method: req.method ?? 'GET',
      path: url.pathname,
      query,
      headers,
      json: contentType === 'application/json' ? body : null,
      raw,
    })
    if (req.headers.authorization !== `Bearer ${TOKEN}`) return this.error(res, 401, 'Unauthenticated.')
    await this.route(req.method ?? 'GET', url.pathname, query, body, raw, contentType, req, res)
  }

  // ── routes ────────────────────────────────────────────────────────────────

  private async route(method: string, path: string, query: Record<string, string>, body: Json, raw: Buffer, contentType: string, req: IncomingMessage, res: ServerResponse): Promise<void> {
    if (path === '/api/sandboxes') {
      if (method === 'POST') return this.create(res, body)
      if (method === 'GET') return this.list(res, query)
    }
    const match = /^\/api\/sandboxes\/([^/]+)(\/.*)?$/.exec(path)
    if (!match) return this.error(res, 404, 'Not found.')
    const id = decodeURIComponent(match[1]!)
    const rest = match[2] ?? ''
    const sandbox = this.sandboxes.get(id)
    if (!sandbox) return this.error(res, 404, 'Sandbox not found.')

    if (rest === '' && method === 'GET') {
      const remaining = this.pendingPolls.get(id)
      if (remaining !== undefined) {
        if (remaining <= 1) {
          this.pendingPolls.delete(id)
          sandbox.status = 'running'
          sandbox.started_at = now()
        } else {
          this.pendingPolls.set(id, remaining - 1)
        }
      }
      if (sandbox.metadata.fail && sandbox.status === 'pending') {
        sandbox.status = 'failed'
        sandbox.error = 'ImagePullBackOff: image not found'
      }
      return this.json(res, { data: sandbox })
    }
    if (rest === '' && method === 'DELETE') {
      if (sandbox.status === 'terminated' || sandbox.status === 'failed') {
        return this.error(res, 409, 'The sandbox has ended.', 'not_running', { status: sandbox.status })
      }
      Object.assign(sandbox, { status: 'terminated', end_reason: 'deleted', ended_at: now() })
      return this.json(res, { data: sandbox })
    }
    if (rest === '/timeout' && method === 'POST') {
      sandbox.ttl_seconds = Number(body.ttl_seconds ?? 0)
      return this.json(res, { data: sandbox })
    }
    if (rest === '/keepalive' && method === 'POST') {
      sandbox.last_activity_at = now()
      return this.json(res, { data: sandbox })
    }
    if (rest === '/pause' && method === 'POST') {
      sandbox.status = 'paused'
      return this.json(res, { data: sandbox })
    }
    if (rest === '/resume' && method === 'POST') {
      sandbox.status = 'running'
      return this.json(res, { data: sandbox })
    }
    if (rest === '/network' && method === 'PUT') {
      const network = body.network
      sandbox.network = network && typeof network === 'object'
        ? { mode: 'allowlist', egress_allow: [...(network.egress_allow ?? [])] }
        : { mode: 'none', egress_allow: [] }
      return this.json(res, { data: sandbox })
    }
    if (rest === '/logs' && method === 'GET') {
      const data: Json = { events: [{ id: 'e1', type: 'created', message: 'Sandbox created', data: {}, user_id: '1', created_at: now() }] }
      if (query.pod) data.pod = { phase: 'Running', conditions: [], events: [], log: '' }
      return this.json(res, { data })
    }
    if (rest === '/terminal' && method === 'POST') {
      return this.json(res, { data: { session_id: 'sess-1', url: '/ws/shell?token=sess-1' } })
    }
    if (sandbox.status !== 'running') {
      return this.error(res, 409, 'The sandbox is not running.', 'not_running', { status: sandbox.status })
    }
    const stream = Boolean(body.stream) || String(req.headers.accept ?? '').includes('text/event-stream')
    if (rest === '/exec' && method === 'POST') return this.exec(res, id, body, stream)
    if (rest === '/run' && method === 'POST') return this.run(res, body, stream)
    if (rest === '/exec/ws' && method === 'POST') return this.execWsTicket(res, body)
    if (rest.startsWith('/files')) return this.files(res, id, method, rest, query, body, raw, contentType)
    if (rest.startsWith('/processes')) return this.processRoutes(res, id, method, rest, query, body)
    return this.error(res, 404, 'Not found.')
  }

  private create(res: ServerResponse, body: Json): void {
    if (body.runtime === 'quota') return this.error(res, 422, 'The organization runs its maximum of 2 sandboxes.', 'quota_concurrent')
    if (body.runtime === 'unknown') return this.error(res, 422, 'Unknown runtime.', 'runtime_unavailable', { errors: { runtime: ['Unknown runtime.'] } })
    const metadata = body.metadata ?? {}
    const id = this.nextId()
    const pending = Boolean(metadata.slow || metadata.never || metadata.fail)
    const sandbox = sandboxObject(id, body, pending ? 'pending' : 'running')
    this.sandboxes.set(id, sandbox)
    const files = new Map<string, Uint8Array>()
    for (const entry of body.files ?? []) files.set(entry.path, new Uint8Array(Buffer.from(entry.content_base64, 'base64')))
    this.fs.set(id, files)
    this.processes.set(id, new Map())
    if (metadata.slow) this.pendingPolls.set(id, Number(metadata.slow))
    this.json(res, { data: sandbox }, pending ? 202 : 201)
  }

  private list(res: ServerResponse, query: Record<string, string>): void {
    const status = query.status ?? 'active'
    let items = [...this.sandboxes.values()]
    if (status === 'active') items = items.filter((s) => ['pending', 'running', 'paused'].includes(s.status))
    else if (status !== 'all') items = items.filter((s) => s.status === status)
    for (const [key, value] of Object.entries(query)) {
      const m = /^metadata\[(.+)\]$/.exec(key)
      if (m) items = items.filter((s) => String(s.metadata[m[1]!]) === value)
    }
    this.json(res, { data: items, meta: { total: items.length, page: 1, per_page: 50 } })
  }

  // ── exec / run ────────────────────────────────────────────────────────────

  private static command(body: Json): string {
    return Array.isArray(body.cmd) ? body.cmd.join(' ') : String(body.cmd ?? '')
  }

  private outcome(body: Json): [Buffer, Buffer, number, boolean] {
    const cmd = FakeApi.command(body)
    const env: Json = body.env ?? {}
    if (cmd.startsWith('echo ')) return [Buffer.from(`${cmd.slice(5)}\n`), Buffer.alloc(0), 0, false]
    if (cmd.startsWith('exit ')) {
      const code = Number(cmd.slice(5))
      return [Buffer.alloc(0), Buffer.from(`exit ${code}\n`), code, false]
    }
    if (cmd === 'binary') return [Buffer.from([0xff, 0xfe, 0x00, 0x6f, 0x6b]), Buffer.alloc(0), 0, false]
    if (cmd === 'cat') {
      return [body.stdin_base64 ? Buffer.from(body.stdin_base64, 'base64') : Buffer.from(String(body.stdin ?? '')), Buffer.alloc(0), 0, false]
    }
    if (cmd.startsWith('printenv ')) return [Buffer.from(`${env[cmd.slice(9)] ?? ''}\n`), Buffer.alloc(0), 0, false]
    if (cmd === 'sleep') return [Buffer.alloc(0), Buffer.alloc(0), 137, true]
    return [Buffer.from(`ran: ${cmd}\n`), Buffer.alloc(0), 0, false]
  }

  private async exec(res: ServerResponse, id: string, body: Json, stream: boolean): Promise<void> {
    const cmd = FakeApi.command(body)
    if (!stream) {
      const [stdout, stderr, code, timedOut] = this.outcome(body)
      const data: Json = { exit_code: code, timed_out: timedOut, duration_ms: 5, truncated: false }
      for (const [key, value] of [['stdout', stdout], ['stderr', stderr]] as const) {
        const text = value.toString('utf8')
        if (Buffer.from(text, 'utf8').equals(value)) {
          data[key] = text
        } else {
          data[key] = ''
          data[`${key}_base64`] = value.toString('base64')
        }
      }
      return this.json(res, { data })
    }
    this.sseStart(res)
    await this.sse(res, 'start', { exec_id: 'exec-1', sandbox_id: id })
    if (cmd === 'error') {
      await this.sse(res, 'error', { message: 'The sandbox ended during the command.', reason: 'not_running' })
      res.end()
      return
    }
    if (cmd === 'multi') {
      await this.sse(res, 'stdout', { data: 'line 1\n' })
      res.write(': ping\n\n')
      await this.sse(res, 'stderr', { data: 'warning\n' })
      await this.sse(res, 'stdout', { data_base64: Buffer.from([0xff, 0xfe]).toString('base64') })
      await this.sse(res, 'stdout', { data: 'héllo ✓\n' })
      await this.sse(res, 'exit', { exit_code: 3, timed_out: false, duration_ms: 21, truncated: false })
      res.end()
      return
    }
    const [stdout, stderr, code, timedOut] = this.outcome(body)
    if (stdout.length) await this.sse(res, 'stdout', { data: stdout.toString('utf8') })
    if (stderr.length) await this.sse(res, 'stderr', { data: stderr.toString('utf8') })
    await this.sse(res, 'exit', { exit_code: code, timed_out: timedOut, duration_ms: 5, truncated: false })
    res.end()
  }

  private async run(res: ServerResponse, body: Json, stream: boolean): Promise<void> {
    const failed = String(body.code ?? '').includes('throw')
    const result: Json = {
      language: body.language ?? 'python',
      exit_code: failed ? 1 : 0,
      stdout: failed ? '' : '42\n',
      stderr: failed ? 'Error: boom\n' : '',
      timed_out: false,
      duration_ms: 30,
      truncated: false,
      files_changed: [{ path: '/output/plot.png', size: PNG.length }, { path: '/workspace/data.csv', size: 12 }],
      outputs: [{ path: '/output/plot.png', mime: 'image/png', size: PNG.length, data_base64: Buffer.from(PNG).toString('base64') }],
    }
    if (!stream) return this.json(res, { data: result })
    this.sseStart(res)
    await this.sse(res, 'start', { exec_id: 'run-1', sandbox_id: 'x' })
    if (result.stdout) await this.sse(res, 'stdout', { data: result.stdout })
    if (result.stderr) await this.sse(res, 'stderr', { data: result.stderr })
    await this.sse(res, 'exit', { exit_code: result.exit_code, timed_out: false, duration_ms: 30, truncated: false })
    const { stdout: _o, stderr: _e, ...final } = result
    await this.sse(res, 'result', final, false)
    res.end()
  }

  // ── exec WebSocket ────────────────────────────────────────────────────────

  private execWsTicket(res: ServerResponse, body: Json): void {
    if ('stream' in body) return this.error(res, 422, 'The exec WebSocket takes no stream field.')
    if (body.tty !== undefined && (typeof body.cols !== 'number' || typeof body.rows !== 'number')) {
      return this.error(res, 422, 'cols and rows are required with tty.')
    }
    const token = `tok-${this.nextId('ws')}`
    if (!this.expireExecTickets) this.execTickets.set(token, body)
    const path = `/ws/sandbox-exec?token=${token}`
    const data: Json = {
      token,
      url: this.absoluteExecWsUrl ? `${this.url.replace(/^http/, 'ws')}${path}` : path,
      exec_id: `exec-${token}`,
      expires_in: 60,
    }
    if (!this.legacyExecWs) data.tty = body.tty === true
    this.json(res, { data })
  }

  private upgrade(req: IncomingMessage, socket: Duplex, head: Buffer): void {
    const url = new URL(req.url ?? '/', 'http://fake')
    if (url.pathname !== '/ws/sandbox-exec' || !acceptUpgrade(req, socket)) {
      if (!socket.destroyed) socket.end('HTTP/1.1 404 Not Found\r\nConnection: close\r\n\r\n')
      return
    }
    const token = url.searchParams.get('token') ?? ''
    const body = this.execTickets.get(token)
    this.execTickets.delete(token) // one-time
    const log: ExecSocketLog = { token, body: body ?? {}, messages: [], socket: null as unknown as ServerSocket, url: req.url ?? '', headers: req.headers }
    log.socket = new ServerSocket(socket, head, (text) => {
      const message = JSON.parse(text) as Json
      log.messages.push(message)
      this.execWsMessage(log, message)
    })
    this.execSockets.push(log)
    const ws = log.socket
    if (!body) {
      ws.sendJson({ type: 'error', message: 'The exec session has expired. Request a new token.' })
      ws.close()
      return
    }
    const start: Json = { type: 'start', exec_id: `exec-${token}` }
    if (!this.legacyExecWs) start.tty = body.tty === true
    ws.sendJson(start)
    const cmd = FakeApi.command(body)
    if (body.stdin !== undefined || body.stdin_base64 !== undefined) {
      if (cmd === 'cat') {
        const initial = body.stdin_base64 ? Buffer.from(body.stdin_base64, 'base64') : Buffer.from(String(body.stdin))
        ws.sendJson({ type: 'stdout', data: initial.toString('base64') })
      }
    }
    if (cmd === 'cat' || cmd === 'sleep') return
    if (cmd === 'fail') {
      ws.sendJson({ type: 'error', message: 'The sandbox ended during the command.' })
      ws.close()
      return
    }
    if (cmd === 'drop') {
      setImmediate(() => ws.drop())
      return
    }
    if (cmd === 'fragmented') {
      ws.ping('heartbeat')
      ws.sendFragmented(JSON.stringify({ type: 'stdout', data: Buffer.from('part one, part two\n').toString('base64') }), 3)
      ws.sendJson({ type: 'stdout', data: Buffer.alloc(70000, 0x61).toString('base64') })
      ws.sendJson({ type: 'exit', exit_code: 0, timed_out: false, duration_ms: 9, killed: false })
      ws.close()
      return
    }
    const [stdout, stderr, code, timedOut] = this.outcome(body)
    if (stdout.length) ws.sendJson({ type: 'stdout', data: stdout.toString('base64') })
    if (stderr.length) ws.sendJson({ type: 'stderr', data: stderr.toString('base64') })
    ws.sendJson({ type: 'exit', exit_code: code, timed_out: timedOut, duration_ms: 5, killed: false })
    ws.close()
  }

  private execWsMessage(log: ExecSocketLog, message: Json): void {
    const ws = log.socket
    const cmd = FakeApi.command(log.body)
    switch (message.type) {
      case 'stdin':
        if (cmd === 'cat') ws.sendJson({ type: 'stdout', data: String(message.data) })
        return
      case 'stdin_close':
        if (cmd === 'cat') {
          ws.sendJson({ type: 'exit', exit_code: 0, timed_out: false, duration_ms: 7, killed: false })
          ws.close()
        }
        return
      case 'kill':
        ws.sendJson({ type: 'exit', exit_code: 137, timed_out: false, duration_ms: 8, killed: true })
        ws.close()
        return
      default:
        // resize and unknown frames: recorded only
    }
  }

  // ── files ─────────────────────────────────────────────────────────────────

  private files(res: ServerResponse, id: string, method: string, rest: string, query: Record<string, string>, body: Json, raw: Buffer, contentType: string): void {
    const fs = this.fs.get(id)!
    const path = normalize(query.path ?? body.path ?? '/workspace')
    const entry = (p: string, content: Uint8Array): Json => ({ name: p.split('/').pop(), path: p, type: 'file', size: content.length, mode: '0644', modified_at: now() })

    if (rest === '/files' && method === 'GET') {
      if (fs.has(path)) return this.json(res, { data: { path, is_dir: false, entries: [entry(path, fs.get(path)!)], truncated: false } })
      const prefix = `${path.replace(/\/$/, '')}/`
      const children = new Map<string, Json>()
      for (const [name, content] of fs) {
        if (!name.startsWith(prefix)) continue
        const [first, ...more] = name.slice(prefix.length).split('/')
        const child = prefix + first
        if (more.length === 0) children.set(child, entry(child, content))
        else if (!children.has(child)) children.set(child, { name: first, path: child, type: 'dir', size: 0, mode: '0755', modified_at: now() })
      }
      if (children.size === 0 && !['/workspace', '/output', '/tmp'].includes(path)) return this.error(res, 404, 'No such file or directory.')
      const entries = [...children.values()].sort((a, b) => String(a.name).localeCompare(String(b.name)))
      return this.json(res, { data: { path, is_dir: true, entries, truncated: false } })
    }
    if (rest === '/files' && method === 'DELETE') {
      if (fs.has(path)) {
        fs.delete(path)
      } else {
        const inside = [...fs.keys()].filter((n) => n.startsWith(`${path}/`))
        if (inside.length === 0) return this.error(res, 404, 'No such file or directory.')
        if (query.recursive !== '1') return this.error(res, 422, 'The directory is not empty; pass recursive=1.')
        for (const name of inside) fs.delete(name)
      }
      return this.json(res, { data: { path, deleted: true } })
    }
    if (rest === '/files/mkdir' && method === 'POST') {
      if (!fs.has(`${path}/.keep`)) fs.set(`${path}/.keep`, new Uint8Array(0))
      return this.json(res, { data: { path } })
    }
    if (rest === '/files/content' && method === 'GET') {
      const content = fs.get(path)
      if (!content) return this.error(res, 404, 'No such file or directory.')
      if (query.format === 'json') return this.json(res, { data: { path, size: content.length, content_base64: Buffer.from(content).toString('base64') } })
      return this.bytes(res, content, 'application/octet-stream')
    }
    if (rest === '/files/content' && method === 'PUT') {
      const content = contentType === 'application/json' ? new Uint8Array(Buffer.from(String(body.content_base64 ?? ''), 'base64')) : new Uint8Array(raw)
      if (content.length > 1024 * 1024) return this.error(res, 413, 'The file is larger than the upload limit.', 'file_too_large')
      fs.set(path, content)
      return this.json(res, { data: { path, size: content.length } })
    }
    if (rest === '/files/archive' && method === 'GET') {
      const prefix = `${path.replace(/\/$/, '')}/`
      const entries = [...fs.entries()]
        .filter(([name]) => name.startsWith(prefix) && !name.endsWith('/.keep'))
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([name, content]) => ({ path: `./${name.slice(prefix.length)}`, type: 'file' as const, data: content }))
      return this.bytes(res, createTar(entries), 'application/x-tar')
    }
    if (rest === '/files/archive' && method === 'PUT') {
      for (const item of readTar(new Uint8Array(raw))) {
        if (item.type === 'file') fs.set(normalize(`${path}/${item.path}`), item.data ?? new Uint8Array(0))
      }
      return this.json(res, { data: { path, bytes: raw.length } })
    }
    return this.error(res, 404, 'Not found.')
  }

  // ── processes ─────────────────────────────────────────────────────────────

  private async processRoutes(res: ServerResponse, id: string, method: string, rest: string, query: Record<string, string>, body: Json): Promise<void> {
    const procs = this.processes.get(id)!
    if (rest === '/processes' && method === 'POST') {
      const pid = this.nextId('proc')
      const process: Json = {
        id: pid, sandbox_id: id, pid: 100 + procs.size, command: FakeApi.command(body),
        cwd: body.cwd ?? '/workspace', status: 'running', exit_code: null, started_at: now(), ended_at: null,
      }
      procs.set(pid, process)
      return this.json(res, { data: process }, 201)
    }
    if (rest === '/processes' && method === 'GET') return this.json(res, { data: [...procs.values()] })
    const match = /^\/processes\/([^/]+)(\/logs)?$/.exec(rest)
    const process = match ? procs.get(decodeURIComponent(match[1]!)) : undefined
    if (!match || !process) return this.error(res, 404, 'Process not found.')
    if (match[2]) {
      if (query.follow) {
        this.sseStart(res)
        await this.sse(res, 'start', { exec_id: process.id, sandbox_id: id })
        await this.sse(res, 'stdout', { data: 'serving on :8000\n' })
        await this.sse(res, 'exit', { exit_code: 0, timed_out: false, duration_ms: 1000, truncated: false })
        res.end()
        return
      }
      const tail = Number(query.tail_bytes ?? 65536)
      const stdout = 'serving on :8000\n'.slice(-tail)
      return this.json(res, { data: { stdout, stderr: '', truncated: tail < 17 } })
    }
    if (method === 'GET') return this.json(res, { data: process })
    if (method === 'DELETE') {
      Object.assign(process, { status: 'killed', ended_at: now(), signal: query.signal })
      return this.json(res, { data: process })
    }
    return this.error(res, 404, 'Not found.')
  }
}

function normalize(path: string): string {
  const parts: string[] = []
  for (const part of path.split('/')) {
    if (part === '' || part === '.') continue
    if (part === '..') parts.pop()
    else parts.push(part)
  }
  return `/${parts.join('/')}`
}
