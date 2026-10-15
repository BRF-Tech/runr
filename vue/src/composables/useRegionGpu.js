import { ref, computed, watch, unref } from 'vue'
import { aiApi } from '@/api/ai'

/** Cached GPU status per region (GET /knative-regions/{id}/gpu) for the project / deploy forms. */
const cache = new Map()

export function clearRegionGpuCache() {
  cache.clear()
}

/**
 * GPU status of a region: products (type, memory, count) for the GPU fields, totals and
 * the sandbox compatibility per project type. A region without GPU support answers an empty
 * product list; an error leaves `gpu` null.
 */
export function useRegionGpu(regionId) {
  const gpu     = ref(null)
  const loading = ref(false)
  const error   = ref(null)

  async function load({ refresh = false } = {}) {
    const id = unref(regionId)
    if (!id) { gpu.value = null; return }
    if (!refresh && cache.has(id)) { gpu.value = cache.get(id); return }
    loading.value = true
    error.value   = null
    try {
      const { data } = await aiApi.gpu(id, refresh)
      cache.set(id, data)
      if (unref(regionId) === id) gpu.value = data
    } catch (e) {
      error.value = e?.response?.data?.message ?? null
      gpu.value   = null
    } finally {
      loading.value = false
    }
  }

  watch(() => unref(regionId), () => load(), { immediate: true })

  const products  = computed(() => gpu.value?.summary?.products ?? [])
  const total     = computed(() => gpu.value?.summary?.total ?? 0)
  const available = computed(() => gpu.value?.summary?.available ?? null)
  const hasGpus   = computed(() => total.value > 0 || products.value.length > 0)

  /** Sandbox compatibility of a project type on the region ({gpu: ok|nvproxy|unsupported, message}). */
  function sandboxFor(serviceType) {
    return gpu.value?.sandbox?.[serviceType] ?? null
  }

  return { gpu, loading, error, products, total, available, hasGpus, load, sandboxFor }
}
