import { parseCpuMillicores } from '@/utils/projectHelpers'

// Reference instance sizes. They are only presets that fill CPU and memory in
// the project forms: no plan, package or price is attached to them, and any
// other CPU/memory value stays valid. CPU is a Kubernetes quantity, memory MiB.
export const INSTANCE_SIZES = Object.freeze([
  { id: 'nano',   cpu: '125m',  memory_mb: 128 },
  { id: 'micro',  cpu: '250m',  memory_mb: 256 },
  { id: 'small',  cpu: '500m',  memory_mb: 512 },
  { id: 'medium', cpu: '1000m', memory_mb: 1024 },
  { id: 'large',  cpu: '2000m', memory_mb: 4096 },
  { id: 'xlarge', cpu: '4000m', memory_mb: 8192 },
])

// Size a new project starts with, per service type (must match the API).
export const DEFAULT_SIZE_BY_TYPE = Object.freeze({
  xaas: 'micro',
  faas: 'micro',
  caas: 'small',
})

const FALLBACK_SIZE = 'micro'

// Numbers are millicores (what the form CPU field holds); strings are
// Kubernetes quantities, so '1' == '1000m' and '0.5' == '500m'.
export function toMillicores(cpu) {
  if (typeof cpu === 'number') return Number.isFinite(cpu) ? Math.round(cpu) : 0
  return parseCpuMillicores(cpu)
}

// '250m' -> '0.25', '1000m' -> '1'
export function formatVcpu(cpu) {
  return String(toMillicores(cpu) / 1000)
}

export function findInstanceSize(id) {
  return INSTANCE_SIZES.find(s => s.id === id) ?? null
}

// { id, cpu, memory_mb } of the default size for a service type.
export function defaultSizeFor(serviceType) {
  return findInstanceSize(DEFAULT_SIZE_BY_TYPE[serviceType] ?? FALLBACK_SIZE)
}

// Per-pod sidecar and per-project consumer sizes counted by the API
// (api KnativeConstants QUEUE_PROXY_* / QUEUE_CONSUMER_*).
const QUEUE_PROXY    = { cpu_m: 100, memory_mb: 100 }
const QUEUE_CONSUMER = { cpu_m: 50,  memory_mb: 64 }

// CPU (millicores) + memory (MiB) a project reserves — the formula of the API's
// quota admission (ResourceReservation): size × max(1, min_scale) plus the
// queue-proxy per Knative Service pod; queue projects add one queue consumer;
// schedule projects run one pod without queue-proxy. Former AI types count 0.
export function projectReservation(project) {
  const type = project?.service_type
  if (!DEFAULT_SIZE_BY_TYPE[type]) return { cpu_m: 0, memory_mb: 0 }

  const c = project.config ?? {}
  const size = defaultSizeFor(type)
  const cpu = c.cpu ? toMillicores(c.cpu) : toMillicores(size.cpu)
  const mem = c.memory_mb ? Number(c.memory_mb) : size.memory_mb
  const trigger = c.trigger_type || 'http'

  if (trigger === 'schedule') return { cpu_m: cpu, memory_mb: mem }

  const n = Math.max(1, Number(c.min_scale) || 0)
  const out = { cpu_m: (cpu + QUEUE_PROXY.cpu_m) * n, memory_mb: (mem + QUEUE_PROXY.memory_mb) * n }
  if (trigger === 'queue') {
    out.cpu_m += QUEUE_CONSUMER.cpu_m
    out.memory_mb += QUEUE_CONSUMER.memory_mb
  }
  return out
}

// Preset whose CPU and memory both equal the given values, or null (custom).
export function matchInstanceSize(cpu, memoryMb) {
  const m = toMillicores(cpu)
  const mem = Number(memoryMb)
  return INSTANCE_SIZES.find(s => toMillicores(s.cpu) === m && s.memory_mb === mem) ?? null
}
