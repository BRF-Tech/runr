import { describe, expect, it } from 'vitest'
import {
  addableTypes, buildGenericConfig, buildTriggerPayload, emptyForm, eventBody, formFromTrigger, genericValues,
  hasDedicatedForm, parseEventData, primaryTrigger, triggerSummary, validateForm, validateGeneric, workloadTrigger,
} from '@/modules/triggers/utils/triggers'
import { HOOKS, HTTP, KAFKA, NIGHTLY, REGIONS, TYPES, TYPES_WITH_KAFKA, eventDetail, trigger } from './fixtures'

// Trigger list helpers: per-type payloads, client checks with the API's rules, what a project can
// add, the generic form of types without a dedicated one, inbox bodies.

const fakeT = (key, params) => (params ? `${key} ${JSON.stringify(params)}` : key)
const errorKeys = (errors) => Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v.key]))

function form(type, patch = {}, types = TYPES) {
  return Object.assign(emptyForm(type, types), patch)
}

describe('buildTriggerPayload', () => {
  it('sends only the type and the switch for http', () => {
    expect(buildTriggerPayload(form('http'), { creating: true, types: TYPES })).toEqual({ type: 'http', enabled: true, config: {} })
  })

  it('sends a schedule with its cron and delivery settings that differ from the default', () => {
    const f = form('schedule', { name: 'nightly', cron: ' 0 3 * * * ' })
    f.delivery.max_retries = '2'
    f.delivery.concurrency = ''
    expect(buildTriggerPayload(f, { creating: true, types: TYPES })).toEqual({
      type: 'schedule', name: 'nightly', enabled: true, config: { cron: '0 3 * * *', delivery: { max_retries: 2 } },
    })
  })

  it('sends a schedule\'s own time zone; without one the organization\'s applies', () => {
    expect(buildTriggerPayload(form('schedule', { cron: '0 9 * * *', timezone: ' Europe/Berlin ' }), { types: TYPES }).config)
      .toEqual({ cron: '0 9 * * *', timezone: 'Europe/Berlin' })
    expect(buildTriggerPayload(form('schedule', { cron: '0 9 * * *', timezone: '' }), { types: TYPES }).config)
      .toEqual({ cron: '0 9 * * *' })
    expect(formFromTrigger(trigger({ ...NIGHTLY, config: { cron: '0 3 * * *', timezone: 'Asia/Tokyo' } }), TYPES).timezone).toBe('Asia/Tokyo')
    expect(formFromTrigger(NIGHTLY, TYPES).timezone).toBe('')
  })

  it('sends a queue stream with where to start (earliest by default)', () => {
    expect(buildTriggerPayload(form('queue', { stream: 'orders' }), { types: TYPES }).config).toEqual({ stream: 'orders', start_from: 'earliest' })
    expect(buildTriggerPayload(form('queue', { stream: 'orders', start_from: 'latest' }), { types: TYPES }).config).toEqual({ stream: 'orders', start_from: 'latest' })
  })

  it('sends the optional queue stream of a workflow and no delivery settings', () => {
    const f = form('workflow')
    f.delivery.concurrency = '8'
    expect(buildTriggerPayload(f, { types: TYPES }).config).toEqual({})
    f.queue_stream = 'orders'
    expect(buildTriggerPayload(f, { types: TYPES }).config).toEqual({ queue_stream: 'orders' })
  })

  it('sends a webhook preset with the typed secret only', () => {
    const f = form('webhook', { verify: 'github' })
    expect(buildTriggerPayload(f, { types: TYPES })).toEqual({ enabled: true, config: { verify: 'github' } })
    f.signing_secret = 'whsec-123456'
    expect(buildTriggerPayload(f, { types: TYPES }).secrets).toEqual({ signing_secret: 'whsec-123456' })
  })

  it('sends the HMAC settings, tolerance only for timestamped presets and the advanced fields', () => {
    const f = form('webhook', {
      verify: 'hmac', hmac: { header: 'X-Sig', algorithm: 'sha512', encoding: 'base64', prefix: 'sha512=' },
      tolerance_seconds: '60', delivery_id_header: 'X-Request-Id', max_body_kb: '256', retention_days: '14',
    })
    expect(buildTriggerPayload(f, { types: TYPES }).config).toEqual({
      verify: 'hmac', hmac: { header: 'X-Sig', algorithm: 'sha512', encoding: 'base64', prefix: 'sha512=' },
      delivery_id_header: 'X-Request-Id', max_body_kb: 256, retention_days: 14,
    })
    f.verify = 'stripe'
    expect(buildTriggerPayload(f, { types: TYPES }).config).toMatchObject({ verify: 'stripe', tolerance_seconds: 60 })
    expect(buildTriggerPayload(f, { types: TYPES }).config).not.toHaveProperty('hmac')
  })

  it('sends a topic subscription (new events by default)', () => {
    expect(buildTriggerPayload(form('topic', { topic: 'orders.created' }), { types: TYPES }).config).toEqual({ topic: 'orders.created', start_from: 'latest' })
  })

  it('sends platform events, collapsing a list with "*" to all events', () => {
    const f = form('platform_event', { events: ['deployment.failed', 'alert.fired'], projects: ['p1'], retention_days: '3' })
    expect(buildTriggerPayload(f, { types: TYPES }).config).toEqual({ events: ['deployment.failed', 'alert.fired'], projects: ['p1'], retention_days: 3 })
    f.events = ['deployment.failed', '*']
    f.projects = []
    expect(buildTriggerPayload(f, { types: TYPES }).config).toEqual({ events: ['*'], retention_days: 3 })
  })

  it('round-trips a stored trigger through the form', () => {
    const stored = trigger({ ...HOOKS, config: { verify: 'hmac', hmac: { header: 'X-Sig', algorithm: 'sha1', encoding: 'hex', prefix: '' }, retention_days: 10, delivery: { concurrency: 2 } } })
    const f = formFromTrigger(stored, TYPES)
    expect(f.name).toBe('hooks')
    expect(f.signing_secret).toBe('')
    expect(f.delivery).toEqual({ concurrency: '2', max_retries: '', retry_backoff_seconds: '', retry_backoff_max_seconds: '' })
    expect(buildTriggerPayload(f, { types: TYPES })).toEqual({ name: 'hooks', enabled: true, config: stored.config })
  })
})

describe('validateForm', () => {
  it('checks names like the API and against the other triggers', () => {
    expect(errorKeys(validateForm(form('http', { name: 'Bad_Name' }), { types: TYPES }))).toEqual({ name: 'triggers.validation.name' })
    expect(errorKeys(validateForm(form('http', { name: 'http' }), { types: TYPES, siblings: [HTTP] }))).toEqual({ name: 'triggers.validation.nameTaken' })
    expect(validateForm(form('http', { name: 'public-api' }), { types: TYPES, siblings: [HTTP] })).toEqual({})
  })

  it('needs a valid cron for a schedule', () => {
    expect(errorKeys(validateForm(form('schedule'), { types: TYPES }))).toEqual({ 'config.cron': 'triggers.validation.cronRequired' })
    expect(errorKeys(validateForm(form('schedule', { cron: '0 0 * * * *' }), { types: TYPES }))).toEqual({ 'config.cron': 'triggers.validation.cron' })
    expect(validateForm(form('schedule', { cron: '@hourly' }), { types: TYPES })).toEqual({})
  })

  it('takes an optional time zone the browser knows (the API checks the exact name)', () => {
    expect(validateForm(form('schedule', { cron: '0 9 * * *', timezone: 'Europe/Berlin' }), { types: TYPES })).toEqual({})
    expect(validateForm(form('schedule', { cron: '0 9 * * *', timezone: ' ' }), { types: TYPES })).toEqual({})
    for (const zone of ['Mars/Olympus', 'Local']) {
      expect(errorKeys(validateForm(form('schedule', { cron: '0 9 * * *', timezone: zone }), { types: TYPES })))
        .toEqual({ 'config.timezone': 'triggers.validation.timezone' })
    }
  })

  it('refuses reserved and malformed stream names', () => {
    expect(errorKeys(validateForm(form('queue'), { types: TYPES }))).toEqual({ 'config.stream': 'triggers.validation.streamRequired' })
    expect(errorKeys(validateForm(form('queue', { stream: 'a b' }), { types: TYPES }))).toEqual({ 'config.stream': 'triggers.validation.stream' })
    expect(errorKeys(validateForm(form('queue', { stream: 'runr.internal' }), { types: TYPES }))).toEqual({ 'config.stream': 'triggers.validation.streamReserved' })
    expect(errorKeys(validateForm(form('workflow', { queue_stream: 'runr.x' }), { types: TYPES }))).toEqual({ 'config.queue_stream': 'triggers.validation.streamReserved' })
  })

  it('needs a signing secret for verification unless one is stored', () => {
    const f = form('webhook', { verify: 'slack' })
    expect(errorKeys(validateForm(f, { types: TYPES }))).toEqual({ 'secrets.signing_secret': 'triggers.validation.secretRequired' })
    expect(validateForm(f, { types: TYPES, hasSigningSecret: true })).toEqual({})
    expect(validateForm(form('webhook', { verify: 'none' }), { types: TYPES })).toEqual({})
    expect(errorKeys(validateForm(form('webhook', { verify: 'github', signing_secret: 'short' }), { types: TYPES })))
      .toEqual({ 'secrets.signing_secret': 'triggers.validation.secretLength' })
  })

  it('checks headers, the retention and the delivery ranges', () => {
    const f = form('webhook', { verify: 'hmac', signing_secret: 'long-enough', hmac: { header: 'X Sig', algorithm: 'sha256', encoding: 'hex', prefix: '' }, retention_days: '31', max_body_kb: '2048' })
    f.delivery.concurrency = '64'
    const errors = validateForm(f, { types: TYPES })
    expect(errorKeys(errors)).toEqual({
      'config.hmac.header': 'triggers.validation.header',
      'config.retention_days': 'triggers.validation.range',
      'config.max_body_kb': 'triggers.validation.range',
      'config.delivery.concurrency': 'triggers.validation.range',
    })
    expect(errors['config.delivery.concurrency'].params).toEqual({ min: 1, max: 32 })
  })

  it('needs a topic and at least one platform event', () => {
    expect(errorKeys(validateForm(form('topic'), { types: TYPES }))).toEqual({ 'config.topic': 'triggers.validation.topicRequired' })
    expect(errorKeys(validateForm(form('platform_event'), { types: TYPES }))).toEqual({ 'config.events': 'triggers.validation.eventsRequired' })
  })
})

describe('addableTypes', () => {
  const reasons = (list) => Object.fromEntries(list.map(c => [c.type, c.reason]))

  it('offers every type next to HTTP except a second HTTP and the workflow', () => {
    expect(reasons(addableTypes(TYPES, [HTTP], REGIONS[0]))).toEqual({
      http: 'typeLimit', schedule: null, queue: null, workflow: 'exclusive', webhook: null, topic: null, platform_event: null,
    })
  })

  it('offers nothing next to a workflow trigger', () => {
    const list = addableTypes(TYPES, [trigger({ type: 'workflow', name: 'workflow' })], REGIONS[0])
    expect(list.every(c => c.disabled && c.reason === 'hasExclusive')).toBe(true)
  })

  it('needs Redis for queues and topics, Serving for HTTP', () => {
    expect(reasons(addableTypes(TYPES, [NIGHTLY], { id: 'r3', is_synced: false, has_serving: false, has_redis: false })))
      .toMatchObject({ http: 'serving', queue: 'redis', topic: 'redis', webhook: null })
  })

  it('stops at the project limit and lists API-only types last', () => {
    const many = Array.from({ length: 20 }, (_, i) => trigger({ name: `t${i}`, type: 'schedule' }))
    expect(addableTypes(TYPES, many, REGIONS[0]).every(c => c.reason === 'limit')).toBe(true)
    expect(addableTypes(TYPES_WITH_KAFKA, [HTTP], REGIONS[0]).map(c => c.type).at(-1)).toBe('kafka')
  })
})

describe('list helpers', () => {
  it('finds the primary trigger and how the workload runs', () => {
    expect(primaryTrigger([NIGHTLY, HOOKS, HTTP])).toBe(HTTP)
    expect(workloadTrigger([trigger({ type: 'schedule', name: 's' })], TYPES)).toBe('schedule')
    expect(workloadTrigger([trigger({ type: 'schedule', name: 's' }), trigger({ ...HTTP, enabled: false })], TYPES)).toBe('schedule')
    expect(workloadTrigger([HTTP, NIGHTLY], TYPES)).toBe('http')
    expect(workloadTrigger([HOOKS, NIGHTLY], TYPES)).toBe('queue')
    expect(workloadTrigger([], TYPES)).toBe('http')
  })

  it('summarises the settings of a row', () => {
    expect(triggerSummary(NIGHTLY, fakeT)).toEqual([{ text: '0 3 * * *', mono: true }])
    // the zone a schedule runs in: its own, or the organization's it follows
    expect(triggerSummary({ ...NIGHTLY, config: { cron: '0 3 * * *', timezone: 'UTC' }, effective_timezone: 'UTC' }, fakeT))
      .toEqual([{ text: '0 3 * * *', mono: true }, { text: 'UTC', mono: true }])
    expect(triggerSummary({ ...NIGHTLY, effective_timezone: 'Europe/Istanbul', timezone_source: 'organization' }, fakeT))
      .toEqual([{ text: '0 3 * * *', mono: true }, { text: 'triggers.fields.timezoneOrg {"zone":"Europe/Istanbul"}', mono: false }])
    expect(triggerSummary(HOOKS, fakeT)).toEqual([{ text: 'triggers.webhook.presets.github' }])
    expect(triggerSummary(trigger({ type: 'topic', config: { topic: 'orders' } }), fakeT).map(p => p.text))
      .toEqual(['orders', 'triggers.fields.start_latest'])
    expect(triggerSummary(trigger({ type: 'platform_event', config: { events: ['*'], projects: ['p1', 'p2'] } }), fakeT).map(p => p.text))
      .toEqual(['triggers.platform.allEvents', 'triggers.platform.projectsCount {"n":2}'])
    expect(triggerSummary(HTTP, fakeT)).toEqual([])
  })
})

describe('generic form (types without a dedicated one)', () => {
  const fields = KAFKA.fields

  it('is used only for the types the console has no form for', () => {
    expect(TYPES.every(x => hasDedicatedForm(x.type))).toBe(true)
    expect(hasDedicatedForm('kafka')).toBe(false)
  })

  it('starts from the defaults; secrets are never prefilled', () => {
    expect(genericValues(fields)).toEqual({
      brokers: '', topic: '', group: 'runr', 'sasl.mechanism': 'plain', 'sasl.password': '', batch: '10', tls: true, poll: '5s', headers: '',
    })
    expect(emptyForm('kafka', TYPES_WITH_KAFKA).values.group).toBe('runr')
  })

  it('reads a stored config with nested keys and lists', () => {
    const values = genericValues(fields, { brokers: ['a:9092', 'b:9092'], topic: 'orders', sasl: { mechanism: 'scram-sha-256' }, tls: false, headers: { x: 1 } })
    expect(values).toMatchObject({ brokers: 'a:9092\nb:9092', 'sasl.mechanism': 'scram-sha-256', tls: false, 'sasl.password': '' })
    expect(JSON.parse(values.headers)).toEqual({ x: 1 })
  })

  it('builds the nested config and puts secret fields into secrets', () => {
    const values = {
      ...genericValues(fields), brokers: ' a:9092 \n\n b:9092 ', topic: 'orders', 'sasl.password': 'pw-123', batch: '25', headers: '{"x": 1}',
    }
    expect(buildGenericConfig(fields, values)).toEqual({
      config: {
        brokers: ['a:9092', 'b:9092'], topic: 'orders', group: 'runr', sasl: { mechanism: 'plain' }, batch: 25, tls: true, poll: '5s', headers: { x: 1 },
      },
      secrets: { sasl: { password: 'pw-123' } },
    })
  })

  it('validates required fields, numbers, options and JSON; a stored secret may stay empty', () => {
    const values = { ...genericValues(fields), batch: '2.5', headers: '{nope', 'sasl.mechanism': 'gssapi' }
    expect(errorKeys(validateGeneric(fields, values))).toEqual({
      'config.brokers': 'triggers.generic.required',
      'config.topic': 'triggers.generic.required',
      'secrets.sasl.password': 'triggers.generic.required',
      'config.batch': 'triggers.generic.integer',
      'config.sasl.mechanism': 'triggers.generic.option',
      'config.headers': 'triggers.generic.json',
    })
    const ok = { ...genericValues(fields), brokers: 'a:9092', topic: 'orders' }
    expect(validateGeneric(fields, ok, { secrets: ['sasl.password'] })).toEqual({})
  })

  it('goes through the same payload and checks as the dedicated forms, with delivery settings', () => {
    const f = emptyForm('kafka', TYPES_WITH_KAFKA)
    f.name = 'orders-in'
    f.values.brokers = 'a:9092'
    f.values.topic = 'orders'
    f.values['sasl.password'] = 'pw-123'
    f.delivery.concurrency = '8'
    expect(validateForm(f, { types: TYPES_WITH_KAFKA })).toEqual({})
    expect(buildTriggerPayload(f, { creating: true, types: TYPES_WITH_KAFKA })).toEqual({
      type: 'kafka', name: 'orders-in', enabled: true,
      config: { brokers: ['a:9092'], topic: 'orders', group: 'runr', sasl: { mechanism: 'plain' }, batch: 10, tls: true, poll: '5s', delivery: { concurrency: 8 } },
      secrets: { sasl: { password: 'pw-123' } },
    })
    // editing: the stored secret stays when the field is empty
    const stored = trigger({ type: 'kafka', name: 'orders-in', config: { brokers: ['a:9092'], topic: 'orders' }, secrets: ['sasl'] })
    const edit = formFromTrigger(stored, TYPES_WITH_KAFKA)
    expect(validateForm(edit, { types: TYPES_WITH_KAFKA, secrets: stored.secrets })).toEqual({})
    expect(buildTriggerPayload(edit, { types: TYPES_WITH_KAFKA })).not.toHaveProperty('secrets')
  })
})

describe('inbox helpers', () => {
  it('pretty-prints JSON bodies and falls back to base64', () => {
    const detail = eventDetail('e1', 'failed')
    expect(eventBody(detail).text).toContain('"number": 42')
    expect(eventBody(detail, 'base64')).toEqual({ text: detail.body_base64, binary: false })
    expect(eventBody({ ...detail, body_text: null })).toEqual({ text: detail.body_base64, binary: true })
    expect(eventBody({ ...detail, content_type: 'text/plain', body_text: 'plain' }).text).toBe('plain')
  })

  it('parses any JSON value for a topic publish, not an empty editor', () => {
    expect(parseEventData('[1, 2]')).toEqual({ ok: true, value: [1, 2] })
    expect(parseEventData('"text"')).toEqual({ ok: true, value: 'text' })
    expect(parseEventData('   ').ok).toBe(false)
    expect(parseEventData('{').ok).toBe(false)
  })
})
