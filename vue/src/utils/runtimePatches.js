// Runtime patches (docs/runtimes.md#runtime-patches): levels, badge and banner presentation.

/** Levels from the least to the most urgent (RuntimePatch::LEVELS). */
export const PATCH_LEVELS = ['routine', 'security', 'critical', 'zero-day']

/** Levels with a deadline: RunR moves the projects still behind when it passes. */
export const DEADLINE_LEVELS = ['critical', 'zero-day']

export function levelRank(level) {
  const i = PATCH_LEVELS.indexOf(level)
  return i < 0 ? 0 : i
}

/** i18n key segment of a level ("zero-day" → "zeroDay"). */
export function levelKey(level) {
  return level === 'zero-day' ? 'zeroDay' : (PATCH_LEVELS.includes(level) ? level : 'routine')
}

/** Badge / chip colours of a level (Tailwind classes, same scale as the EOL badges). */
export function levelClasses(level) {
  switch (level) {
    case 'zero-day': return 'bg-red-900/40 border-red-800/50 text-red-400'
    case 'critical': return 'bg-orange-900/40 border-orange-800/50 text-orange-400'
    case 'security': return 'bg-amber-900/40 border-amber-800/50 text-amber-400'
    default:         return 'bg-gray-800/40 border-gray-700/60 text-gray-400'
  }
}

/** Banner colours of a level. */
export function bannerClasses(level) {
  switch (level) {
    case 'zero-day': return 'bg-red-700 text-white'
    case 'critical': return 'bg-orange-600 text-white'
    case 'security': return 'bg-amber-600 text-white'
    default:         return 'bg-gray-700 text-white'
  }
}

/** The CVE record of an identifier. */
export function cveUrl(id) {
  return `https://www.cve.org/CVERecord?id=${encodeURIComponent(id)}`
}

/** The reason of a patch in the UI locale (the API already localizes `reason` by Accept-Language). */
export function patchReason(patch, locale) {
  if (!patch) return ''
  return patch.reasons?.[locale] || patch.reason || patch.reasons?.en || ''
}

/**
 * What the project badge shows for a project's runtime_patch state (ProjectResource), null for
 * nothing (no patch concerns it, or it is up to date):
 *   { kind: 'overdue' | 'pinned' | 'available', level, tag?, warn?, subtle, classes }
 */
export function patchBadge(state) {
  if (!state || !state.state) return null

  if (state.state === 'overdue') {
    return { kind: 'overdue', level: state.level, subtle: false, classes: levelClasses('zero-day') }
  }
  if (state.state === 'pinned') {
    const warn = state.pin?.available === false
    return {
      kind: 'pinned',
      level: state.level,
      tag: state.pin?.tag || state.pin?.id || state.pin?.digest?.slice(0, 19) || '',
      warn,
      subtle: !warn,
      classes: warn ? levelClasses('security') : 'bg-blue-900/30 border-blue-800/50 text-blue-400',
    }
  }
  if (state.state === 'patch_available') {
    const level = state.level || 'routine'
    return { kind: 'available', level, subtle: level === 'routine', classes: levelClasses(level) }
  }
  return null
}

/** Whether a deadline (ISO) has passed. */
export function deadlinePassed(deadlineAt, now = Date.now()) {
  return !!deadlineAt && new Date(deadlineAt).getTime() <= now
}

/** A router location from a notification's data.path ("/settings?tab=patches"). */
export function pathLocation(path) {
  if (!path || typeof path !== 'string' || !path.startsWith('/')) return null
  const [pathname, search = ''] = path.split('?')
  const query = Object.fromEntries(new URLSearchParams(search))
  return Object.keys(query).length ? { path: pathname, query } : { path: pathname }
}
