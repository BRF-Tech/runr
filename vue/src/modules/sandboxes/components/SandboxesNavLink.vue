<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Box } from 'lucide-vue-next'
import { usePermissionsStore } from '@/stores/permissions'

const { t } = useI18n()
const route = useRoute()
const permissions = usePermissionsStore()
const active = computed(() => !!route?.path?.startsWith('/sandboxes'))
</script>

<template>
  <RouterLink
    v-if="permissions.can('sandboxes.view')"
    to="/sandboxes"
    data-nav="sandboxes"
    class="h-8 px-2.5 flex items-center gap-1.5 text-sm rounded-md transition-colors hover:bg-gray-800"
    :class="active ? 'text-brand-500' : 'text-gray-400 hover:text-gray-100'"
    :title="t('sandboxes.nav')"
  >
    <Box class="w-4 h-4" />
    <span class="hidden md:inline">{{ t('sandboxes.nav') }}</span>
  </RouterLink>
</template>
