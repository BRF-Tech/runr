export function fmtCpu(m) {
  if (!m) return '0m'
  return m >= 1000 ? (m / 1000).toFixed(1) + ' core' : m + 'm'
}

export function fmtMem(mb) {
  if (!mb) return '0 MB'
  return mb >= 1024 ? (mb / 1024).toFixed(1) + ' GB' : mb + ' MB'
}

export function statusBadgeClass(status) {
  const base = 'inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium'
  switch (status) {
    case 'ready':         return `${base} bg-emerald-900/40 text-emerald-400 border border-emerald-800/50`
    case 'installing':    return `${base} bg-yellow-900/40 text-yellow-400 border border-yellow-800/50 animate-pulse`
    case 'uninstalling':  return `${base} bg-orange-900/40 text-orange-400 border border-orange-800/50 animate-pulse`
    case 'degraded':      return `${base} bg-orange-900/40 text-orange-400 border border-orange-800/50`
    case 'error':         return `${base} bg-red-900/40 text-red-400 border border-red-800/50`
    case 'not_installed': return `${base} bg-gray-900/40 text-gray-400 border border-gray-800/50`
    default:              return `${base} bg-gray-900/40 text-gray-400 border border-gray-800/50`
  }
}

export function redisStatusBadgeClass(status) {
  const base = 'inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium'
  switch (status) {
    case 'ready':        return `${base} bg-emerald-900/40 text-emerald-400 border border-emerald-800/50`
    case 'installing':   return `${base} bg-yellow-900/40 text-yellow-400 border border-yellow-800/50 animate-pulse`
    case 'uninstalling': return `${base} bg-orange-900/40 text-orange-400 border border-orange-800/50 animate-pulse`
    case 'error':        return `${base} bg-red-900/40 text-red-400 border border-red-800/50`
    default:             return `${base} bg-gray-900/40 text-gray-400 border border-gray-800/50`
  }
}
