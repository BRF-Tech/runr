/**
 * Global projects (docs/global-projects.md): one project in 2..8 regions behind one global host
 * name, `<slug>.<zone>`. The primary region is the project's knative_region_id; DNS answers it
 * first (mode `ha`) or the closest healthy region (mode `proximity`). Only regions whose global
 * DNS server is ready take part.
 */

export const GLOBAL_MIN_REGIONS = 2
export const GLOBAL_MAX_REGIONS = 8
export const GLOBAL_MODES = ['ha', 'proximity']

/** Whether a project payload (ProjectResource) is a global project. */
export function isGlobalProject(project) {
  return project?.placement === 'global'
}

/** Whether a region payload can take part in a global project (its global DNS server is ready). */
export function isGlobalRegion(region) {
  return !!region?.global_dns_ready
}

/**
 * Why a region cannot be picked for a global project, null when it can.
 * Returns an i18n key under globalProjects.create.unavailable.
 */
export function globalRegionUnavailableKey(region, { ready = true, allowed = true } = {}) {
  if (!region) return 'globalProjects.create.unavailable.missing'
  if (region.is_demo) return 'globalProjects.create.unavailable.demo'
  if (!ready) return 'globalProjects.create.unavailable.notReady'
  if (!allowed) return 'globalProjects.create.unavailable.residency'
  if (!isGlobalRegion(region)) return 'globalProjects.create.unavailable.noGlobalDns'
  return null
}

/**
 * The region list of a create form after `id` was toggled: added or removed, the primary kept
 * when it is still in the list, else the first region becomes the primary.
 */
export function toggleRegion(regionIds, primaryId, id) {
  const ids = regionIds.includes(id) ? regionIds.filter(r => r !== id) : [...regionIds, id]
  const primary = ids.includes(primaryId) ? primaryId : (ids[0] ?? null)
  return { regionIds: ids, primaryId: primary }
}

/** Whether a create form's global fields are complete: 2..8 regions, the primary among them. */
export function globalSelectionValid(regionIds, primaryId) {
  return Array.isArray(regionIds)
    && regionIds.length >= GLOBAL_MIN_REGIONS
    && regionIds.length <= GLOBAL_MAX_REGIONS
    && !!primaryId
    && regionIds.includes(primaryId)
}

/**
 * The global fields of POST /projects: placement, mode, regions (primary first) and the primary
 * as knative_region_id. Empty for a regional project.
 */
export function globalCreateFields(form) {
  if (form?.placement !== 'global') return {}
  const primary = form.knative_region_id
  const ids = [primary, ...(form.region_ids ?? []).filter(id => id !== primary)].filter(Boolean)
  return {
    placement:         'global',
    global_mode:       GLOBAL_MODES.includes(form.global_mode) ? form.global_mode : 'ha',
    region_ids:        ids,
    knative_region_id: primary || undefined,
  }
}

/**
 * A 422 of POST /projects that belongs to the first wizard step (name, region choice, global
 * fields): the message to show there, null when the error belongs elsewhere.
 */
export function stepOneError(errors) {
  for (const [field, messages] of Object.entries(errors ?? {})) {
    if (field === 'name' || field === 'placement' || field === 'global_mode' || field === 'knative_region_id'
      || field === 'region_ids' || field.startsWith('region_ids.')) {
      return Array.isArray(messages) ? messages[0] : String(messages)
    }
  }
  return null
}

const BADGE = 'inline-flex items-center px-2 py-0.5 rounded-sm text-[10px] font-medium border'

/**
 * Tailwind classes of a region copy's status badge (pending, building, deploying, ready, failed,
 * removing, fenced — the region lost the primary in a failover and waits to rejoin).
 */
export function regionStatusClass(status) {
  switch (status) {
    case 'ready':     return `${BADGE} bg-emerald-900/40 text-emerald-400 border-emerald-800/50`
    case 'building':
    case 'deploying': return `${BADGE} bg-blue-900/40 text-blue-300 border-blue-800/50`
    case 'failed':    return `${BADGE} bg-red-900/40 text-red-400 border-red-800/50`
    case 'fenced':    return `${BADGE} bg-orange-900/40 text-orange-300 border-orange-800/50`
    case 'removing':  return `${BADGE} bg-yellow-900/40 text-yellow-400 border-yellow-800/50`
    default:          return `${BADGE} bg-gray-800 text-gray-400 border-gray-700`
  }
}

/**
 * Tailwind classes of a state of the project's data in a region (data_status: ready, syncing,
 * failed) or of a binding's replica / data link there (pending, provisioning, ready, promoting,
 * recloning, failed, removing).
 */
export function dataStatusClass(status) {
  switch (status) {
    case 'ready':        return `${BADGE} bg-emerald-900/40 text-emerald-400 border-emerald-800/50`
    case 'syncing':
    case 'pending':
    case 'provisioning': return `${BADGE} bg-blue-900/40 text-blue-300 border-blue-800/50`
    case 'promoting':
    case 'recloning':    return `${BADGE} bg-orange-900/40 text-orange-300 border-orange-800/50`
    case 'failed':       return `${BADGE} bg-red-900/40 text-red-400 border-red-800/50`
    case 'removing':     return `${BADGE} bg-yellow-900/40 text-yellow-400 border-yellow-800/50`
    default:             return `${BADGE} bg-gray-800 text-gray-400 border-gray-700`
  }
}

/** The failover settings of a global project. */
export const GLOBAL_FAILOVERS = ['automatic', 'manual']

/**
 * Whether the primary can fail over to region `r` now: a secondary whose copy serves (ready), no
 * move of the primary running.
 */
export function canFailoverTo(global, r) {
  return !!global && !global.moving_to && r?.role !== 'primary' && r?.status === 'ready'
}

/** A replica's replay lag (seconds) as a short text: 0 s, 42 s, 3 min, 2 h; null when unknown. */
export function formatLag(seconds) {
  if (seconds === null || seconds === undefined || Number.isNaN(Number(seconds))) return null
  const s = Math.max(0, Math.round(Number(seconds)))
  if (s < 120) return `${s} s`
  if (s < 7200) return `${Math.round(s / 60)} min`
  return `${Math.round(s / 3600)} h`
}

/** Where a region's PowerDNS server gets its GeoIP database (docs/global-projects.md). */
export const GEOIP_SOURCES = ['default', 'url', 'file', 'none']

/** The command that puts the operator's GeoIP file on the server's volume (source `file`). */
export const GEOIP_COPY_COMMAND = 'kubectl -n runr-global-dns cp geoip.mmdb <pod>:/var/lib/powerdns/geoip.mmdb -c pdns'

/** Dot + text colour of a server's GeoIP state (loaded, missing, off, unknown). */
export function geoipStatusColors(status) {
  switch (status) {
    case 'loaded':  return { dot: 'bg-emerald-400', text: 'text-emerald-400' }
    case 'missing': return { dot: 'bg-red-400', text: 'text-red-400' }
    case 'off':     return { dot: 'bg-gray-500', text: 'text-gray-400' }
    default:        return { dot: 'bg-yellow-400', text: 'text-yellow-400' }
  }
}

/** Dot + text colour of a global DNS server status (disabled, installing, ready, error, uninstalling). */
export function serverStatusColors(status) {
  switch (status) {
    case 'ready':        return { dot: 'bg-emerald-400', text: 'text-emerald-400' }
    case 'installing':
    case 'uninstalling': return { dot: 'bg-yellow-400 animate-pulse', text: 'text-yellow-400' }
    case 'error':        return { dot: 'bg-red-400', text: 'text-red-400' }
    default:             return { dot: 'bg-gray-500', text: 'text-gray-400' }
  }
}

/** Text colour of a delegation / sync / TLS state. */
export function stateTextClass(state) {
  switch (state) {
    case 'ok':
    case 'in_sync':
    case 'ready':   return 'text-emerald-400'
    case 'partial':
    case 'drift':
    case 'pending': return 'text-yellow-400'
    case 'missing':
    case 'error':   return 'text-red-400'
    default:        return 'text-gray-400'
  }
}

/** Whether a server is busy (installing / uninstalling): the card polls until it settles. */
export function serverBusy(server) {
  return server?.status === 'installing' || server?.status === 'uninstalling'
}

/**
 * Whether a global project is still changing — a copy rolls out, the primary moves, a region's data
 * syncs, a replica is promoted or rebuilt (the tab polls meanwhile).
 */
export function rolloutBusy(global) {
  if (global?.moving_to) return true
  if ((global?.regions ?? []).some(r => ['pending', 'building', 'deploying', 'removing'].includes(r.status) || r.data_status === 'syncing')) return true
  return (global?.bindings ?? []).some(b => (b.regions ?? []).some(r => ['pending', 'provisioning', 'promoting', 'recloning', 'removing'].includes(r.status)))
}

/** The response body of a global endpoint: the object itself or wrapped in `data`. */
export function unwrap(data) {
  return data && typeof data === 'object' && data.data && !Array.isArray(data.data) ? data.data : data
}

/** The first message of an API error: a field error, the message, else `fallback`. */
export function apiErrorMessage(e, fallback) {
  const body = e?.response?.data ?? {}
  return Object.values(body.errors ?? {}).flat()[0] ?? body.message ?? body.reason ?? fallback
}
