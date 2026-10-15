// Bindings helpers shared by the project tab, the Data page and the region Data services tab.
// Env keys mirror App\Services\Bindings\BindingEnv::KEYS (docs/bindings.md).

export const BINDING_TYPES = ['kv', 'object', 'postgres', 'external']

export const ENV_KEYS = {
  kv:       ['REDIS_URL', 'KV_PREFIX'],
  object:   ['S3_ENDPOINT', 'S3_BUCKET', 'S3_ACCESS_KEY_ID', 'S3_SECRET_ACCESS_KEY', 'S3_REGION', 'S3_FORCE_PATH_STYLE'],
  postgres: ['DATABASE_URL', 'PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD', 'PGSSLMODE'],
}

/** Env prefix of an attachment: empty, or upper case ending in "_" (ORDERS_) */
export const PREFIX_PATTERN = /^[A-Z][A-Z0-9_]*_$/
/** Binding name: DNS label, at most 30 characters */
export const NAME_PATTERN = /^[a-z]([-a-z0-9]*[a-z0-9])?$/
/** Key of an external binding's value */
export const ENV_NAME_PATTERN = /^[A-Z][A-Z0-9_]*$/

/** Statuses during which the API is still working on the binding (poll until they end) */
export const BUSY_STATUSES = ['pending', 'provisioning', 'rotating', 'purging']

/** Unprefixed env keys of a binding type; external: the keys of its values (object or rows). */
export function envKeys(type, values = null) {
  if (type === 'external') {
    const keys = Array.isArray(values) ? values.map(r => r?.key) : Object.keys(values ?? {})
    return keys.map(k => String(k ?? '').trim()).filter(Boolean)
  }
  return ENV_KEYS[type] ?? []
}

/** Env var names an attachment adds: prefix + key. */
export function envNames(type, prefix = '', values = null) {
  const p = prefix ?? ''
  return envKeys(type, values).map(k => p + k)
}

/** Env names of an existing binding (API row: env_keys) with a prefix. */
export function bindingEnvNames(binding, prefix = '') {
  return (binding?.env_keys ?? envKeys(binding?.type)).map(k => (prefix ?? '') + k)
}

export function isValidPrefix(prefix) {
  return !prefix || PREFIX_PATTERN.test(prefix)
}

export function isValidName(name) {
  return !!name && name.length <= 30 && NAME_PATTERN.test(name)
}

export function isValidEnvKey(key) {
  return !!key && key.length <= 64 && ENV_NAME_PATTERN.test(key)
}

/** What the user types into a prefix field: upper case, A-Z 0-9 _ only. */
export function normalizePrefix(input) {
  return String(input ?? '').toUpperCase().replace(/[^A-Z0-9_]/g, '_')
}

/** Names in `names` another attachment of the project already sets. */
export function envConflicts(names, takenNames) {
  const taken = new Set(takenNames ?? [])
  return (names ?? []).filter(n => taken.has(n))
}

/** 1536 → "1.5 KiB" */
export function fmtBytes(bytes) {
  const n = Number(bytes) || 0
  if (n < 1024) return `${n} B`
  const units = ['KiB', 'MiB', 'GiB', 'TiB']
  let value = n / 1024
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }
  return `${value >= 100 ? value.toFixed(0) : value.toFixed(1)} ${units[unit]}`
}

const MIB = 1024 * 1024
const GIB = 1024 * MIB

/** Size limit of a binding in bytes (KV memory, bucket quota, Postgres storage); null = none. */
export function limitBytes(binding) {
  const s = binding?.settings ?? {}
  switch (binding?.type) {
    case 'kv':       return s.memory_mb ? s.memory_mb * MIB : null
    case 'object':   return s.quota_gb ? s.quota_gb * GIB : null
    case 'postgres': return s.storage_gb ? s.storage_gb * GIB : null
    default:         return null
  }
}

/** Measured usage as a percentage of the limit (0–100), null without a limit. */
export function usagePercent(binding) {
  const limit = limitBytes(binding)
  if (!limit) return null
  return Math.min(100, Math.round(((Number(binding.usage_bytes) || 0) / limit) * 1000) / 10)
}

export function isBusy(binding) {
  return BUSY_STATUSES.includes(binding?.status)
}

/** Badge classes of a binding / health state. */
export function bindingStatusClass(status) {
  const base = 'inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium border'
  switch (status) {
    case 'ready':        return `${base} bg-emerald-900/40 border-emerald-800/40 text-emerald-400`
    case 'provisioning':
    case 'pending':
    case 'rotating':     return `${base} bg-amber-900/40 border-amber-800/40 text-amber-400 animate-pulse`
    case 'degraded':     return `${base} bg-orange-900/40 border-orange-800/40 text-orange-400`
    case 'deleting':
    case 'purging':      return `${base} bg-red-900/30 border-red-800/40 text-red-300`
    case 'error':        return `${base} bg-red-900/40 border-red-800/40 text-red-400`
    default:             return `${base} bg-gray-800/40 border-gray-700/40 text-gray-400`
  }
}

/** Settings of a new binding with the defaults of GET /bindings/types. */
export function defaultSettings(type, plan = 'shared', types = null) {
  const d = types?.[type]?.defaults ?? {}
  switch (type) {
    case 'kv':     return { memory_mb: d.memory_mb ?? 64 }
    case 'object': return { quota_gb: d.quota_gb ?? 5 }
    case 'postgres':
      return plan === 'dedicated'
        ? {
            storage_gb: d.dedicated_storage_gb ?? 5,
            connection_limit: d.connection_limit ?? 20,
            statement_timeout: d.statement_timeout ?? '30s',
            instances: d.instances ?? 1,
            backups: false,
          }
        : {
            storage_gb: d.storage_gb ?? 1,
            connection_limit: d.connection_limit ?? 20,
            statement_timeout: d.statement_timeout ?? '30s',
          }
    default: return {}
  }
}

/**
 * Request body of a new binding from the form state:
 * { name, type, plan?, knative_region_id?, settings?, values? }.
 */
export function bindingPayload(form) {
  const payload = { name: form.name?.trim(), type: form.type }
  if (form.type === 'external') {
    payload.values = Object.fromEntries((form.values ?? [])
      .filter(r => String(r.key ?? '').trim())
      .map(r => [String(r.key).trim(), String(r.value ?? '')]))
    return payload
  }
  if (form.knative_region_id) payload.knative_region_id = form.knative_region_id
  if (form.type === 'postgres') payload.plan = form.plan || 'shared'
  const settings = {}
  for (const [k, v] of Object.entries(form.settings ?? {})) {
    if (v === '' || v === null || v === undefined) continue
    settings[k] = typeof v === 'string' && /^\d+$/.test(v) && k !== 'statement_timeout' ? Number(v) : v
  }
  payload.settings = settings
  return payload
}
