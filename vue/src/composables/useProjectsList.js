import { ref, computed } from 'vue'
import { useSettingsStore } from '@/stores/settings'
import { projectReservation } from '@/utils/instanceSizes'
import { sumCapacities, hasLimit } from '@/utils/capacityBudgets'

/**
 * Filter state, resource computations, and region helpers for ProjectsView.
 */
export function useProjectsList(projects) {
  const settingsStore = useSettingsStore()

  // ── Filter state ────────────────────────────────────────────────────────────
  const filter          = ref('all')
  const searchQuery     = ref('')
  const regionFilter    = ref([])
  const regionDropOpen  = ref(false)
  const runtimeFilter   = ref([])
  const runtimeDropOpen = ref(false)
  const scalingFilter     = ref([])
  const scalingDropOpen   = ref(false)

  const availableRegions = computed(() => settingsStore.data.regions ?? [])

  const availableRuntimes = computed(() => {
    const seen = new Set(), list = []
    for (const p of projects.value) {
      if (p.service_type === 'caas' || !p.runtime) continue
      const key = `${p.runtime}:${p.runtime_version ?? ''}`
      if (seen.has(key)) continue
      seen.add(key)
      list.push({ key, runtime: p.runtime, version: p.runtime_version ?? '' })
    }
    return list.sort((a, b) => a.runtime.localeCompare(b.runtime) || a.version.localeCompare(b.version))
  })

  const filtered = computed(() => {
    let list = projects.value
    if (searchQuery.value) {
      const q = searchQuery.value.toLowerCase()
      list = list.filter((p) => p.name.toLowerCase().includes(q) || p.slug?.toLowerCase().includes(q))
    }
    if (filter.value !== 'all') {
      list = list.filter((p) => p.service_type === filter.value)
    }
    if (regionFilter.value.length > 0) {
      list = list.filter((p) => regionFilter.value.includes(p.knative_region_id))
    }
    if (runtimeFilter.value.length > 0) {
      list = list.filter((p) => runtimeFilter.value.includes(`${p.runtime}:${p.runtime_version ?? ''}`))
    }
    if (scalingFilter.value.length > 0) {
      list = list.filter((p) => {
        const s2z = p.config?.scale_to_zero !== false
        return (scalingFilter.value.includes('s2z') && s2z) || (scalingFilter.value.includes('custom') && !s2z)
      })
    }
    return list
  })

  function toggleFilter(arr, val) {
    const idx = arr.indexOf(val)
    idx === -1 ? arr.push(val) : arr.splice(idx, 1)
  }

  function closeDropdowns(e) {
    if (!e.target.closest('[data-region-dropdown]')) regionDropOpen.value = false
    if (!e.target.closest('[data-runtime-dropdown]')) runtimeDropOpen.value = false
    if (!e.target.closest('[data-scaling-dropdown]')) scalingDropOpen.value = false
  }

  // ── Resource budgets ────────────────────────────────────────────────────────
  // Each region of GET /settings carries its budgets as the API admits against them (`capacity`:
  // the always-on and the FaaS overcommit budget per resource): the region capacity, or in an
  // organization the organization's quota on a platform region (capacity_scope "quota").
  const isInfinite = r => r.unlimited || r.region_mode === 'infinite'
  // a platform region in an organization (is_synced) reports no cluster status of its own
  const isReachable = r => r.is_synced || r.is_demo || r.cluster_status === 'online'

  const regionBreakdown = computed(() => {
    return (settingsStore.data.regions ?? []).map((r) => {
      if (!isInfinite(r)) return { ...r, reachable: isReachable(r) }
      // no budgets on an unlimited region: what the projects there reserve
      const rp = projects.value.filter((p) => p.knative_region_id === r.id)
      const usedMem = rp.reduce((s, p) => s + projectReservation(p).memory_mb, 0)
      const usedCpu = rp.reduce((s, p) => s + projectReservation(p).cpu_m, 0)
      return { ...r, reachable: isReachable(r), usedMem, usedCpu }
    })
  })

  // Total over the active, reachable regions with budgets (infinite regions have none)
  const capacityTotal = computed(() => sumCapacities(
    regionBreakdown.value
      .filter(r => !isInfinite(r) && r.is_active !== false && r.reachable && hasLimit(r.capacity))
      .map(r => r.capacity),
  ))

  const hasReadyRegion = computed(() =>
    (settingsStore.data.regions ?? []).some(r => r.is_active && (r.is_synced || r.is_demo || r.has_kubeconfig)),
  )

  // ── Region helpers ──────────────────────────────────────────────────────────
  function findRegion(project) {
    if (!project.knative_region_id) return null
    return (settingsStore.data.regions ?? []).find(r => r.id === project.knative_region_id) ?? null
  }

  function regionName(project) { return findRegion(project)?.name ?? null }
  function isDemoRegion(project) { return findRegion(project)?.is_demo ?? false }

  return {
    filter, searchQuery,
    regionFilter, regionDropOpen, availableRegions,
    runtimeFilter, runtimeDropOpen, availableRuntimes,
    scalingFilter, scalingDropOpen,
    filtered, toggleFilter, closeDropdowns,
    capacityTotal, regionBreakdown, hasReadyRegion,
    regionName, isDemoRegion,
    settingsStore,
  }
}
