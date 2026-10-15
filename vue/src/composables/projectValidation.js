// Client-side mirror of the API's Kubernetes limits (api/app/Rules/*).
// The API stays authoritative; these only give early hints in the project forms.
import { moduleRegistry } from '@/modules/index'

// Project::MAX_SLUG_LENGTH — the slug names the Knative Service and the CronJob "<slug>-trigger" (max 52)
export const MAX_SLUG_LENGTH = 44
const SLUG_PATTERN = /^[a-z]([-a-z0-9]*[a-z0-9])?$/

// Laravel Str::slug approximation: ASCII-fold, "_" and whitespace → "-", drop other symbols
export function slugify(name) {
  return String(name ?? '')
    .replace(/ı/g, 'i')
    .normalize('NFKD').replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/@/g, '-at-')
    .replace(/_/g, '-')
    .replace(/[^-\p{L}\p{N}\s]+/gu, '')
    .replace(/[-\s]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

// Final slug incl. the tenant suffix the API appends in multi-tenant mode
export function projectSlug(name) {
  const base = slugify(name)
  const suffix = moduleRegistry.queueStreamSuffixFn?.() || ''
  return base + suffix
}

// → null | { key, params } for t(key, params)
export function slugError(name) {
  if (!String(name ?? '').trim()) return null
  const slug = projectSlug(name)
  if (!SLUG_PATTERN.test(slug)) return { key: 'create.validation.slugInvalid', params: { slug: slug || '—' } }
  if (slug.length > MAX_SLUG_LENGTH) return { key: 'create.validation.slugTooLong', params: { slug, length: slug.length, max: MAX_SLUG_LENGTH } }
  return null
}

const CRON_MACROS = ['@yearly', '@annually', '@monthly', '@weekly', '@daily', '@midnight', '@hourly']

// Kubernetes CronJob schedule: 5 fields or a macro (no seconds, no TZ prefix, no L/W/#)
export function isValidCron(expr) {
  const s = String(expr ?? '').trim()
  if (CRON_MACROS.includes(s.toLowerCase())) return true
  const f = s.split(/\s+/).filter(Boolean)
  if (f.length !== 5) return false
  if (!f.slice(0, 3).every(x => /^[0-9*?/,-]+$/.test(x))) return false
  if (!f.slice(3).every(x => /^[0-9A-Za-z*?/,-]+$/.test(x))) return false
  return !/l/i.test(f[4])
}

export const ENV_MAX_KEYS = 100
export const ENV_MAX_BYTES = 32768
const ENV_KEY = /^[A-Za-z_][A-Za-z0-9_]*$/
const ENV_RESERVED = ['PORT', 'K_SERVICE', 'K_CONFIGURATION', 'K_REVISION', 'RUNR_MODE', 'RUNR_HANDLER', 'RUNR_FILENAME']

// raw = "KEY=value" lines (EnvVarsEditor) → null | { key, params }
export function envVarsError(raw) {
  const lines = String(raw ?? '').split('\n').map(l => l.trim()).filter(Boolean)
  const keys = []
  let bytes = 0
  for (const line of lines) {
    const i = line.indexOf('=')
    const key = (i === -1 ? line : line.slice(0, i)).trim()
    if (!ENV_KEY.test(key)) return { key: 'create.validation.envKeyInvalid', params: { key: key.slice(0, 64) } }
    if (ENV_RESERVED.includes(key.toUpperCase())) return { key: 'create.validation.envKeyReserved', params: { key } }
    keys.push(key)
    bytes += new TextEncoder().encode(line).length - 1
  }
  if (new Set(keys).size > ENV_MAX_KEYS) return { key: 'create.validation.envTooMany', params: { max: ENV_MAX_KEYS } }
  if (bytes > ENV_MAX_BYTES) return { key: 'create.validation.envTooLarge', params: { max: ENV_MAX_BYTES / 1024 } }
  return null
}

// Region request_timeout (effective settings, API default 300) caps the project timeout
export function maxTimeoutFor(region) {
  const v = Number(region?.knative_settings?.request_timeout)
  return v > 0 ? v : 300
}

// Code file names become ConfigMap keys (flat, no folders)
export function isValidFileName(name) {
  const s = String(name ?? '')
  return /^[-._a-zA-Z0-9]+$/.test(s) && s.length <= 253 && s !== '.' && !s.startsWith('..')
}
