import { describe, it, expect, vi } from 'vitest'
import { uploadPackage, resumableImport, exportPayload, estimatePayload, unwrap, importSourcePayload, importSourceComplete } from '@/modules/org-transfer/api'
import { transfer } from './support'

// The package upload loop and the request builders of the export / import (docs/org-export.md).

function file(size, name = 'runr-org-acme.tar.zst') {
  const blob = new Blob([new Uint8Array(size).fill(7)])
  return Object.assign(blob, { name })
}

function importing(extra = {}) {
  return transfer({ id: 'im-1', kind: 'import', status: 'uploading', upload: { size_bytes: 10, uploaded_bytes: 0, chunk_size: 4, sha256: null }, ...extra })
}

describe('uploadPackage', () => {
  it('sends the file in chunks at their offsets and reports progress', async () => {
    const api = { uploadChunk: vi.fn(async (id, offset, blob) => ({ data: { uploaded_bytes: offset + blob.size, size_bytes: 10 } })) }
    const progress = []

    const result = await uploadPackage({ transfer: importing(), file: file(10), api, onProgress: p => progress.push(p.uploaded) })

    expect(api.uploadChunk.mock.calls.map(c => [c[0], c[1], c[2].size])).toEqual([['im-1', 0, 4], ['im-1', 4, 4], ['im-1', 8, 2]])
    expect(progress).toEqual([0, 4, 8, 10])
    expect(result).toEqual({ uploaded: 10, total: 10 })
  })

  it('resumes after what the API already holds', async () => {
    const api = { uploadChunk: vi.fn(async (id, offset, blob) => ({ data: { uploaded_bytes: offset + blob.size } })) }

    await uploadPackage({ transfer: importing({ upload: { size_bytes: 10, uploaded_bytes: 8, chunk_size: 4 } }), file: file(10), api })

    expect(api.uploadChunk).toHaveBeenCalledTimes(1)
    expect(api.uploadChunk.mock.calls[0][1]).toBe(8)
  })

  it('never moves back when the API answers less than was sent', async () => {
    const answers = [4, 4, 8]
    const api = { uploadChunk: vi.fn(async () => ({ data: { uploaded_bytes: answers.shift() } })) }

    await uploadPackage({ transfer: importing(), file: file(10), api })

    // 0 → 4, then 4 → 4 (not moved back: the next chunk starts after what was sent), 8 → 8 (end)
    expect(api.uploadChunk.mock.calls.map(c => c[1])).toEqual([0, 4, 8])
  })

  it('retries a chunk on network errors and 5xx, not on 4xx', async () => {
    const flaky = { uploadChunk: vi.fn()
      .mockRejectedValueOnce(new Error('network'))
      .mockRejectedValueOnce({ response: { status: 502 } })
      .mockImplementation(async (id, offset, blob) => ({ data: { uploaded_bytes: offset + blob.size } })) }
    await uploadPackage({ transfer: importing({ upload: { size_bytes: 4, uploaded_bytes: 0, chunk_size: 4 } }), file: file(4), api: flaky })
    expect(flaky.uploadChunk).toHaveBeenCalledTimes(3)

    const refused = { uploadChunk: vi.fn().mockRejectedValue({ response: { status: 409, data: { code: 'not_uploading' } } }) }
    await expect(uploadPackage({ transfer: importing(), file: file(10), api: refused })).rejects.toMatchObject({ response: { status: 409 } })
    expect(refused.uploadChunk).toHaveBeenCalledTimes(1)
  })

  it('stops between chunks when aborted', async () => {
    const controller = new AbortController()
    const api = { uploadChunk: vi.fn(async (id, offset, blob) => { controller.abort(); return { data: { uploaded_bytes: offset + blob.size } } }) }

    await expect(uploadPackage({ transfer: importing(), file: file(10), api, signal: controller.signal })).rejects.toMatchObject({ name: 'AbortError' })
    expect(api.uploadChunk).toHaveBeenCalledTimes(1)
  })
})

describe('resumableImport', () => {
  it('finds the open import of the same file by size (and name when known)', () => {
    const open = importing({ upload: { size_bytes: 10, uploaded_bytes: 4, chunk_size: 4 }, options: { filename: 'runr-org-acme.tar.zst' } })
    const done = importing({ id: 'im-2', status: 'planned' })

    expect(resumableImport([done, open], file(10))?.id).toBe('im-1')
    expect(resumableImport([open], file(11))).toBeNull()
    expect(resumableImport([open], file(10, 'other.tar.zst'))).toBeNull()
    expect(resumableImport([open], null)).toBeNull()
  })
})

describe('import sources', () => {
  it('builds a binding source and an S3 source with its credentials only', () => {
    expect(importSourcePayload({ type: 'binding', bindingId: 'b9', key: '/runr-exports/p.tar.zst', sha256: 'AB' })).toEqual({
      source: { type: 'binding', binding_id: 'b9', key: 'runr-exports/p.tar.zst' }, sha256: 'ab',
    })
    expect(importSourcePayload({ type: 's3', endpoint: 'https://s3.example.com', bucket: 'b', region: '', pathStyle: true, key: 'k.tar.zst', accessKeyId: 'AK', secretAccessKey: 'SK' })).toEqual({
      source: { type: 's3', endpoint: 'https://s3.example.com', bucket: 'b', path_style: true, key: 'k.tar.zst', access_key_id: 'AK', secret_access_key: 'SK' },
    })
  })

  it('needs a key, and the binding or the bucket with its credentials', () => {
    expect(importSourceComplete({ type: 'binding', bindingId: 'b9', key: '' })).toBe(false)
    expect(importSourceComplete({ type: 'binding', bindingId: 'b9', key: 'k' })).toBe(true)
    expect(importSourceComplete({ type: 's3', endpoint: 'https://s3', bucket: 'b', key: 'k', accessKeyId: 'AK', secretAccessKey: '' })).toBe(false)
    expect(importSourceComplete({ type: 's3', endpoint: 'https://s3', bucket: 'b', key: 'k', accessKeyId: 'AK', secretAccessKey: 'SK' })).toBe(true)
  })
})

describe('request builders', () => {
  const base = {
    allProjects: true, projects: [], includeMembers: true, includeImages: false, bindingData: ['b1'],
    includeSecrets: true, passphrase: 'correct horse battery', destination: { type: 'download' },
  }

  it('builds the export request with the passphrase only when secrets are included', () => {
    expect(exportPayload(base)).toEqual({
      include_members: true, include_images: false, include_secrets: true, include_history: false, binding_data: ['b1'],
      passphrase: 'correct horse battery', destination: { type: 'download' },
    })
    expect(exportPayload({ ...base, includeHistory: true }).include_history).toBe(true)
    expect(exportPayload({ ...base, includeSecrets: false })).not.toHaveProperty('passphrase')
    expect(exportPayload({ ...base, allProjects: false, projects: ['p1'] }).projects).toEqual(['p1'])
    expect(exportPayload({ ...base, bindingData: 'all' }).binding_data).toBe('all')
  })

  it('sends S3 credentials and binding targets in the destination', () => {
    const s3 = exportPayload({ ...base, destination: { type: 's3', endpoint: 'https://s3.example.com', bucket: 'b', region: '', accessKeyId: 'AK', secretAccessKey: 'SK', pathStyle: true, prefix: 'runr-exports' } })
    expect(s3.destination).toEqual({ type: 's3', endpoint: 'https://s3.example.com', bucket: 'b', access_key_id: 'AK', secret_access_key: 'SK', path_style: true, prefix: 'runr-exports' })

    const binding = exportPayload({ ...base, destination: { type: 'binding', bindingId: 'b9', prefix: '' } })
    expect(binding.destination).toEqual({ type: 'binding', binding_id: 'b9' })
  })

  it('asks the estimate without destination and passphrase', () => {
    const payload = estimatePayload({ ...base, destination: { type: 's3', accessKeyId: 'AK', secretAccessKey: 'SK' } })
    expect(payload).not.toHaveProperty('destination')
    expect(payload).not.toHaveProperty('passphrase')
    expect(payload).toMatchObject({ include_members: true, include_secrets: true, binding_data: ['b1'] })
  })

  it('unwraps {data} answers', () => {
    expect(unwrap({ data: { data: { id: 'x' } } })).toEqual({ id: 'x' })
    expect(unwrap({ data: { id: 'y' } })).toEqual({ id: 'y' })
  })
})
