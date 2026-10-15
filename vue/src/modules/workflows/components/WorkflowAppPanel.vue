<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { KeyRound, RefreshCw, Save, Eye, Radio } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { workflowsApi } from '../api/workflows'
import { formatTime } from '../utils/format'

const props = defineProps({
  projectId: { type: String, required: true },
  app:       { type: Object, default: null },
  canManage: { type: Boolean, default: true },
})

const emit = defineEmits(['updated', 'synced'])
const { t, locale } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()

const override = ref(props.app?.endpoint_override ?? '')
const saving   = ref(false)
const syncing  = ref(false)
const rotating = ref(false)
const key      = ref(null)
const error    = ref(null)

watch(() => props.app?.endpoint_override, (v) => { override.value = v ?? '' })

const syncClass = computed(() => ({
  ok:    'text-emerald-400',
  error: 'text-red-400',
  never: 'text-gray-400',
}[props.app?.sync_status] ?? 'text-gray-400'))

async function saveEndpoint() {
  saving.value = true
  error.value  = null
  try {
    const { data } = await workflowsApi.updateApp(props.projectId, override.value.trim())
    emit('updated', data.data)
    toast.success(t('workflows.app.saved'))
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    saving.value = false
  }
}

async function sync() {
  syncing.value = true
  error.value   = null
  try {
    const { data } = await workflowsApi.sync(props.projectId)
    emit('synced', data)
    toast.success(t('workflows.app.synced'))
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
    emit('synced', null)
  } finally {
    syncing.value = false
  }
}

async function rotate() {
  const ok = await confirm({
    title:        t('workflows.app.rotateTitle'),
    message:      t('workflows.app.rotateConfirm'),
    confirmLabel: t('workflows.app.rotateKey'),
    destructive:  true,
  })
  if (!ok) return
  rotating.value = true
  try {
    const { data } = await workflowsApi.rotateKey(props.projectId)
    key.value = null
    emit('updated', data.data)
    toast.success(t('workflows.app.rotated'))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  } finally {
    rotating.value = false
  }
}

async function reveal() {
  try {
    const { data } = await workflowsApi.signingKey(props.projectId)
    key.value = data.signing_key
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}
</script>

<template>
  <div class="bg-gray-900 border border-gray-800 rounded-md p-4 space-y-3 text-xs" data-test="app-panel">
    <div class="flex flex-wrap items-center gap-2">
      <span class="text-[11px] uppercase tracking-wider font-medium text-gray-400">{{ t('workflows.app.settings') }}</span>
      <span :class="syncClass" data-test="sync-status">{{ t(`workflows.app.syncStatus.${app?.sync_status ?? 'never'}`) }}</span>
      <span class="text-gray-400">· {{ t('workflows.app.lastSynced') }}: {{ app?.last_synced_at ? formatTime(app.last_synced_at, locale) : t('workflows.app.never') }}</span>
      <span v-if="app?.sdk" class="text-gray-400">· {{ t('workflows.app.sdk') }}: <span class="font-mono">{{ app.sdk.name }} {{ app.sdk.version }}</span></span>
      <button v-if="canManage" type="button" class="btn-primary text-xs px-3 py-1.5 ml-auto" :disabled="syncing" data-test="sync-button" @click="sync">
        <RefreshCw class="w-3.5 h-3.5" :class="syncing ? 'animate-spin' : ''" />{{ syncing ? t('workflows.app.syncing') : t('workflows.app.sync') }}
      </button>
    </div>

    <p v-if="app?.sync_status === 'error' && app?.sync_error" class="text-red-400 break-all">{{ app.sync_error }}</p>

    <div class="grid gap-3 md:grid-cols-2">
      <div>
        <label class="label">{{ t('workflows.app.endpoint') }}</label>
        <p v-if="app?.endpoint_url" class="font-mono text-gray-100 break-all" data-test="endpoint-url">{{ app.endpoint_url }}</p>
        <p v-else class="text-yellow-400">{{ t('workflows.app.endpointUnknown') }}</p>
        <form v-if="canManage" class="flex items-center gap-2 mt-2" @submit.prevent="saveEndpoint">
          <input v-model="override" class="input py-1.5! font-mono text-xs" :placeholder="t('workflows.app.endpointPlaceholder')" data-test="endpoint-override" />
          <button type="submit" class="btn-secondary text-xs px-3 py-1.5 shrink-0" :disabled="saving"><Save class="w-3.5 h-3.5" />{{ t('workflows.app.save') }}</button>
        </form>
      </div>
      <div>
        <label class="label">{{ t('workflows.app.keyFingerprint') }}</label>
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-mono text-gray-100">{{ app?.key_fingerprint }}…</span>
          <button v-if="canManage" type="button" class="btn-secondary text-xs px-2.5 py-1" @click="reveal"><Eye class="w-3.5 h-3.5" />{{ t('workflows.app.revealKey') }}</button>
          <button v-if="canManage" type="button" class="btn-secondary text-xs px-2.5 py-1 text-amber-300" :disabled="rotating" data-test="rotate-key" @click="rotate"><KeyRound class="w-3.5 h-3.5" />{{ t('workflows.app.rotateKey') }}</button>
        </div>
        <p v-if="key" class="font-mono text-gray-100 break-all mt-2 bg-gray-950/60 border border-gray-800 rounded-sm px-2 py-1" data-test="signing-key">{{ key }}</p>
        <p class="text-gray-400 mt-1">{{ t('workflows.app.keyHint') }}</p>
      </div>
    </div>

    <div v-if="app?.queue_bridge?.enabled" class="flex items-start gap-2 text-gray-300">
      <Radio class="w-3.5 h-3.5 mt-0.5 text-violet-300 shrink-0" />
      <span>
        <span class="text-gray-400">{{ t('workflows.app.queueBridge') }}:</span>
        {{ t('workflows.app.queueBridgeEvent', { stream: app.queue_bridge.stream, event: app.queue_bridge.event }) }}
        <span v-if="!app.queue_bridge.has_redis" class="text-yellow-400"> {{ t('workflows.app.noRedis') }}</span>
      </span>
    </div>

    <p v-if="error" class="text-red-400 break-all" data-test="app-error">{{ error }}</p>
  </div>
</template>
