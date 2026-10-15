<script setup>
// A hosted MCP server: its gateway URL, access mode, path, call auditing, the gateway key and the
// client configuration for its URL.
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { mcpApi } from '../api/mcp'
import AccessModePicker from './AccessModePicker.vue'
import CopyBlock from './CopyBlock.vue'
import McpClientSnippets from './McpClientSnippets.vue'
import { KeyRound, RefreshCw, Power } from 'lucide-vue-next'

const props = defineProps({
  project:   { type: Object, required: true },
  server:    { type: Object, required: true },
  canManage: { type: Boolean, default: false },
})
const emit = defineEmits(['saved', 'removed'])

const { t, locale } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()

const accessMode = ref(props.server.access_mode)
const path = ref(props.server.path || '/mcp')
const auditCalls = ref(!!props.server.audit_calls)
const saving = ref(false)
const error = ref(null)

watch(() => props.server, (s) => {
  accessMode.value = s.access_mode
  path.value = s.path || '/mcp'
  auditCalls.value = !!s.audit_calls
})

const dirty = computed(() => accessMode.value !== props.server.access_mode
  || (path.value || '/mcp') !== (props.server.path || '/mcp')
  || auditCalls.value !== !!props.server.audit_calls)

const snippetAuth = computed(() => props.server.access_mode === 'public' ? 'public' : props.server.access_mode)

function fmt(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleString(locale.value === 'tr' ? 'tr-TR' : 'en-US', { dateStyle: 'medium', timeStyle: 'short' })
}

async function save() {
  saving.value = true
  error.value = null
  try {
    const { data } = await mcpApi.saveServer(props.project.id, {
      access_mode: accessMode.value,
      path: path.value || '/mcp',
      audit_calls: auditCalls.value,
    })
    emit('saved', data.data ?? data)
    toast.success(t('mcp.project.saved'))
  } catch (e) {
    error.value = e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {}).flat()[0] ?? t('common.error')
  } finally {
    saving.value = false
  }
}

async function rotate() {
  const ok = await confirm({ title: t('mcp.project.rotateTitle'), message: t('mcp.project.rotateConfirm'), confirmLabel: t('mcp.project.rotate') })
  if (!ok) return
  try {
    const { data } = await mcpApi.rotateKey(props.project.id)
    emit('saved', data.data ?? data)
    toast.success(t('mcp.project.rotated'))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

async function remove() {
  const ok = await confirm({ title: t('mcp.project.removeTitle'), message: t('mcp.project.removeConfirm', { name: props.project.name }), destructive: true, confirmLabel: t('mcp.project.remove') })
  if (!ok) return
  try {
    await mcpApi.deleteServer(props.project.id)
    emit('removed')
    toast.success(t('mcp.project.removed'))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}
</script>

<template>
  <section class="rounded-xl border border-gray-800 bg-gray-900/60 px-5 py-4 space-y-4" data-testid="mcp-server-panel">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <p class="text-sm font-semibold text-gray-100">{{ t('mcp.project.title') }}</p>
        <p class="text-xs text-gray-400 mt-0.5">{{ t('mcp.project.hint') }}</p>
      </div>
      <span class="text-[11px] font-medium px-2 py-1 rounded border border-gray-700 bg-gray-800 text-gray-100" data-testid="mcp-current-mode">{{ t(`mcp.access.${server.access_mode}`) }}</span>
    </div>

    <CopyBlock :text="server.url" :label="t('mcp.project.url')" inline />
    <p v-if="server.function_url" class="text-[11px] text-gray-400 font-mono break-all">{{ t('mcp.project.forwardsTo', { url: server.function_url + (server.path || '') }) }}</p>

    <div v-if="server.needs_redeploy" class="rounded-lg border border-amber-800/50 bg-amber-900/20 px-3 py-2 text-xs text-amber-200" data-testid="mcp-needs-redeploy">
      {{ t('mcp.project.needsRedeploy') }}
    </div>

    <template v-if="canManage">
      <div>
        <p class="label mb-2">{{ t('mcp.project.accessMode') }}</p>
        <AccessModePicker v-model="accessMode" />
      </div>
      <div class="flex flex-wrap items-end gap-4">
        <div>
          <label class="label" for="mcp-path">{{ t('mcp.project.path') }}</label>
          <input id="mcp-path" v-model="path" class="input w-56 font-mono" placeholder="/mcp" data-testid="mcp-path" />
        </div>
        <label class="flex items-center gap-2 text-xs text-gray-100 pb-2">
          <input v-model="auditCalls" type="checkbox" class="rounded border-gray-600 bg-gray-800" data-testid="mcp-audit-calls" />
          {{ t('mcp.project.auditCalls') }}
        </label>
      </div>
      <p class="text-[11px] text-gray-400">{{ t('mcp.project.auditCallsHint') }}</p>
      <p v-if="error" class="text-xs text-red-400" data-testid="mcp-panel-error">{{ error }}</p>
      <div class="flex flex-wrap gap-2">
        <button class="btn-primary text-xs" :disabled="!dirty || saving" data-testid="mcp-save" @click="save">{{ saving ? t('common.saving') : t('common.save') }}</button>
        <button class="btn-secondary text-xs flex items-center gap-1.5" data-testid="mcp-rotate" @click="rotate"><RefreshCw class="w-3.5 h-3.5" />{{ t('mcp.project.rotate') }}</button>
        <button class="btn-danger text-xs flex items-center gap-1.5" data-testid="mcp-remove" @click="remove"><Power class="w-3.5 h-3.5" />{{ t('mcp.project.remove') }}</button>
      </div>
      <p class="text-[11px] text-gray-400 flex items-center gap-1"><KeyRound class="w-3 h-3" />{{ t('mcp.project.keyRotatedAt', { date: fmt(server.key_rotated_at) }) }}</p>
    </template>

    <div class="pt-2 border-t border-gray-800">
      <p class="text-xs font-semibold text-gray-100 mb-2">{{ t('mcp.project.connect') }}</p>
      <McpClientSnippets :url="server.url" :name="server.handle" :auth="snippetAuth" />
    </div>
  </section>
</template>
