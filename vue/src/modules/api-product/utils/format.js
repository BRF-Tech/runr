/**
 * Small helpers of the API product pages (no Vue): plan limits as one line, usage counters,
 * the curl example of a route.
 */

const LIMITS = [
  ['rate_per_second', 'perSecond'],
  ['rate_per_minute', 'perMinute'],
  ['quota_per_day', 'perDay'],
  ['quota_per_month', 'perMonth'],
]

/** "10/s (burst 20) · 1,000/day" — `t` translates apiProduct.plans.limit.* */
export function planLimits(plan, t) {
  if (!plan) return ''
  const parts = []
  for (const [field, key] of LIMITS) {
    const value = plan[field]
    if (value == null) continue
    let text = t(`apiProduct.plans.limit.${key}`, { n: Number(value).toLocaleString() })
    if (field === 'rate_per_second' && plan.burst != null && plan.burst !== value) {
      text += ' ' + t('apiProduct.plans.limit.burst', { n: Number(plan.burst).toLocaleString() })
    }
    parts.push(text)
  }
  return parts.length ? parts.join(' · ') : t('apiProduct.plans.limit.none')
}

/** Calls the gateway refused: 401, 403 and both 429s. */
export function refused(row) {
  return (row?.unauthorized ?? 0) + (row?.forbidden ?? 0) + (row?.rate_limited ?? 0) + (row?.quota_exceeded ?? 0)
}

/** A latency in milliseconds ("24.5 ms"), "—" without measured answers. */
export function latency(ms) {
  if (ms == null) return '—'
  const n = Number(ms)
  return `${n >= 100 ? Math.round(n).toLocaleString() : n.toLocaleString(undefined, { maximumFractionDigits: 1 })} ms`
}

/** Share of the function's answers that were no error (5xx or no answer), "—" without any. */
export function successRate(row) {
  const responses = row?.responses ?? 0
  if (!responses) return '—'
  const rate = (100 * (responses - (row.errors ?? 0))) / responses
  return `${rate.toLocaleString(undefined, { maximumFractionDigits: 2 })}%`
}

/** Bytes as B, KB, MB, GB (1024s). */
export function bytes(n) {
  let value = Number(n ?? 0)
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }
  return `${unit === 0 ? value : value.toLocaleString(undefined, { maximumFractionDigits: 1 })} ${units[unit]}`
}

/** The curl call of a route with the way it authenticates. */
export function curlExample(baseUrl, route) {
  const url = `${baseUrl}${route.path}`
  if (route.auth === 'key' || route.auth_mode === 'key') return `curl ${url} -H 'X-Api-Key: rk_…'`
  if (route.auth === 'jwt' || route.auth_mode === 'jwt') return `curl ${url} -H 'Authorization: Bearer <token>'`
  return `curl ${url}`
}

/**
 * The usage API's from / to for a preset range, UTC: last7 / last30 (days up to the end of this
 * hour), thisMonth, lastMonth (calendar months).
 */
export function rangeParams(range, now = new Date()) {
  const to = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate(), now.getUTCHours() + 1))
  const monthStart = (offset) => new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() + offset, 1))
  if (range === 'thisMonth') return { from: monthStart(0).toISOString(), to: to.toISOString() }
  if (range === 'lastMonth') return { from: monthStart(-1).toISOString(), to: monthStart(0).toISOString() }
  const days = range === 'last30' ? 30 : 7
  return { from: new Date(to.getTime() - days * 86400000).toISOString(), to: to.toISOString() }
}
