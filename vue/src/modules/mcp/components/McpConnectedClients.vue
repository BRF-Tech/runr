<script setup>
// MCP clients connected through OAuth: their tokens (also listed under API tokens), revocable
// per client (DELETE /api/mcp/clients/{client}).
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { useRbacLabels } from '@/composables/useRbac'
import { mcpApi } from '../api/mcp'
import { Plug, Trash2 } from 'lucide-vue-next'

const { t, locale } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()
const { permLabel } = useRbacLabels()

const clients = ref([])
const loading = ref(true)
const error = ref(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = await mcpApi.clients()
    clients.value = data.data ?? []
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    loading.value = false
  }
}

function fmt(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleString(locale.value === 'tr' ? 'tr-TR' : 'en-US', { dateStyle: 'medium', timeStyle: 'short' })
}

function scopes(client) {
  const all = new Set()
  for (const token of client.tokens || []) for (const ab of token.abilities || []) all.add(ab)
  return [...all]
}

async function revoke(client) {
  const ok = await confirm({
    title: t('mcp.clients.revokeTitle'),
    message: t('mcp.clients.revokeConfirm', { name: client.name }),
    destructive: true,
    confirmLabel: t('mcp.clients.revoke'),
  })
  if (!ok) return
  try {
    await mcpApi.revokeClient(client.client_id)
    clients.value = clients.value.filter(c => c.client_id !== client.client_id)
    toast.success(t('mcp.clients.revoked', { name: client.name }))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

onMounted(load)
</script>

<template>
  <section class="rounded-xl border border-gray-800 bg-gray-900/60 px-5 py-4" data-testid="mcp-clients">
    <p class="text-sm font-semibold text-gray-100">{{ t('mcp.clients.title') }}</p>
    <p class="text-xs text-gray-400 mt-0.5 mb-3">{{ t('mcp.clients.hint') }}</p>

    <p v-if="loading" class="text-xs text-gray-400">{{ t('common.loading') }}</p>
    <p v-else-if="error" class="text-xs text-red-400">{{ error }}</p>
    <p v-else-if="clients.length === 0" class="text-xs text-gray-400 italic" data-testid="mcp-clients-empty">{{ t('mcp.clients.empty') }}</p>

    <div v-else class="space-y-2">
      <div v-for="client in clients" :key="client.client_id"
        class="rounded-lg border border-gray-800 bg-gray-950/40 px-4 py-3 flex items-start gap-3"
        :data-testid="`mcp-client-${client.client_id}`">
        <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 bg-gray-800/60 border border-gray-700/60">
          <Plug class="w-4 h-4 text-gray-400" />
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold text-gray-100 truncate">{{ client.name }}</p>
          <div class="flex flex-wrap gap-3 mt-0.5 text-[11px] text-gray-400">
            <span>{{ t('mcp.clients.connected') }}: {{ fmt(client.connected_at) }}</span>
            <span>{{ t('mcp.clients.lastUsed') }}: {{ fmt(client.last_used_at) }}</span>
            <span v-if="client.redirect_hosts?.length">{{ t('mcp.clients.redirects') }}: {{ client.redirect_hosts.join(', ') }}</span>
            <span>{{ t('mcp.clients.tokens', { n: (client.tokens || []).length }) }}</span>
          </div>
          <div class="flex flex-wrap gap-1 mt-1.5">
            <span v-for="ab in scopes(client)" :key="ab"
              class="text-[10px] font-medium px-1.5 py-0.5 rounded border border-gray-700/60 bg-gray-800 text-gray-400">
              {{ ab === '*' ? t('tokens.fullAccess') : permLabel(ab) }}
            </span>
          </div>
        </div>
        <button class="p-1.5 rounded-md text-gray-400 hover:text-red-400 hover:bg-red-900/20 transition-colors shrink-0"
          :title="t('mcp.clients.revoke')" data-testid="mcp-client-revoke" @click="revoke(client)">
          <Trash2 class="w-4 h-4" />
        </button>
      </div>
    </div>
  </section>
</template>
