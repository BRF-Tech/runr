// Shared fixtures of the Sandboxes UI specs (not a spec itself)
import { makeI18n } from '@/test-support/helpers'
import en from '@/modules/sandboxes/locales/en.js'

/** vue-i18n with the real English messages plus the sandboxes module's. */
export function sandboxI18n() {
  const i18n = makeI18n()
  i18n.global.mergeLocaleMessage('en', en)
  return i18n
}

const FUTURE = () => new Date(Date.now() + 10 * 60 * 1000).toISOString()
const PAST   = (minutes) => new Date(Date.now() - minutes * 60 * 1000).toISOString()

/** A Sandbox object in the API's shape. */
export function sandbox(id, extra = {}) {
  return {
    id,
    status: 'running',
    region: { id: 'r1', name: 'eu-1' },
    runtime: 'python', runtime_version: '3.14', image: 'ghcr.io/brf-tech/runr/exec-python:3.14',
    sandbox_class: 'gvisor', isolation: 'gvisor',
    resources: { cpu: '500m', cpu_m: 500, memory_mb: 512, disk_mb: 1024, pids_limit: 256 },
    network: { mode: 'none', egress_allow: [] },
    read_only_root: true,
    env_keys: [], metadata: { agent: 'planner' },
    ttl_seconds: 900, idle_timeout_seconds: 600,
    expires_at: FUTURE(), idle_expires_at: FUTURE(), last_activity_at: PAST(1),
    created_at: PAST(5), started_at: PAST(5), ended_at: null, end_reason: null,
    from_pool: false, start_ms: 812, error: null, created_by: '1', tenant_id: null,
    ...extra,
  }
}

/** GET /sandbox-regions rows: r1 with gVisor + Kata, r2 without a sandbox runtime. */
export const regionRows = [
  {
    region: { id: 'r1', name: 'eu-1' }, enabled: true,
    installed_classes: ['kata-fc', 'kata-clh', 'gvisor'], allowed_classes: null,
    effective_classes: ['kata-fc', 'kata-clh', 'gvisor'], default_class: null, effective_default_class: 'kata-fc',
    allow_runc: false, default_ttl_seconds: 900, max_ttl_seconds: 86400,
    max_cpu_m: 4000, max_memory_mb: 8192, max_disk_mb: 10240, can_manage: true,
  },
  {
    region: { id: 'r2', name: 'us-1' }, enabled: true,
    installed_classes: [], allowed_classes: null, effective_classes: [], default_class: null,
    effective_default_class: null, allow_runc: true, default_ttl_seconds: 900, max_ttl_seconds: 3600,
    max_cpu_m: 2000, max_memory_mb: 4096, max_disk_mb: 4096, can_manage: true,
  },
]

export const pythonRuntime = {
  id: 'python', name: 'Python', icon: null, language: 'python', extensions: ['py'], source: 'builtin',
  run_mode: 'http', compiled: false, triggers: ['http', 'schedule', 'queue'], regions: null, is_enabled: true,
  handler: {}, versions: [
    { version: '3.14', is_default: true, eol_at: null },
    { version: '3.12', is_default: false, eol_at: null },
  ],
}

export const nodeRuntime = {
  id: 'nodejs', name: 'Node.js', icon: null, language: 'javascript', extensions: ['js'], source: 'builtin',
  run_mode: 'http', compiled: false, triggers: ['http'], regions: null, is_enabled: true,
  handler: {}, versions: [{ version: '24.14', is_default: true, eol_at: null }],
}

/** Axios-like rejection with a Laravel error body. */
export function apiReject(status, data) {
  const err = new Error(`HTTP ${status}`)
  err.response = { status, data }
  return err
}
