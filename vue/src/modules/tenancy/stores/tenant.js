import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import client from '@/api/client'
import { tenantsApi } from '../api/tenants'

const RECENT_KEY = 'recent_tenants'
const MAX_RECENT = 5

export const useTenantStore = defineStore('tenant', () => {
  const tenants      = ref([])
  const activeTenant = ref(null)
  const loaded       = ref(false)

  const hasTenants     = computed(() => tenants.value.length > 0)
  const activeTenantId = computed(() => activeTenant.value?.id ?? null)
  const activeRole     = computed(() => activeTenant.value?.role ?? null)
  const isCentralMode  = computed(() => activeTenant.value === null)
  const isOwnerOrAdmin = computed(() => ['owner', 'admin'].includes(activeRole.value))
  // A platform administrator in an organization they are no member of (opened as its administrator)
  const isImpersonating = computed(() => activeTenant.value?.impersonating === true)

  // Recent tenant IDs (most recent first)
  const recentIds = ref(JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'))

  const recentTenants = computed(() => {
    const ids = recentIds.value.filter(id => id !== activeTenantId.value)
    return ids
      .map(id => tenants.value.find(t => t.id === id))
      .filter(Boolean)
      .slice(0, MAX_RECENT)
  })

  function trackRecent(tenantId) {
    if (!tenantId) return
    const ids = [tenantId, ...recentIds.value.filter(id => id !== tenantId)].slice(0, 20)
    recentIds.value = ids
    localStorage.setItem(RECENT_KEY, JSON.stringify(ids))
  }

  function setActive(tenant) {
    activeTenant.value = tenant
    if (tenant) localStorage.setItem('active_tenant', JSON.stringify(tenant))
    else localStorage.removeItem('active_tenant')
  }

  function setFromLogin(data) {
    if (data.tenants) tenants.value = data.tenants
    if (data.active_tenant) {
      setActive(data.active_tenant)
      trackRecent(data.active_tenant.id)
    } else {
      setActive(null)
    }
    loaded.value = true
  }

  function restoreFromStorage() {
    const saved = localStorage.getItem('active_tenant')
    if (saved) {
      try { activeTenant.value = JSON.parse(saved) } catch { /* ignore */ }
    }
  }

  async function fetchTenants() {
    try {
      const { data } = await client.get('/tenant/current')
      if (data.tenants) tenants.value = data.tenants
      if (data.tenant) {
        setActive({ ...data.tenant, role: data.role, impersonating: data.impersonating === true })
      }
      loaded.value = true
    } catch { /* tenancy may be disabled */ }
  }

  // The tenants list of a sign-in has no membership flag: read it from /tenant/current first
  async function findTenant(tenantId) {
    let found = tenants.value.find(t => t.id === tenantId)
    if (!found || found.member === undefined) {
      await fetchTenants()
      found = tenants.value.find(t => t.id === tenantId) ?? found
    }
    return found
  }

  // Leaving an organization opened as administrator: audit-logged, best effort
  async function endImpersonation(tenant) {
    if (!tenant?.impersonating) return
    try { await tenantsApi.stopImpersonation(tenant.id) } catch { /* the console leaves anyway */ }
  }

  async function switchTenant(tenantId) {
    const previous = activeTenant.value
    if (!tenantId) {
      await endImpersonation(previous)
      setActive(null)
      return true
    }
    // Verify tenant exists and user has access via backend
    const t = await findTenant(tenantId)
    if (!t?.slug) return false
    try {
      await client.get(`/tenant/check/${t.slug}`)
      if (previous?.id !== tenantId) await endImpersonation(previous)
      // A platform administrator opening an organization they are no member of: impersonation
      let impersonating = false
      if (t.member === false) {
        const { data } = await tenantsApi.impersonate(tenantId)
        impersonating = data?.tenant?.impersonating !== false
      }
      setActive({ ...t, impersonating })
      trackRecent(tenantId)
      return true
    } catch {
      return false
    }
  }

  // The impersonation bar's "Stop": back to the platform (central mode)
  async function stopImpersonation() {
    await endImpersonation(activeTenant.value)
    setActive(null)
  }

  function clear() {
    tenants.value      = []
    activeTenant.value = null
    loaded.value       = false
    localStorage.removeItem('active_tenant')
  }

  // Auto-restore on store creation
  restoreFromStorage()

  return {
    tenants, activeTenant, loaded,
    hasTenants, activeTenantId, activeRole, isCentralMode, isOwnerOrAdmin, isImpersonating,
    recentTenants, recentIds,
    setFromLogin, restoreFromStorage, fetchTenants, switchTenant, stopImpersonation, clear,
    trackRecent,
  }
})
