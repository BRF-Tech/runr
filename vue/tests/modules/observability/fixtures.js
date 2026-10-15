import { createI18n } from 'vue-i18n'
import en from '@/modules/observability/locales/en.js'

/** i18n with the observability messages (English) for component tests. */
export function makeI18n() {
  return createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: { en },
  })
}

/** A normalized trace (GET /projects/{p}/traces/{id}): activator → wrapper server span → db client. */
export function trace(overrides = {}) {
  return {
    trace_id: '4bf92f3577b34da6a3ce929d0e0e4736',
    start: '2026-10-10T10:00:00.000Z',
    start_ms: 1760090400000,
    end_ms: 1760090400050,
    duration_ms: 50,
    span_count: 3,
    error_count: 1,
    services: ['activator', 'orders'],
    spans: [
      span({ span_id: 'aaaaaaaaaaaaaaaa', parent_span_id: null, name: 'activator_proxy', service: 'activator', depth: 0, start_offset_ms: 0, duration_ms: 50 }),
      span({
        span_id: 'bbbbbbbbbbbbbbbb', parent_span_id: 'aaaaaaaaaaaaaaaa', name: 'GET', service: 'orders', kind: 'server', depth: 1,
        start_offset_ms: 5, duration_ms: 40, status: { code: 'error', message: 'HTTP 500' },
        attributes: { 'http.response.status_code': 500, 'runr.cold_start': true, 'url.path': '/orders' },
        events: [{ name: 'exception', offset_ms: 44, attributes: { 'exception.message': 'database unreachable' } }],
        resource: { 'service.name': 'orders', 'runr.project_id': 'p1' },
      }),
      span({ span_id: 'cccccccccccccccc', parent_span_id: 'bbbbbbbbbbbbbbbb', name: 'SELECT orders', service: 'orders', kind: 'client', depth: 2, start_offset_ms: 10, duration_ms: 30 }),
    ],
    ...overrides,
  }
}

export function span(overrides = {}) {
  return {
    trace_id: '4bf92f3577b34da6a3ce929d0e0e4736',
    span_id: '0000000000000000',
    parent_span_id: null,
    name: 'span',
    kind: 'internal',
    service: 'orders',
    status: { code: 'unset', message: null },
    attributes: {},
    events: [],
    resource: { 'service.name': 'orders' },
    depth: 0,
    start_offset_ms: 0,
    duration_ms: 1,
    ...overrides,
  }
}

export const channels = [
  { id: '01JCHANNELSLACK00000000000', name: 'ops-slack', type: 'slack', config: { webhook_host: 'hooks.slack.com', webhook_set: true }, is_enabled: true },
  { id: '01JCHANNELMAIL000000000000', name: 'oncall', type: 'email', config: { emails: ['oncall@example.com'] }, is_enabled: true },
]

export const projects = [{ id: '01JPROJECT0000000000000001', name: 'orders' }, { id: '01JPROJECT0000000000000002', name: 'billing' }]
export const regions = [{ id: '01JREGION00000000000000001', name: 'eu-1' }]
