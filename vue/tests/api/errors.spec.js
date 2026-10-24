import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { AxiosError, CanceledError } from 'axios'
import client from '@/api/client'
import i18n from '@/i18n'
import { connectionFailure, describeConnectionFailure, isCancelled, requestErrorMessage } from '@/api/errors'

// A request that never gets an answer (network down, DNS, a TLS certificate the browser rejected,
// CORS, a dropped connection, a timeout) showed "An error occurred." everywhere. The API client
// now gives it the console's message where the error displays read the API's own.

const NETWORK = 'The console could not reach the server. Check your connection and try again.'
const TIMEOUT = 'The server did not answer in time. Check your connection and try again.'
const t = (key) => i18n.global.t(key)

// What most of the console's error displays do with a failed call
const shown = (e) => e.response?.data?.message ?? t('common.error')

// Fake transport: each spec says how the request ends
let outcome
const originalAdapter = client.defaults.adapter

function failWith(code, message) {
  outcome = (config) => { throw new AxiosError(message, code, config, {}) }
}

function answer(status, data = {}) {
  outcome = (config) => {
    const response = { status, statusText: String(status), data, headers: {}, config, request: {} }
    if (config.validateStatus(status)) return response
    throw new AxiosError(`Request failed with status code ${status}`, AxiosError.ERR_BAD_REQUEST, config, {}, response)
  }
}

async function failure(request = () => client.get('/mcp-servers/templates')) {
  try {
    await request()
  } catch (e) {
    return e
  }
  throw new Error('the request did not fail')
}

beforeEach(() => {
  i18n.global.locale.value = 'en'
  outcome = () => ({ status: 200, data: {}, headers: {} })
  client.defaults.adapter = async (config) => outcome(config)
})

afterEach(() => {
  client.defaults.adapter = originalAdapter
  i18n.global.locale.value = 'tr'
})

describe('a request that gets no answer', () => {
  it('says the console could not reach the server (network down, certificate rejected, CORS, DNS)', async () => {
    failWith(AxiosError.ERR_NETWORK, 'Network Error')
    const e = await failure()

    expect(shown(e)).toBe(NETWORK)
    expect(requestErrorMessage(e, t('common.error'))).toBe(NETWORK)
    expect(e.connectionFailure).toBe('network')
  })

  it('says the same for a dropped connection', async () => {
    failWith(AxiosError.ECONNABORTED, 'Request aborted')
    expect(shown(await failure())).toBe(NETWORK)
  })

  it('says the server did not answer in time for a timeout, in either of axios\' codes', async () => {
    failWith(AxiosError.ECONNABORTED, 'timeout of 30000ms exceeded')
    const e = await failure()
    expect(shown(e)).toBe(TIMEOUT)
    expect(e.connectionFailure).toBe('timeout')

    failWith(AxiosError.ETIMEDOUT, 'timeout of 30000ms exceeded')
    expect(shown(await failure())).toBe(TIMEOUT)
  })

  it('speaks the console\'s language', async () => {
    i18n.global.locale.value = 'tr'
    failWith(AxiosError.ERR_NETWORK, 'Network Error')
    expect(shown(await failure())).toBe('Konsol sunucuya ulaşamadı. Bağlantınızı kontrol edip tekrar deneyin.')
  })

  it('keeps every status check as it was: no status, no field errors, no code', async () => {
    failWith(AxiosError.ERR_NETWORK, 'Network Error')
    const e = await failure()

    expect(e.response.status).toBeUndefined()
    expect(e.response.data).toEqual({ message: NETWORK })
    expect(e.code).toBe(AxiosError.ERR_NETWORK)
    // the chunk uploads retry a call without a status (src/api/ai.js retryable())
    expect(!e.response?.status || e.response.status >= 500).toBe(true)
  })
})

describe('an HTTP error', () => {
  it('still shows the server\'s message', async () => {
    answer(422, { message: 'The name has already been taken.', errors: { name: ['The name has already been taken.'] } })
    const e = await failure(() => client.post('/projects', {}))

    expect(shown(e)).toBe('The name has already been taken.')
    expect(requestErrorMessage(e, t('common.error'))).toBe('The name has already been taken.')
    expect(e.response.status).toBe(422)
    expect(e.connectionFailure).toBeUndefined()
    expect(connectionFailure(e)).toBeNull()
  })

  it('without a message still falls back to "An error occurred."', async () => {
    answer(500, {})
    const e = await failure()

    expect(shown(e)).toBe('An error occurred.')
    expect(requestErrorMessage(e, t('common.error'))).toBe('An error occurred.')
    expect(e.response).toMatchObject({ status: 500, data: {} })
  })

  it('shows the first field error when the API sends no message', async () => {
    answer(422, { errors: { name: ['The name is required.'] } })
    expect(requestErrorMessage(await failure(), t('common.error'))).toBe('The name is required.')
  })
})

describe('a request cancelled on purpose', () => {
  it('shows nothing: an AbortController signal', async () => {
    const controller = new AbortController()
    controller.abort()
    const e = await failure(() => client.get('/mcp-servers/templates', { signal: controller.signal }))

    expect(isCancelled(e)).toBe(true)
    expect(e.response).toBeUndefined()
    expect(connectionFailure(e)).toBeNull()
    expect(requestErrorMessage(e, t('common.error'))).toBeNull()
  })

  it('shows nothing: an axios cancel and an upload loop stopped between chunks', () => {
    const aborted = Object.assign(new Error('Upload aborted'), { name: 'AbortError' })
    for (const e of [new CanceledError(), aborted]) {
      expect(describeConnectionFailure(e).response).toBeUndefined()
      expect(requestErrorMessage(e, t('common.error'))).toBeNull()
    }
  })
})

describe('errors that are not request errors', () => {
  it('are left alone', () => {
    const e = new TypeError('x is undefined')
    expect(connectionFailure(e)).toBeNull()
    expect(describeConnectionFailure(e).response).toBeUndefined()
    expect(requestErrorMessage(e, 'fallback')).toBe('fallback')
    expect(connectionFailure(null)).toBeNull()
  })
})
