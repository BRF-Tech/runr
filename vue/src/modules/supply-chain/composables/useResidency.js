import { ref } from 'vue'
import { supplyChainApi } from '../api'

// The current organization's data residency (GET /api/residency), loaded once per page load:
// region pickers grey out the regions it does not allow and preselect its default region.
const residency = ref(null)
let pending = null

export function useResidency() {
  async function load(force = false) {
    if (residency.value && !force) return residency.value
    if (pending && !force) return pending
    pending = supplyChainApi.residency()
      .then(({ data }) => { residency.value = data?.data ?? null; return residency.value })
      .catch(() => { residency.value = { restricted: false, allowed_region_ids: null, default_region_id: null, regions: [] }; return residency.value })
      .finally(() => { pending = null })
    return pending
  }

  /** Whether a region may be picked (unrestricted until the policy is known). */
  function isAllowed(regionId) {
    const r = residency.value
    if (!r || !r.restricted || !Array.isArray(r.allowed_region_ids)) return true
    return r.allowed_region_ids.includes(regionId)
  }

  function reset() {
    residency.value = null
    pending = null
  }

  return { residency, load, isAllowed, reset }
}
