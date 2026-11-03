// The local proof-of-work check of the bot protection: the shared contract "captcha-local-spec",
// twin of the API's App\Services\Captcha\LocalChallenge. Every encoding detail of the console's
// side lives here (the Web Worker and the main-thread fallback both use it); the contract's
// vectors (api/tests/Fixtures/captcha/captcha-local-vectors.json) check it in
// tests/white-label/captchaPow.spec.js.
//
//   challenge  {algorithm: "SHA-256", salt: "<32 hex>?expires=<unix s>", hash, signature, maxnumber}
//   the scan   the number 0 … maxnumber whose sha256(salt + number) (decimal ASCII) is `hash`
//   solution   {algorithm, salt, number, hash, signature} → JSON → padded standard base64 → `captcha_local`
//
// SHA-256 is computed here, synchronously: crypto.subtle answers with a promise per hash, which
// costs more than the hash itself for these short inputs.

export const ALGORITHM = 'SHA-256'
export const FIELD = 'captcha_local'

const K = Uint32Array.from([
  0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
  0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
  0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
  0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
  0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
  0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
  0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
  0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
])
const IV = Uint32Array.from([0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19])
const W = new Uint32Array(64)

const encoder = new TextEncoder()

function compress(H, b, off) {
  for (let i = 0; i < 16; i++) {
    const j = off + i * 4
    W[i] = (b[j] << 24) | (b[j + 1] << 16) | (b[j + 2] << 8) | b[j + 3]
  }
  for (let i = 16; i < 64; i++) {
    const w15 = W[i - 15]
    const w2 = W[i - 2]
    const s0 = ((w15 >>> 7) | (w15 << 25)) ^ ((w15 >>> 18) | (w15 << 14)) ^ (w15 >>> 3)
    const s1 = ((w2 >>> 17) | (w2 << 15)) ^ ((w2 >>> 19) | (w2 << 13)) ^ (w2 >>> 10)
    W[i] = (W[i - 16] + s0 + W[i - 7] + s1) | 0
  }
  let a = H[0], bb = H[1], c = H[2], d = H[3], e = H[4], f = H[5], g = H[6], h = H[7]
  for (let i = 0; i < 64; i++) {
    const S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7))
    const ch = (e & f) ^ (~e & g)
    const t1 = (h + S1 + ch + K[i] + W[i]) | 0
    const S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10))
    const maj = (a & bb) ^ (a & c) ^ (bb & c)
    const t2 = (S0 + maj) | 0
    h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = bb; bb = a; a = (t1 + t2) | 0
  }
  H[0] += a; H[1] += bb; H[2] += c; H[3] += d; H[4] += e; H[5] += f; H[6] += g; H[7] += h
}

/** SHA-256 of buf[0, len) into H (8 words); buf has room for the padding (len + 72 bytes). */
function digestInto(H, buf, len) {
  const total = ((len + 9 + 63) >> 6) << 6
  buf[len] = 0x80
  buf.fill(0, len + 1, total)
  const bits = len * 8
  buf[total - 4] = (bits >>> 24) & 0xff
  buf[total - 3] = (bits >>> 16) & 0xff
  buf[total - 2] = (bits >>> 8) & 0xff
  buf[total - 1] = bits & 0xff
  H.set(IV)
  for (let off = 0; off < total; off += 64) compress(H, buf, off)
}

function toHex(H) {
  let out = ''
  for (let i = 0; i < 8; i++) out += H[i].toString(16).padStart(8, '0')
  return out
}

function hexWords(hex) {
  const value = String(hex ?? '').toLowerCase()
  if (!/^[0-9a-f]{64}$/.test(value)) return null
  const words = new Uint32Array(8)
  for (let i = 0; i < 8; i++) words[i] = parseInt(value.slice(i * 8, i * 8 + 8), 16)
  return words
}

/** sha256 of a string (UTF-8) or bytes, lower-case hex. */
export function sha256Hex(input) {
  const bytes = typeof input === 'string' ? encoder.encode(input) : input
  const buf = new Uint8Array(bytes.length + 72)
  buf.set(bytes)
  const H = new Uint32Array(8)
  digestInto(H, buf, bytes.length)
  return toHex(H)
}

/** hash = sha256(salt + number), the number in decimal ASCII. */
export function hashOf(salt, number) {
  return sha256Hex(`${salt}${number}`)
}

/** A challenge the API handed out (409 {captcha: "fallback", challenge} or POST /captcha/local/challenge). */
export function isChallenge(challenge) {
  return !!challenge && challenge.algorithm === ALGORITHM && typeof challenge.salt === 'string'
    && hexWords(challenge.hash) !== null && typeof challenge.signature === 'string'
    && Number.isInteger(challenge.maxnumber) && challenge.maxnumber >= 0
}

/**
 * The scan, synchronous (the Web Worker's loop). onProgress(done, total) every `every` numbers.
 * Returns the number, or null when no number up to maxnumber matches.
 */
export function solveChallenge(challenge, { onProgress, every = 10000, start = 0, end } = {}) {
  if (!isChallenge(challenge)) throw new Error('not a challenge')
  const target = hexWords(challenge.hash)
  const salt = encoder.encode(challenge.salt)
  const max = Math.min(end ?? challenge.maxnumber, challenge.maxnumber)
  const buf = new Uint8Array(salt.length + 24 + 72)
  buf.set(salt)
  const H = new Uint32Array(8)

  for (let n = start; n <= max; n++) {
    // the number's decimal digits after the salt
    let len = salt.length
    const digits = String(n)
    for (let i = 0; i < digits.length; i++) buf[len++] = digits.charCodeAt(i)
    digestInto(H, buf, len)
    if (H[0] === target[0] && H[1] === target[1] && H[2] === target[2] && H[3] === target[3]
      && H[4] === target[4] && H[5] === target[5] && H[6] === target[6] && H[7] === target[7]) {
      return n
    }
    if (onProgress && n > start && n % every === 0) onProgress(n, challenge.maxnumber)
  }
  return null
}

/**
 * The same scan in slices on the main thread (no Web Worker): yields between slices so the page
 * stays responsive. Resolves with the number or null; rejects with an AbortError when aborted.
 */
export async function solveChallengeAsync(challenge, { onProgress, signal, slice = 5000 } = {}) {
  if (!isChallenge(challenge)) throw new Error('not a challenge')
  for (let start = 0; start <= challenge.maxnumber; start += slice) {
    if (signal?.aborted) throw new DOMException('aborted', 'AbortError')
    const found = solveChallenge(challenge, { start, end: start + slice - 1 })
    if (found !== null) return found
    onProgress?.(Math.min(start + slice, challenge.maxnumber), challenge.maxnumber)
    await new Promise(resolve => setTimeout(resolve, 0))
  }
  return null
}

function base64(text) {
  const bytes = encoder.encode(text)
  let binary = ''
  for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i])
  return btoa(binary)
}

/** The solution JSON (field order algorithm, salt, number, hash, signature) in padded standard base64. */
export function encodePayload(solution) {
  return base64(JSON.stringify({
    algorithm: solution.algorithm,
    salt: solution.salt,
    number: solution.number,
    hash: solution.hash,
    signature: solution.signature,
  }))
}

/** The `captcha_local` value of a solved challenge. */
export function encodeSolution(challenge, number) {
  return encodePayload({ ...challenge, number })
}

/**
 * One job of the Web Worker (workers/captchaPow.worker.js): {challenge} in, messages out —
 * {type: "progress", done, total}, {type: "solved", number, payload, took}, {type: "error", error}.
 */
export function runPowJob(data, post, now = () => Date.now()) {
  try {
    const challenge = data?.challenge
    const started = now()
    const number = solveChallenge(challenge, { onProgress: (done, total) => post({ type: 'progress', done, total }) })
    if (number === null) {
      post({ type: 'error', error: 'unsolved' })
      return
    }
    post({ type: 'solved', number, payload: encodeSolution(challenge, number), took: now() - started })
  } catch (e) {
    post({ type: 'error', error: String(e?.message || e) })
  }
}
