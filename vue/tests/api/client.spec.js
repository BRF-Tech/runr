import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { AxiosError } from 'axios'
import client, { clearEtagCache } from '@/api/client'
import { moduleRegistry } from '@/modules/index'

// Fake transport: answers from a queue and records every request config it sees.
// Like axios' own adapters it rejects when validateStatus fails.
let requests = []
let responses = []

function reply(status, data = {}, headers = {}) {
  responses.push({ status, data, headers })
}

const originalAdapter = client.defaults.adapter

beforeEach(() => {
  requests = []
  responses = []
  clearEtagCache()
  moduleRegistry.requestInterceptors.splice(0)
  client.defaults.adapter = async (config) => {
    requests.push(config)
    const r = responses.shift() ?? { status: 200, data: {}, headers: {} }
    const response = { ...r, statusText: String(r.status), config, request: {} }
    if (config.validateStatus(r.status)) return response
    throw new AxiosError(`Request failed with status code ${r.status}`, AxiosError.ERR_BAD_RESPONSE, config, {}, response)
  }
})

afterEach(() => {
  client.defaults.adapter = originalAdapter
})

describe('request interceptor', () => {
  it('sends the stored bearer token and locale', async () => {
    localStorage.setItem('token', 'tok-123')
    localStorage.setItem('locale', 'en')
    await client.get('/projects')
    expect(requests[0].headers.Authorization).toBe('Bearer tok-123')
    expect(requests[0].headers['Accept-Language']).toBe('en')
  })

  it('omits Authorization without a token and defaults the locale to tr', async () => {
    await client.get('/projects')
    expect(requests[0].headers.Authorization).toBeUndefined()
    expect(requests[0].headers['Accept-Language']).toBe('tr')
  })

  it('targets the /api base URL with JSON headers', async () => {
    await client.post('/auth/login', { login: 'a' })
    expect(requests[0].baseURL).toBe('/api')
    expect(requests[0].headers.Accept).toBe('application/json')
    expect(requests[0].withCredentials).toBe(true)
  })

  it('runs module request interceptors on every request', async () => {
    moduleRegistry.requestInterceptors.push((config) => { config.headers['X-Context-ID'] = 'tenant-7' })
    await client.get('/projects')
    expect(requests[0].headers['X-Context-ID']).toBe('tenant-7')
  })
})

describe('ETag cache', () => {
  it('replays the cached body when the server answers 304', async () => {
    reply(200, { items: [1, 2] }, { etag: 'W/"v1"' })
    const first = await client.get('/projects', { params: { page: 1 } })
    expect(first.data).toEqual({ items: [1, 2] })
    expect(requests[0].headers['If-None-Match']).toBeUndefined()

    reply(304, '')
    const second = await client.get('/projects', { params: { page: 1 } })
    expect(requests[1].headers['If-None-Match']).toBe('W/"v1"')
    expect(second.status).toBe(200)
    expect(second.data).toEqual({ items: [1, 2] })
  })

  it('keys the cache by URL, params and context', async () => {
    reply(200, { page: 1 }, { etag: '"p1"' })
    await client.get('/projects', { params: { page: 1 } })

    await client.get('/projects', { params: { page: 2 } })
    expect(requests[1].headers['If-None-Match']).toBeUndefined()

    moduleRegistry.requestInterceptors.push((config) => { config.headers['X-Context-ID'] = 'other' })
    await client.get('/projects', { params: { page: 1 } })
    expect(requests[2].headers['If-None-Match']).toBeUndefined()
  })

  it('does not cache non-GET responses', async () => {
    reply(200, { ok: true }, { etag: '"post"' })
    await client.post('/projects', {})
    await client.get('/projects')
    expect(requests[1].headers['If-None-Match']).toBeUndefined()
  })

  it('rejects a 304 it has no cached body for', async () => {
    reply(304, '')
    await expect(client.get('/never-seen')).rejects.toMatchObject({ response: { status: 304 } })
  })

  it('forgets everything on clearEtagCache()', async () => {
    reply(200, {}, { etag: '"x"' })
    await client.get('/projects')
    clearEtagCache()
    await client.get('/projects')
    expect(requests[1].headers['If-None-Match']).toBeUndefined()
  })
})

describe('401 handling', () => {
  let hrefSpy

  beforeEach(() => {
    // jsdom cannot navigate; capture the redirect target instead.
    hrefSpy = vi.fn()
    vi.spyOn(window, 'location', 'get').mockReturnValue({
      get pathname() { return window.__path ?? '/' },
      get search() { return window.__search ?? '' },
      set href(v) { hrefSpy(v) },
    })
  })

  afterEach(() => {
    delete window.__path
    delete window.__search
  })

  it('drops the session and redirects to login, keeping the current page', async () => {
    localStorage.setItem('token', 't')
    localStorage.setItem('user', '{"id":1}')
    window.__path = '/projects/42'
    window.__search = '?tab=logs'
    reply(401, { message: 'Unauthenticated.' })

    await expect(client.get('/auth/me')).rejects.toMatchObject({ response: { status: 401 } })
    expect(localStorage.getItem('token')).toBeNull()
    expect(localStorage.getItem('user')).toBeNull()
    expect(hrefSpy).toHaveBeenCalledWith('/login?redirect=%2Fprojects%2F42%3Ftab%3Dlogs')
  })

  it('stays on the login page when a call there answers 401 (no reload, no nested redirect)', async () => {
    // A wrong password answers 401: the page shows the error, a reload would drop it (and a
    // 401 from a background call would reload the page in a loop)
    localStorage.setItem('token', 'stale')
    window.__path = '/login'
    window.__search = '?redirect=%2Fsettings'
    reply(401, { message: 'These credentials do not match our records.' })
    await expect(client.post('/auth/login', {})).rejects.toMatchObject({ response: { status: 401 } })
    expect(hrefSpy).not.toHaveBeenCalled()
    expect(localStorage.getItem('token')).toBeNull()
  })

  it('passes other errors through untouched', async () => {
    localStorage.setItem('token', 't')
    reply(422, { errors: { name: ['taken'] } })
    await expect(client.post('/projects', {})).rejects.toMatchObject({ response: { status: 422, data: { errors: { name: ['taken'] } } } })
    expect(localStorage.getItem('token')).toBe('t')
    expect(hrefSpy).not.toHaveBeenCalled()
  })
})
