import client from './client'

// AI on RunR: GPU support of a region, Hugging Face models (library, offline snapshots,
// air-gapped uploads), model services and their bindings to projects (docs/ai.md,
// docs/huggingface.md).
export const aiApi = {
  // ── GPU support (region) ──────────────────────────────────────────────────────────
  gpu:                (regionId, refresh = false) => client.get(`/knative-regions/${regionId}/gpu`, { params: refresh ? { refresh: 1 } : {} }),
  installGpu:         (regionId, payload)         => client.post(`/knative-regions/${regionId}/gpu/install`, payload),
  uninstallGpu:       (regionId)                  => client.post(`/knative-regions/${regionId}/gpu/uninstall`),
  updateGpuSettings:  (regionId, payload)         => client.put(`/knative-regions/${regionId}/gpu/settings`, payload),
  modelStorage:       (regionId)                  => client.get(`/knative-regions/${regionId}/model-storage`),
  updateModelStorage: (regionId, payload)         => client.put(`/knative-regions/${regionId}/model-storage`, payload),

  // ── Hub + catalog ─────────────────────────────────────────────────────────────────
  engines:        ()                         => client.get('/models/engines'),
  hubSearch:      (params = {})              => client.get('/models/hub/search', { params }),
  hubInfo:        (id, revision = undefined) => client.get('/models/hub/info', { params: revision ? { id, revision } : { id } }),
  hubToken:       ()                         => client.get('/models/hub-token'),
  setHubToken:    (token)                    => client.put('/models/hub-token', { token }),
  removeHubToken: ()                         => client.delete('/models/hub-token'),
  gpuQuota:       (regionId)                 => client.get('/models/gpu-quota', { params: { knative_region_id: regionId } }),

  // ── Model library ─────────────────────────────────────────────────────────────────
  models:      ()                 => client.get('/models'),
  createModel: (payload)          => client.post('/models', payload),
  getModel:    (id)               => client.get(`/models/${id}`),
  updateModel: (id, payload)      => client.patch(`/models/${id}`, payload),
  deleteModel: (id)               => client.delete(`/models/${id}`),

  // Offline copies per region (mirror from the Hub, import from a PVC path / OCI artifact)
  snapshots:      (modelId)             => client.get(`/models/${modelId}/snapshots`),
  createSnapshot: (modelId, regionId)   => client.post(`/models/${modelId}/snapshots`, { knative_region_id: regionId }),
  deleteSnapshot: (modelId, snapshotId) => client.delete(`/models/${modelId}/snapshots/${snapshotId}`),
  snapshotLogs:   (modelId, snapshotId) => client.get(`/models/${modelId}/snapshots/${snapshotId}/logs`),

  // Air-gapped uploads (chunked, see uploadModelFiles below)
  createUpload:   (modelId, payload)  => client.post(`/models/${modelId}/uploads`, payload),
  getUpload:      (modelId, uploadId) => client.get(`/models/${modelId}/uploads/${uploadId}`),
  uploadChunk:    (modelId, uploadId, path, offset, blob, config = {}) => client.put(
    `/models/${modelId}/uploads/${uploadId}/chunk`,
    blob,
    { ...config, params: { path, offset }, headers: { 'Content-Type': 'application/octet-stream' } },
  ),
  completeUpload: (modelId, uploadId) => client.post(`/models/${modelId}/uploads/${uploadId}/complete`),
  abortUpload:    (modelId, uploadId) => client.delete(`/models/${modelId}/uploads/${uploadId}`),

  // ── Model services ────────────────────────────────────────────────────────────────
  services:        ()                      => client.get('/model-services'),
  createService:   (payload)               => client.post('/model-services', payload),
  getService:      (id)                    => client.get(`/model-services/${id}`),
  updateService:   (id, payload)           => client.patch(`/model-services/${id}`, payload),
  deleteService:   (id)                    => client.delete(`/model-services/${id}`),
  deployService:   (id)                    => client.post(`/model-services/${id}/deploy`),
  stopService:     (id)                    => client.post(`/model-services/${id}/stop`),
  serviceLogs:     (id, tail = 200)        => client.get(`/model-services/${id}/logs`, { params: { tail } }),
  invokeService:   (id, payload)           => client.post(`/model-services/${id}/invoke`, payload),
  serviceBindings: (id)                    => client.get(`/model-services/${id}/bindings`),

  // ── Project bindings ──────────────────────────────────────────────────────────────
  projectModels: (projectId)                     => client.get(`/projects/${projectId}/models`),
  bindModel:     (projectId, payload)            => client.post(`/projects/${projectId}/models`, payload),
  updateBinding: (projectId, bindingId, payload) => client.patch(`/projects/${projectId}/models/${bindingId}`, payload),
  unbindModel:   (projectId, bindingId)          => client.delete(`/projects/${projectId}/models/${bindingId}`),
}

/** Default chunk size when the upload session does not name one (8 MiB). */
export const DEFAULT_CHUNK_SIZE = 8 * 1024 * 1024

/** Attempts per chunk: a network error or a 5xx answer is retried, anything else fails at once. */
export const CHUNK_ATTEMPTS = 3

/**
 * Path of a picked file inside the model directory: the relative path of a folder pick
 * without the picked folder itself (`my-model/config.json` → `config.json`), else the name.
 */
export function uploadPath(file) {
  const rel = file?.webkitRelativePath || file?.relativePath || ''
  if (rel) {
    const parts = rel.split('/').filter(Boolean)
    return parts.length > 1 ? parts.slice(1).join('/') : parts.join('/')
  }
  return file?.name ?? ''
}

function retryable(err) {
  const status = err?.response?.status
  return !status || status >= 500
}

/**
 * Upload the files of an upload session chunk by chunk: each file is sliced into
 * `chunkSize` pieces that are PUT as raw bytes with their offset. Uploading resumes after the
 * bytes the session already has (`files[].uploaded_bytes`). Progress is reported after every
 * chunk: { path, fileUploaded, fileTotal, uploaded, total }.
 *
 * @param {object}   opts
 * @param {string}   opts.modelId
 * @param {object}   opts.upload      upload session (POST /models/{model}/uploads response)
 * @param {Array}    opts.files       [{ path, file: File|Blob }]
 * @param {Function} [opts.onProgress]
 * @param {AbortSignal} [opts.signal] stops between chunks
 * @param {object}   [opts.api]       api implementation (tests)
 * @returns {Promise<{ uploaded: number, total: number }>}
 */
export async function uploadModelFiles({ modelId, upload, files, onProgress, signal, api = aiApi }) {
  const chunkSize = Number(upload?.chunk_size) > 0 ? Number(upload.chunk_size) : DEFAULT_CHUNK_SIZE
  const already   = Object.fromEntries((upload?.files ?? []).map(f => [f.path, Number(f.uploaded_bytes) || 0]))
  const total     = files.reduce((sum, f) => sum + (f.file?.size ?? 0), 0)
  let uploaded    = files.reduce((sum, f) => sum + Math.min(already[f.path] ?? 0, f.file?.size ?? 0), 0)

  for (const { path, file } of files) {
    const size = file?.size ?? 0
    let offset = Math.min(already[path] ?? 0, size)

    while (offset < size) {
      if (signal?.aborted) {
        const err = new Error('Upload aborted')
        err.name = 'AbortError'
        throw err
      }
      const end  = Math.min(offset + chunkSize, size)
      const blob = file.slice(offset, end)

      let attempt = 0
      for (;;) {
        try {
          await api.uploadChunk(modelId, upload.id, path, offset, blob)
          break
        } catch (err) {
          attempt++
          if (attempt >= CHUNK_ATTEMPTS || !retryable(err) || signal?.aborted) throw err
        }
      }

      uploaded += end - offset
      offset = end
      onProgress?.({ path, fileUploaded: offset, fileTotal: size, uploaded, total })
    }

    if (size === 0) onProgress?.({ path, fileUploaded: 0, fileTotal: 0, uploaded, total })
  }

  return { uploaded, total }
}

/** Hex SHA-256 of a Blob (Web Crypto); null where it is unavailable. */
export async function sha256Hex(blob) {
  const subtle = globalThis.crypto?.subtle
  if (!subtle || !blob?.arrayBuffer) return null
  const digest = await subtle.digest('SHA-256', await blob.arrayBuffer())
  return [...new Uint8Array(digest)].map(b => b.toString(16).padStart(2, '0')).join('')
}

/** Files up to this size get a SHA-256 the receiver verifies (larger ones are checked by size). */
export const SHA256_MAX_BYTES = 64 * 1024 * 1024
