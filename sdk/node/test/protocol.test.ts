import { describe, expect, it } from 'vitest'
import { digest, envelope, parseSignature, sign, verify } from '../src/protocol'

// Vectors shared with the engine (PHP) and Python SDK tests
const T = 1700000000
const REQUEST = '{"type":"introspect","protocol":"v1","ts":1700000000}'

describe('signatures', () => {
  it('matches the shared vectors', () => {
    expect(digest(REQUEST, 'rwk_test_key', T)).toBe('838d3d8827a74b4ac397a08960c72044b191644d1fe0a873781bf6af995b239a')
    expect(digest('{"type":"execute","workflow":"orders","steps":{}}', 'rwk_other_key', T)).toBe(
      '33a2197f7add68c82a3a73656e8025afed0a2f86401f31caa89bba5990b11ace',
    )
  })

  it('signs with one v1 per key', () => {
    const header = sign(REQUEST, ['rwk_test_key', 'rwk_other_key'], T)
    expect(header.startsWith('t=1700000000,v1=838d3d88')).toBe(true)
    expect(parseSignature(header)).toEqual({ t: T, v1: expect.any(Array) })
    expect(parseSignature(header).v1).toHaveLength(2)
  })

  it('verifies within the tolerance only', () => {
    const header = sign(REQUEST, ['rwk_test_key'], T)
    expect(verify(REQUEST, header, ['rwk_test_key'], 300, T + 300)).toBe(true)
    expect(verify(REQUEST, header, ['rwk_test_key'], 300, T + 301)).toBe(false)
    expect(verify(REQUEST, header, ['rwk_test_key'], 300, T - 301)).toBe(false)
  })

  it('rejects tampering, wrong and empty keys', () => {
    const header = sign(REQUEST, ['rwk_test_key'], T)
    expect(verify(REQUEST + ' ', header, ['rwk_test_key'], 300, T)).toBe(false)
    expect(verify(REQUEST, header, ['rwk_wrong'], 300, T)).toBe(false)
    expect(verify(REQUEST, header, [null, undefined, ''], 300, T)).toBe(false)
  })

  it('supports rotation in both directions', () => {
    expect(verify(REQUEST, sign(REQUEST, ['rwk_new', 'rwk_previous'], T), ['rwk_previous'], 300, T)).toBe(true)
    expect(verify(REQUEST, sign(REQUEST, ['rwk_new'], T), ['rwk_previous', 'rwk_new'], 300, T)).toBe(true)
  })

  it('rejects malformed headers', () => {
    for (const header of ['', 'garbage', 't=abc,v1=00', 't=1700000000', 'v1=' + '0'.repeat(64), 't=1700000000,v1=short']) {
      expect(verify(REQUEST, header, ['rwk_test_key'], 300, T)).toBe(false)
    }
  })

  it('builds envelopes that verify', () => {
    const env = envelope({ type: 'introspect' }, ['rwk_test_key'])
    expect(env.runr_workflow).toBe('v1')
    expect(env.request).toBe('{"type":"introspect"}')
    expect(verify(env.request, env.signature!, ['rwk_test_key'])).toBe(true)
  })
})
