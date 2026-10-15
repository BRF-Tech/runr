<script setup>
// Projects page action (moduleRegistry.projectsActions): opens the one-click MCP server modal.
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePermissionsStore } from '@/stores/permissions'
import CreateMcpServerModal from './CreateMcpServerModal.vue'
import { Bot } from 'lucide-vue-next'

defineProps({
  hasReadyRegion: { type: Boolean, default: true },
  existingNames:  { type: Array, default: () => [] },
})
const emit = defineEmits(['created'])

const { t } = useI18n()
const perms = usePermissionsStore()
const open = ref(false)

function onCreated(project) {
  open.value = false
  emit('created', project)
}
</script>

<template>
  <div v-if="perms.can('projects.create')">
    <button
      class="btn-secondary flex items-center gap-1.5"
      :disabled="!hasReadyRegion"
      data-testid="new-mcp-server-btn"
      @click="hasReadyRegion && (open = true)"
    ><Bot class="w-4 h-4" />{{ t('mcp.create.button') }}</button>
    <CreateMcpServerModal v-if="open" :existing-names="existingNames" @created="onCreated" @close="open = false" />
  </div>
</template>
