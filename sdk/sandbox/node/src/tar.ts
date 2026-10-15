/**
 * A small tar (ustar + pax) writer and reader for directory transfer — no dependencies.
 * Long names go into the ustar prefix field or, beyond 255 bytes, a pax `path` record; the reader
 * also understands GNU long names (`L` / `K`) as written by GNU tar.
 */

export type TarEntryType = 'file' | 'directory' | 'symlink'

export interface TarEntry {
  /** Relative path, `/`-separated (`src/main.py`); a leading `/` or `./` is dropped. */
  path: string
  type?: TarEntryType
  /** Content of a file. */
  data?: Uint8Array
  /** Permission bits (default 0o644 for files, 0o755 for directories). */
  mode?: number
  /** Modification time (default now). */
  mtime?: Date | number
  /** Target of a symlink. */
  linkname?: string
}

const BLOCK = 512
const encoder = new TextEncoder()
const decoder = new TextDecoder()

function normalizePath(path: string): string {
  const parts: string[] = []
  for (const part of path.replace(/\\/g, '/').split('/')) {
    if (part === '' || part === '.') continue
    if (part === '..') throw new Error(`Invalid tar entry path: ${path}`)
    parts.push(part)
  }
  if (parts.length === 0) throw new Error(`Invalid tar entry path: ${path}`)
  return parts.join('/')
}

function writeString(block: Uint8Array, offset: number, length: number, value: string): void {
  const bytes = encoder.encode(value)
  block.set(bytes.subarray(0, length), offset)
}

function writeOctal(block: Uint8Array, offset: number, length: number, value: number): void {
  // length - 1 octal digits, zero-padded, then NUL
  const digits = Math.max(0, Math.floor(value)).toString(8).padStart(length - 1, '0')
  if (digits.length > length - 1) throw new Error(`Value ${value} does not fit a tar header field`)
  writeString(block, offset, length - 1, digits)
  block[offset + length - 1] = 0
}

function header(name: string, prefix: string, size: number, mode: number, mtime: number, typeflag: string, linkname = ''): Uint8Array {
  const block = new Uint8Array(BLOCK)
  writeString(block, 0, 100, name)
  writeOctal(block, 100, 8, mode)
  writeOctal(block, 108, 8, 0) // uid
  writeOctal(block, 116, 8, 0) // gid
  writeOctal(block, 124, 12, size)
  writeOctal(block, 136, 12, mtime)
  block.fill(0x20, 148, 156) // checksum placeholder: spaces
  writeString(block, 156, 1, typeflag)
  writeString(block, 157, 100, linkname)
  writeString(block, 257, 6, 'ustar')
  block[262] = 0
  writeString(block, 263, 2, '00')
  writeString(block, 345, 155, prefix)
  let sum = 0
  for (const byte of block) sum += byte
  const checksum = sum.toString(8).padStart(6, '0')
  writeString(block, 148, 6, checksum)
  block[154] = 0
  block[155] = 0x20
  return block
}

/** Split a name into ustar prefix (≤155 bytes) + name (≤100 bytes), or null when it does not fit. */
function splitName(path: string): [string, string] | null {
  if (encoder.encode(path).length <= 100) return ['', path]
  for (let i = path.length - 1; i > 0; i--) {
    if (path[i] !== '/') continue
    const prefix = path.slice(0, i)
    const name = path.slice(i + 1)
    if (encoder.encode(prefix).length <= 155 && encoder.encode(name).length <= 100 && name !== '') {
      return [prefix, name]
    }
  }
  return null
}

function paxRecord(key: string, value: string): string {
  const body = ` ${key}=${value}\n`
  let length = encoder.encode(body).length
  let record = `${length}${body}`
  // The length includes its own digits
  while (encoder.encode(record).length !== length) {
    length = encoder.encode(record).length
    record = `${length}${body}`
  }
  return record
}

function padded(data: Uint8Array): Uint8Array[] {
  const rest = data.length % BLOCK
  return rest === 0 ? [data] : [data, new Uint8Array(BLOCK - rest)]
}

function concat(parts: Uint8Array[]): Uint8Array {
  let total = 0
  for (const part of parts) total += part.length
  const out = new Uint8Array(total)
  let offset = 0
  for (const part of parts) {
    out.set(part, offset)
    offset += part.length
  }
  return out
}

/** A tar archive of the entries. */
export function createTar(entries: TarEntry[]): Uint8Array {
  const parts: Uint8Array[] = []
  for (const entry of entries) {
    const type = entry.type ?? 'file'
    let path = normalizePath(entry.path)
    if (type === 'directory') path += '/'
    const data = type === 'file' ? entry.data ?? new Uint8Array(0) : new Uint8Array(0)
    const mode = entry.mode ?? (type === 'directory' ? 0o755 : 0o644)
    const mtimeValue = entry.mtime instanceof Date ? entry.mtime.getTime() : entry.mtime ?? Date.now()
    const mtime = Math.floor(mtimeValue / 1000)
    const typeflag = type === 'directory' ? '5' : type === 'symlink' ? '2' : '0'
    const linkname = entry.linkname ?? ''

    const pax: string[] = []
    let split = splitName(path)
    if (!split) {
      pax.push(paxRecord('path', path))
      split = ['', path.slice(-100).replace(/^\/+/, '')]
    }
    if (encoder.encode(linkname).length > 100) pax.push(paxRecord('linkpath', linkname))
    if (pax.length > 0) {
      const records = encoder.encode(pax.join(''))
      parts.push(header('PaxHeader', '', records.length, 0o644, mtime, 'x'))
      parts.push(...padded(records))
    }
    parts.push(header(split[1], split[0], data.length, mode, mtime, typeflag, linkname.slice(0, 100)))
    if (data.length > 0) parts.push(...padded(data))
  }
  parts.push(new Uint8Array(BLOCK * 2))
  return concat(parts)
}

function readString(block: Uint8Array, offset: number, length: number): string {
  const slice = block.subarray(offset, offset + length)
  const end = slice.indexOf(0)
  return decoder.decode(end === -1 ? slice : slice.subarray(0, end))
}

function readNumber(block: Uint8Array, offset: number, length: number): number {
  const first = block[offset] ?? 0
  if (first & 0x80) {
    // base-256 (GNU) for large values
    let value = first & 0x7f
    for (let i = 1; i < length; i++) value = value * 256 + (block[offset + i] ?? 0)
    return value
  }
  const text = readString(block, offset, length).trim()
  return text ? parseInt(text, 8) : 0
}

function parsePax(data: Uint8Array): Record<string, string> {
  const out: Record<string, string> = {}
  const text = decoder.decode(data)
  let position = 0
  while (position < text.length) {
    const space = text.indexOf(' ', position)
    if (space === -1) break
    const length = parseInt(text.slice(position, space), 10)
    if (!Number.isFinite(length) || length <= 0) break
    // The length counts bytes; records here are ASCII keys + UTF-8 values, so re-slice by bytes
    const bytes = encoder.encode(text.slice(position))
    const record = decoder.decode(bytes.subarray(0, length))
    const body = record.slice(record.indexOf(' ') + 1).replace(/\n$/, '')
    const equals = body.indexOf('=')
    if (equals > 0) out[body.slice(0, equals)] = body.slice(equals + 1)
    position += record.length
  }
  return out
}

/** The entries of a tar archive (files with their data, directories and symlinks). */
export function readTar(archive: Uint8Array): TarEntry[] {
  const entries: TarEntry[] = []
  let offset = 0
  let pax: Record<string, string> = {}
  let longName: string | null = null
  let longLink: string | null = null

  while (offset + BLOCK <= archive.length) {
    const block = archive.subarray(offset, offset + BLOCK)
    if (block.every((byte) => byte === 0)) break
    offset += BLOCK

    let sum = 0
    for (let i = 0; i < BLOCK; i++) sum += i >= 148 && i < 156 ? 0x20 : block[i] ?? 0
    const stored = readNumber(block, 148, 8)
    if (stored !== sum) throw new Error('Invalid tar header checksum')

    const size = readNumber(block, 124, 12)
    const typeflag = String.fromCharCode(block[156] ?? 0x30)
    const data = archive.subarray(offset, offset + size)
    offset += Math.ceil(size / BLOCK) * BLOCK

    if (typeflag === 'x') {
      pax = parsePax(data)
      continue
    }
    if (typeflag === 'g') continue
    if (typeflag === 'L') {
      longName = readString(data, 0, data.length)
      continue
    }
    if (typeflag === 'K') {
      longLink = readString(data, 0, data.length)
      continue
    }

    const magic = readString(block, 257, 6)
    const prefix = magic.startsWith('ustar') ? readString(block, 345, 155) : ''
    let name = readString(block, 0, 100)
    if (prefix) name = `${prefix}/${name}`
    name = pax.path ?? longName ?? name
    const linkname = pax.linkpath ?? longLink ?? readString(block, 157, 100)
    pax = {}
    longName = null
    longLink = null

    const mode = readNumber(block, 100, 8)
    const mtime = readNumber(block, 136, 12) * 1000
    const path = name.replace(/^(\.\/)+/, '').replace(/\/+$/, '')
    if (path === '' || path === '.') continue

    if (typeflag === '5') {
      entries.push({ path, type: 'directory', mode, mtime })
    } else if (typeflag === '2') {
      entries.push({ path, type: 'symlink', linkname, mode, mtime })
    } else if (typeflag === '0' || typeflag === '\u0000' || typeflag === '7') {
      entries.push({ path, type: 'file', data: data.slice(), mode, mtime })
    }
    // Hard links, devices and FIFOs are skipped
  }
  return entries
}
