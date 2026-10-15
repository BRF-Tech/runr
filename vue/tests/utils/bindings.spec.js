import { describe, it, expect } from 'vitest'
import {
  ENV_KEYS, envKeys, envNames, bindingEnvNames, isValidPrefix, isValidName, isValidEnvKey, normalizePrefix,
  envConflicts, fmtBytes, limitBytes, usagePercent, isBusy, defaultSettings, bindingPayload,
} from '@/utils/bindings'

// Helpers of the bindings UI (docs/bindings.md): env names, validation, sizes, request bodies

describe('bindings env names', () => {
  it('mirrors the env keys of every managed type', () => {
    expect(ENV_KEYS.kv).toEqual(['REDIS_URL', 'KV_PREFIX'])
    expect(ENV_KEYS.object).toContain('S3_SECRET_ACCESS_KEY')
    expect(ENV_KEYS.postgres).toEqual(['DATABASE_URL', 'PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD', 'PGSSLMODE'])
  })

  it('prefixes the names an attachment adds', () => {
    expect(envNames('kv', 'ORDERS_')).toEqual(['ORDERS_REDIS_URL', 'ORDERS_KV_PREFIX'])
    expect(envNames('postgres', '')[0]).toBe('DATABASE_URL')
    expect(envNames('postgres', null)[0]).toBe('DATABASE_URL')
  })

  it('takes the keys of an external binding from its rows or values', () => {
    expect(envKeys('external', [{ key: 'API_URL', value: 'x' }, { key: ' ', value: '' }, { key: 'TOKEN' }])).toEqual(['API_URL', 'TOKEN'])
    expect(envNames('external', 'PAY_', { API_URL: 'x' })).toEqual(['PAY_API_URL'])
    expect(envKeys('nope')).toEqual([])
  })

  it('uses the env_keys of an existing binding', () => {
    expect(bindingEnvNames({ type: 'external', env_keys: ['URL'] }, 'X_')).toEqual(['X_URL'])
    expect(bindingEnvNames({ type: 'kv' }, '')).toEqual(['REDIS_URL', 'KV_PREFIX'])
  })

  it('finds names another attachment already sets', () => {
    expect(envConflicts(['DATABASE_URL', 'PGHOST'], ['PGHOST', 'REDIS_URL'])).toEqual(['PGHOST'])
    expect(envConflicts(['A'], null)).toEqual([])
  })
})

describe('bindings validation', () => {
  it('accepts an empty prefix or upper case ending in _', () => {
    expect(isValidPrefix('')).toBe(true)
    expect(isValidPrefix('ORDERS_')).toBe(true)
    expect(isValidPrefix('ORDERS')).toBe(false)
    expect(isValidPrefix('_X_')).toBe(false)
    expect(isValidPrefix('orders_')).toBe(false)
  })

  it('normalizes what is typed into a prefix', () => {
    expect(normalizePrefix('orders-db_')).toBe('ORDERS_DB_')
    expect(normalizePrefix(null)).toBe('')
  })

  it('validates binding names as DNS labels of at most 30 characters', () => {
    expect(isValidName('orders-db')).toBe(true)
    expect(isValidName('Orders')).toBe(false)
    expect(isValidName('1db')).toBe(false)
    expect(isValidName('db-')).toBe(false)
    expect(isValidName('a'.repeat(31))).toBe(false)
  })

  it('validates external value keys', () => {
    expect(isValidEnvKey('API_TOKEN')).toBe(true)
    expect(isValidEnvKey('api_token')).toBe(false)
    expect(isValidEnvKey('9X')).toBe(false)
  })
})

describe('bindings sizes', () => {
  it('formats bytes', () => {
    expect(fmtBytes(0)).toBe('0 B')
    expect(fmtBytes(1536)).toBe('1.5 KiB')
    expect(fmtBytes(5 * 1024 ** 3)).toBe('5.0 GiB')
  })

  it('knows the size limit of each type', () => {
    expect(limitBytes({ type: 'kv', settings: { memory_mb: 64 } })).toBe(64 * 1024 ** 2)
    expect(limitBytes({ type: 'object', settings: { quota_gb: 5 } })).toBe(5 * 1024 ** 3)
    expect(limitBytes({ type: 'postgres', settings: { storage_gb: 1 } })).toBe(1024 ** 3)
    expect(limitBytes({ type: 'external', settings: {} })).toBeNull()
  })

  it('computes the usage percentage, capped at 100', () => {
    expect(usagePercent({ type: 'kv', settings: { memory_mb: 100 }, usage_bytes: 50 * 1024 ** 2 })).toBe(50)
    expect(usagePercent({ type: 'kv', settings: { memory_mb: 1 }, usage_bytes: 10 * 1024 ** 2 })).toBe(100)
    expect(usagePercent({ type: 'external' })).toBeNull()
  })

  it('treats provisioning and rotating bindings as busy', () => {
    expect(isBusy({ status: 'provisioning' })).toBe(true)
    expect(isBusy({ status: 'rotating' })).toBe(true)
    expect(isBusy({ status: 'ready' })).toBe(false)
  })
})

describe('bindings request bodies', () => {
  it('fills defaults per type and plan', () => {
    const types = { postgres: { defaults: { storage_gb: 2, dedicated_storage_gb: 10, instances: 2, connection_limit: 30, statement_timeout: '10s' } } }
    expect(defaultSettings('kv')).toEqual({ memory_mb: 64 })
    expect(defaultSettings('postgres', 'shared', types)).toEqual({ storage_gb: 2, connection_limit: 30, statement_timeout: '10s' })
    expect(defaultSettings('postgres', 'dedicated', types)).toMatchObject({ storage_gb: 10, instances: 2, backups: false })
  })

  it('builds a managed binding body with numeric settings', () => {
    expect(bindingPayload({
      name: ' orders-db ', type: 'postgres', plan: 'dedicated', knative_region_id: 'r1',
      settings: { storage_gb: '20', instances: 2, statement_timeout: '30s', backups: true, connection_limit: '' },
    })).toEqual({
      name: 'orders-db', type: 'postgres', plan: 'dedicated', knative_region_id: 'r1',
      settings: { storage_gb: 20, instances: 2, statement_timeout: '30s', backups: true },
    })
  })

  it('builds an external body from its rows without a region', () => {
    expect(bindingPayload({
      name: 'payments', type: 'external', knative_region_id: 'r1',
      values: [{ key: 'API_URL', value: 'https://pay.example.com' }, { key: '', value: 'ignored' }],
    })).toEqual({ name: 'payments', type: 'external', values: { API_URL: 'https://pay.example.com' } })
  })

  it('leaves the plan out for other types', () => {
    expect(bindingPayload({ name: 'cache', type: 'kv', plan: 'dedicated', settings: { memory_mb: 32 } }))
      .toEqual({ name: 'cache', type: 'kv', settings: { memory_mb: 32 } })
  })
})
