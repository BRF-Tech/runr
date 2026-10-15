// Shared helpers of the Vitest component / unit specs (not a spec itself).
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import en from '@/locales/en'

/** vue-i18n in composition mode with the real English messages. */
export function makeI18n() {
  return createI18n({
    legacy:         false,
    locale:         'en',
    fallbackLocale: 'en',
    messages:       { en },
    missingWarn:    false,
    fallbackWarn:   false,
  })
}

/** A fresh, active Pinia (real stores; the API modules are mocked by each spec). */
export function freshPinia() {
  const pinia = createPinia()
  setActivePinia(pinia)
  return pinia
}

/** Axios-like rejection of an API call. */
export function apiError(status, data = {}) {
  const err = new Error(`HTTP ${status}`)
  err.response = { status, data }
  return err
}

export const Q_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciLz4='

/** Runtime catalog entries (GET /runtimes shape). */
export const catalog = {
  python: {
    id: 'python', name: 'Python', icon: null, language: 'python', extensions: ['py'], source: 'builtin',
    run_mode: 'http', compiled: false, triggers: ['http', 'schedule', 'queue'], regions: null, is_enabled: true,
    handler: { file: 'main', function: 'handler', filename: 'main.py', pattern: 'def\\s+handler\\s*\\(' },
    dependency_file: 'requirements.txt', projects_count: 3,
    versions: [
      { version: '3.14', is_default: true, eol_at: null, gpu_required: false, image: 'ghcr.io/runrsh/exec-python:3.14', built: false },
      { version: '3.12', is_default: false, eol_at: null, gpu_required: false, image: 'ghcr.io/runrsh/exec-python:3.12', built: false },
      { version: '3.9', is_default: false, eol_at: '2025-10-07', gpu_required: false, image: 'ghcr.io/runrsh/exec-python:3.9', built: false },
    ],
  },
  nodejs: {
    id: 'nodejs', name: 'Node.js', icon: null, language: 'javascript', extensions: ['js'], source: 'builtin',
    run_mode: 'http', compiled: false, triggers: ['http', 'schedule', 'queue'], regions: null, is_enabled: true,
    handler: { file: 'main', function: 'handler', filename: 'main.js', pattern: '(?:exports\\.handler|function\\s+handler)' },
    projects_count: 0,
    versions: [{ version: '24.14', is_default: true, eol_at: null, gpu_required: false, image: 'ghcr.io/runrsh/exec-nodejs:24.14', built: false }],
  },
  c: {
    id: 'c', name: 'C', icon: null, language: 'c', extensions: ['c', 'h'], source: 'platform',
    run_mode: 'exec', compiled: true, triggers: ['http', 'schedule', 'queue'], regions: ['r1'], is_enabled: true,
    handler: { file: 'main', function: 'handler', filename: 'main.c', pattern: null }, projects_count: 1,
    versions: [{ version: '17', is_default: true, eol_at: null, gpu_required: false, image: 'ghcr.io/runrsh/reqs/alpine:3.21', built: false }],
  },
  q: {
    id: 'q', name: 'Q', description: 'Tiny toy language', icon: Q_ICON, language: 'lua', extensions: ['q'], source: 'custom',
    run_mode: 'persistent', compiled: false, triggers: ['http', 'queue'], regions: null, is_enabled: true,
    handler: { file: 'main', function: 'handler', filename: 'main.q', pattern: null }, projects_count: 0,
    versions: [{ version: '1.0', is_default: true, eol_at: null, gpu_required: false, image: null, built: true }],
  },
  rust: {
    id: 'rust', name: 'Rust', icon: null, language: 'rust', extensions: ['rs'], source: 'platform',
    run_mode: 'http', compiled: true, triggers: ['http', 'schedule', 'queue'], regions: ['r2'], is_enabled: true,
    handler: { file: 'main', function: 'handler', filename: 'main.rs', pattern: null }, projects_count: 0,
    versions: [{ version: '1.82', is_default: true, eol_at: null, gpu_required: false, image: 'docker.io/library/rust:1.82-alpine', built: false }],
  },
}

/** Regions of the settings payload: r1 ready, r2 ready, r3 without a cluster. */
export const regions = [
  { id: 'r1', name: 'eu-1', is_active: true, is_synced: true, has_serving: true, has_redis: true, total_cpu_m: 4000, total_memory_mb: 8192 },
  { id: 'r2', name: 'us-1', is_active: true, is_synced: true, has_serving: true, has_redis: false, total_cpu_m: 4000, total_memory_mb: 8192 },
  { id: 'r3', name: 'lab', is_active: false, is_synced: false, has_kubeconfig: false, total_cpu_m: 0, total_memory_mb: 0 },
]

// ── AI (GPU support, model services) ────────────────────────────────────────────────

/** GPU status of a region (GET /knative-regions/{id}/gpu): one RTX 4050 node, device plugin ready. */
export function gpuStatus(extra = {}) {
  return {
    mode: 'device-plugin',
    status: 'ready',
    status_message: '[10:00:00] NVIDIA device plugin ready\n',
    settings: { time_slicing_replicas: null, runtime_class: 'nvidia', gvisor_nvproxy: false, operator_driver: true, operator_toolkit: true },
    summary: {
      gpu_nodes: 1, total: 1, allocatable: 1, allocated: 0, available: 1,
      products: [{ product: 'NVIDIA-GeForce-RTX-4050-Laptop-GPU', memory_mb: 6141, count: 1, allocatable: 1 }],
      hardware_without_plugin: [],
    },
    nodes: [{
      name: 'gpu-1', ready: true, product: 'NVIDIA-GeForce-RTX-4050-Laptop-GPU', memory_mb: 6141, count: 1, allocatable: 1,
      allocated: 0, replicas: 1, compute_capability: '8.9', driver_version: '550.54.15', cuda_version: '12.4',
      has_device_plugin: true, hardware_detected: true, taints: [],
    }],
    sandbox: {
      xaas: { runtime_class: null, gpu: 'ok', message: null },
      faas: { runtime_class: 'gvisor', gpu: 'nvproxy', message: null },
      caas: { runtime_class: 'kata-clh', gpu: 'unsupported', message: 'Kata VMs get no GPU passthrough: map CAAS projects to runc or gVisor.' },
    },
    detected_at: '2026-10-10T10:00:00Z',
    ...extra,
  }
}

/** A model service (GET /model-services item). */
export function modelService(extra = {}) {
  return {
    id: 'ms1', name: 'minilm', service_name: 'm-minilm', ai_model_id: 'am1',
    model: { id: 'am1', name: 'all-minilm-l6-v2', hub_id: 'sentence-transformers/all-MiniLM-L6-v2', task: 'feature-extraction', library: 'sentence-transformers', size_bytes: 90_900_000 },
    knative_region_id: 'r1', region_name: 'eu-1', engine: 'tei', device: 'cpu', mode: 'online', api: 'tei',
    gpu: null, cpu: '1', memory_mb: 1024, min_scale: 0, max_scale: 1, scale_to_zero: true, timeout_seconds: 300,
    image: 'ghcr.io/huggingface/text-embeddings-inference:cpu-1.5', args: [], env_vars: {}, port: 8080, health_path: '/health',
    served_name: 'minilm', status: 'ready', status_message: null, url: 'http://m-minilm.fn.svc.cluster.local',
    playground: { kind: 'embeddings', path: '/v1/embeddings' }, cold_start: { size_bytes: 90_900_000, suggest_min_scale: false },
    bindings_count: 0, deployed_at: '2026-10-10T10:00:00Z', created_at: '2026-10-10T09:59:00Z',
    ...extra,
  }
}

/** Hub model card (GET /models/hub/info). */
export function hubInfo(extra = {}) {
  return {
    id: 'sentence-transformers/all-MiniLM-L6-v2', sha: '8b3219a92973c328a8e22fadcfa821b5dc75636a',
    task: 'feature-extraction', library: 'sentence-transformers', license: 'apache-2.0', gated: false, private: false,
    downloads: 98_000_000, likes: 3200, tags: ['sentence-transformers', 'pytorch'], size_bytes: 90_900_000, parameters: 22_700_000,
    files: [{ name: 'model.safetensors', size: 90_868_376 }, { name: 'config.json', size: 612 }],
    card_summary: 'This is a sentence-transformers model: it maps sentences to a 384 dimensional vector space.',
    engines: [
      { engine: 'tei', devices: ['cpu', 'gpu'], recommended: true, api: 'tei' },
      { engine: 'transformers', devices: ['cpu', 'gpu'], recommended: false, api: 'runr' },
    ],
    suggested: { engine: 'tei', device: 'cpu', cpu: '1', memory_mb: 1024, gpu: null, suggest_min_scale: false },
    warnings: [],
    ...extra,
  }
}
