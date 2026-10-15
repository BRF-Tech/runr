// Helpers of the Sandboxes UI (pure functions — unit-testable without a component)

export const ALIVE_STATUSES = ['pending', 'running', 'paused']

export const STATUS_STYLES = {
  pending:     'bg-yellow-900/40 text-yellow-300 border-yellow-800',
  running:     'bg-emerald-900/40 text-emerald-300 border-emerald-800',
  paused:      'bg-sky-900/40 text-sky-300 border-sky-800',
  terminating: 'bg-orange-900/40 text-orange-300 border-orange-800',
  terminated:  'bg-gray-800 text-gray-400 border-gray-700',
  failed:      'bg-red-900/40 text-red-300 border-red-800',
}

export const ISOLATION_STYLES = {
  kata:   'border-violet-800/50 bg-violet-950/40 text-violet-300',
  gvisor: 'border-sky-800/50 bg-sky-950/40 text-sky-300',
  runc:   'border-amber-800/50 bg-amber-950/30 text-amber-300',
}

/** Languages of POST /sandboxes/{id}/run, in picker order. */
export const RUN_LANGUAGES = ['python', 'javascript', 'typescript', 'bash', 'sh', 'php', 'ruby', 'go']

const RUNTIME_LANGUAGE = { python: 'python', pytorch: 'python', tensorflow: 'python', nodejs: 'javascript', php: 'php', go: 'go', bash: 'bash' }

/** Default code-interpreter language of a sandbox (from its runtime). */
export function defaultLanguage(sandbox) {
  return RUNTIME_LANGUAGE[sandbox?.runtime] ?? (sandbox?.runtime ? 'bash' : 'python')
}

export function isAlive(status) {
  return ALIVE_STATUSES.includes(status)
}

/** Short form of a ULID for tables: the random tail is what tells sandboxes apart. */
export function shortId(id) {
  if (!id) return ''
  return id.length > 10 ? id.slice(-8) : id
}

/** "45s", "12m", "3h 5m", "2d 4h" */
export function formatDuration(seconds) {
  const s = Math.max(0, Math.floor(seconds))
  if (s < 60) return `${s}s`
  const m = Math.floor(s / 60)
  if (m < 60) return `${m}m${s % 60 && m < 10 ? ` ${s % 60}s` : ''}`
  const h = Math.floor(m / 60)
  if (h < 24) return `${h}h${m % 60 ? ` ${m % 60}m` : ''}`
  const d = Math.floor(h / 24)
  return `${d}d${h % 24 ? ` ${h % 24}h` : ''}`
}

/** Seconds since an ISO time (0 for missing / future times). */
export function secondsSince(iso, now = Date.now()) {
  if (!iso) return 0
  return Math.max(0, (now - new Date(iso).getTime()) / 1000)
}

/** Seconds until an ISO time (negative when past, null when missing). */
export function secondsUntil(iso, now = Date.now()) {
  if (!iso) return null
  return (new Date(iso).getTime() - now) / 1000
}

export function formatBytes(bytes) {
  const n = Number(bytes) || 0
  if (n < 1024) return `${n} B`
  const units = ['KiB', 'MiB', 'GiB', 'TiB']
  let v = n / 1024
  let i = 0
  while (v >= 1024 && i < units.length - 1) { v /= 1024; i++ }
  return `${v < 10 ? v.toFixed(1) : Math.round(v)} ${units[i]}`
}

export function formatDateTime(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString()
}

/** Egress allowlist textarea → entries (trimmed, comments and blanks dropped, unique). */
export function parseAllowlist(text) {
  const out = []
  for (const raw of String(text ?? '').split(/[\n,]/)) {
    const line = raw.replace(/#.*$/, '').trim()
    if (line && !out.includes(line)) out.push(line)
  }
  return out
}

/** Key/value rows → object (rows without a key are skipped; later rows win). */
export function rowsToObject(rows) {
  const out = {}
  for (const row of rows ?? []) {
    const key = String(row.key ?? '').trim()
    if (key) out[key] = String(row.value ?? '')
  }
  return out
}

/**
 * The POST /sandboxes body of the create dialog's form.
 *
 * @param {object} form  { region, source: 'runtime'|'image', runtime, version, image, cpu, memory, disk,
 *                         ttlMinutes, idleMinutes, sandboxClass, networkMode: 'none'|'allowlist',
 *                         allowlist, env: [{key, value}], metadata: [{key, value}], readOnlyRoot }
 */
export function buildCreatePayload(form) {
  const payload = {
    region:         form.region || undefined,
    cpu:            String(form.cpu ?? '').trim() || undefined,
    memory:         Number(form.memory) || undefined,
    disk:           Number(form.disk) || undefined,
    ttl_seconds:    Math.round(Number(form.ttlMinutes) * 60) || undefined,
    idle_timeout:   form.idleMinutes === '' || form.idleMinutes === null || form.idleMinutes === undefined
      ? undefined
      : Math.max(0, Math.round(Number(form.idleMinutes) * 60)),
    network:        form.networkMode === 'allowlist' ? { egress_allow: parseAllowlist(form.allowlist) } : 'none',
    read_only_root: !!form.readOnlyRoot,
    wait:           false,
  }

  if (form.source === 'image') {
    payload.image = String(form.image ?? '').trim()
  } else {
    payload.runtime = form.runtime || undefined
    payload.version = form.version || undefined
  }

  if (form.sandboxClass && form.sandboxClass !== 'auto') payload.sandbox_class = form.sandboxClass

  const env = rowsToObject(form.env)
  if (Object.keys(env).length) payload.env = env
  const metadata = rowsToObject(form.metadata)
  if (Object.keys(metadata).length) payload.metadata = metadata

  for (const key of Object.keys(payload)) {
    if (payload[key] === undefined) delete payload[key]
  }

  return payload
}

/** Uint8Array → base64 (chunked: String.fromCharCode has an argument limit). */
export function bytesToBase64(bytes) {
  let binary = ''
  const chunk = 0x8000
  for (let i = 0; i < bytes.length; i += chunk) {
    binary += String.fromCharCode(...bytes.subarray(i, i + chunk))
  }
  return btoa(binary)
}

export function base64ToBytes(b64) {
  const bin = atob(b64 ?? '')
  const out = new Uint8Array(bin.length)
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i)
  return out
}

/** UTF-8 text of bytes, null when they are not valid UTF-8 (a binary file). */
export function bytesToText(bytes) {
  try {
    const text = new TextDecoder('utf-8', { fatal: true }).decode(bytes)
    // NUL bytes: treat as binary even when they decode
    return text.includes('\u0000') ? null : text
  } catch {
    return null
  }
}

/** A browser File → base64 of its bytes (FileReader works in every browser and in jsdom). */
export function fileToBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload  = () => {
      const url = String(reader.result ?? '')
      resolve(url.includes(',') ? url.slice(url.indexOf(',') + 1) : '')
    }
    reader.onerror = () => reject(reader.error)
    reader.readAsDataURL(file)
  })
}

/** Join a directory and a name into an absolute sandbox path. */
export function joinPath(dir, name) {
  const base = String(dir || '/').replace(/\/+$/, '')
  return `${base}/${String(name).replace(/^\/+/, '')}`
}

/** Parent directory of an absolute path ("/" stays "/"). */
export function parentPath(path) {
  const trimmed = String(path || '/').replace(/\/+$/, '')
  const idx = trimmed.lastIndexOf('/')
  return idx <= 0 ? '/' : trimmed.slice(0, idx)
}

/** "/workspace/src" → [{ name: '/', path: '/' }, { name: 'workspace', path: '/workspace' }, { name: 'src', path: '/workspace/src' }] */
export function breadcrumbs(path) {
  const parts = String(path || '/').split('/').filter(Boolean)
  const crumbs = [{ name: '/', path: '/' }]
  let acc = ''
  for (const part of parts) {
    acc += `/${part}`
    crumbs.push({ name: part, path: acc })
  }
  return crumbs
}

/** Save a Blob as a download in the browser. */
export function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob instanceof Blob ? blob : new Blob([blob]))
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

/** Laravel error body → first message (message, else the first validation error). */
export function apiError(e, fallback) {
  return e?.response?.data?.message
    ?? Object.values(e?.response?.data?.errors ?? {}).flat()[0]
    ?? fallback
}

/** Laravel validation errors → flat list of "field: message" strings. */
export function apiErrorList(e) {
  const errors = e?.response?.data?.errors ?? {}
  const out = []
  for (const [field, messages] of Object.entries(errors)) {
    for (const message of [].concat(messages)) out.push(`${field}: ${message}`)
  }
  return out
}

/** Cumulative histogram buckets → per-bucket counts (le_ms null = +Inf). */
export function histogramBars(histogram) {
  const buckets = histogram?.buckets ?? []
  let previous = 0
  return buckets.map((b) => {
    const count = Math.max(0, (b.count ?? 0) - previous)
    previous = b.count ?? previous
    return { le_ms: b.le_ms ?? null, count }
  })
}

/** "250 ms", "2.5 s", "1 min" */
export function formatMs(ms) {
  const n = Number(ms)
  if (!Number.isFinite(n)) return '—'
  if (n < 1000) return `${Math.round(n)} ms`
  if (n < 60000) return `${(n / 1000).toFixed(n % 1000 ? 1 : 0)} s`
  return `${(n / 60000).toFixed(n % 60000 ? 1 : 0)} min`
}
