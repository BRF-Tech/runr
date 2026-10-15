import { after, afterEach, before, describe, test } from 'node:test'
import assert from 'node:assert/strict'
import { Buffer } from 'node:buffer'

import {
  AuthError,
  ConflictError,
  NotFoundError,
  PayloadTooLargeError,
  QuotaError,
  RunrSandboxError,
  Sandbox,
  SandboxStartTimeoutError,
  ValidationError,
  createTar,
  type ExecEvent,
} from '../src/index.js'
import { FakeApi, PNG, TOKEN } from './fake-server.js'

let api: FakeApi
let opts: { apiUrl: string; apiKey: string }

before(async () => {
  api = await new FakeApi().start()
  opts = { apiUrl: api.url, apiKey: TOKEN }
})

after(async () => {
  await api.stop()
})

afterEach(() => {
  api.requests.length = 0
})

async function collect(events: AsyncIterable<ExecEvent>): Promise<ExecEvent[]> {
  const out: ExecEvent[] = []
  for await (const event of events) out.push(event)
  return out
}

describe('create / connect / list', () => {
  test('create sends the options and resolves with a running sandbox', async () => {
    const sandbox = await Sandbox.create({
      ...opts,
      runtime: 'python',
      version: '3.14',
      cpu: '500m',
      memory: 512,
      disk: '1Gi',
      ttlSeconds: 600,
      idleTimeout: 120,
      network: ['pypi.org:443', '10.0.0.0/8'],
      env: { KEY: 'v' },
      files: { '/workspace/main.py': 'print(1)\n', '/workspace/data.bin': new Uint8Array([0, 1]) },
      region: 'eu-1',
      sandboxClass: 'gvisor',
      readOnlyRoot: true,
      pidsLimit: 128,
      metadata: { agent: 'planner' },
    })
    try {
      assert.equal(sandbox.status, 'running')
      const [create] = api.calls('POST', /^\/api\/sandboxes$/)
      assert.ok(create)
      assert.deepEqual(create.json, {
        runtime: 'python',
        version: '3.14',
        cpu: '500m',
        memory: 512,
        disk: '1Gi',
        ttl_seconds: 600,
        idle_timeout: 120,
        network: { egress_allow: ['pypi.org:443', '10.0.0.0/8'] },
        env: { KEY: 'v' },
        files: [
          { path: '/workspace/main.py', content_base64: Buffer.from('print(1)\n').toString('base64') },
          { path: '/workspace/data.bin', content_base64: 'AAE=' },
        ],
        region: 'eu-1',
        sandbox_class: 'gvisor',
        read_only_root: true,
        pids_limit: 128,
        metadata: { agent: 'planner' },
        wait: true,
      })
      assert.equal(create.headers.authorization, `Bearer ${TOKEN}`)
      assert.equal(create.headers.accept, 'application/json')
      assert.match(create.headers['user-agent'] ?? '', /^runr-sandbox-node\//)
      assert.deepEqual(Buffer.from(api.fs.get(sandbox.id)!.get('/workspace/data.bin')!), Buffer.from([0, 1]))
    } finally {
      await sandbox.close()
    }
  })

  test('file specs with modes', async () => {
    const sandbox = await Sandbox.create({ ...opts, files: [{ path: '/workspace/run.sh', content: '#!/bin/sh\n', mode: 0o755 }] })
    await sandbox.close()
    const [create] = api.calls('POST', /^\/api\/sandboxes$/)
    assert.deepEqual(create!.json!.files, [{ path: '/workspace/run.sh', content_base64: Buffer.from('#!/bin/sh\n').toString('base64'), mode: '0755' }])
  })

  test('a 202 answer is polled until running', async () => {
    const sandbox = await Sandbox.create({ ...opts, metadata: { slow: '2' } })
    assert.equal(sandbox.status, 'running')
    assert.equal(api.calls('GET', new RegExp(`^/api/sandboxes/${sandbox.id}$`)).length, 2)
    await sandbox.close()
  })

  test('wait: false resolves with the pending sandbox', async () => {
    const sandbox = await Sandbox.create({ ...opts, metadata: { never: '1' }, wait: false })
    assert.equal(sandbox.status, 'pending')
    assert.equal(api.calls('POST', /^\/api\/sandboxes$/)[0]!.json!.wait, false)
    await sandbox.close()
  })

  test('a sandbox that does not start in time is removed', async () => {
    await assert.rejects(Sandbox.create({ ...opts, metadata: { never: '1' }, startTimeoutMs: 600 }), (error: unknown) => {
      assert.ok(error instanceof SandboxStartTimeoutError)
      assert.equal(error.reason, 'start_timeout')
      return true
    })
    assert.equal(api.calls('DELETE', /^\/api\/sandboxes\/sbx\d+$/).length, 1)
  })

  test('a sandbox that fails to start rejects', async () => {
    await assert.rejects(Sandbox.create({ ...opts, metadata: { fail: '1' } }), (error: unknown) => {
      assert.ok(error instanceof RunrSandboxError)
      assert.equal(error.reason, 'start_failed')
      assert.match(error.message, /ImagePullBackOff/)
      return true
    })
  })

  test('quota and validation errors', async () => {
    await assert.rejects(Sandbox.create({ ...opts, runtime: 'quota' }), (error: unknown) => {
      assert.ok(error instanceof QuotaError)
      assert.ok(error instanceof ValidationError)
      assert.equal(error.status, 422)
      assert.equal(error.reason, 'quota_concurrent')
      return true
    })
    await assert.rejects(Sandbox.create({ ...opts, runtime: 'unknown' }), (error: unknown) => {
      assert.ok(error instanceof ValidationError && !(error instanceof QuotaError))
      assert.deepEqual((error.body as { errors: unknown }).errors, { runtime: ['Unknown runtime.'] })
      return true
    })
  })

  test('a wrong token is an auth error', async () => {
    await assert.rejects(Sandbox.create({ apiUrl: api.url, apiKey: 'wrong' }), AuthError)
  })

  test('connect and unknown ids', async () => {
    const created = await Sandbox.create(opts)
    const again = await Sandbox.connect(created.id, opts)
    assert.equal(again.id, created.id)
    await assert.rejects(Sandbox.connect('nope', opts), NotFoundError)
    await created.close()
  })

  test('list with filters', async () => {
    const a = await Sandbox.create({ ...opts, metadata: { agent: 'a' } })
    const b = await Sandbox.create({ ...opts, metadata: { agent: 'b' } })
    const found = await Sandbox.list({ metadata: { agent: 'b' } }, opts)
    assert.deepEqual(found.map((s) => s.id), [b.id])
    assert.deepEqual(api.calls('GET', /^\/api\/sandboxes$/).at(-1)!.query, { 'metadata[agent]': 'b' })
    await a.close()
    await b.close()
  })

  test('the tenant header is sent', async () => {
    const sandbox = await Sandbox.create({ ...opts, tenantId: 't-1' })
    await sandbox.close()
    assert.equal(api.calls('POST', /^\/api\/sandboxes$/)[0]!.headers['x-tenant-id'], 't-1')
  })
})

describe('disposal', () => {
  test('await using removes the sandbox', async () => {
    let id = ''
    {
      await using sandbox = await Sandbox.create(opts)
      id = sandbox.id
      assert.equal(sandbox.status, 'running')
    }
    assert.equal(api.sandboxes.get(id)!.status, 'terminated')
  })

  test('await using removes the sandbox when the block throws', async () => {
    let id = ''
    await assert.rejects(async () => {
      await using sandbox = await Sandbox.create(opts)
      id = sandbox.id
      throw new Error('boom')
    }, /boom/)
    assert.equal(api.sandboxes.get(id)!.status, 'terminated')
  })

  test('close and kill are idempotent', async () => {
    const sandbox = await Sandbox.create(opts)
    await sandbox.kill()
    await sandbox.kill() // 409: already ended
    await sandbox.close()
    await sandbox.close()
    assert.equal(api.calls('DELETE', /^\/api\/sandboxes\/sbx\d+$/).length, 3)
  })

  test('close ignores a sandbox that is gone', async () => {
    const sandbox = await Sandbox.create(opts)
    api.sandboxes.delete(sandbox.id)
    await sandbox.close()
  })
})

describe('exec', () => {
  test('returns the result', async () => {
    await using sandbox = await Sandbox.create(opts)
    const result = await sandbox.exec('echo hello', { timeout: 30, env: { A: '1' }, cwd: '/tmp' })
    assert.equal(result.ok, true)
    assert.equal(result.stdout, 'hello\n')
    assert.deepEqual(api.calls('POST', /\/exec$/).at(-1)!.json, { cmd: 'echo hello', timeout: 30, env: { A: '1' }, cwd: '/tmp' })

    const failed = await sandbox.exec(['exit', '3'])
    assert.equal(failed.ok, false)
    assert.equal(failed.exitCode, 3)
    assert.equal(failed.stderr, 'exit 3\n')
    assert.deepEqual(api.calls('POST', /\/exec$/).at(-1)!.json!.cmd, ['exit', '3'])

    const timedOut = await sandbox.exec('sleep')
    assert.equal(timedOut.timedOut, true)
    assert.equal(timedOut.ok, false)
    assert.equal(timedOut.exitCode, 137)
  })

  test('binary output and stdin', async () => {
    await using sandbox = await Sandbox.create(opts)
    const binary = await sandbox.exec('binary')
    assert.deepEqual(Buffer.from(binary.stdoutBytes), Buffer.from([0xff, 0xfe, 0x00, 0x6f, 0x6b]))
    assert.equal((await sandbox.exec('cat', { stdin: 'text in' })).stdout, 'text in')
    assert.equal(api.calls('POST', /\/exec$/).at(-1)!.json!.stdin, 'text in')
    await sandbox.exec('cat', { stdin: new Uint8Array([0, 1]) })
    assert.equal(api.calls('POST', /\/exec$/).at(-1)!.json!.stdin_base64, 'AAE=')
  })

  test('execStream yields output and exit', async () => {
    await using sandbox = await Sandbox.create(opts)
    const events = await collect(sandbox.execStream('multi'))
    assert.deepEqual(events.map((e) => e.type), ['stdout', 'stderr', 'stdout', 'stdout', 'exit'])
    const [first, second, third, fourth, exit] = events
    assert.ok(first?.type === 'stdout' && first.text === 'line 1\n')
    assert.ok(second?.type === 'stderr' && second.text === 'warning\n')
    assert.ok(third?.type === 'stdout')
    assert.deepEqual(Buffer.from(third.data), Buffer.from([0xff, 0xfe]))
    assert.ok(fourth?.type === 'stdout' && fourth.text === 'héllo ✓\n')
    assert.ok(exit?.type === 'exit')
    assert.equal(exit.exitCode, 3)
    assert.equal(exit.durationMs, 21)
    assert.equal(exit.execId, 'exec-1')
    const request = api.calls('POST', /\/exec$/).at(-1)!
    assert.equal(request.json!.stream, true)
    assert.equal(request.headers.accept, 'text/event-stream')
  })

  test('an error event rejects', async () => {
    await using sandbox = await Sandbox.create(opts)
    await assert.rejects(collect(sandbox.execStream('error')), (error: unknown) => {
      assert.ok(error instanceof RunrSandboxError)
      assert.equal(error.reason, 'not_running')
      return true
    })
  })

  test('leaving a stream early is fine', async () => {
    await using sandbox = await Sandbox.create(opts)
    for await (const event of sandbox.execStream('multi')) {
      assert.equal(event.type, 'stdout')
      break
    }
    assert.equal((await sandbox.exec('echo after')).stdout, 'after\n')
  })

  test('exec on a paused sandbox is a conflict', async () => {
    await using sandbox = await Sandbox.create(opts)
    await sandbox.pause()
    assert.equal(sandbox.status, 'paused')
    await assert.rejects(sandbox.exec('echo x'), ConflictError)
    await sandbox.resume()
    assert.equal((await sandbox.exec('echo x')).stdout, 'x\n')
  })
})

describe('runCode', () => {
  test('returns the structured result', async () => {
    await using sandbox = await Sandbox.create(opts)
    const result = await sandbox.runCode('print(6 * 7)', { language: 'python', timeout: 20 })
    assert.equal(result.ok, true)
    assert.equal(result.stdout, '42\n')
    assert.equal(result.language, 'python')
    assert.deepEqual(result.filesChanged.map((f) => f.path), ['/output/plot.png', '/workspace/data.csv'])
    assert.equal(result.outputs.length, 1)
    assert.equal(result.outputs[0]!.mime, 'image/png')
    assert.deepEqual(Buffer.from(result.outputs[0]!.data), Buffer.from(PNG))
    assert.deepEqual(api.calls('POST', /\/run$/).at(-1)!.json, { code: 'print(6 * 7)', language: 'python', timeout: 20 })
  })

  test('a failing run', async () => {
    await using sandbox = await Sandbox.create(opts)
    const result = await sandbox.runCode("throw new Error('boom')", { language: 'javascript' })
    assert.equal(result.ok, false)
    assert.match(result.stderr, /boom/)
  })

  test('runCodeStream ends with a result', async () => {
    await using sandbox = await Sandbox.create(opts)
    const events = await collect(sandbox.runCodeStream('print(6 * 7)'))
    assert.deepEqual(events.map((e) => e.type), ['stdout', 'exit', 'result'])
    const last = events.at(-1)!
    assert.ok(last.type === 'result')
    assert.deepEqual(Buffer.from(last.result.outputs[0]!.data), Buffer.from(PNG))
  })
})

describe('files', () => {
  test('write, read, list, remove', async () => {
    await using sandbox = await Sandbox.create(opts)
    assert.deepEqual(await sandbox.files.write('/workspace/a.txt', 'hello'), { path: '/workspace/a.txt', size: 5 })
    const put = api.calls('PUT', /\/files\/content$/).at(-1)!
    assert.equal(put.headers['content-type'], 'application/octet-stream')
    assert.equal(put.raw.toString(), 'hello')

    await sandbox.files.write('/workspace/run.sh', '#!/bin/sh\n', { mode: 0o755 })
    assert.equal(api.calls('PUT', /\/files\/content$/).at(-1)!.json!.mode, '0755')

    assert.equal(await sandbox.files.readText('/workspace/a.txt'), 'hello')
    assert.deepEqual(Buffer.from(await sandbox.files.read('/workspace/a.txt')), Buffer.from('hello'))
    assert.deepEqual((await sandbox.files.list('/workspace')).map((e) => e.name), ['a.txt', 'run.sh'])
    assert.equal(await sandbox.files.exists('/workspace/a.txt'), true)

    await sandbox.files.remove('/workspace/a.txt')
    assert.equal(await sandbox.files.exists('/workspace/a.txt'), false)
    await assert.rejects(sandbox.files.read('/workspace/a.txt'), NotFoundError)
  })

  test('directories', async () => {
    await using sandbox = await Sandbox.create(opts)
    await sandbox.files.mkdir('/workspace/src')
    await sandbox.files.write('/workspace/src/x.py', 'x')
    const entries = await sandbox.files.list('/workspace')
    assert.deepEqual(entries.map((e) => [e.name, e.type]), [['src', 'dir']])
    await assert.rejects(sandbox.files.remove('/workspace/src'), ValidationError)
    await sandbox.files.remove('/workspace/src', { recursive: true })
    assert.deepEqual(api.calls('DELETE', /\/files$/).at(-1)!.query, { path: '/workspace/src', recursive: '1' })
  })

  test('a large upload is payload too large', async () => {
    await using sandbox = await Sandbox.create(opts)
    await assert.rejects(sandbox.files.write('/workspace/big', new Uint8Array(1024 * 1024 + 1)), (error: unknown) => {
      assert.ok(error instanceof PayloadTooLargeError)
      assert.equal(error.reason, 'file_too_large')
      return true
    })
  })

  test('directory round trip through tar', async () => {
    await using sandbox = await Sandbox.create(opts)
    await sandbox.files.uploadDirectory({ 'main.py': "print('hi')\n", 'pkg/util.bin': new Uint8Array([0, 0xff]) }, '/workspace/project')
    const put = api.calls('PUT', /\/files\/archive$/).at(-1)!
    assert.equal(put.headers['content-type'], 'application/x-tar')
    assert.deepEqual(put.query, { path: '/workspace/project' })
    const fs = api.fs.get(sandbox.id)!
    assert.equal(Buffer.from(fs.get('/workspace/project/main.py')!).toString(), "print('hi')\n")

    const files = await sandbox.files.downloadDirectory('/workspace/project')
    assert.deepEqual(Object.keys(files).sort(), ['main.py', 'pkg/util.bin'])
    assert.deepEqual(Buffer.from(files['pkg/util.bin']!), Buffer.from([0, 0xff]))
  })

  test('raw archives', async () => {
    await using sandbox = await Sandbox.create(opts)
    await sandbox.files.uploadArchive(createTar([{ path: 'a.txt', data: new TextEncoder().encode('A') }]), '/workspace')
    const archive = await sandbox.files.downloadArchive('/workspace')
    assert.ok(archive.length >= 1024)
    assert.equal(Buffer.from(api.fs.get(sandbox.id)!.get('/workspace/a.txt')!).toString(), 'A')
  })
})

describe('processes', () => {
  test('start, list, get, logs, follow, kill', async () => {
    await using sandbox = await Sandbox.create(opts)
    const process = await sandbox.processes.start('python3 -m http.server', { env: { PORT: '8000' }, cwd: '/workspace' })
    assert.equal(process.status, 'running')
    assert.equal(process.pid, 100)
    assert.deepEqual(api.calls('POST', /\/processes$/).at(-1)!.json, { cmd: 'python3 -m http.server', env: { PORT: '8000' }, cwd: '/workspace' })
    assert.deepEqual((await sandbox.processes.list()).map((p) => p.id), [process.id])
    assert.equal((await sandbox.processes.get(process.id)).status, 'running')
    const logs = await sandbox.processes.logs(process.id, { tailBytes: 1024 })
    assert.equal(logs.stdout, 'serving on :8000\n')
    assert.equal(logs.truncated, false)
    assert.deepEqual((await collect(sandbox.processes.follow(process.id))).map((e) => e.type), ['stdout', 'exit'])
    const killed = await sandbox.processes.kill(process.id, 'kill')
    assert.equal(killed.status, 'killed')
    assert.deepEqual(api.calls('DELETE', /\/processes\//).at(-1)!.query, { signal: 'KILL' })
    await assert.rejects(sandbox.processes.get('nope'), NotFoundError)
  })
})

describe('lifecycle', () => {
  test('timeout, keepalive, network, logs, terminal, refresh', async () => {
    await using sandbox = await Sandbox.create(opts)
    assert.equal((await sandbox.setTimeout(1200)).info.ttl_seconds, 1200)
    await sandbox.keepalive()
    await sandbox.setNetwork(['example.com:443'])
    assert.deepEqual(sandbox.info.network, { mode: 'allowlist', egress_allow: ['example.com:443'] })
    assert.deepEqual(api.calls('PUT', /\/network$/).at(-1)!.json, { network: { egress_allow: ['example.com:443'] } })
    await sandbox.setNetwork('none')
    assert.equal(sandbox.info.network.mode, 'none')
    const logs = await sandbox.logs({ pod: true })
    assert.equal(logs.events[0]!.type, 'created')
    assert.equal(logs.pod!.phase, 'Running')
    assert.equal((await sandbox.terminal({ cols: 120, rows: 40 })).session_id, 'sess-1')
    assert.equal((await sandbox.refresh()).status, 'running')
    assert.equal(JSON.parse(JSON.stringify(sandbox)).id, sandbox.id)
  })
})
