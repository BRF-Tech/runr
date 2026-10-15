<script setup>
import { watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { usePermissionsStore } from '@/stores/permissions'
import { moduleRegistry } from '@/modules/index'

// Loads the signed-in user's effective permissions; again after sign-in and tenant switches.
const auth  = useAuthStore()
const perms = usePermissionsStore()

watch(
  () => [auth.token, moduleRegistry.viewKey],
  ([token]) => (token ? perms.load() : perms.reset()),
  { immediate: true },
)
</script>

<template><span class="hidden" /></template>
