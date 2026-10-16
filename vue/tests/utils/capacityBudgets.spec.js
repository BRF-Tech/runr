import { describe, it, expect } from 'vitest'
import { pct, barWidth, fillColor, budgetRows, sumCapacities, hasLimit } from '@/utils/capacityBudgets'

/** One resource of RegionCapacity::summary() / TenantQuotaService::budgets(). */
function budget(capacity, alwaysOn, factor, faasUsed) {
  const faasBudget = Math.max(0, Math.floor(capacity * factor) - alwaysOn)
  return {
    capacity,
    always_on_used: alwaysOn,
    always_on_available: Math.max(0, capacity - alwaysOn),
    faas_overcommit: factor,
    faas_budget: faasBudget,
    faas_used: faasUsed,
    faas_available: Math.max(0, faasBudget - faasUsed),
  }
}

describe('capacity budgets', () => {
  it('measures a budget without capping it', () => {
    expect(pct(500, 1000)).toBe(50)
    expect(pct(1500, 1000)).toBe(150)
    expect(barWidth(1500, 1000)).toBe(100)
    // nothing left to give: full as soon as something holds it
    expect(pct(0, 0)).toBe(0)
    expect(pct(10, 0)).toBe(100)
  })

  it('colours a budget by how full it is', () => {
    expect(fillColor(50)).toBe('rgb(var(--brand-500))')
    expect(fillColor(70)).toBe('#f97316')
    expect(fillColor(90)).toBe('#ef4444')
  })

  it('lists the resources a capacity carries in display order', () => {
    const rows = budgetRows({ memory_mb: budget(1000, 0, 1.5, 0), cpu_m: budget(1000, 0, 3, 0) })
    expect(rows.map(r => [r.key, r.unit, r.label])).toEqual([['cpu_m', 'm', 'common.cpu'], ['memory_mb', ' MB', 'common.memory']])
    expect(budgetRows(null)).toEqual([])
  })

  it('adds up regions and keeps the factor only when they agree', () => {
    const total = sumCapacities([
      { cpu_m: budget(4000, 1000, 3, 2000), memory_mb: budget(8192, 1024, 1.5, 2048) },
      { cpu_m: budget(2000, 500, 3, 6000), memory_mb: budget(4096, 0, 2, 0) },
      null,
    ])

    expect(total.cpu_m).toEqual({
      capacity: 6000, always_on_used: 1500, always_on_available: 4500,
      faas_budget: 11000 + 5500, faas_used: 8000, faas_available: 9000 + 0, faas_overcommit: 3,
    })
    expect(total.memory_mb.capacity).toBe(12288)
    expect(total.memory_mb.faas_overcommit).toBeNull()
  })

  it('leaves resources without a limit out of a sum that has limits', () => {
    const total = sumCapacities([
      { cpu_m: budget(1000, 350, 3, 700) },
      { cpu_m: budget(0, 200, 3, 100) }, // a quota of 0: no limit
    ])
    expect(total.cpu_m.capacity).toBe(1000)
    expect(total.cpu_m.always_on_used).toBe(350)

    // only unlimited parts: what they hold, without a limit
    const unlimited = sumCapacities([{ cpu_m: budget(0, 200, 3, 100) }])
    expect(unlimited.cpu_m.capacity).toBe(0)
    expect(unlimited.cpu_m.always_on_used + unlimited.cpu_m.faas_used).toBe(300)
    expect(hasLimit(unlimited)).toBe(false)
    expect(hasLimit(total)).toBe(true)
    expect(sumCapacities([])).toBeNull()
  })
})
