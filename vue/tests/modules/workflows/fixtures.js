import { createI18n } from 'vue-i18n'
import en from '@/locales/en/workflows.js'

/** i18n with the workflows messages (English) for component tests. */
export function makeI18n() {
  return createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: { en: { ...en, common: { error: 'Error', loading: 'Loading' } } },
  })
}

export function run(overrides = {}) {
  return {
    id: '01JRUN0000000000000000000A',
    workflow: { id: 'w1', name: 'orders' },
    project_id: 'p1',
    status: 'completed',
    trigger_source: 'api',
    event_id: null,
    event_name: 'order.created',
    event: { id: 'e1', name: 'order.created', data: { order_id: 7 }, ts: 1 },
    output: { ok: true },
    error: null,
    attempt: 0,
    retry_count: 0,
    idempotency_key: null,
    concurrency_key: null,
    parent_run_id: null,
    replay_of_run_id: null,
    replay_from_step: null,
    next_attempt_at: null,
    timeout_at: '2030-01-01T00:00:00.000Z',
    started_at: '2026-10-10T10:00:00.000Z',
    ended_at: '2026-10-10T10:00:02.000Z',
    duration_ms: 2000,
    steps_count: 2,
    created_at: '2026-10-10T10:00:00.000Z',
    steps: [
      step({ id: 's1', step_key: 'charge', name: 'charge', position: 1, output: { charged: true } }),
      step({ id: 's2', step_key: 'nap', name: 'nap', type: 'sleep', position: 2, output: null, input: { duration: '1h' } }),
    ],
    children: [],
    ...overrides,
  }
}

export function step(overrides = {}) {
  return {
    id: 's',
    step_key: 'step',
    name: 'step',
    type: 'run',
    status: 'completed',
    position: 1,
    attempt: 1,
    max_attempts: 4,
    input: null,
    output: null,
    error: null,
    attempts: [],
    wake_at: null,
    timeout_at: null,
    wait_event: null,
    wait_expression: null,
    child_run_id: null,
    started_at: '2026-10-10T10:00:00.000Z',
    ended_at: '2026-10-10T10:00:01.000Z',
    duration_ms: 12,
    ...overrides,
  }
}
