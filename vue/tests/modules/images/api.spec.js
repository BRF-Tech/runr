import { describe, it, expect, vi } from 'vitest'
import { uploadImageFile, nameFromFilename, validName, fileSha256 } from '@/modules/images/api'
import { upload } from './support'

function fakeApi(answers = {}) {
  const sent = []
  return {
    sent,
    uploadChunk: vi.fn(async (id, offset, blob) => {
      const text = await blob.text()
      sent.push([offset, text])
      const fail = answers.fail?.shift()
      if (fail) throw fail
      return { data: { uploaded_bytes: offset + text.length, size_bytes: 10 } }
    }),
  }
}

describe('uploadImageFile', () => {
  it('sends the file chunk by chunk and resumes after what the region has', async () => {
    const api = fakeApi()
    const progress = []
    const file = new Blob(['abcdefghij'])

    const res = await uploadImageFile({ upload: upload({ uploaded_bytes: 4 }), file, api, onProgress: p => progress.push(p.uploaded) })

    expect(api.sent).toEqual([[4, 'efgh'], [8, 'ij']])
    expect(res).toEqual({ uploaded: 10, total: 10 })
    expect(progress).toEqual([4, 8, 10])
  })

  it('retries network errors and 5xx answers, not 4xx', async () => {
    const api = fakeApi({ fail: [new Error('network'), { response: { status: 502 } }] })
    await uploadImageFile({ upload: upload(), file: new Blob(['abcd']), api })
    expect(api.uploadChunk).toHaveBeenCalledTimes(3)

    const refused = fakeApi({ fail: [{ response: { status: 422 } }] })
    await expect(uploadImageFile({ upload: upload(), file: new Blob(['abcd']), api: refused })).rejects.toEqual({ response: { status: 422 } })
    expect(refused.uploadChunk).toHaveBeenCalledTimes(1)
  })

  it('stops between chunks when aborted', async () => {
    const controller = new AbortController()
    const api = fakeApi()
    api.uploadChunk.mockImplementationOnce(async (id, offset, blob) => {
      controller.abort()
      return { data: { uploaded_bytes: offset + (await blob.text()).length } }
    })
    await expect(uploadImageFile({ upload: upload(), file: new Blob(['abcdefgh']), api, signal: controller.signal }))
      .rejects.toMatchObject({ name: 'AbortError' })
    expect(api.uploadChunk).toHaveBeenCalledTimes(1)
  })
})

describe('names', () => {
  it('derives repository names from file names like the API', () => {
    expect(nameFromFilename('myapp.tar.gz')).toBe('myapp')
    expect(nameFromFilename('C:\\Users\\me\\My App.tar')).toBe('my-app')
    expect(nameFromFilename('Q Interpreter.wasm')).toBe('q-interpreter')
    expect(nameFromFilename('.tar')).toBe('image')
  })

  it('accepts repository[:tag] and an empty name', () => {
    for (const ok of ['', 'web', 'web:1.0', 'team/web-ui:v2_rc.1', 'a/b/c']) expect(validName(ok)).toBe(true)
    for (const bad of ['Web', 'a/b/c/d', 'web:', 'web:-x', 'my app', '../x']) expect(validName(bad)).toBe(false)
  })

  it('hashes only files small enough for the browser', async () => {
    expect(await fileSha256(null)).toBeNull()
    const big = { size: 1024 ** 3 }
    expect(await fileSha256(big)).toBeNull()
  })
})
