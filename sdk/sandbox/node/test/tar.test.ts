import { test } from 'node:test'
import assert from 'node:assert/strict'

import { createTar, readTar } from '../src/index.js'

const encoder = new TextEncoder()
const decoder = new TextDecoder()

test('round trip of files and directories', () => {
  const archive = createTar([
    { path: 'src', type: 'directory' },
    { path: 'src/main.py', data: encoder.encode("print('hi')\n"), mode: 0o755 },
    { path: 'data.bin', data: new Uint8Array([0, 1, 2, 255]) },
    { path: 'empty.txt' },
  ])
  assert.equal(archive.length % 512, 0)
  const entries = readTar(archive)
  assert.deepEqual(entries.map((e) => [e.path, e.type]), [
    ['src', 'directory'],
    ['src/main.py', 'file'],
    ['data.bin', 'file'],
    ['empty.txt', 'file'],
  ])
  assert.equal(decoder.decode(entries[1]!.data), "print('hi')\n")
  assert.equal(entries[1]!.mode, 0o755)
  assert.deepEqual([...entries[2]!.data!], [0, 1, 2, 255])
  assert.equal(entries[3]!.data!.length, 0)
})

test('ustar header fields', () => {
  const archive = createTar([{ path: 'a.txt', data: encoder.encode('A'), mtime: new Date('2026-01-02T03:04:05Z') }])
  const header = archive.subarray(0, 512)
  assert.equal(decoder.decode(header.subarray(0, 5)), 'a.txt')
  assert.equal(decoder.decode(header.subarray(257, 262)), 'ustar')
  assert.equal(decoder.decode(header.subarray(124, 135)), '00000000001')
  assert.equal(parseInt(decoder.decode(header.subarray(136, 147)), 8), Date.UTC(2026, 0, 2, 3, 4, 5) / 1000)
  assert.equal(String.fromCharCode(header[156]!), '0')
  // Two zero blocks end the archive
  assert.ok(archive.subarray(archive.length - 1024).every((byte) => byte === 0))
})

test('names longer than 100 bytes use the prefix field', () => {
  const path = `${'d'.repeat(80)}/${'e'.repeat(60)}/file.txt`
  const [entry] = readTar(createTar([{ path, data: encoder.encode('x') }]))
  assert.equal(entry!.path, path)
})

test('names longer than 255 bytes use a pax record', () => {
  const path = `${'segment/'.repeat(40)}file-çğ.txt`
  const archive = createTar([{ path, data: encoder.encode('long') }])
  const entries = readTar(archive)
  assert.equal(entries.length, 1)
  assert.equal(entries[0]!.path, path)
  assert.equal(decoder.decode(entries[0]!.data), 'long')
})

test('leading slashes and ./ are dropped, .. is refused', () => {
  assert.equal(readTar(createTar([{ path: '/abs/x' }]))[0]!.path, 'abs/x')
  assert.equal(readTar(createTar([{ path: './rel/y' }]))[0]!.path, 'rel/y')
  assert.throws(() => createTar([{ path: '../escape' }]), /Invalid tar entry path/)
})

test('symlinks', () => {
  const [link] = readTar(createTar([{ path: 'latest', type: 'symlink', linkname: 'v1/data' }]))
  assert.equal(link!.type, 'symlink')
  assert.equal(link!.linkname, 'v1/data')
})

test('a corrupted header is detected', () => {
  const archive = createTar([{ path: 'a.txt', data: encoder.encode('A') }])
  archive[10] = 0x41
  assert.throws(() => readTar(archive), /checksum/)
})

test('GNU long names', () => {
  // A GNU tar "L" entry carries the real name of the next header
  const name = `${'x'.repeat(150)}.txt`
  const longHeader = createTar([{ path: '././@LongLink', data: encoder.encode(`${name}\u0000`) }]).subarray(0, 1024)
  longHeader[156] = 'L'.charCodeAt(0)
  // fix the checksum after changing the type flag
  longHeader.fill(0x20, 148, 156)
  let sum = 0
  for (let i = 0; i < 512; i++) sum += longHeader[i]!
  longHeader.set(encoder.encode(sum.toString(8).padStart(6, '0')), 148)
  longHeader[154] = 0
  longHeader[155] = 0x20
  const file = createTar([{ path: 'short.txt', data: encoder.encode('gnu') }])
  const archive = new Uint8Array(longHeader.length + file.length)
  archive.set(longHeader, 0)
  archive.set(file, longHeader.length)
  const entries = readTar(archive)
  assert.equal(entries.length, 1)
  assert.equal(entries[0]!.path, name)
  assert.equal(decoder.decode(entries[0]!.data), 'gnu')
})
