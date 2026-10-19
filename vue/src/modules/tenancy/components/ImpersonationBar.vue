<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ShieldAlert } from 'lucide-vue-next'
import { useTenantStore } from '../stores/tenant'

/**
 * Shown while a platform administrator has an organization open as its administrator
 * (impersonation, audit-logged). Stop ends it (audit-logged) and returns to the platform.
 */
const { t } = useI18n()
const tenantStore = useTenantStore()

const isImpersonating = computed(() => tenantStore.isImpersonating)
const tenantName      = computed(() => tenantStore.activeTenant?.name ?? '')
const stopping        = ref(false)

async function stopImpersonation() {
  stopping.value = true
  try {
    await tenantStore.stopImpersonation()
  } finally {
    // A fresh start in the platform context (an organization's page would not exist there)
    const path = window.location.pathname
    if (path.startsWith('/projects/') || path.startsWith('/support')) window.location.assign('/projects')
    else window.location.reload()
  }
}
</script>

<template>
  <div
    v-if="isImpersonating"
    class="bg-amber-600 text-white text-center text-xs py-1.5 px-4 font-medium flex items-center justify-center gap-2"
    role="status"
    data-testid="impersonation-bar"
  >
    <ShieldAlert class="w-3.5 h-3.5 shrink-0" />
    <span>{{ t('tenancy.impersonating', { name: tenantName }) }}</span>
    <button
      class="underline hover:text-amber-200 transition-colors disabled:opacity-60"
      :disabled="stopping"
      @click="stopImpersonation"
    >
      {{ t('tenancy.stopImpersonation') }}
    </button>
  </div>
</template>
