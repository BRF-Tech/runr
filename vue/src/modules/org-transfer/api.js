import client from '@/api/client'
import { CHUNK_ATTEMPTS } from '@/api/ai'

/**
 * Organization export / import (docs/org-export.md): a versioned package of the organization —
 * projects with every version's code, configs, domains, bindings and optionally their data,
 * images and secrets — downloaded or written to S3, and imported into this or another RunR
 * installation after a dry-run plan. Owners only (org.transfer + the Owner role).
 */
export const orgTransferApi = {
  list:     ()              => client.get('/org-transfers'),
  get:      (id)            => client.get(`/org-transfers/${id}`),
  cancel:   (id)            => client.delete(`/org-transfers/${id}`),
  estimate: (options)       => client.post('/org-transfers/export/estimate', options),
  // the passphrase and S3 credentials go with this one request only — the API never stores them
  startExport: (payload)    => client.post('/org-transfers/export', payload),
  download: (id, config = {}) => client.get(`/org-transfers/${id}/download`, { responseType: 'blob', timeout: 0, ...config }),

  startImport:  (payload)   => client.post('/org-transfers/import', payload),
  uploadChunk:  (id, offset, blob, config = {}) => client.put(`/org-transfers/${id}/chunk`, blob, {
    params:  { offset },
    headers: { 'Content-Type': 'application/octet-stream' },
    timeout: 0,
    ...config,
  }),
  complete: (id)            => client.post(`/org-transfers/${id}/complete`, {}),
  // resume reading the package of an import from its binding / bucket (S3: the credentials again)
  resumeFetch: (id, credentials = {}) => client.post(`/org-transfers/${id}/fetch`, credentials),
  // the usage records and bills of the source an import kept, read-only
  history:  (id, params = {}) => client.get(`/org-transfers/${id}/history`, { params }),
  plan:     (id, choices)   => client.post(`/org-transfers/${id}/plan`, choices),
  apply:    (id, choices)   => client.post(`/org-transfers/${id}/apply`, choices),
}

/** Default chunk size when the transfer does not name one (8 MiB). */
export const DEFAULT_CHUNK_SIZE = 8 * 1024 * 1024

/** Shortest passphrase the API accepts for encrypted secrets. */
export const MIN_PASSPHRASE = 12

/** Statuses of a transfer whose job still runs (or waits for the upload). */
export const ACTIVE_STATUSES = ['queued', 'running', 'uploading', 'fetching', 'verifying', 'applying']

/** Statuses after which nothing changes any more. */
export const FINAL_STATUSES = ['completed', 'failed', 'cancelled', 'expired']

function retryable(err) {
  const status = err?.response?.status
  return !status || status >= 500
}

/** The transfer of an API answer ({data: …} or the object itself). */
export function unwrap(res) {
  return res?.data?.data ?? res?.data ?? null
}

/**
 * Send a package chunk by chunk from `transfer.upload.uploaded_bytes` on (resume), each chunk PUT
 * as raw bytes with its offset and retried on network errors / 5xx. Progress after every chunk:
 * { uploaded, total }.
 *
 * @param {object}   opts
 * @param {object}   opts.transfer  the import transfer (POST /org-transfers/import or GET …/{id})
 * @param {Blob}     opts.file
 * @param {Function} [opts.onProgress]
 * @param {AbortSignal} [opts.signal]  stops between chunks
 * @param {object}   [opts.api]        api implementation (tests)
 */
export async function uploadPackage({ transfer, file, onProgress, signal, api = orgTransferApi }) {
  const upload    = transfer?.upload ?? {}
  const chunkSize = Number(upload.chunk_size) > 0 ? Number(upload.chunk_size) : DEFAULT_CHUNK_SIZE
  const total     = file?.size ?? 0
  let offset      = Math.min(Number(upload.uploaded_bytes) || 0, total)
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
        res = await api.uploadChunk(transfer.id, offset, blob)
        break
      } catch (err) {
        if (attempt >= CHUNK_ATTEMPTS || !retryable(err) || signal?.aborted) throw err
      }
    }

    // what the API holds contiguously: never move back, never past what was sent
    const received = Number(res?.data?.uploaded_bytes)
    offset = Number.isFinite(received) && received > offset ? Math.min(received, total) : end
    onProgress?.({ uploaded: offset, total })
  }

  return { uploaded: offset, total }
}

/** An open import of the same file (name and size) that can resume, else null. */
export function resumableImport(transfers, file) {
  if (!file) return null
  return (transfers ?? []).find((tr) => {
    if (tr.kind !== 'import' || tr.status !== 'uploading' || Number(tr.upload?.size_bytes) !== file.size) return false
    // the file name the upload was started with, when the API reports it
    const name = tr.package?.filename ?? tr.options?.filename ?? null
    return name === null || name === file.name
  }) ?? null
}

/** Saves the downloaded package (axios responseType 'blob') under its file name. */
export function savePackage(response, fallbackName) {
  const disposition = response?.headers?.['content-disposition'] ?? ''
  const name = disposition.match(/filename="?([^";]+)"?/)?.[1] ?? fallbackName
  const url = URL.createObjectURL(response.data)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}

/**
 * The export request of the form: only the fields the API takes, credentials only for an S3
 * destination, the passphrase only with secrets.
 */
export function exportPayload(form) {
  const payload = {
    include_members: !!form.includeMembers,
    include_images:  !!form.includeImages,
    include_secrets: !!form.includeSecrets,
    include_history: !!form.includeHistory,
  }
  if (form.allProjects === false) payload.projects = [...(form.projects ?? [])]
  if (form.bindingData === 'all') payload.binding_data = 'all'
  else if (Array.isArray(form.bindingData) && form.bindingData.length) payload.binding_data = [...form.bindingData]
  if (form.includeSecrets) payload.passphrase = form.passphrase

  const dest = form.destination ?? { type: 'download' }
  if (dest.type === 'binding') {
    payload.destination = { type: 'binding', binding_id: dest.bindingId, ...(dest.prefix ? { prefix: dest.prefix } : {}) }
  } else if (dest.type === 's3') {
    payload.destination = {
      type:              's3',
      endpoint:          dest.endpoint,
      bucket:            dest.bucket,
      ...(dest.region ? { region: dest.region } : {}),
      access_key_id:     dest.accessKeyId,
      secret_access_key: dest.secretAccessKey,
      path_style:        !!dest.pathStyle,
      ...(dest.prefix ? { prefix: dest.prefix } : {}),
    }
  } else {
    payload.destination = { type: 'download' }
  }
  return payload
}

/**
 * The import request of a package RunR reads itself (docs/org-export.md#import): an object
 * binding of the organization or an S3 bucket — the credentials only for S3, never stored.
 */
export function importSourcePayload(source) {
  const key = String(source.key ?? '').trim().replace(/^\/+/, '')
  const sha256 = String(source.sha256 ?? '').trim().toLowerCase()
  const out = source.type === 's3'
    ? {
        type:              's3',
        endpoint:          String(source.endpoint ?? '').trim(),
        bucket:            String(source.bucket ?? '').trim(),
        ...(source.region ? { region: String(source.region).trim() } : {}),
        path_style:        !!source.pathStyle,
        key,
        access_key_id:     source.accessKeyId,
        secret_access_key: source.secretAccessKey,
      }
    : { type: 'binding', binding_id: source.bindingId, key }
  return { source: out, ...(sha256 ? { sha256 } : {}) }
}

/** Whether a source form has what its type needs. */
export function importSourceComplete(source) {
  if (!String(source.key ?? '').trim()) return false
  if (source.type === 's3') return !!(source.endpoint && source.bucket && source.accessKeyId && source.secretAccessKey)
  return !!source.bindingId
}

/** The estimate request: the export options without destination and passphrase. */
export function estimatePayload(form) {
  const payload = exportPayload({ ...form, includeSecrets: false, destination: { type: 'download' } })
  delete payload.destination
  return { ...payload, include_secrets: !!form.includeSecrets }
}
