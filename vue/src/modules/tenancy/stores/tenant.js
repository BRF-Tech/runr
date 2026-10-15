import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import client from '@/api/client'

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

  function setFromLogin(data) {
    if (data.tenants) tenants.value = data.tenants
    if (data.active_tenant) {
      activeTenant.value = data.active_tenant
      localStorage.setItem('active_tenant', JSON.stringify(data.active_tenant))
      trackRecent(data.active_tenant.id)
    } else {
      activeTenant.value = null
      localStorage.removeItem('active_tenant')
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
        activeTenant.value = { ...data.tenant, role: data.role }
        localStorage.setItem('active_tenant', JSON.stringify(activeTenant.value))
      }
      loaded.value = true
    } catch { /* tenancy may be disabled */ }
  }

  async function switchTenant(tenantId) {
    if (!tenantId) {
      activeTenant.value = null
      localStorage.removeItem('active_tenant')
      return true
    }
    // Verify tenant exists and user has access via backend
    const t = tenants.value.find(t => t.id === tenantId)
    if (!t?.slug) return false
    try {
      await client.get(`/tenant/check/${t.slug}`)
      activeTenant.value = { ...t }
      localStorage.setItem('active_tenant', JSON.stringify(activeTenant.value))
      trackRecent(tenantId)
      return true
    } catch {
      return false
    }
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
    hasTenants, activeTenantId, activeRole, isCentralMode, isOwnerOrAdmin,
    recentTenants, recentIds,
    setFromLogin, restoreFromStorage, fetchTenants, switchTenant, clear,
    trackRecent,
  }
})
