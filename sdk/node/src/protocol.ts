/**
 * Wire format of the RunR workflow protocol v1 (docs/workflows-protocol.md).
 *
 * Request (engine → function), HTTP POST, JSON body:
 *   {"runr_workflow": "v1", "signature": "t=…,v1=…", "request": "<JSON string>"}
 * The signature is also sent as the X-RunR-Signature header:
 *   t=<unix seconds>,v1=<hex HMAC-SHA256(key, "<t>.<request>")>[,v1=…]
 */
import { createHmac, timingSafeEqual } from 'crypto'

export const VERSION = 'v1'
export const HEADER_SIGNATURE = 'X-RunR-Signature'
export const HEADER_PROTOCOL = 'X-RunR-Workflow-Protocol'
export const DEFAULT_TOLERANCE = 300

export interface Envelope {
  runr_workflow: string
  signature?: string
  request: string
}

export function digest(request: string, key: string, timestamp: number): string {
  return createHmac('sha256', key).update(`${timestamp}.${request}`, 'utf8').digest('hex')
}

export function sign(request: string, keys: string[], timestamp?: number): string {
  const t = Math.floor(timestamp ?? Date.now() / 1000)
  return [`t=${t}`, ...keys.map((k) => `v1=${digest(request, k, t)}`)].join(',')
}

export function parseSignature(header: string): { t: number | null; v1: string[] } {
  let t: number | null = null
  const v1: string[] = []
  for (const part of (header || '').split(',')) {
    const idx = part.indexOf('=')
    if (idx < 0) continue
    const key = part.slice(0, idx).trim()
    const value = part.slice(idx + 1).trim()
    if (key === 't' && /^\d+$/.test(value)) t = Number(value)
    else if (key === 'v1' && value) v1.push(value.toLowerCase())
  }
  return { t, v1 }
}

function safeEqual(a: string, b: string): boolean {
  const ab = Buffer.from(a, 'utf8')
  const bb = Buffer.from(b, 'utf8')
  return ab.length === bb.length && timingSafeEqual(ab, bb)
}

export function verify(
  request: string,
  header: string,
  keys: Array<string | null | undefined>,
  tolerance = DEFAULT_TOLERANCE,
  now?: number,
): boolean {
  const { t, v1 } = parseSignature(header)
  if (t === null || v1.length === 0) return false
  const current = Math.floor(now ?? Date.now() / 1000)
  if (Math.abs(current - t) > tolerance) return false
  for (const key of keys) {
    if (!key) continue
    const expected = digest(request, key, t)
    if (v1.some((candidate) => safeEqual(expected, candidate))) return true
  }
  return false
}

/** A signed envelope — used by tests and local tooling to play the engine. */
export function envelope(request: unknown, keys: string[], timestamp?: number): Envelope {
  const raw = JSON.stringify(request)
  return { runr_workflow: VERSION, signature: sign(raw, keys, timestamp), request: raw }
}
