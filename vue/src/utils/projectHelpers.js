import { BUILTIN_EXTENSIONS, runtimeExtension } from '@/utils/runtimes'

export const RUNTIME_EXT = BUILTIN_EXTENSIONS

// Service types that can be created, edited and deployed. Rows of any other
// type (the hidden AI types of older installs) are legacy: they are listed and
// can be deleted, nothing else.
export const SERVICE_TYPES = ['xaas', 'faas', 'caas']

export function isLegacyServiceType(serviceType) {
  return !!serviceType && !SERVICE_TYPES.includes(serviceType)
}

export function parseCpuMillicores(cpu) {
  if (!cpu) return 0
  const s = String(cpu).trim()
  if (s.endsWith('m')) return parseInt(s) || 0
  return Math.round(parseFloat(s) * 1000) || 0
}

export function runtimeTag(project) {
  if (project.service_type === 'caas') return project.config?.image || null
  const ext = runtimeExtension(project.runtime) ?? project.runtime
  if (!ext) return null
  return project.runtime_version ? `${ext} ${project.runtime_version}` : ext
}

export function endpointUrl(project) {
  return project.endpoint_url ?? null
}
