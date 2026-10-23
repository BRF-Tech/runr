// Shared texts of a rightsizing recommendation (GET /rightsizing, /projects/{id}/rightsizing):
// the card on a project's page and the organization's list use the same wording.

/** An amount in the installation's currency ("$4.20"); the plain number when the currency is unknown. */
export function money(amount, currency, locale = 'en') {
  const value = Math.abs(Number(amount) || 0)
  try {
    return new Intl.NumberFormat(locale === 'tr' ? 'tr-TR' : 'en-US', { style: 'currency', currency: currency || 'USD' }).format(value)
  } catch {
    return `${value.toFixed(2)} ${currency || ''}`.trim()
  }
}

/** "256 MB / 125m" */
export function sizeText(size) {
  if (!size) return '—'
  return `${size.memory_mb} MB / ${size.cpu_m}m`
}

/** "256 MB, 375m" of what a change frees (absolute values; the caller says frees or takes). */
export function resourcesText(cpuM, memoryMb) {
  const parts = []
  if (memoryMb) parts.push(`${Math.abs(memoryMb)} MB`)
  if (cpuM) parts.push(`${Math.abs(cpuM)}m CPU`)
  return parts.join(', ')
}

/** The reason labels of a recommendation, joined. */
export function reasonsText(t, reasons) {
  return (reasons ?? []).map(r => t(`rightsizing.reasons.${r}`)).join(', ')
}

/**
 * The money line of a recommendation, null without billing:
 *  reserved  "about $4.20 less per month" (negative: more)
 *  usage     "billed by use: frees capacity, not money"
 *  fixed     "the allocation could shrink by 768 MB, 1125m CPU (about $30.38 per month)"
 */
export function savingsText(t, recommendation, locale = 'en') {
  const s = recommendation?.savings
  if (!s || s.monthly === null || s.monthly === undefined) return null
  const amount = money(s.monthly, s.currency, locale)
  if (s.basis === 'usage') return t('rightsizing.usageBasis')
  if (s.basis === 'fixed') {
    const freed = s.freed_allocation ?? {}
    const resources = resourcesText(freed.cpu_m, freed.memory_mb)
    return Number(s.monthly) < 0
      ? t('rightsizing.fixedGrow', { resources, amount })
      : t('rightsizing.fixedBasis', { resources, amount })
  }
  return Number(s.monthly) < 0 ? t('rightsizing.cost', { amount }) : t('rightsizing.saving', { amount })
}

/** "Frees 256 MB, 375m CPU" / "Takes 512 MB more", null when nothing changes. */
export function freedText(t, recommendation) {
  const f = recommendation?.freed
  if (!f || (!f.memory_mb && !f.cpu_m)) return null
  const frees = (f.memory_mb ?? 0) >= 0 && (f.cpu_m ?? 0) >= 0
  const resources = resourcesText(f.cpu_m, f.memory_mb)
  const text = frees ? t('rightsizing.frees', { resources }) : t('rightsizing.takes', { resources })
  return f.always_on && f.pods > 1 ? `${text} ${t('rightsizing.perPods', { pods: f.pods })}` : text
}

/** The sentences of what changes and why: memory first, then CPU. */
export function changeLines(t, r) {
  if (!r) return []
  const days = r.evidence?.window_days ?? 14
  const why = (keys) => (r.reasons ?? []).filter(k => keys.includes(k)).map(k => t(`rightsizing.reasons.${k}`)).join(', ')
  const lines = []
  if (r.changes?.memory === 'down') {
    lines.push(t('rightsizing.memoryDown', { current: r.current.memory_mb, used: Math.round(r.evidence?.memory_max_mb ?? 0), days, recommended: r.recommended.memory_mb }))
  } else if (r.changes?.memory === 'up') {
    lines.push(t('rightsizing.memoryUp', { current: r.current.memory_mb, why: why(['memory_oom_killed', 'memory_near_limit']), recommended: r.recommended.memory_mb }))
  }
  if (r.changes?.cpu === 'down') {
    lines.push(t('rightsizing.cpuDown', { current: r.current.cpu_m, used: r.evidence?.cpu_p95_m ?? 0, days, recommended: r.recommended.cpu_m }))
  } else if (r.changes?.cpu === 'up') {
    lines.push(t('rightsizing.cpuUp', { current: r.current.cpu_m, why: why(['cpu_throttled']), recommended: r.recommended.cpu_m }))
  }
  return lines
}
