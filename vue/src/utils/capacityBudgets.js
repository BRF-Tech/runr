// Capacity budgets (docs/architecture.md → Region capacity and admission): per resource the
// always-on budget (the capacity, no overcommit) and the FaaS overcommit budget (capacity ×
// factor − what the always-on budget holds). The API sends them in the shape of
// RegionCapacity::summary() — a region's (GET /knative-regions/{id}/capacity, the region
// resource's `capacity`) or an organization's quota on a region (`capacity` of a region in
// GET /settings with capacity_scope "quota", TenantQuotaService::budgets()):
//   { cpu_m: { capacity, always_on_used, always_on_available, faas_overcommit, faas_budget,
//              faas_used, faas_available }, memory_mb: { … } }
// A capacity of 0 means no limit for that resource (a quota of 0, or a region not measured yet).

export const BUDGET_RESOURCES = [
  { key: 'cpu_m', unit: 'm', label: 'common.cpu' },
  { key: 'memory_mb', unit: ' MB', label: 'common.memory' },
]

const SUMMED = ['capacity', 'always_on_used', 'always_on_available', 'faas_budget', 'faas_used', 'faas_available']

/** How full a budget is, in percent (not capped: an overcommitted budget shows above 100). */
export function pct(used, total) {
  if (total > 0) return Math.round((used / total) * 100)
  return used > 0 ? 100 : 0
}

/** Width of a budget's bar, in percent of the track. */
export function barWidth(used, total) {
  return Math.min(100, pct(used, total))
}

export function fillColor(percent) {
  if (percent > 80) return '#ef4444'
  if (percent > 60) return '#f97316'
  return 'rgb(var(--brand-500))'
}

export function fillBackground(percent) {
  if (percent > 80) return 'rgba(239,68,68,0.08)'
  if (percent > 60) return 'rgba(249,115,22,0.08)'
  return 'rgb(var(--brand-500) / 0.07)'
}

/** The resources a capacity object carries, in display order, with their unit and label key. */
export function budgetRows(capacity) {
  if (!capacity) return []
  return BUDGET_RESOURCES.filter(r => capacity[r.key]).map(r => ({ ...r, ...capacity[r.key] }))
}

/**
 * Several capacities together (the projects page total over the regions). Resources without a
 * limit (capacity 0) are left out of the sum where others have one; the factor is kept when
 * every summed part has the same one, else null.
 */
export function sumCapacities(capacities) {
  const list = (capacities ?? []).filter(Boolean)
  const total = {}

  for (const { key } of BUDGET_RESOURCES) {
    const parts = list.map(c => c[key]).filter(Boolean)
    if (parts.length === 0) continue

    const limited = parts.filter(p => p.capacity > 0)
    const summed = limited.length > 0 ? limited : parts
    const sum = Object.fromEntries(SUMMED.map(f => [f, summed.reduce((s, p) => s + (Number(p[f]) || 0), 0)]))
    const factors = new Set(summed.map(p => Number(p.faas_overcommit)))
    sum.faas_overcommit = factors.size === 1 ? Number(summed[0].faas_overcommit) : null
    total[key] = sum
  }

  return Object.keys(total).length > 0 ? total : null
}

/** Whether any resource of a capacity object has a limit (something to draw bars for). */
export function hasLimit(capacity) {
  return budgetRows(capacity).some(r => r.capacity > 0)
}
