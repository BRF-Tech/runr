import { afterEach, test } from 'node:test'
import assert from 'node:assert/strict'

import {
  AuthError,
  ConflictError,
  NotFoundError,
  PayloadTooLargeError,
  QuotaError,
  RateLimitError,
  RunrSandboxError,
  ValidationError,
  apiBaseUrl,
  errorFromResponse,
  resolveConfig,
} from '../src/index.js'

const saved = { ...process.env }

afterEach(() => {
  for (const name of ['RUNR_API_URL', 'RUNR_API_KEY', 'RUNR_TENANT_ID']) {
    if (saved[name] === undefined) delete process.env[name]
    else process.env[name] = saved[name]
  }
})

test('the /api suffix', () => {
  assert.equal(apiBaseUrl('https://runr.example.com'), 'https://runr.example.com/api')
  assert.equal(apiBaseUrl('https://runr.example.com/'), 'https://runr.example.com/api')
  assert.equal(apiBaseUrl('https://runr.example.com/api'), 'https://runr.example.com/api')
  assert.equal(apiBaseUrl('https://runr.example.com/api/'), 'https://runr.example.com/api')
  assert.equal(apiBaseUrl('https://example.com/runr'), 'https://example.com/runr/api')
})

test('environment variables', () => {
  process.env.RUNR_API_URL = 'https://runr.example.com'
  process.env.RUNR_API_KEY = 'env-key'
  process.env.RUNR_TENANT_ID = 'tenant-7'
  const config = resolveConfig()
  assert.equal(config.apiUrl, 'https://runr.example.com/api')
  assert.equal(config.apiKey, 'env-key')
  assert.equal(config.tenantId, 'tenant-7')
  assert.equal(config.timeoutMs, 300_000)
})

test('options win over the environment', () => {
  process.env.RUNR_API_URL = 'https://env.example.com'
  process.env.RUNR_API_KEY = 'env-key'
  const config = resolveConfig({ apiUrl: 'https://arg.example.com', apiKey: 'arg-key', timeoutMs: 5000 })
  assert.equal(config.apiUrl, 'https://arg.example.com/api')
  assert.equal(config.apiKey, 'arg-key')
  assert.equal(config.timeoutMs, 5000)
  assert.equal(config.tenantId, null)
})

test('a call without a URL is refused', () => {
  delete process.env.RUNR_API_URL
  assert.throws(() => resolveConfig(), (error: unknown) => {
    assert.ok(error instanceof RunrSandboxError)
    assert.match(error.message, /RUNR_API_URL/)
    return true
  })
})

test('error mapping', () => {
  const cases: Array<[number, Record<string, unknown>, new (...args: never[]) => RunrSandboxError]> = [
    [401, { message: 'Unauthenticated.' }, AuthError],
    [403, { message: 'Forbidden.' }, AuthError],
    [404, { message: 'Not found.' }, NotFoundError],
    [409, { message: 'Not running.', reason: 'not_running' }, ConflictError],
    [413, { message: 'Too large.' }, PayloadTooLargeError],
    [422, { message: 'Quota.', reason: 'quota_cpu' }, QuotaError],
    [422, { message: 'Invalid.', errors: { cpu: ['bad'] } }, ValidationError],
    [429, { message: 'Too Many Attempts.' }, RateLimitError],
    [502, { message: 'Cluster unreachable.', reason: 'cluster_error' }, RunrSandboxError],
  ]
  for (const [status, body, cls] of cases) {
    const error = errorFromResponse(status, body)
    assert.equal(error.constructor, cls, `${status} → ${cls.name}`)
    assert.equal(error.status, status)
    assert.equal(error.message, body.message)
    assert.deepEqual(error.body, body)
    assert.equal(error.name, cls.name)
  }
  assert.equal(errorFromResponse(413, { message: 'x' }).reason, 'file_too_large')
  assert.equal(errorFromResponse(502, null, '<html>Bad Gateway</html>').message, '<html>Bad Gateway</html>')
  assert.equal(errorFromResponse(500, null, '').message, 'HTTP 500')
  assert.equal((errorFromResponse(429, {}, '', '7') as RateLimitError).retryAfter, 7)
  assert.equal((errorFromResponse(429, {}, '', 'soon') as RateLimitError).retryAfter, null)
})
