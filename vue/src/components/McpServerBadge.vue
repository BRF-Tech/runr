<script setup>
// "MCP server" chip of a project served behind the RunR MCP gateway (project.mcp_server).
import { useI18n } from 'vue-i18n'
import { Bot } from 'lucide-vue-next'

defineProps({ server: { type: Object, default: null } })
const { t } = useI18n()
</script>

<template>
  <span
    v-if="server"
    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md border text-xs font-mono font-medium"
    style="background:rgb(139 92 246/0.12);border-color:rgb(139 92 246/0.35);color:#a78bfa"
    :title="server.url || ''"
    data-testid="mcp-server-badge"
  ><Bot class="w-3 h-3" />{{ t('mcp.badge') }}</span>
</template>
