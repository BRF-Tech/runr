<script setup>
// Keeps the console branded: loads GET /api/branding for the platform or the active tenant on
// start, after a tenant switch (RouterView re-mount via viewKey) and when the user signs out.
import { onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { moduleRegistry } from '@/modules/index'
import { useBrandingStore, activeTenantSlug } from '../stores/branding'

const branding = useBrandingStore()
const route = useRoute()

function refresh(force = false) {
  const slug = activeTenantSlug()
  if (force || !branding.loaded || branding.tenantSlug !== slug) branding.load(slug)
}

onMounted(() => {
  branding.apply()
  refresh(true)
})

watch(() => moduleRegistry.viewKey, () => refresh(true))

// Signing out lands on /login without the tenant: back to the platform branding. The tenancy
// step on the login page loads the tenant's branding itself (no route change).
watch(() => route.name, (name, previous) => {
  if (name === 'login' && previous && previous !== 'login') refresh()
})
</script>

<template>
  <span v-if="false" />
</template>
