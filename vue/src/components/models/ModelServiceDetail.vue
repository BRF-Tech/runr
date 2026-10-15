<script setup>
// One model service: status, internal URL, configuration (scaling and resources editable),
// playground, pod logs and the projects it is bound to.
import { ref, reactive, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { X, Play, Square, Trash2, RefreshCw, Copy } from 'lucide-vue-next'
import { aiApi } from '@/api/ai'
import { useAiStore } from '@/stores/ai'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { AI_STATUS_CLASSES, formatBytes } from '@/utils/ai'
import ModelPlayground from './ModelPlayground.vue'

const { t } = useI18n()
const ai = useAiStore()
const { confirm } = useConfirm()
const toast = useToast()

const props = defineProps({
  service: { type: Object, required: true },
})
const emit = defineEmits(['close', 'deleted'])

const tab = ref('playground')
const busy = ref(false)
const logs = ref(null)
const logsLoading = ref(false)
const bindings = ref([])
const bindingsLoaded = ref(false)

const edit = reactive({ cpu: '', memory_mb: 0, min_scale: 0, max_scale: 1, scale_to_zero: true, timeout_seconds: 300 })
const editSaving = ref(false)

watch(() => props.service, (s) => {
  if (!s) return
  edit.cpu             = s.cpu ?? ''
  edit.memory_mb       = s.memory_mb ?? 0
  edit.min_scale       = s.min_scale ?? 0
  edit.max_scale       = s.max_scale ?? 1
  edit.scale_to_zero   = s.scale_to_zero ?? true
  edit.timeout_seconds = s.timeout_seconds ?? 300
}, { immediate: true })

const statusClass = computed(() => AI_STATUS_CLASSES[props.service.status] ?? 'bg-gray-800 text-gray-400 border-gray-700')
const running = computed(() => ['ready', 'deploying', 'waiting_snapshot'].includes(props.service.status))

function errMessage(e) {
  return e?.response?.data?.message ?? Object.values(e?.response?.data?.errors ?? {}).flat()[0] ?? t('common.error')
}

async function refresh() {
  try {
    const { data } = await aiApi.getService(props.service.id)
    ai.upsertService(data?.data ?? data)
  } catch { /* the list poll catches up */ }
}

async function deploy() {
  busy.value = true
  try {
    const { data } = await aiApi.deployService(props.service.id)
    ai.upsertService(data?.data ?? data ?? { id: props.service.id, status: 'deploying' })
    toast.success(t('ai.services.deployQueued'))
  } catch (e) { toast.error(errMessage(e)) } finally { busy.value = false }
}

async function stop() {
  const ok = await confirm({ title: t('ai.services.stop'), message: t('ai.services.stopConfirm', { name: props.service.name }), confirmLabel: t('ai.services.stop') })
  if (!ok) return
  busy.value = true
  try {
    const { data } = await aiApi.stopService(props.service.id)
    ai.upsertService(data?.data ?? data ?? { id: props.service.id, status: 'stopped' })
    toast.success(t('ai.services.stopQueued'))
  } catch (e) { toast.error(errMessage(e)) } finally { busy.value = false }
}

async function remove() {
  const ok = await confirm({ title: t('ai.services.delete'), message: t('ai.services.deleteConfirm', { name: props.service.name }), destructive: true, confirmLabel: t('ai.services.delete') })
  if (!ok) return
  busy.value = true
  try {
    await aiApi.deleteService(props.service.id)
    ai.removeService(props.service.id)
    toast.success(t('ai.services.deleted'))
    emit('deleted', props.service.id)
  } catch (e) { toast.error(errMessage(e)) } finally { busy.value = false }
}

async function saveConfig() {
  editSaving.value = true
  try {
    const { data } = await aiApi.updateService(props.service.id, {
      cpu: edit.cpu || undefined,
      memory_mb: Number(edit.memory_mb) || undefined,
      min_scale: Number(edit.min_scale) || 0,
      max_scale: Number(edit.max_scale) || 1,
      scale_to_zero: edit.scale_to_zero,
      timeout_seconds: Number(edit.timeout_seconds) || undefined,
    })
    ai.upsertService(data?.data ?? data)
    toast.success(t('ai.services.saved'))
  } catch (e) { toast.error(errMessage(e)) } finally { editSaving.value = false }
}

async function loadLogs() {
  logsLoading.value = true
  try {
    const { data } = await aiApi.serviceLogs(props.service.id)
    logs.value = data?.pods ?? []
  } catch (e) {
    logs.value = []
    toast.error(errMessage(e))
  } finally { logsLoading.value = false }
}

async function loadBindings() {
  try {
    const { data } = await aiApi.serviceBindings(props.service.id)
    bindings.value = data?.data ?? []
  } catch { bindings.value = [] } finally { bindingsLoaded.value = true }
}

watch(tab, (v) => {
  if (v === 'logs' && logs.value === null) loadLogs()
  if (v === 'bindings' && !bindingsLoaded.value) loadBindings()
})

async function copyUrl() {
  try { await navigator.clipboard.writeText(props.service.url ?? ''); toast.success(t('ai.services.urlCopied')) } catch { /* clipboard unavailable */ }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex justify-end" data-testid="service-detail">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="emit('close')" />
    <aside class="relative w-full max-w-2xl h-full bg-gray-950 border-l border-gray-800 flex flex-col">
      <div class="px-5 py-4 border-b border-gray-800 space-y-2">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <h3 class="font-mono text-sm text-gray-100 truncate">{{ service.name }}</h3>
              <span class="inline-block px-2 py-0.5 rounded-md border text-[11px] font-mono" :class="statusClass" data-testid="service-status">
                {{ t(`ai.services.statuses.${service.status}`, service.status) }}
              </span>
            </div>
            <p class="text-[11px] text-gray-400 font-mono truncate">{{ service.model?.hub_id ?? service.model?.name }} · {{ service.region_name }}</p>
          </div>
          <button class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800" @click="emit('close')"><X class="w-4 h-4" /></button>
        </div>
        <p v-if="service.status_message" class="text-[11px] font-mono whitespace-pre-wrap" :class="service.status === 'failed' ? 'text-red-300' : 'text-gray-400'">{{ service.status_message }}</p>
        <div v-if="service.url" class="flex items-center gap-2">
          <code class="text-[11px] text-gray-300 bg-gray-900 rounded px-2 py-1 truncate" data-testid="service-url">{{ service.url }}</code>
          <button class="p-1 rounded text-gray-400 hover:text-gray-100" :title="t('common.copy')" @click="copyUrl"><Copy class="w-3.5 h-3.5" /></button>
        </div>
        <div v-if="ai.canDeploy" class="flex items-center gap-2 pt-1">
          <button v-if="!running" class="btn-primary text-xs" :disabled="busy" data-testid="service-deploy" @click="deploy"><Play class="w-3.5 h-3.5" />{{ t('ai.services.deploy') }}</button>
          <button v-else class="btn-secondary text-xs" :disabled="busy" data-testid="service-stop" @click="stop"><Square class="w-3.5 h-3.5" />{{ t('ai.services.stop') }}</button>
          <button class="btn-secondary text-xs" :disabled="busy" @click="refresh"><RefreshCw class="w-3.5 h-3.5" />{{ t('ai.services.refresh') }}</button>
          <button class="ml-auto p-1.5 rounded-md text-gray-400 hover:text-red-400 hover:bg-red-900/20" :disabled="busy" :title="t('ai.services.delete')" data-testid="service-delete" @click="remove"><Trash2 class="w-4 h-4" /></button>
        </div>
      </div>

      <div class="flex gap-0 border-b border-gray-800 px-5 shrink-0">
        <button v-for="tb in ['playground', 'config', 'logs', 'bindings']" :key="tb" class="tab-line text-xs font-medium -mb-px"
          :class="tab === tb ? 'is-active' : ''" :data-testid="`service-tab-${tb}`" @click="tab = tb">{{ t(`ai.services.tabs.${tb}`) }}</button>
      </div>

      <div class="flex-1 overflow-y-auto px-5 py-4">
        <ModelPlayground v-if="tab === 'playground'" :service="service" />

        <div v-else-if="tab === 'config'" class="space-y-4 text-xs">
          <div class="grid grid-cols-2 gap-x-4 gap-y-2">
            <span class="text-gray-500">{{ t('ai.deploy.engine') }}</span><span class="text-gray-200">{{ t(`ai.engines.${service.engine}`, service.engine) }}</span>
            <span class="text-gray-500">{{ t('ai.deploy.device') }}</span><span class="text-gray-200 font-mono">{{ (service.device ?? '').toUpperCase() }}<template v-if="service.gpu"> · {{ service.gpu.count }}× {{ service.gpu.type || t('ai.gpu.anyType') }}</template></span>
            <span class="text-gray-500">{{ t('ai.deploy.mode') }}</span><span class="text-gray-200">{{ t(`ai.modes.${service.mode}`, service.mode) }}</span>
            <span class="text-gray-500">{{ t('ai.services.api') }}</span><span class="text-gray-200">{{ t(`ai.apis.${service.api}`, service.api ?? '') }}</span>
            <span class="text-gray-500">{{ t('ai.services.servedName') }}</span><span class="text-gray-200 font-mono">{{ service.served_name }}</span>
            <span class="text-gray-500">{{ t('ai.deploy.image') }}</span><span class="text-gray-200 font-mono break-all">{{ service.image || '—' }}</span>
            <span class="text-gray-500">{{ t('ai.hub.size') }}</span><span class="text-gray-200 font-mono">{{ formatBytes(service.model?.size_bytes) }}</span>
          </div>
          <p v-if="service.cold_start?.suggest_min_scale && service.scale_to_zero" class="text-[11px] text-gray-400 bg-gray-800/40 border border-gray-700 rounded px-3 py-2">{{ t('ai.deploy.coldStart') }} {{ t('ai.gpu.minScaleSuggestion') }}</p>

          <div v-if="ai.canDeploy" class="space-y-3 border-t border-gray-800 pt-3">
            <div class="grid grid-cols-3 gap-3">
              <div><label class="label" for="svc-cpu">{{ t('ai.deploy.cpu') }}</label><input id="svc-cpu" v-model="edit.cpu" class="input font-mono" /></div>
              <div><label class="label" for="svc-mem">{{ t('create.fields.memoryMb') }}</label><input id="svc-mem" v-model.number="edit.memory_mb" type="number" min="64" class="input font-mono" /></div>
              <div><label class="label" for="svc-timeout">{{ t('create.fields.timeoutS') }}</label><input id="svc-timeout" v-model.number="edit.timeout_seconds" type="number" min="1" class="input font-mono" /></div>
              <div><label class="label" for="svc-min">{{ t('ai.services.minScale') }}</label><input id="svc-min" v-model.number="edit.min_scale" type="number" min="0" max="10" class="input font-mono" data-testid="svc-min-scale" /></div>
              <div><label class="label" for="svc-max">{{ t('ai.services.maxScale') }}</label><input id="svc-max" v-model.number="edit.max_scale" type="number" min="1" max="100" class="input font-mono" /></div>
              <label class="flex items-center gap-2 text-gray-300 mt-6"><input v-model="edit.scale_to_zero" type="checkbox" class="rounded border-gray-700 bg-gray-800" />{{ t('ai.services.scaleToZero') }}</label>
            </div>
            <button class="btn-primary text-xs" :disabled="editSaving" data-testid="svc-save" @click="saveConfig">{{ editSaving ? '...' : t('common.save') }}</button>
            <p class="text-[11px] text-gray-400">{{ t('ai.services.saveHint') }}</p>
          </div>
        </div>

        <div v-else-if="tab === 'logs'" class="space-y-3">
          <button class="btn-secondary text-xs" :disabled="logsLoading" @click="loadLogs"><RefreshCw class="w-3.5 h-3.5" :class="logsLoading ? 'animate-spin' : ''" />{{ t('ai.services.reloadLogs') }}</button>
          <p v-if="logs && !logs.length" class="text-xs text-gray-400">{{ t('ai.services.noPods') }}</p>
          <div v-for="pod in logs ?? []" :key="pod.name" class="rounded-lg border border-gray-800 bg-gray-900/60">
            <p class="px-3 py-1.5 border-b border-gray-800 text-[11px] font-mono text-gray-300 flex items-center gap-2">
              {{ pod.name }} <span class="text-gray-500">{{ pod.phase }}</span>
              <span v-if="pod.ready" class="text-emerald-400">{{ t('ai.services.podReady') }}</span>
            </p>
            <pre class="px-3 py-2 text-[11px] font-mono text-gray-300 whitespace-pre-wrap max-h-80 overflow-y-auto">{{ pod.logs || '—' }}</pre>
          </div>
        </div>

        <div v-else-if="tab === 'bindings'" class="space-y-2">
          <p class="text-[11px] text-gray-400">{{ t('ai.services.bindingsHint') }}</p>
          <p v-if="bindingsLoaded && !bindings.length" class="text-xs text-gray-400">{{ t('ai.services.noBindings') }}</p>
          <RouterLink v-for="b in bindings" :key="b.id" :to="`/projects/${b.project?.id ?? b.project_id}#models`"
            class="flex items-center justify-between rounded-lg border border-gray-800 bg-gray-900/50 px-3 py-2 text-xs hover:border-gray-700">
            <span class="text-gray-100">{{ b.project?.name ?? b.project_id }}</span>
            <code class="text-gray-400">RUNR_MODEL_{{ b.alias }}_URL</code>
          </RouterLink>
        </div>
      </div>
    </aside>
  </div>
</template>
