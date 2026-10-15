import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { brandingApi } from '../api/whiteLabel'
import {
  DEFAULT_BRANDING, applyBranding, normalizeBranding, readCachedBranding, writeCachedBranding,
} from '../utils/branding'

export { DEFAULT_BRANDING, applyBranding, normalizeBranding, activeTenantSlug } from '../utils/branding'

/**
 * Branding of the console (GET /api/branding): the platform branding, or a tenant's override
 * when loaded with its slug (tenant sign-in, active tenant). Every load is written to the
 * document (CSS variables, title, favicon) and cached in localStorage for the next page load.
 */
export const useBrandingStore = defineStore('branding', () => {
  const data       = ref(readCachedBranding() || normalizeBranding(DEFAULT_BRANDING))
  const loaded     = ref(false)
  const tenantSlug = ref(null)
  let pending      = null
  let seq          = 0

  const productName   = computed(() => data.value.product_name)
  const companyName   = computed(() => data.value.company_name)
  const features      = computed(() => data.value.features)
  /** RunR's own brand (product name "RunR"): RunR wordmark, "RunR by <company>" footer. */
  const isDefaultName = computed(() => data.value.is_default_brand)
  const hasCustomLogo = computed(() => !!(data.value.logo_light_url || data.value.logo_dark_url))
  /** Square icon: the branding's mark, null → the bundled RunR icon is used. */
  const markUrl       = computed(() => data.value.logo_mark_url)

  /**
   * Custom logo for a theme (light theme → light logo, dark themes / dark surfaces → dark
   * logo; each falls back to the other), null when the branding has none.
   */
  function logoFor(theme) {
    return theme === 'light'
      ? (data.value.logo_light_url || data.value.logo_dark_url)
      : (data.value.logo_dark_url || data.value.logo_light_url)
  }

  function set(raw) {
    data.value = normalizeBranding(raw)
    apply()
    writeCachedBranding(data.value)
  }

  function apply() {
    applyBranding(data.value)
  }

  /** GET /api/branding (with the tenant override when a slug is given). */
  async function load(slug = null) {
    const key = slug || null
    if (pending && tenantSlug.value === key) return pending
    tenantSlug.value = key
    const id = ++seq
    const request = brandingApi.get(key)
      .then(({ data: d }) => {
        // a newer load (other tenant) wins
        if (id === seq) { set(d); loaded.value = true }
        return data.value
      })
      .catch(() => { if (id === seq) apply(); return data.value })
      .finally(() => { if (pending === request) pending = null })
    pending = request
    return request
  }

  return { data, loaded, tenantSlug, productName, companyName, features, isDefaultName, hasCustomLogo, markUrl, logoFor, set, apply, load }
})
