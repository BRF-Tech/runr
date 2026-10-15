<script setup>
// Project tab "MCP": the project served as an MCP server behind the RunR gateway — endpoint,
// access mode, client configuration, the built-in inspector and the call log (docs/mcp.md,
// "Hosting MCP servers").
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePermissionsStore } from '@/stores/permissions'
import { mcpApi } from '../api/mcp'
import McpServerPanel from './McpServerPanel.vue'
import McpServerSetup from './McpServerSetup.vue'
import McpInspector from './McpInspector.vue'
import McpCallLog from './McpCallLog.vue'

const props = defineProps({ project: { type: Object, required: true } })

const { t } = useI18n()
const perms = usePermissionsStore()

const server = ref(null)
const loading = ref(true)
const error = ref(null)

const canManage = computed(() => perms.can('mcp.servers.manage', props.project.id))
const canCall = computed(() => perms.can('mcp.servers.call', props.project.id))
const canViewLogs = computed(() => perms.can('logs.view', props.project.id))

async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = await mcpApi.server(props.project.id)
    server.value = data.data ?? data
  } catch (e) {
    if (e.response?.status === 404) server.value = null
    else error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    loading.value = false
  }
}

function onSaved(updated) {
  server.value = updated
}

onMounted(load)
watch(() => props.project.id, load)
</script>

<template>
  <div class="space-y-5" data-testid="mcp-tab">
    <p v-if="loading" class="text-xs text-gray-400 py-6 text-center">{{ t('common.loading') }}</p>
    <p v-else-if="error" class="text-xs text-red-400" data-testid="mcp-tab-error">{{ error }}</p>

    <template v-else-if="!server">
      <McpServerSetup v-if="canManage" :project="project" @saved="onSaved" />
      <p v-else class="text-sm text-gray-400" data-testid="mcp-tab-not-server">{{ t('mcp.project.notServer') }}</p>
    </template>

    <template v-else>
      <McpServerPanel :project="project" :server="server" :can-manage="canManage" @saved="onSaved" @removed="server = null" />
      <McpInspector v-if="canCall" :project="project" />
      <McpCallLog v-if="canViewLogs" :project="project" />
    </template>
  </div>
</template>
