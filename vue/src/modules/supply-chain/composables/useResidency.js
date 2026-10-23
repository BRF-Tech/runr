import { ref } from 'vue'
import { moduleRegistry } from '@/modules/index'
import { supplyChainApi } from '../api'

// The current organization's data residency (GET /api/residency), loaded once per session and
// organization: region pickers grey out the regions it does not allow and preselect its default
// region.
const residency = ref(null)
let pending = null
// The sign-in and organization the cached policy belongs to (an organization switch re-mounts
// the views with a new moduleRegistry.viewKey), and the generation of the cache: an answer for
// a cache that was reset meanwhile is dropped
let scope = null
let generation = 0

function currentScope() {
  return `${localStorage.getItem('token') ?? ''}|${moduleRegistry.viewKey}`
}

export function useResidency() {
  async function load(force = false) {
    // Another sign-in or another organization: the cached policy is not theirs
    if (scope !== currentScope()) reset()
    if (residency.value && !force) return residency.value
    if (pending && !force) return pending
    scope = currentScope()
    const gen = ++generation
    const request = supplyChainApi.residency()
      .then(({ data }) => data?.data ?? null)
      .catch(() => ({ restricted: false, allowed_region_ids: null, default_region_id: null, regions: [] }))
      .then((value) => {
        if (gen === generation) residency.value = value
        return value
      })
      .finally(() => { if (pending === request) pending = null })
    pending = request
    return request
  }

  /** Whether a region may be picked (unrestricted until the policy is known). */
  function isAllowed(regionId) {
    const r = residency.value
    if (!r || !r.restricted || !Array.isArray(r.allowed_region_ids)) return true
    return r.allowed_region_ids.includes(regionId)
  }

  function reset() {
    generation++
    residency.value = null
    pending = null
    scope = null
  }

  return { residency, load, isAllowed, reset }
}
