import { describe, it, expect, afterEach } from 'vitest'
import { moduleRegistry } from '@/modules/index'
import {
  MAX_SLUG_LENGTH, slugify, projectSlug, slugError, isValidCron,
  ENV_MAX_KEYS, ENV_MAX_BYTES, envVarsError, maxTimeoutFor, isValidFileName,
} from '@/composables/projectValidation'

afterEach(() => {
  moduleRegistry.queueStreamSuffixFn = null
})

describe('slugify (Laravel Str::slug mirror)', () => {
  it.each([
    ['My Project', 'my-project'],
    ['  spaced   out  ', 'spaced-out'],
    ['snake_case_name', 'snake-case-name'],
    ['Işık Çağrı Şöleni', 'isik-cagri-soleni'],
    ['Ünlü Göl', 'unlu-gol'],
    ['café', 'cafe'],
    ['ops@acme', 'ops-at-acme'],
    ['a!b#c$d', 'abcd'],
    ['--trim--', 'trim'],
    ['a - - b', 'a-b'],
    [null, ''],
    [undefined, ''],
  ])('%j -> %j', (input, expected) => {
    expect(slugify(input)).toBe(expected)
  })
})

describe('projectSlug', () => {
  it('appends the tenant suffix of multi-tenant installs', () => {
    moduleRegistry.queueStreamSuffixFn = () => '-acme'
    expect(projectSlug('Billing API')).toBe('billing-api-acme')
  })

  it('has no suffix in single-tenant mode', () => {
    expect(projectSlug('Billing API')).toBe('billing-api')
  })
})

describe('slugError (Kubernetes DNS-1035 label)', () => {
  it('ignores an empty name (the form shows "required" instead)', () => {
    expect(slugError('')).toBeNull()
    expect(slugError('   ')).toBeNull()
  })

  it('accepts a valid name', () => {
    expect(slugError('hello-world')).toBeNull()
    expect(slugError('a'.repeat(MAX_SLUG_LENGTH))).toBeNull()
  })

  it.each(['1st-project', '42', '!!!', '-'])('rejects %j, which does not start with a letter after slugging', (name) => {
    expect(slugError(name)?.key).toBe('create.validation.slugInvalid')
  })

  it('shows a dash when nothing of the name survives slugging', () => {
    expect(slugError('!!!')).toEqual({ key: 'create.validation.slugInvalid', params: { slug: '—' } })
  })

  it('rejects names whose slug exceeds the CronJob-safe length', () => {
    const err = slugError('a'.repeat(MAX_SLUG_LENGTH + 1))
    expect(err).toEqual({
      key: 'create.validation.slugTooLong',
      params: { slug: 'a'.repeat(MAX_SLUG_LENGTH + 1), length: MAX_SLUG_LENGTH + 1, max: MAX_SLUG_LENGTH },
    })
  })

  it('counts the tenant suffix towards the length limit', () => {
    moduleRegistry.queueStreamSuffixFn = () => '-tenant'
    expect(slugError('a'.repeat(MAX_SLUG_LENGTH - 6))?.key).toBe('create.validation.slugTooLong')
  })

  it('keeps the slug + "-trigger" within the 52-character CronJob name limit', () => {
    expect(MAX_SLUG_LENGTH + '-trigger'.length).toBeLessThanOrEqual(52)
  })
})

describe('isValidCron (Kubernetes CronJob schedule)', () => {
  it.each([
    '* * * * *',
    '*/5 * * * *',
    '0 0 * * 1-5',
    '0,30 8-18 * * *',
    '0 12 1 JAN MON',
    '15 3 1-7 * sun',
    '0 0 ? * *',
    '@daily',
    '@HOURLY',
    '@annually',
    '  0 0 * * *  ',
  ])('accepts %j', (expr) => {
    expect(isValidCron(expr)).toBe(true)
  })

  it.each([
    '',
    null,
    '* * * *',
    '0 0 * * * *',
    'CRON_TZ=UTC 0 0 * * *',
    '0 0 L * *',
    '0 0 * * 5L',
    '0 0 * * MON#2',
    'a b c d e',
    '@reboot',
    '@every 5m',
  ])('rejects %j', (expr) => {
    expect(isValidCron(expr)).toBe(false)
  })
})

describe('envVarsError', () => {
  it('accepts valid KEY=value lines, blank lines and values containing "="', () => {
    expect(envVarsError('')).toBeNull()
    expect(envVarsError(null)).toBeNull()
    expect(envVarsError('A=1\n\n_B=two\nDATABASE_URL=postgres://u:p@h/db?x=1')).toBeNull()
  })

  it.each(['1BAD=x', 'WITH-DASH=x', 'has space=x', '=novalue'])('rejects the invalid key in %j', (line) => {
    expect(envVarsError(line)?.key).toBe('create.validation.envKeyInvalid')
  })

  it('truncates a long invalid key in the message', () => {
    const err = envVarsError(`${'9'.repeat(100)}=x`)
    expect(err.params.key).toHaveLength(64)
  })

  it.each(['PORT=8080', 'port=1', 'K_SERVICE=x', 'RUNR_HANDLER=main'])('rejects the reserved key in %j', (line) => {
    expect(envVarsError(line)?.key).toBe('create.validation.envKeyReserved')
  })

  it(`rejects more than ${ENV_MAX_KEYS} distinct keys`, () => {
    const ok = Array.from({ length: ENV_MAX_KEYS }, (_, i) => `K${i}=v`).join('\n')
    expect(envVarsError(ok)).toBeNull()
    expect(envVarsError(`${ok}\nEXTRA=v`)).toEqual({ key: 'create.validation.envTooMany', params: { max: ENV_MAX_KEYS } })
  })

  it('counts a repeated key once', () => {
    const lines = Array.from({ length: ENV_MAX_KEYS + 5 }, () => 'SAME=v').join('\n')
    expect(envVarsError(lines)).toBeNull()
  })

  it(`rejects more than ${ENV_MAX_BYTES} bytes of variables, counted in UTF-8`, () => {
    const big = `BIG=${'x'.repeat(ENV_MAX_BYTES)}`
    expect(envVarsError(big)).toEqual({ key: 'create.validation.envTooLarge', params: { max: 32 } })
    // 2-byte characters: half the count already crosses the limit
    expect(envVarsError(`U=${'ü'.repeat(ENV_MAX_BYTES / 2 + 1)}`)?.key).toBe('create.validation.envTooLarge')
  })
})

describe('maxTimeoutFor', () => {
  it('uses the region request_timeout', () => {
    expect(maxTimeoutFor({ knative_settings: { request_timeout: 900 } })).toBe(900)
    expect(maxTimeoutFor({ knative_settings: { request_timeout: '120' } })).toBe(120)
  })

  it.each([null, {}, { knative_settings: {} }, { knative_settings: { request_timeout: 0 } }, { knative_settings: { request_timeout: 'x' } }])(
    'falls back to the API default of 300s for %j', (region) => {
      expect(maxTimeoutFor(region)).toBe(300)
    },
  )
})

describe('isValidFileName (ConfigMap key)', () => {
  it.each(['main.py', 'index.js', '.env', 'requirements.txt', 'a-b_c.d', 'x'.repeat(253)])('accepts %j', (name) => {
    expect(isValidFileName(name)).toBe(true)
  })

  it.each(['', '.', '..', '../etc', 'dir/file.py', 'with space.js', 'ü.py', 'x'.repeat(254), null])('rejects %j', (name) => {
    expect(isValidFileName(name)).toBe(false)
  })
})
