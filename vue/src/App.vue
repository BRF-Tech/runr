<script setup>
import { onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { moduleRegistry } from '@/modules/index'
import { useRealtimeNotifications } from '@/composables/useRealtimeNotifications'
import { fetchVersion } from '@/api/version'
import ToastContainer from '@/components/ToastContainer.vue'
import ConfirmContainer from '@/components/ConfirmContainer.vue'
import MigrationWarningBar from '@/components/MigrationWarningBar.vue'
import RuntimePatchBanner from '@/components/RuntimePatchBanner.vue'
import { useBrandingStore } from '@/modules/white-label/stores/branding'
import { useAuthStore } from '@/stores/auth'

// The broadcast channels are private (authorized with the session token): join them once
// signed in (also after an in-app login), leave them on sign-out
const auth = useAuthStore()
const { subscribe, unsubscribe } = useRealtimeNotifications()
watch(() => auth.token, (token) => (token ? subscribe() : unsubscribe()))

onMounted(() => {
  subscribe()
  fetchVersion().then(v => {
    if (v) console.log(`%c RunR %c ${v} `, 'background:#f97316;color:#fff;font-weight:bold;border-radius:3px 0 0 3px', 'background:#1e293b;color:#94a3b8;border-radius:0 3px 3px 0')
  })
})

const { locale } = useI18n()
// Title from the white-label branding (product name)
const branding = useBrandingStore()

function syncLocale() {
  document.title = branding.productName
  document.documentElement.lang = locale.value === 'tr' ? 'tr' : 'en'
}

syncLocale()
watch([locale, () => branding.productName], syncLocale)

// One view instance per page: the same view with other route params (another project, opened
// from a project page by a clone, a notification or a link) mounts afresh and loads that page,
// instead of keeping the previous page's data and requests. viewKey: a tenant switch.
function viewKey(route) {
  return `${moduleRegistry.viewKey}:${JSON.stringify(route.params)}`
}
</script>

<template>
  <component v-for="(comp, i) in moduleRegistry.appTopSlots" :key="'top-'+i" :is="comp" />
  <MigrationWarningBar />
  <!-- Zero-day / critical runtime patches, RunR releases, patch feed trouble (until resolved) -->
  <RuntimePatchBanner />
  <RouterView v-slot="{ Component, route }">
    <component :is="Component" :key="viewKey(route)" />
  </RouterView>
  <ToastContainer />
  <ConfirmContainer />
</template>
