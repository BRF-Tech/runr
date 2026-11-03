import { afterEach, describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import {
  ALGORITHM, FIELD, encodePayload, encodeSolution, hashOf, isChallenge, runPowJob, sha256Hex,
  solveChallenge, solveChallengeAsync,
} from '@/modules/white-label/utils/captchaPow'

// The console's side of the local check against the shared contract's vectors: the one copy in
// api/tests/Fixtures/captcha/captcha-local-vectors.json that the API's LocalChallengeTest reads too
// (20 vectors: 7 positive, 13 negative, app_key "TEST"). The console solves and encodes; the API
// verifies — here every vector's challenge side, solution and encoding is reproduced.

// import.meta.dirname: under jsdom import.meta.url is not a file: URL
const file = JSON.parse(readFileSync(resolve(import.meta.dirname, '../../../api/tests/Fixtures/captcha/captcha-local-vectors.json'), 'utf8'))
const positives = file.vectors.filter(v => v.kind === 'positive')
const withJson = file.vectors.filter(v => v.solution.payload_json)

describe('the contract vectors', () => {
  it('are the contract file', () => {
    expect(file.algorithm).toBe(ALGORITHM)
    expect(file.defaults.payload_field).toBe(FIELD)
    expect(positives).toHaveLength(7)
    expect(file.vectors.filter(v => v.kind === 'negative')).toHaveLength(13)
  })

  it.each(file.vectors.map(v => [v.id, v]))('%s: the challenge hash is sha256(salt + number) where the vector says so', (id, v) => {
    expect(v.expected.hash).toBe(v.challenge.hash.toLowerCase())
    if (v.kind === 'positive') {
      expect(hashOf(v.challenge.salt, v.input.number)).toBe(v.expected.hash)
      expect(isChallenge(v.challenge)).toBe(true)
    }
  })

  it.each(positives.map(v => [v.id, v]))('%s: the scan finds the number', (id, v) => {
    expect(solveChallenge(v.challenge)).toBe(v.input.number)
  })

  it.each(positives.map(v => [v.id, v]))('%s: the solution is encoded byte for byte like the contract', (id, v) => {
    const decoded = JSON.parse(v.solution.payload_json)
    if (decoded.hash === v.challenge.hash) {
      expect(encodeSolution(v.challenge, v.input.number)).toBe(v.solution.payload_base64)
    }
    // P6 sends upper-case hex: the same JSON encodes the same way
    expect(encodePayload(decoded)).toBe(v.solution.payload_base64)
  })

  it.each(withJson.map(v => [v.id, v]))('%s: JSON → padded standard base64', (id, v) => {
    expect(encodePayload(JSON.parse(v.solution.payload_json))).toBe(v.solution.payload_base64)
  })

  it('a scan above the ceiling finds nothing (N5: the number is past maxnumber)', () => {
    const n5 = file.vectors.find(v => v.id === 'N5')
    expect(solveChallenge({ ...n5.challenge, maxnumber: n5.input.maxnumber })).toBeNull()
  })
})

describe('sha256Hex', () => {
  it.each([
    ['', 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'],
    ['abc', 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad'],
    ['abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq', '248d6a61d20638b8e5c026930c3e6039a33ce45964ff2167f6ecedd419db06c1'],
    ['a'.repeat(1000), '41edece42d63e8d9bf515a9ba6932e1c20cbc9f5a5d134645adb5db1b9737ea3'],
  ])('%#: the known digest', (input, digest) => {
    expect(sha256Hex(input)).toBe(digest)
  })
})

describe('solving', () => {
  const salt = '0123456789abcdef0123456789abcdef?expires=1762300600'
  const challenge = (n, maxnumber = 30000) => ({ algorithm: 'SHA-256', salt, hash: hashOf(salt, n), signature: '00'.repeat(32), maxnumber })

  it('reports progress', () => {
    const progress = vi.fn()
    expect(solveChallenge(challenge(25000), { onProgress: progress, every: 10000 })).toBe(25000)
    expect(progress.mock.calls).toEqual([[10000, 30000], [20000, 30000]])
  })

  it('refuses what is not a challenge', () => {
    expect(isChallenge(null)).toBe(false)
    expect(isChallenge({ ...challenge(1), algorithm: 'SHA-512' })).toBe(false)
    expect(isChallenge({ ...challenge(1), hash: 'nothex' })).toBe(false)
    expect(isChallenge({ ...challenge(1), maxnumber: '10' })).toBe(false)
    expect(() => solveChallenge({ algorithm: 'MD5' })).toThrow()
  })

  it('the main-thread fallback solves in slices and can be aborted', async () => {
    const progress = vi.fn()
    await expect(solveChallengeAsync(challenge(12345), { onProgress: progress, slice: 5000 })).resolves.toBe(12345)
    expect(progress).toHaveBeenCalledWith(5000, 30000)

    const controller = new AbortController()
    controller.abort()
    await expect(solveChallengeAsync(challenge(29000), { signal: controller.signal })).rejects.toMatchObject({ name: 'AbortError' })
    await expect(solveChallengeAsync({ ...challenge(1), hash: hashOf('other', 1), maxnumber: 2000 })).resolves.toBeNull()
  })

  it('a worker job reports progress, then the encoded answer', () => {
    const post = vi.fn()
    let clock = 100
    runPowJob({ challenge: challenge(21000) }, post, () => (clock += 50))

    const messages = post.mock.calls.map(([m]) => m)
    expect(messages.filter(m => m.type === 'progress').map(m => m.done)).toEqual([10000, 20000])
    const solved = messages.at(-1)
    expect(solved).toMatchObject({ type: 'solved', number: 21000, took: 50 })
    expect(JSON.parse(atob(solved.payload))).toEqual({ algorithm: 'SHA-256', salt, number: 21000, hash: hashOf(salt, 21000), signature: '00'.repeat(32) })
  })

  it('a worker job reports what went wrong', () => {
    const post = vi.fn()
    runPowJob({ challenge: { ...challenge(1), hash: hashOf('other', 1), maxnumber: 100 } }, post)
    expect(post).toHaveBeenLastCalledWith({ type: 'error', error: 'unsolved' })

    runPowJob({}, post)
    expect(post.mock.calls.at(-1)[0].type).toBe('error')
  })
})

describe('the Web Worker', () => {
  afterEach(() => { self.onmessage = null })

  it('answers a {challenge} message with the solved answer', async () => {
    const post = vi.spyOn(self, 'postMessage').mockImplementation(() => {})
    await import('@/modules/white-label/workers/captchaPow.worker.js')

    const salt = 'feedfacefeedfacefeedfacefeedface?expires=1762300600'
    self.onmessage({ data: { challenge: { algorithm: 'SHA-256', salt, hash: hashOf(salt, 42), signature: 'ab'.repeat(32), maxnumber: 1000 } } })

    const solved = post.mock.calls.map(([m]) => m).find(m => m.type === 'solved')
    expect(solved.number).toBe(42)
    expect(solved.payload).toBe(encodeSolution({ algorithm: 'SHA-256', salt, hash: hashOf(salt, 42), signature: 'ab'.repeat(32) }, 42))
  })
})
