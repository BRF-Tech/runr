import type { SandboxContext } from './context.js'
import { decodeText, normalizeMode, toBase64, toFileEntry } from './convert.js'
import { NotFoundError } from './errors.js'
import { unwrap } from './http.js'
import { createTar, readTar, type TarEntry } from './tar.js'
import type { FileEntry } from './types.js'

const encoder = new TextEncoder()

/** File operations of a sandbox (`sandbox.files`). Paths are absolute. */
export class Files {
  constructor(private readonly sandbox: SandboxContext) {}

  /** Entries of a directory (a file's own entry when `path` is a file). */
  async list(path = '/workspace'): Promise<FileEntry[]> {
    const data = unwrap<{ entries?: Record<string, unknown>[] }>(
      await this.sandbox.http.json('GET', this.sandbox.path('/files'), { query: { path } }),
    )
    return (data?.entries ?? []).map(toFileEntry)
  }

  async read(path: string): Promise<Uint8Array> {
    return this.sandbox.http.bytes('GET', this.sandbox.path('/files/content'), {
      query: { path },
      accept: 'application/octet-stream',
    })
  }

  async readText(path: string): Promise<string> {
    return decodeText(await this.read(path))
  }

  /** Write a file; parent directories are created. `mode` like `0o755` or `'0755'`. */
  async write(path: string, data: string | Uint8Array, options: { mode?: number | string } = {}): Promise<{ path: string; size: number }> {
    const bytes = typeof data === 'string' ? encoder.encode(data) : data
    const payload = options.mode !== undefined
      ? await this.sandbox.http.json('PUT', this.sandbox.path('/files/content'), {
          query: { path },
          json: { content_base64: toBase64(bytes), mode: normalizeMode(options.mode) },
        })
      : await this.sandbox.http.json('PUT', this.sandbox.path('/files/content'), {
          query: { path },
          body: bytes,
          contentType: 'application/octet-stream',
        })
    return unwrap(payload)
  }

  async remove(path: string, options: { recursive?: boolean } = {}): Promise<void> {
    await this.sandbox.http.json('DELETE', this.sandbox.path('/files'), {
      query: { path, recursive: options.recursive ? 1 : undefined },
    })
  }

  async mkdir(path: string): Promise<void> {
    await this.sandbox.http.json('POST', this.sandbox.path('/files/mkdir'), { json: { path } })
  }

  async exists(path: string): Promise<boolean> {
    try {
      await this.sandbox.http.json('GET', this.sandbox.path('/files'), { query: { path } })
      return true
    } catch (error) {
      if (error instanceof NotFoundError) return false
      throw error
    }
  }

  /** Extract a tar archive into `dir`. */
  async uploadArchive(tar: Uint8Array, dir = '/workspace'): Promise<{ path: string; bytes: number }> {
    return unwrap(await this.sandbox.http.json('PUT', this.sandbox.path('/files/archive'), {
      query: { path: dir },
      body: tar,
      contentType: 'application/x-tar',
    }))
  }

  /** A tar archive of `dir`'s contents (entries relative to it). */
  async downloadArchive(dir = '/workspace'): Promise<Uint8Array> {
    return this.sandbox.http.bytes('GET', this.sandbox.path('/files/archive'), {
      query: { path: dir },
      accept: 'application/x-tar',
    })
  }

  /** Upload in-memory files `{relative path: content}` into `dir` as one archive. */
  async uploadDirectory(entries: Record<string, string | Uint8Array>, dir = '/workspace'): Promise<{ path: string; bytes: number }> {
    const tarEntries: TarEntry[] = Object.entries(entries).map(([path, content]) => ({
      path,
      type: 'file',
      data: typeof content === 'string' ? encoder.encode(content) : content,
    }))
    return this.uploadArchive(createTar(tarEntries), dir)
  }

  /** The files below `dir` as `{relative path: content}`. */
  async downloadDirectory(dir = '/workspace'): Promise<Record<string, Uint8Array>> {
    const out: Record<string, Uint8Array> = {}
    for (const entry of readTar(await this.downloadArchive(dir))) {
      if (entry.type === 'file' || entry.type === undefined) out[entry.path] = entry.data ?? new Uint8Array(0)
    }
    return out
  }
}
