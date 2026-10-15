import { ref, readonly } from 'vue'
import i18n from '@/i18n'
import { tenantsApi } from '../api/tenants'

const activeTenantDetail = ref(null)
const users              = ref([])
const regions            = ref([])
const selectedTab        = ref('users')
const loading            = ref(false)
const error              = ref(null)

export function useTenantDetail() {
  async function loadTenant(id) {
    loading.value = true
    error.value   = null
    try {
      const [tenantRes, usersRes, regionsRes] = await Promise.all([
        tenantsApi.show(id),
        tenantsApi.listUsers(id),
        tenantsApi.listRegions(id),
      ])
      activeTenantDetail.value = tenantRes.data
      users.value              = usersRes.data
      regions.value            = regionsRes.data
    } catch (e) {
      error.value = e.response?.data?.message ?? i18n.global.t('common.error')
    } finally {
      loading.value = false
    }
  }

  async function refreshUsers(id) {
    try {
      const { data } = await tenantsApi.listUsers(id)
      users.value = data
    } catch { /* silent */ }
  }

  async function refreshRegions(id) {
    try {
      const { data } = await tenantsApi.listRegions(id)
      regions.value = data
    } catch { /* silent */ }
  }

  function reset() {
    activeTenantDetail.value = null
    users.value              = []
    regions.value            = []
    selectedTab.value        = 'users'
    error.value              = null
  }

  return {
    activeTenantDetail: readonly(activeTenantDetail),
    users,
    regions,
    selectedTab,
    loading:  readonly(loading),
    error:    readonly(error),
    loadTenant,
    refreshUsers,
    refreshRegions,
    reset,
  }
}
