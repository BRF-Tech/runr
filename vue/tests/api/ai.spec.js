import { describe, it, expect, vi } from 'vitest'
import { uploadModelFiles, uploadPath, sha256Hex, DEFAULT_CHUNK_SIZE, CHUNK_ATTEMPTS } from '@/api/ai'

vi.mock('@/api/client', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() } }))

function blobOf(size, fill = 'a') {
  return new Blob([fill.repeat(size)])
}

function fakeApi(impl) {
  const calls = []
  return {
    calls,
    uploadChunk: vi.fn(async (modelId, uploadId, path, offset, blob) => {
      calls.push({ modelId, uploadId, path, offset, size: blob.size })
      if (impl) return impl(calls.length, { path, offset })
      return { data: { path, uploaded_bytes: offset + blob.size } }
    }),
  }
}

function httpError(status) {
  const err = new Error(`HTTP ${status}`)
  err.response = { status, data: {} }
  return err
}

describe('uploadModelFiles (chunked upload of air-gapped model files)', () => {
  it('slices every file by the session chunk size and sends each slice with its offset', async () => {
    const api = fakeApi()
    const progress = vi.fn()

    const result = await uploadModelFiles({
      modelId: 'am1',
      upload:  { id: 'up1', chunk_size: 4, files: [] },
      files:   [{ path: 'model.safetensors', file: blobOf(10) }, { path: 'config.json', file: blobOf(3) }],
      onProgress: progress,
      api,
    })

    expect(api.calls).toEqual([
      { modelId: 'am1', uploadId: 'up1', path: 'model.safetensors', offset: 0, size: 4 },
      { modelId: 'am1', uploadId: 'up1', path: 'model.safetensors', offset: 4, size: 4 },
      { modelId: 'am1', uploadId: 'up1', path: 'model.safetensors', offset: 8, size: 2 },
      { modelId: 'am1', uploadId: 'up1', path: 'config.json', offset: 0, size: 3 },
    ])
    expect(result).toEqual({ uploaded: 13, total: 13 })
    expect(progress).toHaveBeenLastCalledWith({ path: 'config.json', fileUploaded: 3, fileTotal: 3, uploaded: 13, total: 13 })
    expect(progress).toHaveBeenCalledTimes(4)
  })

  it('resumes after the bytes the session already has', async () => {
    const api = fakeApi()

    await uploadModelFiles({
      modelId: 'am1',
      upload:  { id: 'up1', chunk_size: 4, files: [{ path: 'a.bin', size: 10, uploaded_bytes: 8 }, { path: 'b.bin', size: 2, uploaded_bytes: 2 }] },
      files:   [{ path: 'a.bin', file: blobOf(10) }, { path: 'b.bin', file: blobOf(2) }],
      api,
    })

    expect(api.calls).toEqual([{ modelId: 'am1', uploadId: 'up1', path: 'a.bin', offset: 8, size: 2 }])
  })

  it('uses 8 MiB chunks when the session names none', () => {
    expect(DEFAULT_CHUNK_SIZE).toBe(8 * 1024 * 1024)
  })

  it('retries a chunk after a network error or 5xx, up to the attempt limit', async () => {
    let n = 0
    const api = fakeApi(() => {
      n++
      if (n === 1) throw new Error('network down')        // no response
      if (n === 2) throw httpError(502)
      return {}
    })

    await uploadModelFiles({ modelId: 'am1', upload: { id: 'up1', chunk_size: 8 }, files: [{ path: 'x', file: blobOf(5) }], api })

    expect(api.uploadChunk).toHaveBeenCalledTimes(3)
    expect(api.calls.every(c => c.offset === 0)).toBe(true)
  })

  it('gives up after the last attempt', async () => {
    const api = fakeApi(() => { throw httpError(503) })

    await expect(uploadModelFiles({ modelId: 'am1', upload: { id: 'up1', chunk_size: 8 }, files: [{ path: 'x', file: blobOf(5) }], api }))
      .rejects.toThrow('HTTP 503')
    expect(api.uploadChunk).toHaveBeenCalledTimes(CHUNK_ATTEMPTS)
  })

  it('does not retry a rejected chunk (4xx)', async () => {
    const api = fakeApi(() => { throw httpError(422) })

    await expect(uploadModelFiles({ modelId: 'am1', upload: { id: 'up1', chunk_size: 8 }, files: [{ path: 'x', file: blobOf(5) }], api }))
      .rejects.toThrow('HTTP 422')
    expect(api.uploadChunk).toHaveBeenCalledTimes(1)
  })

  it('stops between chunks when aborted', async () => {
    const controller = new AbortController()
    const api = fakeApi((n) => { if (n === 1) controller.abort(); return {} })

    await expect(uploadModelFiles({
      modelId: 'am1', upload: { id: 'up1', chunk_size: 2 }, files: [{ path: 'x', file: blobOf(6) }], signal: controller.signal, api,
    })).rejects.toMatchObject({ name: 'AbortError' })
    expect(api.uploadChunk).toHaveBeenCalledTimes(1)
  })

  it('reports empty files once without sending a chunk', async () => {
    const api = fakeApi()
    const progress = vi.fn()

    await uploadModelFiles({ modelId: 'am1', upload: { id: 'up1', chunk_size: 2 }, files: [{ path: 'empty.txt', file: blobOf(0) }], onProgress: progress, api })

    expect(api.uploadChunk).not.toHaveBeenCalled()
    expect(progress).toHaveBeenCalledWith({ path: 'empty.txt', fileUploaded: 0, fileTotal: 0, uploaded: 0, total: 0 })
  })
})

describe('uploadPath', () => {
  it('drops the picked folder from a folder pick and keeps sub-folders', () => {
    expect(uploadPath({ name: 'config.json', webkitRelativePath: 'my-model/config.json' })).toBe('config.json')
    expect(uploadPath({ name: 'a.bin', webkitRelativePath: 'my-model/onnx/a.bin' })).toBe('onnx/a.bin')
  })

  it('uses the file name for a file pick', () => {
    expect(uploadPath({ name: 'model.safetensors', webkitRelativePath: '' })).toBe('model.safetensors')
  })
})

describe('sha256Hex', () => {
  it('hashes a blob with Web Crypto', async () => {
    if (!globalThis.crypto?.subtle) return
    // sha256("abc")
    expect(await sha256Hex(new Blob(['abc']))).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad')
  })
})
