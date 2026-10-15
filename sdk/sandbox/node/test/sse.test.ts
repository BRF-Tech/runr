import { test } from 'node:test'
import assert from 'node:assert/strict'

import { SseDecoder, readSse, type SseEvent } from '../src/index.js'

const encoder = new TextEncoder()

function decodeAll(...chunks: Array<string | Uint8Array>): SseEvent[] {
  const decoder = new SseDecoder()
  const events: SseEvent[] = []
  for (const chunk of chunks) events.push(...decoder.push(chunk))
  events.push(...decoder.end())
  return events
}

test('a simple event', () => {
  assert.deepEqual(decodeAll('event: stdout\ndata: {"data":"hi"}\n\n'), [{ event: 'stdout', data: '{"data":"hi"}' }])
})

test('an event without a name is a message', () => {
  assert.equal(decodeAll('data: x\n\n')[0]!.event, 'message')
})

test('several data lines are joined with newlines', () => {
  assert.equal(decodeAll('data: one\ndata: two\ndata:three\n\n')[0]!.data, 'one\ntwo\nthree')
})

test('comments are ignored', () => {
  const events = decodeAll(': ping\n\n: another\nevent: exit\n: in between\ndata: {}\n\n')
  assert.deepEqual(events.map((e) => e.event), ['exit'])
})

test('CRLF and CR line endings', () => {
  assert.deepEqual(decodeAll('event: a\r\ndata: 1\r\n\r\n'), [{ event: 'a', data: '1' }])
  assert.deepEqual(decodeAll('event: b\rdata: 2\r\r'), [{ event: 'b', data: '2' }])
})

test('chunks split anywhere, byte by byte', () => {
  const bytes = encoder.encode('event: stdout\r\ndata: {"data":"héllo ✓"}\r\n\r\nevent: exit\ndata: {"exit_code":0}\n\n')
  const chunks = Array.from(bytes, (byte) => new Uint8Array([byte]))
  const events = decodeAll(...chunks)
  assert.deepEqual(events.map((e) => e.event), ['stdout', 'exit'])
  assert.deepEqual(JSON.parse(events[0]!.data), { data: 'héllo ✓' })
})

test('a CR LF pair split across chunks is one line end', () => {
  assert.deepEqual(decodeAll('data: a\r', '\n\r', '\n'), [{ event: 'message', data: 'a' }])
})

test('multi-byte UTF-8 split across chunks', () => {
  const bytes = encoder.encode('data: çğü\n\n')
  assert.equal(decodeAll(bytes.subarray(0, 7), bytes.subarray(7, 8), bytes.subarray(8))[0]!.data, 'çğü')
})

test('only one leading space is stripped', () => {
  assert.equal(decodeAll('data:   indented\n\n')[0]!.data, '  indented')
})

test('a field without a colon has an empty value', () => {
  assert.deepEqual(decodeAll('data\n\n'), [{ event: 'message', data: '' }])
})

test('a block without data dispatches nothing', () => {
  assert.deepEqual(decodeAll('event: lonely\n\n'), [])
})

test('id and retry', () => {
  const events = decodeAll('id: 7\nretry: 1500\ndata: x\n\ndata: y\n\n')
  assert.deepEqual(events[0], { event: 'message', data: 'x', id: '7', retry: 1500 })
  assert.deepEqual(events[1], { event: 'message', data: 'y', id: '7' })
})

test('an invalid retry is ignored', () => {
  assert.equal(decodeAll('retry: soon\ndata: x\n\n')[0]!.retry, undefined)
})

test('an unterminated last event is dispatched at the end', () => {
  assert.deepEqual(decodeAll('event: exit\ndata: {"exit_code":1}'), [{ event: 'exit', data: '{"exit_code":1}' }])
})

test('a byte order mark at the start is skipped', () => {
  assert.equal(decodeAll(new Uint8Array([0xef, 0xbb, 0xbf, ...encoder.encode('data: x\n\n')]))[0]!.data, 'x')
})

test('readSse over a ReadableStream', async () => {
  const stream = new ReadableStream<Uint8Array>({
    start(controller) {
      controller.enqueue(encoder.encode('event: stdout\ndata: {"data":"a"}\n'))
      controller.enqueue(encoder.encode('\nevent: exit\ndata: {}\n\n'))
      controller.close()
    },
  })
  const events: SseEvent[] = []
  for await (const event of readSse(stream)) events.push(event)
  assert.deepEqual(events.map((e) => e.event), ['stdout', 'exit'])
})
