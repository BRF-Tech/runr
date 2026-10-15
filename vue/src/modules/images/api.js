import client from '@/api/client'
import { sha256Hex, SHA256_MAX_BYTES, CHUNK_ATTEMPTS } from '@/api/ai'

/**
 * Uploaded images (docs/images.md): container images (docker save / OCI layout tars) and
 * WebAssembly modules uploaded into a region's registry, chunk by chunk through RunR.
 */
export const imagesApi = {
  list:     (params = {}) => client.get('/images', { params }),
  get:      (id)          => client.get(`/images/${id}`),
  remove:   (id)          => client.delete(`/images/${id}`),
  quota:    ()            => client.get('/images/quota'),

  uploads:        ()          => client.get('/images/uploads'),
  createUpload:   (payload)   => client.post('/images/uploads', payload),
  getUpload:      (id)        => client.get(`/images/uploads/${id}`),
  uploadChunk:    (id, offset, blob, config = {}) => client.put(`/images/uploads/${id}/chunk`, blob, {
    params:  { offset },
    headers: { 'Content-Type': 'application/octet-stream' },
    timeout: 0,
    ...config,
  }),
  completeUpload: (id)        => client.post(`/images/uploads/${id}/complete`),
  abortUpload:    (id)        => client.delete(`/images/uploads/${id}`),
}

/** Default chunk size when the upload does not name one (8 MiB). */
export const DEFAULT_CHUNK_SIZE = 8 * 1024 * 1024

/** Statuses of an upload whose receiver still runs. */
export const OPEN_STATUSES = ['preparing', 'ready', 'verifying', 'pushing']

function retryable(err) {
  const status = err?.response?.status
  return !status || status >= 500
}

/**
 * Send the file of an upload chunk by chunk from `upload.uploaded_bytes` on (resume), each chunk
 * PUT as raw bytes with its offset and retried on network errors / 5xx. Progress after every
 * chunk: { uploaded, total }.
 *
 * @param {object}   opts
 * @param {object}   opts.upload   the upload (POST /images/uploads or GET …/{id})
 * @param {Blob}     opts.file
 * @param {Function} [opts.onProgress]
 * @param {AbortSignal} [opts.signal]  stops between chunks
 * @param {object}   [opts.api]        api implementation (tests)
 */
export async function uploadImageFile({ upload, file, onProgress, signal, api = imagesApi }) {
  const chunkSize = Number(upload?.chunk_size) > 0 ? Number(upload.chunk_size) : DEFAULT_CHUNK_SIZE
  const total     = file?.size ?? 0
  let offset      = Math.min(Number(upload?.uploaded_bytes) || 0, total)
  onProgress?.({ uploaded: offset, total })

  while (offset < total) {
    if (signal?.aborted) {
      const err = new Error('Upload aborted')
      err.name = 'AbortError'
      throw err
    }
    const end  = Math.min(offset + chunkSize, total)
    const blob = file.slice(offset, end)

    let res
    for (let attempt = 1; ; attempt++) {
      try {
        res = await api.uploadChunk(upload.id, offset, blob)
        break
      } catch (err) {
        if (attempt >= CHUNK_ATTEMPTS || !retryable(err) || signal?.aborted) throw err
      }
    }

    // the region's contiguous prefix: never move back, never past what was sent
    const received = Number(res?.data?.uploaded_bytes)
    offset = Number.isFinite(received) && received > offset ? Math.min(received, total) : end
    onProgress?.({ uploaded: offset, total })
  }

  return { uploaded: offset, total }
}

/** SHA-256 the region verifies, for files small enough to hash in the browser (else null). */
export async function fileSha256(file) {
  return file && file.size <= SHA256_MAX_BYTES ? sha256Hex(file) : null
}

/** repository[:tag] from a file name, as the API derives it (myapp.tar.gz → myapp). */
export function nameFromFilename(filename) {
  const base = String(filename ?? '').split(/[\\/]/).pop().toLowerCase()
    .replace(/(\.tar\.gz|\.tgz|\.tar|\.oci|\.wasm|\.gz)+$/, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
  return base || 'image'
}

/** Whether a name is repository[:tag] in the grammar the API accepts. */
export function validName(name) {
  const value = String(name ?? '').trim()
  if (!value) return true // empty: derived from the file name
  const i = value.lastIndexOf(':')
  const slash = value.lastIndexOf('/')
  const [repo, tag] = i > slash ? [value.slice(0, i), value.slice(i + 1)] : [value, 'latest']
  const repoOk = repo.length <= 128 && /^[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*(?:\/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*){0,2}$/.test(repo)
  return repoOk && /^[A-Za-z0-9_][A-Za-z0-9._-]{0,127}$/.test(tag)
}
