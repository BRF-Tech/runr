// Trigger list helpers (docs/triggers.md): per-type form state, the API payload, client-side
// validation with the API's rules and what a project can add. Pure functions, unit tested.
import { isValidCron } from '@/composables/projectValidation'

/** Display order of the types (GET /trigger-types order is the registry's). */
export const TYPE_ORDER = ['http', 'schedule', 'queue', 'workflow', 'webhook', 'topic', 'platform_event']

/** Fallbacks when GET /trigger-types does not list them (same values as the API). */
export const WEBHOOK_PRESETS = ['none', 'stripe', 'github', 'slack', 'hmac']
export const HMAC_ALGORITHMS = ['sha256', 'sha1', 'sha512']
export const HMAC_ENCODINGS = ['hex', 'base64']
export const PLATFORM_EVENTS = [
  'deployment.finished', 'deployment.failed', 'alert.fired', 'alert.resolved',
  'runtime.patch_available', 'cost.limit_reached',
  'environment.promotion.pending_approval', 'environment.promotion.approved', 'environment.promotion.rejected',
  'environment.promotion.deployed', 'environment.promotion.failed',
]

/** config.delivery: [key, min, max, default] (config/triggers.php defaults). */
export const DELIVERY_FIELDS = [
  ['concurrency', 1, 32, 4],
  ['max_retries', 0, 20, 5],
  ['retry_backoff_seconds', 1, 300, 2],
  ['retry_backoff_max_seconds', 1, 3600, 300],
]
export const DELIVERY_DEFAULTS = Object.fromEntries(DELIVERY_FIELDS.map(([k, , , d]) => [k, d]))

export const MAX_TRIGGERS = 20
export const RETENTION_MAX_DAYS = 30
export const WEBHOOK_MAX_BODY_KB = 1024

const filled = (v) => v !== null && v !== undefined && String(v).trim() !== ''
const int = (v) => Number.parseInt(String(v).trim(), 10)
const str = (v) => (v === null || v === undefined ? '' : String(v))

/**
 * Whether the browser knows the zone (an IANA name). Lenient on purpose: the API checks the exact
 * name against its zone list (case included) and its 422 is shown next to the field.
 */
export function isTimeZone(zone) {
  if (!zone || zone === 'Local') return false
  try {
    new Intl.DateTimeFormat('en', { timeZone: zone })
    return true
  } catch {
    return false
  }
}

export const NAME_PATTERN = /^[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])?$/
export const STREAM_PATTERN = /^[a-zA-Z0-9._-]+$/
export const HEADER_PATTERN = /^[A-Za-z0-9-]+$/
export const TOPIC_NAME_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/

// ── Type metadata ───────────────────────────────────────────────────────────────────────

/** Metadata of a type from GET /trigger-types `data`, with the API's values for the built-in ones. */
export function typeInfo(types, type) {
  const found = (types ?? []).find(x => x.type === type)
  if (found) return found
  const servesHttp = type === 'http' || type === 'workflow'
  return {
    type,
    label: type,
    serves_http: servesHttp,
    gateway: !servesHttp,
    requires_redis: type === 'queue' || type === 'topic',
    exclusive: type === 'workflow',
    stores_events: type === 'webhook' || type === 'platform_event',
    secrets: type === 'webhook' ? ['signing_secret'] : [],
    max_per_project: servesHttp ? 1 : null,
  }
}

/** The types in display order (unknown ones last). */
export function sortTypes(types) {
  const rank = (t) => {
    const i = TYPE_ORDER.indexOf(t.type)
    return i === -1 ? TYPE_ORDER.length : i
  }
  return [...(types ?? [])].sort((a, b) => rank(a) - rank(b))
}

/** Whether the project's event gateway delivers the type (common delivery settings). */
export function isGatewayType(types, type) {
  return !!typeInfo(types, type).gateway
}

/** Whether the type stores its events (inbox viewer, replay). */
export function storesEvents(types, type) {
  return !!typeInfo(types, type).stores_events
}

/** Whether the region has Redis for queue / topic triggers (same rule as the project forms). */
export function regionHasRedis(region) {
  return !!(region && (region.is_synced || region.has_redis))
}

/** Whether the region serves public HTTP (Knative Serving). */
export function regionServesHttp(region) {
  return !region || !!(region.is_synced || region.has_serving || region.is_demo)
}

/**
 * The types the project can add now: [{ ...typeInfo, disabled, reason }]. reason is a key under
 * triggers.unavailable.
 */
export function addableTypes(types, triggers, region) {
  const list = triggers ?? []
  const hasExclusive = list.some(t => typeInfo(types, t.type).exclusive)
  return sortTypes(types).map((info) => {
    let reason = null
    if (list.length >= MAX_TRIGGERS) reason = 'limit'
    else if (hasExclusive) reason = 'hasExclusive'
    else if (info.exclusive && list.length > 0) reason = 'exclusive'
    else if (info.max_per_project && list.filter(t => t.type === info.type).length >= info.max_per_project) reason = 'typeLimit'
    else if (info.serves_http && !regionServesHttp(region)) reason = 'serving'
    else if (info.requires_redis && !regionHasRedis(region)) reason = 'redis'
    return { ...info, disabled: reason !== null, reason }
  })
}

// ── List ────────────────────────────────────────────────────────────────────────────────

/** The primary trigger: the first enabled one in order (the API marks it `primary`). */
export function primaryTrigger(triggers) {
  const list = [...(triggers ?? [])].sort((a, b) => (a.position ?? 0) - (b.position ?? 0))
  return list.find(t => t.primary) ?? list.find(t => t.enabled) ?? null
}

/**
 * How the workload runs for the runtime (TriggerPlan::runtimeTriggers): schedule for a single
 * enabled schedule trigger (CronJob), http when a trigger serves HTTP, queue when only the event
 * gateway calls the function.
 */
export function workloadTrigger(triggers, types = null) {
  const enabled = (triggers ?? []).filter(t => t.enabled)
  if (enabled.length === 1 && enabled[0].type === 'schedule') return 'schedule'
  if (enabled.some(t => typeInfo(types, t.type).serves_http)) return 'http'
  return enabled.length ? 'queue' : 'http'
}

/** Platform event name → message key segment (vue-i18n paths cannot contain dots). */
export function eventKey(event) {
  return String(event).replace(/\./g, '_')
}

/**
 * One-line description of a trigger's settings: [{ text, mono }] parts for the list row.
 * `t` translates triggers.* keys.
 */
export function triggerSummary(trigger, t) {
  const c = trigger?.config ?? {}
  switch (trigger?.type) {
    case 'schedule': {
      // the zone it runs in: its own, else the organization's (effective_timezone)
      const zone = c.timezone || trigger.effective_timezone
      const parts = [{ text: c.cron ?? '', mono: true }]
      if (zone) parts.push({ text: c.timezone ? zone : t('triggers.fields.timezoneOrg', { zone }), mono: !!c.timezone })
      return parts
    }
    case 'queue':
      return [{ text: c.stream ?? '', mono: true }, { text: t(`triggers.fields.start_${c.start_from === 'latest' ? 'latest' : 'earliest'}`) }]
    case 'workflow':
      return c.queue_stream ? [{ text: c.queue_stream, mono: true }] : []
    case 'webhook':
      return [{ text: t(`triggers.webhook.presets.${c.verify ?? 'none'}`) }]
    case 'topic':
      return [{ text: c.topic ?? '', mono: true }, { text: t(`triggers.fields.start_${c.start_from === 'earliest' ? 'earliest' : 'latest'}`) }]
    case 'platform_event': {
      const events = c.events ?? []
      const parts = [{ text: events.includes('*') ? t('triggers.platform.allEvents') : events.join(', '), mono: !events.includes('*') }]
      if (c.projects?.length) parts.push({ text: t('triggers.platform.projectsCount', { n: c.projects.length }) })
      return parts
    }
    default:
      return []
  }
}

// ── Generic form (types without a dedicated form) ─────────────────────────────────────────
//
// GET /trigger-types describes each type's config as `fields`: [{ key ("a.b" nests), type
// (string | text | list | integer | boolean | select | duration | json), label, help, required,
// default, options, secret }]. The core types have dedicated forms and `fields: []`; any other
// type is edited with a form built from its fields. Secret fields go to `secrets.<key>`
// (write-only: never prefilled, empty keeps the stored value).

/** Whether the console has a dedicated form for the type. */
export function hasDedicatedForm(type) {
  return TYPE_ORDER.includes(type)
}

/** The `fields` of a type (GET /trigger-types). */
export function typeFields(types, type) {
  return typeInfo(types, type).fields ?? []
}

function getPath(obj, key) {
  return String(key).split('.').reduce((o, k) => (o === null || o === undefined ? undefined : o[k]), obj)
}

function setPath(obj, key, value) {
  const parts = String(key).split('.')
  let o = obj
  for (const p of parts.slice(0, -1)) {
    if (typeof o[p] !== 'object' || o[p] === null) o[p] = {}
    o = o[p]
  }
  o[parts[parts.length - 1]] = value
}

/** A stored config value → the input's value. */
function fieldInput(field, value) {
  if (field.type === 'boolean') return !!value
  if (value === null || value === undefined) return ''
  if (field.type === 'list') return Array.isArray(value) ? value.join('\n') : String(value)
  if (field.type === 'json') return typeof value === 'string' ? value : JSON.stringify(value, null, 2)
  return String(value)
}

/** Editor values of the fields: the stored config, else each field's default; secrets start empty. */
export function genericValues(fields, config = null) {
  return Object.fromEntries((fields ?? []).map((f) => {
    if (f.secret) return [f.key, '']
    const stored = config ? getPath(config, f.key) : undefined
    return [f.key, fieldInput(f, stored !== undefined ? stored : f.default)]
  }))
}

const listItems = (raw) => String(raw ?? '').split('\n').map(s => s.trim()).filter(Boolean)

/** Whether the trigger has the secret set (the API lists the names of the set secrets). */
export function secretIsSet(secrets, key) {
  const names = secrets ?? []
  return names.includes(key) || names.includes(String(key).split('.')[0])
}

/** { config, secrets } of the generic form's values. */
export function buildGenericConfig(fields, values) {
  const config = {}
  const secrets = {}
  for (const f of fields ?? []) {
    const raw = values?.[f.key]
    if (f.secret) {
      if (filled(raw)) setPath(secrets, f.key, String(raw))
      continue
    }
    switch (f.type) {
      case 'boolean':
        setPath(config, f.key, !!raw)
        break
      case 'integer':
        if (filled(raw)) setPath(config, f.key, int(raw))
        break
      case 'list': {
        const items = listItems(raw)
        if (items.length) setPath(config, f.key, items)
        break
      }
      case 'json':
        if (filled(raw)) setPath(config, f.key, JSON.parse(String(raw)))
        break
      default:
        if (filled(raw)) setPath(config, f.key, String(raw).trim())
        break
    }
  }
  return { config, secrets }
}

/** Errors of the generic form, keyed like the API's (config.<key>, secrets.<key>). */
export function validateGeneric(fields, values, { secrets = [] } = {}) {
  const errors = {}
  for (const f of fields ?? []) {
    const raw = values?.[f.key]
    const key = f.secret ? `secrets.${f.key}` : `config.${f.key}`
    if (f.secret) {
      if (f.required && !filled(raw) && !secretIsSet(secrets, f.key)) errors[key] = { key: 'triggers.generic.required', params: {} }
      continue
    }
    if (f.type === 'boolean') continue
    const empty = f.type === 'list' ? listItems(raw).length === 0 : !filled(raw)
    if (empty) {
      if (f.required) errors[key] = { key: 'triggers.generic.required', params: {} }
      continue
    }
    if (f.type === 'integer' && !/^-?\d+$/.test(String(raw).trim())) errors[key] = { key: 'triggers.generic.integer', params: {} }
    if (f.type === 'select' && Array.isArray(f.options) && f.options.length && !f.options.map(String).includes(String(raw))) errors[key] = { key: 'triggers.generic.option', params: {} }
    if (f.type === 'json') {
      try { JSON.parse(String(raw)) } catch { errors[key] = { key: 'triggers.generic.json', params: {} } }
    }
  }
  return errors
}

// ── Form state ──────────────────────────────────────────────────────────────────────────

function emptyDelivery() {
  return Object.fromEntries(DELIVERY_FIELDS.map(([k]) => [k, '']))
}

/** Editor state of a new trigger of `type` (`types`: GET /trigger-types, for the generic form). */
export function emptyForm(type, types = null) {
  return {
    type,
    name: '',
    enabled: true,
    cron: '',
    timezone: '', // empty: the organization's time zone
    stream: '',
    start_from: type === 'topic' ? 'latest' : 'earliest',
    queue_stream: '',
    topic: '',
    verify: 'none',
    hmac: { header: 'X-Signature', algorithm: 'sha256', encoding: 'hex', prefix: '' },
    delivery_id_header: '',
    tolerance_seconds: '',
    max_body_kb: '',
    retention_days: '',
    events: [],
    projects: [],
    delivery: emptyDelivery(),
    signing_secret: '',
    // generic form: field key → input value
    values: hasDedicatedForm(type) ? {} : genericValues(typeFields(types, type)),
  }
}

/** Editor state of a stored trigger (presented by the API). */
export function formFromTrigger(trigger, types = null) {
  const c = trigger?.config ?? {}
  const form = emptyForm(trigger.type, types)
  if (!hasDedicatedForm(trigger.type)) form.values = genericValues(typeFields(types, trigger.type), c)
  form.name = trigger.name ?? ''
  form.enabled = trigger.enabled !== false
  form.cron = str(c.cron)
  form.timezone = str(c.timezone)
  form.stream = str(c.stream)
  form.queue_stream = str(c.queue_stream)
  form.topic = str(c.topic)
  if (c.start_from) form.start_from = c.start_from
  if (trigger.type === 'webhook') {
    form.verify = c.verify ?? 'none'
    form.hmac = { ...form.hmac, ...(c.hmac ?? {}) }
    form.delivery_id_header = str(c.delivery_id_header)
    form.tolerance_seconds = str(c.tolerance_seconds)
    form.max_body_kb = str(c.max_body_kb)
  }
  form.retention_days = str(c.retention_days)
  form.events = [...(c.events ?? [])]
  form.projects = [...(c.projects ?? [])]
  // Only the trigger's own delivery values: the defaults stay placeholders
  for (const [k] of DELIVERY_FIELDS) form.delivery[k] = str(c.delivery?.[k])
  return form
}

function deliveryPayload(delivery) {
  const out = {}
  for (const [k] of DELIVERY_FIELDS) {
    if (filled(delivery?.[k])) out[k] = int(delivery[k])
  }
  return Object.keys(out).length ? out : null
}

/** config of the API request for the form (replaces the stored config on PATCH). */
export function buildConfig(form, types = null) {
  const config = {}
  switch (form.type) {
    case 'schedule':
      config.cron = form.cron.trim()
      if (filled(form.timezone)) config.timezone = form.timezone.trim()
      break
    case 'queue':
      config.stream = form.stream.trim()
      config.start_from = form.start_from === 'latest' ? 'latest' : 'earliest'
      break
    case 'workflow':
      if (form.queue_stream.trim()) config.queue_stream = form.queue_stream.trim()
      break
    case 'webhook':
      config.verify = form.verify
      if (form.verify === 'hmac') {
        config.hmac = {
          header:    form.hmac.header?.trim() || 'X-Signature',
          algorithm: form.hmac.algorithm || 'sha256',
          encoding:  form.hmac.encoding || 'hex',
          prefix:    form.hmac.prefix ?? '',
        }
      }
      if (filled(form.delivery_id_header)) config.delivery_id_header = form.delivery_id_header.trim()
      if (['stripe', 'slack'].includes(form.verify) && filled(form.tolerance_seconds)) config.tolerance_seconds = int(form.tolerance_seconds)
      if (filled(form.max_body_kb)) config.max_body_kb = int(form.max_body_kb)
      if (filled(form.retention_days)) config.retention_days = int(form.retention_days)
      break
    case 'topic':
      config.topic = form.topic
      config.start_from = form.start_from === 'earliest' ? 'earliest' : 'latest'
      break
    case 'platform_event':
      config.events = form.events.includes('*') ? ['*'] : [...form.events]
      if (form.projects.length) config.projects = [...form.projects]
      if (filled(form.retention_days)) config.retention_days = int(form.retention_days)
      break
    case 'http':
      break
    default:
      if (!hasDedicatedForm(form.type)) Object.assign(config, buildGenericConfig(typeFields(types, form.type), form.values).config)
      break
  }
  if (isGatewayType(types, form.type)) {
    const delivery = deliveryPayload(form.delivery)
    if (delivery) config.delivery = delivery
  }
  return config
}

/**
 * Body of POST (creating) or PATCH …/triggers. Secrets are sent only when typed: an empty field
 * keeps the stored one.
 */
export function buildTriggerPayload(form, { creating = false, types = null } = {}) {
  const payload = { enabled: !!form.enabled, config: buildConfig(form, types) }
  if (creating) payload.type = form.type
  if (form.name.trim()) payload.name = form.name.trim()
  const secrets = hasDedicatedForm(form.type)
    ? (form.type === 'webhook' && form.signing_secret ? { signing_secret: form.signing_secret } : {})
    : buildGenericConfig(typeFields(types, form.type), form.values).secrets
  if (Object.keys(secrets).length) payload.secrets = secrets
  return payload
}

function range(errors, key, value, min, max) {
  if (!filled(value)) return
  const n = Number(String(value).trim())
  if (!Number.isInteger(n) || n < min || n > max) errors[key] = { key: 'triggers.validation.range', params: { min, max } }
}

/**
 * Client-side checks (the API's rules) → { 'config.cron': { key, params } … }, keyed like the
 * API's 422 errors. `hasSigningSecret`: the stored webhook has one already; `secrets`: the names
 * of the stored trigger's set secrets (generic form).
 */
export function validateForm(form, { types = null, hasSigningSecret = false, siblings = [], secrets = [] } = {}) {
  const errors = {}
  const err = (field, key, params = {}) => { errors[field] = { key, params } }

  const name = form.name.trim()
  if (name && !NAME_PATTERN.test(name)) err('name', 'triggers.validation.name')
  else if (name && siblings.some(s => s.name === name)) err('name', 'triggers.validation.nameTaken', { name })

  switch (form.type) {
    case 'schedule':
      if (!form.cron.trim()) err('config.cron', 'triggers.validation.cronRequired')
      else if (!isValidCron(form.cron)) err('config.cron', 'triggers.validation.cron')
      if (filled(form.timezone) && !isTimeZone(form.timezone.trim())) err('config.timezone', 'triggers.validation.timezone')
      break
    case 'queue': {
      const stream = form.stream.trim()
      if (!stream) err('config.stream', 'triggers.validation.streamRequired')
      else if (!STREAM_PATTERN.test(stream)) err('config.stream', 'triggers.validation.stream')
      else if (stream.startsWith('runr.')) err('config.stream', 'triggers.validation.streamReserved')
      break
    }
    case 'workflow': {
      const stream = form.queue_stream.trim()
      if (stream && !STREAM_PATTERN.test(stream)) err('config.queue_stream', 'triggers.validation.stream')
      else if (stream.startsWith('runr.')) err('config.queue_stream', 'triggers.validation.streamReserved')
      break
    }
    case 'webhook':
      if (form.verify !== 'none' && !form.signing_secret && !hasSigningSecret) err('secrets.signing_secret', 'triggers.validation.secretRequired')
      if (form.signing_secret && (form.signing_secret.length < 8 || form.signing_secret.length > 512)) err('secrets.signing_secret', 'triggers.validation.secretLength')
      if (form.verify === 'hmac' && filled(form.hmac.header) && !HEADER_PATTERN.test(form.hmac.header.trim())) err('config.hmac.header', 'triggers.validation.header')
      if (form.verify === 'hmac' && String(form.hmac.prefix ?? '').length > 32) err('config.hmac.prefix', 'triggers.validation.prefix')
      if (filled(form.delivery_id_header) && !HEADER_PATTERN.test(form.delivery_id_header.trim())) err('config.delivery_id_header', 'triggers.validation.header')
      if (['stripe', 'slack'].includes(form.verify)) range(errors, 'config.tolerance_seconds', form.tolerance_seconds, 0, 86400)
      range(errors, 'config.max_body_kb', form.max_body_kb, 1, WEBHOOK_MAX_BODY_KB)
      range(errors, 'config.retention_days', form.retention_days, 1, RETENTION_MAX_DAYS)
      break
    case 'topic':
      if (!form.topic) err('config.topic', 'triggers.validation.topicRequired')
      break
    case 'platform_event':
      if (!form.events.length) err('config.events', 'triggers.validation.eventsRequired')
      range(errors, 'config.retention_days', form.retention_days, 1, RETENTION_MAX_DAYS)
      break
    case 'http':
      break
    default:
      if (!hasDedicatedForm(form.type)) Object.assign(errors, validateGeneric(typeFields(types, form.type), form.values, { secrets }))
      break
  }

  if (isGatewayType(types, form.type)) {
    for (const [k, min, max] of DELIVERY_FIELDS) range(errors, `config.delivery.${k}`, form.delivery?.[k], min, max)
  }
  return errors
}

/** First message of a 422 `errors` object per field ({ 'config.cron': ['…'] } → { 'config.cron': '…' }). */
export function apiFieldErrors(errors) {
  return Object.fromEntries(Object.entries(errors ?? {}).map(([k, v]) => [k, Array.isArray(v) ? v[0] : String(v)]))
}

/** Message of a failed API call: the API's message, else its first field error, else the fallback. */
export function errorMessage(e, fallback) {
  const data = e?.response?.data
  return data?.message ?? Object.values(data?.errors ?? {}).flat()[0] ?? fallback
}

// ── Inbox events ────────────────────────────────────────────────────────────────────────

export const EVENT_STATUSES = ['pending', 'delivering', 'delivered', 'failed']

const EVENT_STATUS_CLASSES = {
  pending:    'bg-gray-800 text-gray-300 border-gray-700',
  delivering: 'bg-sky-900/40 text-sky-300 border-sky-800/50',
  delivered:  'bg-emerald-900/40 text-emerald-300 border-emerald-800/50',
  failed:     'bg-red-900/40 text-red-300 border-red-800/50',
}

export function eventStatusClass(status) {
  return EVENT_STATUS_CLASSES[status] ?? EVENT_STATUS_CLASSES.pending
}

/** Bytes → "512 B" / "2.0 KiB" / "1.5 MiB". */
export function formatSize(bytes) {
  const n = Number(bytes ?? 0)
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KiB`
  return `${(n / 1024 / 1024).toFixed(1)} MiB`
}

/**
 * The body of an event detail for display: { text, binary }. JSON is pretty-printed; a body the
 * API could not return as text (binary, larger than 64 KiB) shows its base64.
 */
export function eventBody(event, mode = 'text') {
  if (!event) return { text: '', binary: false }
  const hasText = typeof event.body_text === 'string'
  if (mode === 'base64' || !hasText) return { text: event.body_base64 ?? '', binary: !hasText }
  const text = event.body_text
  if (/json/i.test(event.content_type ?? '') || /^\s*[[{]/.test(text)) {
    try { return { text: JSON.stringify(JSON.parse(text), null, 2), binary: false } } catch { /* not JSON after all */ }
  }
  return { text, binary: false }
}

// ── Topics ──────────────────────────────────────────────────────────────────────────────

/** Regions where topics can be created: the API needs Redis there (or the demo region). */
export function topicRegionAvailable(region) {
  return !!(region && (region.has_redis || region.is_demo))
}

/** JSON typed into the publish editor → { ok, value } (any JSON value; empty is invalid). */
export function parseEventData(text) {
  const s = String(text ?? '').trim()
  if (!s) return { ok: false, value: null }
  try {
    return { ok: true, value: JSON.parse(s) }
  } catch {
    return { ok: false, value: null }
  }
}
