<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTenantStore } from '../stores/tenant'
import { tenantsApi } from '../api/tenants'

const { t } = useI18n()
const tenantStore = useTenantStore()

const isImpersonating = computed(() => tenantStore.activeTenant?.impersonating === true)
const tenantName      = computed(() => tenantStore.activeTenant?.name ?? '')

async function stopImpersonation() {
  try {
    await tenantsApi.stopImpersonation()
    tenantStore.activeTenant.impersonating = false
    localStorage.removeItem('active_tenant')
    window.location.reload()
  } catch {
    // silent — reload anyway
    window.location.reload()
  }
}
</script>

<template>
  <div
    v-if="isImpersonating"
    class="bg-amber-600 text-white text-center text-xs py-1.5 px-4 font-medium"
  >
    {{ t('tenancy.impersonating', { name: tenantName }) }}
    <button
      class="underline ml-2 hover:text-amber-200 transition-colors"
      @click="stopImpersonation"
    >
      {{ t('tenancy.stopImpersonation') }}
    </button>
  </div>
</template>
