// Shared data of the trigger specs: GET /trigger-types, presented triggers, stored events, topics.

const type = (key, label, o = {}) => ({
  type: key, label, serves_http: false, gateway: true, requires_redis: false, exclusive: false,
  stores_events: false, secrets: [], max_per_project: null, fields: [], ...o,
})

export const TYPES = [
  type('http', 'HTTP', { serves_http: true, gateway: false, max_per_project: 1 }),
  type('schedule', 'Schedule'),
  type('queue', 'Queue', { requires_redis: true }),
  type('workflow', 'Workflow', { serves_http: true, gateway: false, exclusive: true, max_per_project: 1 }),
  type('webhook', 'Webhook inbox', { stores_events: true, secrets: ['signing_secret'] }),
  type('topic', 'Topic', { requires_redis: true }),
  type('platform_event', 'Platform events', { stores_events: true }),
]

/** A type without a dedicated form: the console builds it from `fields`. */
export const KAFKA = type('kafka', 'Kafka (API label)', {
  requires_redis: false,
  secrets: ['sasl.password'],
  fields: [
    { key: 'brokers', type: 'list', label: 'Brokers', help: 'host:port, one per line', required: true, default: null, options: null, secret: false },
    { key: 'topic', type: 'string', label: 'Kafka topic', help: null, required: true, default: null, options: null, secret: false },
    { key: 'group', type: 'string', label: 'Consumer group', help: null, required: false, default: 'runr', options: null, secret: false },
    { key: 'sasl.mechanism', type: 'select', label: 'SASL mechanism', help: null, required: false, default: 'plain', options: ['plain', 'scram-sha-256'], secret: false },
    { key: 'sasl.password', type: 'string', label: 'SASL password', help: null, required: true, default: null, options: null, secret: true },
    { key: 'batch', type: 'integer', label: 'Batch size', help: null, required: false, default: 10, options: null, secret: false },
    { key: 'tls', type: 'boolean', label: 'TLS', help: null, required: false, default: true, options: null, secret: false },
    { key: 'poll', type: 'duration', label: 'Poll interval', help: null, required: false, default: '5s', options: null, secret: false },
    { key: 'headers', type: 'json', label: 'Extra headers', help: null, required: false, default: null, options: null, secret: false },
  ],
})

export const TYPES_WITH_KAFKA = [...TYPES, KAFKA]

export const DELIVERY = { concurrency: 4, max_retries: 5, retry_backoff_seconds: 2, retry_backoff_max_seconds: 300 }

/** A presented trigger (TriggerPresenter::one). */
export function trigger(o = {}) {
  return {
    id: '01JTRG0000000000000000HTTP', name: 'http', type: 'http', label: 'HTTP', enabled: true, position: 0,
    primary: true, runs_on: 'service', config: {}, secrets: [], delivery: null, retention_days: null,
    created_at: '2026-10-21T10:00:00Z', updated_at: '2026-10-21T10:00:00Z', ...o,
  }
}

export const HTTP = trigger()
export const HOOKS = trigger({
  id: '01JTRG000000000000000HOOKS', name: 'hooks', type: 'webhook', label: 'Webhook inbox', position: 1, primary: false,
  runs_on: 'gateway', config: { verify: 'github' }, secrets: ['signing_secret'], delivery: DELIVERY, retention_days: 7,
  url: 'https://api.runr.test/api/hooks/central/' + 'k'.repeat(40), has_signing_secret: true,
})
export const NIGHTLY = trigger({
  id: '01JTRG0000000000000NIGHTLY', name: 'nightly', type: 'schedule', label: 'Schedule', position: 2, primary: false,
  runs_on: 'gateway', config: { cron: '0 3 * * *' }, delivery: DELIVERY,
})

export const PLAN = { workload: 'service', public: true, gateway: true, runtime_trigger: 'http', requires_redis: false }

export const PROJECT = { id: 'p1', name: 'Orders', slug: 'orders', service_type: 'xaas', knative_region_id: 'r1' }

export const REGIONS = [
  { id: 'r1', name: 'eu-1', is_active: true, is_synced: true, has_serving: true, has_redis: true },
  { id: 'r2', name: 'us-1', is_active: true, is_synced: false, has_serving: true, has_redis: false },
]

export function event(id, status, o = {}) {
  const body = JSON.stringify({ action: 'opened', number: 42 })
  return {
    id, trigger_id: HOOKS.id, kind: 'webhook', type: 'dev.runr.webhook.received', subject: 'pull_request',
    delivery_id: `gh-${id}`, content_type: 'application/json', size: body.length, status,
    attempts: status === 'failed' ? 6 : 1, replays: 0, last_error: status === 'failed' ? 'HTTP 500 from the function' : null,
    received_at: '2026-10-21T10:00:00Z', delivered_at: status === 'delivered' ? '2026-10-21T10:00:01Z' : null,
    expires_at: '2026-10-28T10:00:00Z', ...o,
  }
}

export function eventDetail(id, status, o = {}) {
  const body = JSON.stringify({ action: 'opened', number: 42 })
  return {
    ...event(id, status),
    headers: { 'Content-Type': 'application/json', 'X-GitHub-Event': 'pull_request' },
    body_base64: btoa(body), body_text: body, ...o,
  }
}

export const TOPIC = {
  id: '01JTOP00000000000000ORDERS', name: 'orders.created', description: 'New orders', knative_region_id: 'r1',
  region_name: 'eu-1', stream: 'runr.topic.orders.created', created_by: '1', created_at: '2026-10-21T10:00:00Z',
}
