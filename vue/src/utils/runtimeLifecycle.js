// The lifecycle of runtimes and their versions (docs/runtimes.md#lifecycle) as the console shows
// it. The API (RuntimeResource, ProjectResource runtime_lifecycle, settings executor_images) carries
// the dates; a version ends at the earlier of its end of life and its sunset (ends_at): after it the
// version leaves the pickers and its projects can no longer be saved or deployed. A deprecated
// version stays usable with a badge. An orphaned runtime left its source: no new projects, its
// projects deploy until its sunset.

/** Whether a date (YYYY-MM-DD or ISO) has come. */
export function reached(date, now = new Date()) {
  if (!date) return false
  const d = new Date(date)
  return !Number.isNaN(d.getTime()) && d <= now
}

/** YYYY-MM-DD of a date or ISO string (the API's dates as the console prints them). */
export function day(date) {
  if (!date) return ''
  return String(date).slice(0, 10)
}

/** The date a version ends: ends_at, else the earlier of eol_at and sunset_at (fallback for older payloads). */
export function versionEndsAt(v) {
  if (!v) return null
  if (v.ends_at) return v.ends_at
  const dates = [v.eol_at, v.sunset_at].filter(Boolean).sort((a, b) => day(a).localeCompare(day(b)))
  return dates[0] ?? null
}

/** What ends a version: 'eol' or 'sunset' (the earlier date; eol on a tie), null without a date. */
export function versionEndedBy(v) {
  if (!versionEndsAt(v)) return null
  if (v.sunset_at && (!v.eol_at || day(v.sunset_at) < day(v.eol_at))) return 'sunset'
  return 'eol'
}

/** Whether a version has ended (past its end of life or sunset): it is not offered any more. */
export function versionEnded(v, now = new Date()) {
  return reached(versionEndsAt(v), now) || v?.lifecycle === 'eol' || v?.lifecycle === 'sunset'
}

/** A version's state: active | deprecated | eol | sunset. */
export function versionState(v, now = new Date()) {
  if (!v) return 'active'
  if (reached(versionEndsAt(v), now)) return versionEndedBy(v)
  if (['deprecated', 'eol', 'sunset'].includes(v.lifecycle)) return v.lifecycle
  if (reached(v.deprecated_at, now)) return 'deprecated'
  return 'active'
}

/** The versions a picker offers: those that have not ended. */
export function pickableVersions(versions, now = new Date()) {
  return (versions ?? []).filter(v => !versionEnded(v, now))
}

/** A runtime's state: active | deprecated | sunset | orphaned. */
export function runtimeState(r) {
  return r?.lifecycle?.state ?? 'active'
}

/** Whether new projects may not pick the runtime (it left its source, or its sunset came). */
export function runtimeRetired(r) {
  return ['orphaned', 'sunset'].includes(runtimeState(r))
}

/** Badge colours of a lifecycle state (the EOL badges' scale: yellow warns, red ended). */
export function lifecycleClasses(state) {
  switch (state) {
    case 'eol':
    case 'sunset':     return 'bg-red-900/40 border-red-800/50 text-red-400'
    case 'deprecated':
    case 'orphaned':   return 'bg-yellow-900/40 border-yellow-800/50 text-yellow-400'
    default:           return 'bg-gray-800/40 border-gray-700/60 text-gray-400'
  }
}

/** The name of a runtime id for a replacement hint (the catalog's name when it has one). */
function nameOf(id, names) {
  if (!id) return ''
  return (typeof names === 'function' ? names(id) : names?.[id]) || id
}

/** Short badge text of a runtime's lifecycle (null when active). */
export function runtimeBadgeLabel(r, t) {
  const lc = r?.lifecycle ?? {}
  switch (runtimeState(r)) {
    case 'deprecated': return lc.sunset_at ? t('runtimes.lifecycle.badge.deprecatedUntil', { date: day(lc.sunset_at) }) : t('runtimes.lifecycle.states.deprecated')
    case 'sunset':     return t('runtimes.lifecycle.badge.sunset', { date: day(lc.sunset_at) })
    case 'orphaned':   return lc.sunset_at ? t('runtimes.lifecycle.badge.orphanedUntil', { date: day(lc.sunset_at) }) : t('runtimes.lifecycle.states.orphaned')
    default:           return null
  }
}

/** Tooltip of a runtime's lifecycle: dates, replacement, notice (one line each). */
export function runtimeBadgeTitle(r, t, names = null) {
  const lc = r?.lifecycle ?? {}
  const state = runtimeState(r)
  const lines = []
  if (state === 'orphaned') {
    lines.push(t('runtimes.lifecycle.orphanedSince', { date: day(lc.orphaned_at) }))
    if (lc.sunset_at) lines.push(t('runtimes.lifecycle.deploysUntil', { date: day(lc.sunset_at) }))
  } else if (state === 'sunset') {
    lines.push(t('runtimes.lifecycle.sunsetPassed', { date: day(lc.sunset_at) }))
  } else if (state === 'deprecated') {
    if (lc.deprecated_at) lines.push(t('runtimes.lifecycle.deprecatedSince', { date: day(lc.deprecated_at) }))
    if (lc.sunset_at) lines.push(t('runtimes.lifecycle.sunsetOn', { date: day(lc.sunset_at) }))
  }
  if (lc.replacement) lines.push(t('runtimes.lifecycle.replacement', { name: nameOf(lc.replacement, names) }))
  if (lc.notice) lines.push(lc.notice)
  return lines.join('\n')
}

/** Tooltip of a version chip: end of life, deprecation, sunset. */
export function versionTitle(v, t, now = new Date()) {
  const lines = []
  if (v?.eol_at) lines.push(t(reached(v.eol_at, now) ? 'runtimes.lifecycle.eolPassed' : 'runtimes.list.eolAt', { date: day(v.eol_at) }))
  if (v?.deprecated_at && reached(v.deprecated_at, now)) lines.push(t('runtimes.lifecycle.deprecatedSince', { date: day(v.deprecated_at) }))
  else if (v?.deprecated_at) lines.push(t('runtimes.lifecycle.deprecatedFrom', { date: day(v.deprecated_at) }))
  if (v?.sunset_at) lines.push(t(reached(v.sunset_at, now) ? 'runtimes.lifecycle.sunsetPassed' : 'runtimes.lifecycle.sunsetOn', { date: day(v.sunset_at) }))
  return lines.join('\n')
}

/**
 * The notice of a project's runtime lifecycle (ProjectResource runtime_lifecycle), null when there
 * is nothing to say: { state, level: 'warning' | 'ended', title, body }.
 *   eol / sunset  the version ended: saving and deploying are refused
 *   orphaned      the runtime left its source: deploys stop at its sunset
 *   deprecated    still usable; the sunset date and the replacement when known
 */
export function projectLifecycleNotice(project, t, names = null) {
  const lc = project?.runtime_lifecycle
  if (!lc || !lc.state || lc.state === 'active') return null
  const runtime = [nameOf(project.runtime, names), lc.version].filter(Boolean).join(' ')
  // The whole runtime goes (it left its source, or its sunset): another runtime; else another version
  const runtimeGoes = lc.state === 'orphaned' || lc.runtime_state === 'orphaned' || lc.runtime_state === 'sunset'
  const replacement = lc.replacement
    ? t('runtimes.lifecycle.project.moveTo', { name: nameOf(lc.replacement, names) })
    : t(runtimeGoes ? 'runtimes.lifecycle.project.moveToRuntime' : 'runtimes.lifecycle.project.moveToVersion')
  const notice = lc.notice ? ` ${lc.notice}` : ''

  if (lc.state === 'eol' || lc.state === 'sunset') {
    const key = lc.state === 'sunset' ? 'runtimes.lifecycle.project.sunsetTitle' : 'runtimes.lifecycle.project.eolTitle'
    return {
      state: lc.state, level: 'ended',
      title: t(key, { runtime, date: day(lc.ends_at) }),
      body: `${t('runtimes.lifecycle.project.endedBody')} ${replacement}${notice}`,
    }
  }
  if (lc.state === 'orphaned') {
    const until = lc.ends_at ?? lc.sunset_at
    return {
      state: 'orphaned', level: 'warning',
      title: t('runtimes.lifecycle.project.orphanedTitle', { runtime }),
      body: `${until ? t('runtimes.lifecycle.project.orphanedUntil', { date: day(until) }) : t('runtimes.lifecycle.project.orphanedBody')} ${replacement}${notice}`,
    }
  }
  // deprecated
  const until = lc.ends_at ?? lc.sunset_at
  return {
    state: 'deprecated', level: 'warning',
    title: until
      ? t('runtimes.lifecycle.project.deprecatedUntil', { runtime, date: day(until), by: t(`runtimes.lifecycle.project.by.${lc.ends_by === 'eol' ? 'eol' : 'sunset'}`) })
      : t('runtimes.lifecycle.project.deprecatedTitle', { runtime }),
    body: `${t(until ? 'runtimes.lifecycle.project.deprecatedBodyUntil' : 'runtimes.lifecycle.project.deprecatedBody')} ${replacement}${notice}`,
  }
}

/** Whether a project's runtime version has ended (saving and deploying are refused). */
export function projectRuntimeEnded(project, now = new Date()) {
  const state = project?.runtime_lifecycle?.state
  if (state === 'eol' || state === 'sunset') return true
  return reached(project?.runtime_lifecycle?.ends_at ?? project?.runtime_eol, now)
}

/** What ended a project's runtime version: 'sunset' or 'eol'. */
export function projectEndedBy(project) {
  const lc = project?.runtime_lifecycle
  if (lc?.state === 'sunset' || lc?.ends_by === 'sunset') return 'sunset'
  return 'eol'
}
