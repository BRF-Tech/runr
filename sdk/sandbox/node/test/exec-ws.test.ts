import { after, afterEach, before, describe, test } from 'node:test'
import assert from 'node:assert/strict'
import { Buffer } from 'node:buffer'
import { createServer, type IncomingMessage, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import type { Duplex } from 'node:stream'

import { ExecSession, RunrSandboxError, Sandbox, ValidationError, type ExecEvent } from '../src/index.js'
import { terminalSize, webSocketUrl } from '../src/exec-ws.js'
import { acceptKey, connectBuiltin, encodeFrame } from '../src/websocket-node.js'
import { connectWebSocket, redactUrl } from '../src/websocket.js'
import { FakeApi, TOKEN } from './fake-server.js'
import { acceptUpgrade, serverFrame } from './ws-server.js'

let api: FakeApi
let sandbox: Sandbox

before(async () => {
  api = await new FakeApi().start()
  sandbox = await Sandbox.create({ apiUrl: api.url, apiKey: TOKEN })
})

after(async () => {
  await sandbox.close()
  await api.stop()
})

afterEach(() => {
  api.requests.length = 0
  api.legacyExecWs = false
  api.absoluteExecWsUrl = false
  api.expireExecTickets = false
})

const b64 = (text: string): string => Buffer.from(text).toString('base64')

async function eventually(check: () => boolean, ms = 2000): Promise<void> {
  const deadline = Date.now() + ms
  while (!check()) {
    if (Date.now() > deadline) throw new Error('condition not met in time')
    await new Promise((resolve) => setTimeout(resolve, 10))
  }
}

async function nextEvent(iterator: AsyncIterator<ExecEvent>): Promise<ExecEvent> {
  const { value, done } = await iterator.next()
  assert.equal(done, false)
  return value as ExecEvent
}

const lastSocket = () => api.execSockets.at(-1)!
const wsPosts = () => api.calls('POST', /\/exec\/ws$/)

const transports: Array<'global' | 'builtin'> = typeof (globalThis as { WebSocket?: unknown }).WebSocket === 'function'
  ? ['global', 'builtin']
  : ['builtin']

for (const transport of transports) {
  describe(`execWs over the ${transport} WebSocket client`, () => {
    test('a command: request body, output, exit, closing handshake', async () => {
      const session = await sandbox.execWs(['echo', 'hi'], { transport, timeout: 30, env: { A: '1' }, cwd: '/workspace/app' })
      assert.ok(session instanceof ExecSession)
      assert.equal(session.tty, false)
      assert.match(session.execId, /^exec-tok-/)
      const result = await session.wait()
      assert.equal(result.stdout, 'hi\n')
      assert.equal(result.stderr, '')
      assert.equal(result.exitCode, 0)
      assert.equal(result.ok, true)
      assert.equal(result.killed, false)
      assert.equal(result.tty, false)
      assert.equal(result.execId, session.execId)
      assert.deepEqual(wsPosts().at(-1)!.json, { cmd: ['echo', 'hi'], timeout: 30, env: { A: '1' }, cwd: '/workspace/app' })
      // The token is used on the console host, without the API key
      assert.match(lastSocket().url, /^\/ws\/sandbox-exec\?token=tok-/)
      assert.equal(lastSocket().headers.authorization, undefined)
      for (const frame of lastSocket().socket.frames) assert.equal(frame.masked, true)
      await eventually(() => lastSocket().socket.clientClosed)
    })

    test('a shell string, stderr and the exit code', async () => {
      const session = await sandbox.execWs('exit 3', { transport })
      const result = await session.wait()
      assert.equal(result.exitCode, 3)
      assert.equal(result.stderr, 'exit 3\n')
      assert.equal(result.ok, false)
      assert.equal(wsPosts().at(-1)!.json!.cmd, 'exit 3')
    })

    test('stdin while the command runs, then end of input', async () => {
      await using session = await sandbox.execWs(['cat'], { transport })
      const events = session[Symbol.asyncIterator]()
      assert.equal(session.sendStdin('one\n'), true)
      const first = await nextEvent(events)
      assert.equal(first.type, 'stdout')
      assert.equal(first.type === 'stdout' && first.text, 'one\n')
      session.sendStdin(new Uint8Array([0xff, 0x00, 0x41]))
      const second = await nextEvent(events)
      assert.deepEqual(second.type === 'stdout' && [...second.data], [0xff, 0x00, 0x41])
      assert.equal(session.closeStdin(), true)
      assert.equal(session.closeStdin(), false)
      assert.equal(session.sendStdin('late'), false)
      const exit = await nextEvent(events)
      assert.ok(exit.type === 'exit')
      assert.equal(exit.exitCode, 0)
      assert.equal(exit.killed, false)
      assert.equal((await events.next()).done, true)
      assert.deepEqual(lastSocket().messages, [
        { type: 'stdin', data: b64('one\n') },
        { type: 'stdin', data: Buffer.from([0xff, 0x00, 0x41]).toString('base64') },
        { type: 'stdin_close' },
      ])
      // The iteration took the output; wait() reports the exit
      const result = await session.wait()
      assert.equal(result.exitCode, 0)
      assert.equal(result.stdout, '')
      assert.equal(session.sendStdin('after exit'), false)
    })

    test('the initial stdin of the request', async () => {
      const session = await sandbox.execWs('cat', { transport, stdin: 'pre\n' })
      session.closeStdin()
      assert.equal((await session.wait()).stdout, 'pre\n')
      assert.deepEqual(wsPosts().at(-1)!.json, { cmd: 'cat', stdin: 'pre\n' })
      const bytes = await sandbox.execWs('cat', { transport, stdin: new Uint8Array([1, 2]) })
      bytes.closeStdin()
      assert.deepEqual([...(await bytes.wait()).stdoutBytes], [1, 2])
      assert.deepEqual(wsPosts().at(-1)!.json, { cmd: 'cat', stdin_base64: 'AQI=' })
    })

    test('a terminal: tty, size and resize frames', async () => {
      const session = await sandbox.execWs(['cat'], { transport, tty: true, cols: 120, rows: 40 })
      assert.equal(session.tty, true)
      assert.equal(session.resize(100, 30), true)
      session.closeStdin()
      const result = await session.wait()
      assert.equal(result.tty, true)
      assert.deepEqual(wsPosts().at(-1)!.json, { cmd: ['cat'], tty: true, cols: 120, rows: 40 })
      assert.deepEqual(lastSocket().messages, [{ type: 'resize', cols: 100, rows: 30 }, { type: 'stdin_close' }])

      const defaults = await sandbox.execWs('cat', { transport, tty: true })
      defaults.closeStdin()
      await defaults.wait()
      assert.deepEqual(wsPosts().at(-1)!.json, { cmd: 'cat', tty: true, cols: 80, rows: 24 })
    })

    test('a server without the tty option runs the command without a terminal', async () => {
      api.legacyExecWs = true
      const session = await sandbox.execWs('cat', { transport, tty: true })
      assert.equal(session.tty, false)
      session.closeStdin()
      assert.equal((await session.wait()).tty, false)
    })

    test('kill ends the command with exit code 137', async () => {
      const session = await sandbox.execWs(['sleep'], { transport })
      const running = session.exit
      assert.equal(running, null)
      assert.equal(session.kill(), true)
      const result = await session.wait()
      assert.equal(result.exitCode, 137)
      assert.equal(result.killed, true)
      assert.equal(result.ok, false)
      assert.equal(session.exit?.killed, true)
      assert.deepEqual(lastSocket().messages, [{ type: 'kill' }])
    })

    test('an error frame rejects with the server message', async () => {
      const session = await sandbox.execWs('fail', { transport })
      await assert.rejects(session.wait(), (error: unknown) => {
        assert.ok(error instanceof RunrSandboxError)
        assert.equal(error.reason, 'exec_error')
        assert.equal(error.message, 'The sandbox ended during the command.')
        return true
      })
      assert.equal(session.sendStdin('x'), false)
    })

    test('an expired token rejects execWs', async () => {
      api.expireExecTickets = true
      await assert.rejects(sandbox.execWs('echo x', { transport }), (error: unknown) => {
        assert.ok(error instanceof RunrSandboxError)
        assert.equal(error.reason, 'exec_error')
        assert.match(error.message, /expired/)
        return true
      })
    })

    test('a connection that ends without exit rejects', async () => {
      const session = await sandbox.execWs('drop', { transport })
      const iteration = (async () => {
        for await (const _ of session) {
          // nothing arrives
        }
      })()
      await assert.rejects(iteration, (error: unknown) => {
        assert.ok(error instanceof RunrSandboxError)
        assert.equal(error.reason, 'websocket_closed')
        return true
      })
      await assert.rejects(session.wait(), RunrSandboxError)
    })

    test('fragmented and long messages, ping answered', async () => {
      const session = await sandbox.execWs('fragmented', { transport })
      const output: string[] = []
      let exit: ExecEvent | null = null
      for await (const event of session) {
        if (event.type === 'stdout') output.push(event.text)
        else exit = event
      }
      assert.equal(output[0], 'part one, part two\n')
      assert.equal(output[1], 'a'.repeat(70000))
      assert.ok(exit?.type === 'exit' && exit.exitCode === 0)
      await eventually(() => lastSocket().socket.frames.some((f) => f.opcode === 0xa))
      const pong = lastSocket().socket.frames.find((f) => f.opcode === 0xa)!
      assert.equal(pong.payload.toString(), 'heartbeat')
      assert.equal(pong.masked, true)
    })

    test('an absolute ws:// URL in the answer is used as it is', async () => {
      api.absoluteExecWsUrl = true
      const session = await sandbox.execWs('echo abs', { transport })
      assert.equal((await session.wait()).stdout, 'abs\n')
    })

    test('an abort kills the command and rejects', async () => {
      const controller = new AbortController()
      const session = await sandbox.execWs('sleep', { transport, signal: controller.signal })
      const waiting = session.wait()
      controller.abort()
      await assert.rejects(waiting, (error: unknown) => error instanceof Error && error.name === 'AbortError')
      await eventually(() => lastSocket().messages.some((m) => m.type === 'kill'))
      await eventually(() => lastSocket().socket.closed)
    })

    test('an aborted signal stops execWs before it connects', async () => {
      const controller = new AbortController()
      controller.abort()
      await assert.rejects(sandbox.execWs('echo x', { transport, signal: controller.signal }), (error: unknown) => error instanceof Error && error.name === 'AbortError')
    })

    test('close() ends a running command', async () => {
      const session = await sandbox.execWs('sleep', { transport })
      const socket = lastSocket().socket
      await session.close()
      await session.close()
      await eventually(() => socket.clientClosed)
      await assert.rejects(session.wait(), (error: unknown) => error instanceof RunrSandboxError && error.reason === 'session_closed')
      assert.equal(session.kill(), false)
    })

    test('runCodeWs sends code and language', async () => {
      const session = await sandbox.runCodeWs('print(2)', { transport, language: 'python', tty: true, cols: 90, rows: 20 })
      await session.wait()
      assert.deepEqual(wsPosts().at(-1)!.json, { code: 'print(2)', language: 'python', tty: true, cols: 90, rows: 20 })
    })
  })
}

describe('exec WebSocket helpers', () => {
  test('the WebSocket URL is on the console host', () => {
    assert.equal(webSocketUrl('http://127.0.0.1:8080/api', '/ws/sandbox-exec?token=a'), 'ws://127.0.0.1:8080/ws/sandbox-exec?token=a')
    assert.equal(webSocketUrl('https://runr.example.com/api', '/ws/sandbox-exec?token=a'), 'wss://runr.example.com/ws/sandbox-exec?token=a')
    assert.equal(webSocketUrl('https://runr.example.com/console/api', 'ws/sandbox-exec?token=a'), 'wss://runr.example.com/ws/sandbox-exec?token=a')
    assert.equal(webSocketUrl('https://runr.example.com/api', 'wss://ws.example.com/ws/sandbox-exec?token=a'), 'wss://ws.example.com/ws/sandbox-exec?token=a')
  })

  test('the token is never part of an error message', () => {
    assert.equal(redactUrl('wss://runr.example.com/ws/sandbox-exec?token=secret'), 'wss://runr.example.com/ws/sandbox-exec?…')
  })

  test('terminal sizes are integers from 1 to 1000', async () => {
    assert.equal(terminalSize('cols', 1000), 1000)
    for (const bad of [0, 1001, 1.5, Number.NaN]) assert.throws(() => terminalSize('cols', bad), ValidationError)
    await assert.rejects(sandbox.execWs('cat', { tty: true, cols: 0 }), ValidationError)
    assert.equal(api.calls('POST', /\/exec\/ws$/).length, 0)
  })

  test('the Sec-WebSocket-Accept value (RFC 6455 example)', () => {
    assert.equal(acceptKey('dGhlIHNhbXBsZSBub25jZQ=='), 's3pPLMBiTxaQ9kYGzzhZRbK+xOo=')
  })

  test('client frames are masked, with 7-, 16- and 64-bit lengths', () => {
    const mask = new Uint8Array([1, 2, 3, 4])
    const small = encodeFrame(0x1, Buffer.from('hi'), mask)
    assert.deepEqual([...small.subarray(0, 6)], [0x81, 0x82, 1, 2, 3, 4])
    assert.deepEqual([...small.subarray(6)], [0x68 ^ 1, 0x69 ^ 2])
    const medium = encodeFrame(0x1, Buffer.alloc(300), mask)
    assert.deepEqual([...medium.subarray(0, 4)], [0x81, 0x80 | 126, 1, 44])
    const large = encodeFrame(0x2, Buffer.alloc(70000), mask)
    assert.deepEqual([...large.subarray(0, 10)], [0x82, 0x80 | 127, 0, 0, 0, 0, 0, 1, 0x11, 0x70])
    assert.equal(large.length, 10 + 4 + 70000)
  })

  test('without a global WebSocket, auto uses the built-in client', async () => {
    const holder = globalThis as { WebSocket?: unknown }
    const saved = holder.WebSocket
    try {
      delete holder.WebSocket
      await assert.rejects(sandbox.execWs('echo x', { transport: 'global' }), (error: unknown) => error instanceof RunrSandboxError && error.reason === 'config')
      const session = await sandbox.execWs('echo auto')
      assert.equal((await session.wait()).stdout, 'auto\n')
      assert.match(String(lastSocket().headers['user-agent']), /^runr-sandbox-node\//)
      assert.equal(lastSocket().headers.origin, api.url)
    } finally {
      if (saved !== undefined) holder.WebSocket = saved
    }
  })
})

describe('built-in client against a misbehaving server', () => {
  let server: Server
  let url = ''
  let onUpgrade: (req: IncomingMessage, socket: Duplex, head: Buffer) => void = () => undefined
  const sockets = new Set<Duplex>()

  before(async () => {
    server = createServer((_req, res) => {
      res.writeHead(404, { 'Content-Type': 'text/plain' })
      res.end('no such path')
    })
    server.on('upgrade', (req: IncomingMessage, socket: Duplex, head: Buffer) => {
      sockets.add(socket)
      onUpgrade(req, socket, head)
    })
    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve))
    url = `ws://127.0.0.1:${(server.address() as AddressInfo).port}`
  })

  after(async () => {
    for (const socket of sockets) socket.destroy()
    server.closeAllConnections?.()
    await new Promise<void>((resolve) => server.close(() => resolve()))
  })

  const handlers = () => {
    const state = { messages: [] as string[], closed: null as null | { code: number | null; error: Error | null } }
    return {
      state,
      handlers: {
        message: (text: string) => { state.messages.push(text) },
        close: (code: number | null, _reason: string, error: Error | null) => { state.closed = { code, error } },
      },
    }
  }

  test('an HTTP answer instead of the upgrade is refused without the token in the message', async () => {
    onUpgrade = (_req, socket) => socket.end('HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n')
    await assert.rejects(connectBuiltin(`${url}/ws/sandbox-exec?token=secret-token`, handlers().handlers), (error: unknown) => {
      assert.ok(error instanceof RunrSandboxError)
      assert.equal(error.reason, 'websocket_error')
      assert.doesNotMatch(error.message, /secret-token/)
      return true
    })
  })

  test('a wrong Sec-WebSocket-Accept is refused', async () => {
    onUpgrade = (_req, socket) => socket.write('HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: bogus\r\n\r\n')
    await assert.rejects(connectBuiltin(`${url}/ws`, handlers().handlers), /invalid handshake/)
  })

  test('a masked server frame fails the connection (1002)', async () => {
    onUpgrade = (req, socket) => {
      acceptUpgrade(req, socket)
      socket.write(Buffer.from([0x81, 0x81, 0, 0, 0, 0, 0x41]))
    }
    const { state, handlers: h } = handlers()
    await connectBuiltin(`${url}/ws`, h)
    await eventually(() => state.closed !== null)
    assert.equal(state.closed!.code, 1002)
    assert.deepEqual(state.messages, [])
  })

  test('frames that arrive with the handshake are read; a close without code is answered', async () => {
    onUpgrade = (req, socket) => {
      acceptUpgrade(req, socket)
      socket.write(Buffer.concat([serverFrame(0x1, Buffer.from('{"type":"start"}')), serverFrame(0x8, Buffer.alloc(0))]))
    }
    const { state, handlers: h } = handlers()
    const connection = await connectWebSocket(`${url}/ws`, h, { transport: 'builtin' })
    await eventually(() => state.closed !== null)
    assert.deepEqual(state.messages, ['{"type":"start"}'])
    assert.equal(state.closed!.code, null)
    assert.equal(state.closed!.error, null)
    assert.equal(connection.send('x'), false)
  })

  test('a server that never answers the handshake times out', async () => {
    onUpgrade = () => undefined
    await assert.rejects(connectBuiltin(`${url}/ws`, handlers().handlers, { timeoutMs: 50 }), /did not open in time/)
  })
})
