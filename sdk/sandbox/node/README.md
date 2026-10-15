# @runr/sandbox

TypeScript client for the **RunR Sandbox API**: start isolated sandboxes on your own Kubernetes
cluster, run commands and code in them, move files in and out, and remove them again. Every sandbox
is a pod under the strongest isolation the region offers (Kata Containers or gVisor), without
network access unless you allow it.

```bash
npm install @runr/sandbox
```

Node.js 18 or newer, ESM, no runtime dependencies (it uses the built-in `fetch`).

## Configuration

| Option | Environment variable | Meaning |
|---|---|---|
| `apiUrl` | `RUNR_API_URL` | your RunR address, e.g. `https://runr.example.com` (`/api` is appended) |
| `apiKey` | `RUNR_API_KEY` | an API token (Settings → API Tokens) |
| `tenantId` | `RUNR_TENANT_ID` | the organization, on multi-tenant installations (sent as `X-Tenant-ID`) |
| `timeoutMs` | | HTTP timeout per request (default 300000; streams have none) |
| `fetch` | | a fetch implementation (default: the global one) |

## Quick start

```ts
import { Sandbox } from '@runr/sandbox'

await using sandbox = await Sandbox.create({ runtime: 'python', ttlSeconds: 600 })
const result = await sandbox.exec('python3 --version')
console.log(result.exitCode, result.stdout)
// leaving the scope removes the sandbox (or call sandbox.close())
```

`await using` needs TypeScript 5.2+ (`"lib": ["ESNext.Disposable"]`); the package defines
`Symbol.asyncDispose` on Node versions that lack it. Without it:

```ts
const sandbox = await Sandbox.create({ runtime: 'python' })
try {
  // ...
} finally {
  await sandbox.close()
}
```

`Sandbox.create()` options:

| Option | Example | Meaning |
|---|---|---|
| `runtime`, `version` | `'python'`, `'3.14'` | a runtime of the RunR catalog (default `python`, its default version) |
| `image` | `'docker.io/library/alpine:3.21'` | any image instead of a runtime (it needs `/bin/sh`; `tar` for directory transfer) |
| `cpu`, `memory`, `disk` | `'500m'`, `512`, `'1Gi'` | resources; memory and disk in MiB or as a quantity |
| `ttlSeconds`, `idleTimeout` | `900`, `300` | lifetime and idle timeout in seconds |
| `network` | `'none'`, `['pypi.org:443', '10.0.0.0/8']` | no traffic (default) or an egress allowlist of hosts / CIDRs |
| `env` | `{ API_KEY: '...' }` | environment of every command |
| `files` | `{ '/workspace/main.py': 'print(1)' }` | files written before the sandbox is returned (`string` or `Uint8Array`) |
| `region`, `sandboxClass` | `'eu-1'`, `'gvisor'` | where and under which isolation (`auto`, `kata`, `kata-fc`, `gvisor`, …) |
| `readOnlyRoot`, `pidsLimit` | `true`, `256` | hardening options |
| `metadata` | `{ agent: 'planner' }` | your labels, filterable in `Sandbox.list({ metadata })` |
| `wait`, `startTimeoutMs` | `true`, `180000` | wait until the sandbox runs (a sandbox that does not start in time is removed) |

Attach to an existing sandbox with `Sandbox.connect(id)` and list them with
`Sandbox.list({ status: 'active', metadata: { agent: 'planner' } })`.

## Commands

```ts
await sandbox.exec('ls -la /workspace')                  // shell string
await sandbox.exec(['python3', '-c', 'print(42)'])        // argv array
const result = await sandbox.exec('cat > in.txt', { stdin: 'data', timeout: 30, env: { A: '1' }, cwd: '/workspace' })

result.ok          // exitCode === 0 and not timed out
result.exitCode    // 137 when the timeout killed it
result.stdout      // text; result.stdoutBytes for the raw bytes
```

### Streaming

```ts
for await (const event of sandbox.execStream('pip install --user requests')) {
  if (event.type === 'stdout') process.stdout.write(event.data)
  else if (event.type === 'stderr') process.stderr.write(event.data)
  else if (event.type === 'exit') console.log('exit code', event.exitCode)
}
```

Output arrives as Server-Sent Events while the command runs. Pass `signal` (an `AbortSignal`) to stop
reading early; breaking out of the loop closes the stream as well.

## Running code

```ts
import { writeFile } from 'node:fs/promises'

const result = await sandbox.runCode(`
import matplotlib.pyplot as plt
plt.plot([1, 2, 3], [1, 4, 9])
plt.savefig("/output/plot.png")
print("done")
`, { language: 'python' })

console.log(result.stdout)            // "done\n"
console.log(result.filesChanged)      // [{ path: '/output/plot.png', size: ... }]
for (const output of result.outputs) {
  await writeFile('plot.png', output.data)   // images saved under /output
}
```

`language` is `python`, `javascript`, `typescript`, `bash`, `sh`, `php`, `ruby`, `go` or the id of a
runtime that runs code through an `exec` command; by default the sandbox's own runtime.
`runCodeStream()` streams the output and ends with a `result` event.

## Files

```ts
await sandbox.files.write('/workspace/data.csv', 'a,b\n1,2\n')
await sandbox.files.write('/workspace/run.sh', '#!/bin/sh\necho hi\n', { mode: 0o755 })
const bytes = await sandbox.files.read('/workspace/data.csv')         // Uint8Array
const text = await sandbox.files.readText('/workspace/data.csv')
for (const entry of await sandbox.files.list('/workspace')) console.log(entry.type, entry.size, entry.path)
await sandbox.files.mkdir('/workspace/src')
await sandbox.files.remove('/workspace/src', { recursive: true })

// Directories travel as one tar archive
await sandbox.files.uploadDirectory({ 'main.py': 'print(1)\n', 'pkg/util.py': '' }, '/workspace/project')
const files = await sandbox.files.downloadDirectory('/workspace/project')   // { 'main.py': Uint8Array, ... }
const tar = await sandbox.files.downloadArchive('/workspace/project')
await sandbox.files.uploadArchive(tar, '/workspace/copy')
```

`createTar()` and `readTar()` are exported for your own archives. Writable places are `/workspace`
(the working directory), `/output`, `/tmp` and `/home/sandbox`.

## Background processes

```ts
const server = await sandbox.processes.start('python3 -m http.server 8000', { cwd: '/workspace' })
console.log(await sandbox.processes.list())
console.log((await sandbox.processes.logs(server.id)).stdout)
for await (const event of sandbox.processes.follow(server.id)) {
  if (event.type !== 'exit' && event.type !== 'result') process.stdout.write(event.data)
}
await sandbox.processes.kill(server.id, 'TERM')
```

## Lifetime

```ts
await sandbox.setTimeout(1800)          // live 30 more minutes (capped by the region's maximum)
await sandbox.keepalive()               // reset the idle timer
await sandbox.pause(); await sandbox.resume()
await sandbox.setNetwork(['api.example.com:443'])
await sandbox.close()                   // remove now (idempotent)
```

## Example: an AI agent code interpreter

A tool handler for an LLM agent: one sandbox per conversation, the model's code runs in it, and
the tool returns what the code printed plus any charts it drew.

```ts
import { Sandbox } from '@runr/sandbox'

export const runPythonTool = {
  name: 'run_python',
  description: 'Run Python code in an isolated sandbox. Save charts to /output/*.png.',
  input_schema: {
    type: 'object',
    properties: { code: { type: 'string', description: 'Python source code' } },
    required: ['code'],
  },
}

export class CodeInterpreter implements AsyncDisposable {
  private constructor(private readonly sandbox: Sandbox) {}

  static async start(): Promise<CodeInterpreter> {
    const sandbox = await Sandbox.create({
      runtime: 'python',
      ttlSeconds: 3600,
      idleTimeout: 900,
      network: ['pypi.org:443', 'files.pythonhosted.org:443'], // pip only
      metadata: { purpose: 'code-interpreter' },
    })
    return new CodeInterpreter(sandbox)
  }

  /** Call this when the model invokes the tool; return the value as the tool result. */
  async handle(input: { code: string }) {
    const result = await this.sandbox.runCode(input.code, { language: 'python', timeout: 120 })
    return {
      exit_code: result.exitCode,
      timed_out: result.timedOut,
      stdout: result.stdout.slice(-20_000),
      stderr: result.stderr.slice(-20_000),
      files_changed: result.filesChanged.map((f) => f.path),
      images: result.outputs.map((o) => ({ path: o.path, media_type: o.mime, data: o.dataBase64 })),
    }
  }

  async [Symbol.asyncDispose]() {
    await this.sandbox.close()
  }
}

// In the agent loop
await using interpreter = await CodeInterpreter.start()
const observation = await interpreter.handle({ code: 'import math\nprint(math.factorial(20))' })
console.log(observation.stdout)
```

The code runs under the region's sandbox isolation, as a non-root user with no service-account
token, inside the CPU, memory, disk and process limits you set; with the allowlist above it can
reach the package index and nothing else.

## Errors

Every error is a `RunrSandboxError` (`status`, `message`, `reason`, `body`):

| Error | When |
|---|---|
| `AuthError` | 401 / 403 — no token, an invalid one, or a token without the ability |
| `NotFoundError` | 404 — unknown sandbox, process or file |
| `ConflictError` | 409 — the sandbox is not running (ended or paused) |
| `QuotaError` | 422 with a `quota_*` reason — e.g. `quota_concurrent`, `quota_cpu`, `quota_seconds` |
| `ValidationError` | other 422 answers — invalid options, unavailable region, runtime or sandbox class |
| `PayloadTooLargeError` | 413 — a file above the upload / download limit |
| `RateLimitError` | 429 — `retryAfter` holds the seconds to wait |
| `SandboxStartTimeoutError` | the sandbox did not run within `startTimeoutMs` |

## Development

```bash
npm install
npm run typecheck
npm test        # compiles to dist-test/ and runs node:test against an in-process fake API
npm run build   # dist/
```

## License

MIT
