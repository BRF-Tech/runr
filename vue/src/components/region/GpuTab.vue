<script setup>
// Region → GPU: GPU worker nodes (allocatable nvidia.com/gpu + GPU Feature Discovery labels),
// NVIDIA GPU support (device plugin with optional time-slicing, or the GPU Operator),
// RuntimeClass, gVisor nvproxy, GPU support per project type and the region's model volume.
import { ref, reactive, computed, watch, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RefreshCw, Cpu, AlertTriangle, HardDrive, Info } from 'lucide-vue-next'
import { aiApi } from '@/api/ai'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { useLatest } from '@/composables/useLatest'
import { clearRegionGpuCache } from '@/composables/useRegionGpu'
import { AI_STATUS_CLASSES, formatGpuMemory } from '@/utils/ai'
import ComponentLogBox from './ComponentLogBox.vue'
import TermText from '@/components/TermText.vue'

const { t } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()

const props = defineProps({
  regionId: { type: String, default: null },
  region:   { type: Object, default: null },
})

const gpu        = ref(null)
const loading    = ref(false)
const refreshing = ref(false)
const busy       = ref(false)
const logCopied  = ref(false)

const install = reactive({
  mode:                  'device-plugin',
  time_slicing_replicas: 1,
  runtime_class:         'auto',
  operator_driver:       true,
  operator_toolkit:      true,
  // Device plugin / GFD only on GPU nodes (on by default); empty list = detected automatically
  restrict_to_gpu_nodes: true,
  gpu_nodes:             '',
})

const settings = reactive({ time_slicing_replicas: 1, gvisor_nvproxy: false, runtime_class: 'auto', restrict_to_gpu_nodes: true, gpu_nodes: '' })

/** "a, b c" → ['a', 'b', 'c']; null when empty (RunR detects the GPU nodes). */
function nodeList(text) {
  const nodes = String(text ?? '').split(/[\s,]+/).map(n => n.trim()).filter(Boolean)
  return nodes.length ? nodes : null
}
const settingsSaving = ref(false)

const storage = reactive({ storage_class: '', size_gi: 100, access_mode: 'ReadWriteMany' })
const storageState = ref(null)
const storageSaving = ref(false)

const status    = computed(() => gpu.value?.status ?? 'not_installed')
const mode      = computed(() => gpu.value?.mode ?? 'none')
const summary   = computed(() => gpu.value?.summary ?? {})
const nodes     = computed(() => gpu.value?.nodes ?? [])
const sandbox   = computed(() => gpu.value?.sandbox ?? {})
const transient = computed(() => ['installing', 'uninstalling'].includes(status.value))
const canInstall   = computed(() => ['not_installed', 'error'].includes(status.value) && mode.value !== 'external')
const canUninstall = computed(() => ['ready', 'error'].includes(status.value) && ['device-plugin', 'operator'].includes(mode.value))
const withoutPlugin = computed(() => summary.value.hardware_without_plugin ?? [])
/** GPUs requested by running pods on a node (node entry, else the allocation map). */
const allocatedOn = (n) => n.allocated ?? gpu.value?.allocation?.by_node?.[n.name] ?? 0

function errMessage(e) {
  if (e?.response?.status === 403) return t('ai.gpu.region.forbidden')
  return e?.response?.data?.message ?? Object.values(e?.response?.data?.errors ?? {}).flat()[0] ?? t('common.error')
}

/** The settings form as the server's settings fill it. */
function settingsForm(s = {}) {
  return {
    time_slicing_replicas: s.time_slicing_replicas || 1,
    gvisor_nvproxy:        !!s.gvisor_nvproxy,
    restrict_to_gpu_nodes: s.restrict_to_gpu_nodes ?? true,
    gpu_nodes:             (s.gpu_nodes ?? []).join(', '),
    // runtime_class is the resolved class (nvidia / null), runtime_class_choice the admin's pick
    runtime_class:         s.runtime_class_choice ?? (s.runtime_class ? 'nvidia' : 'auto'),
  }
}

// keepEdits (the status poll during an install): settings being edited, not saved yet, stay
function apply(data, { keepEdits = false } = {}) {
  if (!data) return
  const edited = keepEdits && gpu.value
    && JSON.stringify(settingsForm(gpu.value.settings ?? {})) !== JSON.stringify(pickSettings())
  gpu.value = data
  const s = data.settings ?? {}
  if (!edited) Object.assign(settings, settingsForm(s))
  install.runtime_class          = s.runtime_class_choice ?? install.runtime_class
  clearRegionGpuCache()
}

function pickSettings() {
  return {
    time_slicing_replicas: settings.time_slicing_replicas,
    gvisor_nvproxy:        settings.gvisor_nvproxy,
    restrict_to_gpu_nodes: settings.restrict_to_gpu_nodes,
    gpu_nodes:             settings.gpu_nodes,
    runtime_class:         settings.runtime_class,
  }
}

// The newest read wins: an action's answer supersedes a poll sent before it
const latest = useLatest()
function supersedeReads() {
  latest.cancel()
  loading.value = false
  refreshing.value = false
}

async function load({ refresh = false, poll = false } = {}) {
  if (!props.regionId) return
  const current = latest.begin()
  if (refresh) refreshing.value = true; else if (!poll) loading.value = true
  try {
    const { data } = await aiApi.gpu(props.regionId, refresh)
    if (current()) apply(data, { keepEdits: poll })
  } catch (e) {
    if (current() && refresh) toast.error(errMessage(e))
  } finally {
    if (current()) {
      loading.value = false
      refreshing.value = false
    }
  }
}

async function loadStorage() {
  if (!props.regionId) return
  try {
    const { data } = await aiApi.modelStorage(props.regionId)
    storageState.value = data
    storage.storage_class = data?.storage_class ?? ''
    storage.size_gi       = data?.size_gi ?? 100
    storage.access_mode   = data?.access_mode ?? 'ReadWriteMany'
  } catch { storageState.value = null }
}

async function installGpu() {
  busy.value = true
  try {
    const payload = {
      mode:          install.mode,
      runtime_class: install.runtime_class,
      time_slicing_replicas: install.time_slicing_replicas > 1 ? install.time_slicing_replicas : null,
      restrict_to_gpu_nodes: install.restrict_to_gpu_nodes,
      gpu_nodes:             install.restrict_to_gpu_nodes ? nodeList(install.gpu_nodes) : null,
    }
    if (install.mode === 'operator') {
      payload.operator_driver  = install.operator_driver
      payload.operator_toolkit = install.operator_toolkit
    }
    const { data } = await aiApi.installGpu(props.regionId, payload)
    supersedeReads()
    apply(data?.gpu)
    toast.success(data?.message ?? t('ai.gpu.region.installQueued'))
  } catch (e) {
    toast.error(errMessage(e))
  } finally {
    busy.value = false
  }
}

async function uninstallGpu() {
  const ok = await confirm({
    title:       t('ai.gpu.region.uninstallTitle'),
    message:     t('ai.gpu.region.uninstallConfirm'),
    destructive: true,
    confirmLabel: t('ai.gpu.region.uninstall'),
  })
  if (!ok) return
  busy.value = true
  try {
    const { data } = await aiApi.uninstallGpu(props.regionId)
    supersedeReads()
    apply(data?.gpu)
    toast.success(data?.message ?? t('ai.gpu.region.uninstallQueued'))
  } catch (e) {
    toast.error(errMessage(e))
  } finally {
    busy.value = false
  }
}

async function saveSettings() {
  settingsSaving.value = true
  try {
    const { data } = await aiApi.updateGpuSettings(props.regionId, {
      time_slicing_replicas: settings.time_slicing_replicas > 1 ? settings.time_slicing_replicas : null,
      gvisor_nvproxy:        settings.gvisor_nvproxy,
      runtime_class:         settings.runtime_class,
      restrict_to_gpu_nodes: settings.restrict_to_gpu_nodes,
      gpu_nodes:             settings.restrict_to_gpu_nodes ? nodeList(settings.gpu_nodes) : null,
    })
    supersedeReads()
    apply(data?.gpu ?? data)
    toast.success(t('ai.gpu.region.settingsSaved'))
  } catch (e) {
    toast.error(errMessage(e))
  } finally {
    settingsSaving.value = false
  }
}

async function saveStorage() {
  storageSaving.value = true
  try {
    const { data } = await aiApi.updateModelStorage(props.regionId, {
      storage_class: storage.storage_class || null,
      size_gi:       Number(storage.size_gi) || 100,
      access_mode:   storage.access_mode,
    })
    storageState.value = data
    toast.success(t('ai.storage.saved'))
  } catch (e) {
    toast.error(errMessage(e))
  } finally {
    storageSaving.value = false
  }
}

async function copyLog() {
  try {
    await navigator.clipboard.writeText(gpu.value?.status_message ?? '')
    logCopied.value = true
    setTimeout(() => { logCopied.value = false }, 1500)
  } catch { /* clipboard unavailable */ }
}

// Live install / uninstall: refresh the status while it runs
let timer = null
watch(transient, (on) => {
  clearInterval(timer)
  if (on) timer = setInterval(() => load({ poll: true }), 5000)
}, { immediate: true })
onUnmounted(() => clearInterval(timer))

watch(() => props.regionId, () => { load(); loadStorage() }, { immediate: true })

const statusClass = (s) => AI_STATUS_CLASSES[s] ?? 'bg-gray-800 text-gray-400 border-gray-700'
const sandboxClass = (s) => s === 'ok'
  ? 'text-emerald-400'
  : s === 'nvproxy' ? 'text-blue-400' : 'text-red-400'
</script>

<template>
  <div class="space-y-6" data-testid="gpu-tab">
    <!-- Title -->
    <div class="flex items-start justify-between gap-3">
      <div>
        <h3 class="text-sm font-semibold text-gray-100">{{ t('ai.gpu.region.title') }}</h3>
        <p class="text-xs text-gray-400 mt-1">{{ t('ai.gpu.region.subtitle') }}</p>
      </div>
      <button class="btn-secondary text-xs" :disabled="refreshing" data-testid="gpu-refresh" @click="load({ refresh: true })">
        <RefreshCw class="w-3.5 h-3.5" :class="refreshing ? 'animate-spin' : ''" />{{ t('ai.gpu.region.refresh') }}
      </button>
    </div>

    <div v-if="loading && !gpu" class="py-8 text-center text-xs text-gray-400">{{ t('common.loading') }}</div>

    <template v-else>
      <!-- Summary -->
      <div class="grid grid-cols-4 gap-3">
        <div v-for="tile in [
          { key: 'gpuNodes',  value: summary.gpu_nodes ?? 0 },
          { key: 'total',     value: summary.total ?? 0 },
          { key: 'allocated', value: summary.allocated ?? gpu?.allocation?.total ?? 0 },
          { key: 'available', value: summary.available ?? 0 },
        ]" :key="tile.key" class="rounded-xl border border-gray-800 bg-gray-900/60 p-3">
          <p class="text-[10px] text-gray-500 uppercase tracking-wider font-medium">{{ t(`ai.gpu.region.summary.${tile.key}`) }}</p>
          <p class="text-lg font-bold text-gray-100 font-mono" :data-testid="`gpu-summary-${tile.key}`">{{ tile.value }}</p>
        </div>
      </div>

      <p v-if="withoutPlugin.length" class="flex items-start gap-2 text-xs text-amber-300/90 bg-amber-900/20 rounded-sm px-3 py-2" data-testid="gpu-without-plugin">
        <AlertTriangle class="w-3.5 h-3.5 shrink-0 mt-0.5" />
        {{ t('ai.gpu.region.hardwareWithoutPlugin', { nodes: withoutPlugin.join(', ') }) }}
      </p>

      <!-- GPU support -->
      <div class="bg-gray-900/60 border border-gray-800 rounded-xl p-4 space-y-3">
        <div class="flex items-center justify-between gap-3">
          <div>
            <h4 class="text-sm font-medium text-gray-100 flex items-center gap-1.5"><Cpu class="w-4 h-4 text-amber-400" />{{ t('ai.gpu.region.support') }}</h4>
            <p class="text-xs text-gray-400 mt-0.5">{{ t(`ai.gpu.region.modes.${mode}`) }}</p>
          </div>
          <div class="flex items-center gap-2">
            <span class="inline-block px-2 py-0.5 rounded-md border text-xs font-mono" :class="statusClass(status)" data-testid="gpu-status">
              {{ t(`ai.gpu.region.statuses.${status}`) }}
            </span>
            <button v-if="canUninstall" class="btn-secondary text-xs" :disabled="busy" data-testid="gpu-uninstall" @click="uninstallGpu">
              {{ t('ai.gpu.region.uninstall') }}
            </button>
          </div>
        </div>

        <ComponentLogBox :status="status === 'uninstalling' ? 'uninstalling' : status === 'installing' ? 'installing' : status" :message="gpu?.status_message ?? ''" :log-copied="logCopied" @copy="copyLog" />

        <p v-if="mode === 'external'" class="text-xs text-gray-400">{{ t('ai.gpu.region.externalHint') }}</p>

        <!-- Install form -->
        <div v-if="canInstall" class="space-y-3 border-t border-gray-800 pt-3" data-testid="gpu-install-form">
          <div class="flex flex-wrap gap-1.5">
            <button v-for="m in ['device-plugin', 'operator']" :key="m" type="button"
              class="rounded-md border px-3 py-1.5 text-xs font-medium transition-colors"
              :class="install.mode === m ? 'border-brand-500/60 bg-brand-900/20 text-brand-400' : 'border-gray-800 bg-gray-900/40 text-gray-400 hover:text-gray-100 hover:border-gray-700'"
              :data-testid="`gpu-mode-${m}`" @click="install.mode = m">
              {{ t(`ai.gpu.region.installModes.${m}`) }}
            </button>
          </div>
          <p class="text-[11px] text-gray-400">{{ t(`ai.gpu.region.installModeHints.${install.mode}`) }}</p>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label" for="gpu-time-slicing">{{ t('ai.gpu.region.timeSlicing') }}</label>
              <input id="gpu-time-slicing" v-model.number="install.time_slicing_replicas" type="number" min="1" max="16" class="input font-mono" data-testid="gpu-time-slicing" />
              <p class="text-[11px] text-gray-400 mt-1">{{ t('ai.gpu.region.timeSlicingHint') }}</p>
            </div>
            <div>
              <label class="label" for="gpu-runtime-class"><TermText path="ai.gpu.region.runtimeClass" /></label>
              <select id="gpu-runtime-class" v-model="install.runtime_class" class="input" data-testid="gpu-runtime-class">
                <option v-for="rc in ['auto', 'nvidia', 'none']" :key="rc" :value="rc">{{ t(`ai.gpu.region.runtimeClasses.${rc}`) }}</option>
              </select>
              <p class="text-[11px] text-gray-400 mt-1">{{ t('ai.gpu.region.runtimeClassHint') }}</p>
            </div>
          </div>

          <div v-if="install.mode === 'operator'" class="flex flex-wrap gap-4">
            <label class="flex items-center gap-2 text-xs text-gray-300">
              <input v-model="install.operator_driver" type="checkbox" class="rounded-sm border-gray-700 bg-gray-800" data-testid="gpu-operator-driver" />
              {{ t('ai.gpu.region.operatorDriver') }}
            </label>
            <label class="flex items-center gap-2 text-xs text-gray-300">
              <input v-model="install.operator_toolkit" type="checkbox" class="rounded-sm border-gray-700 bg-gray-800" data-testid="gpu-operator-toolkit" />
              {{ t('ai.gpu.region.operatorToolkit') }}
            </label>
          </div>

          <!-- Device plugin / GFD (operator: operands) only on the GPU nodes -->
          <div class="space-y-2">
            <label class="flex items-start gap-2 text-xs text-gray-300">
              <input v-model="install.restrict_to_gpu_nodes" type="checkbox" class="mt-0.5 rounded-sm border-gray-700 bg-gray-800" data-testid="gpu-restrict" />
              <span>
                {{ t('ai.gpu.region.restrictToGpuNodes') }}
                <span class="block text-[11px] text-gray-400">{{ t('ai.gpu.region.restrictToGpuNodesHint') }}</span>
              </span>
            </label>
            <div v-if="install.restrict_to_gpu_nodes">
              <label class="label" for="gpu-nodes">{{ t('ai.gpu.region.gpuNodes') }}</label>
              <input id="gpu-nodes" v-model="install.gpu_nodes" type="text" class="input font-mono" :placeholder="t('ai.gpu.region.gpuNodesPlaceholder')" data-testid="gpu-nodes" />
            </div>
          </div>

          <button class="btn-primary text-xs" :disabled="busy" data-testid="gpu-install" @click="installGpu">
            {{ busy ? '...' : t('ai.gpu.region.install') }}
          </button>
        </div>
      </div>

      <!-- Nodes -->
      <div class="bg-gray-900/40 border border-gray-800 rounded-xl p-4 space-y-3">
        <h4 class="text-sm font-medium text-gray-100">{{ t('ai.gpu.region.nodes') }}</h4>
        <p v-if="!nodes.length" class="text-xs text-gray-400 italic">{{ t('ai.gpu.region.noNodes') }}</p>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-xs" data-testid="gpu-node-table">
            <thead>
              <tr class="text-left text-[10px] text-gray-500 uppercase tracking-wider">
                <th class="py-1.5 pr-3">{{ t('ai.gpu.region.cols.node') }}</th>
                <th class="py-1.5 pr-3">{{ t('ai.gpu.region.cols.product') }}</th>
                <th class="py-1.5 pr-3">{{ t('ai.gpu.region.cols.memory') }}</th>
                <th class="py-1.5 pr-3">{{ t('ai.gpu.region.cols.gpus') }}</th>
                <th class="py-1.5 pr-3">{{ t('ai.gpu.region.cols.replicas') }}</th>
                <th class="py-1.5 pr-3">{{ t('ai.gpu.region.cols.driver') }}</th>
                <th class="py-1.5"><TermText path="ai.gpu.region.cols.plugin" /></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="n in nodes" :key="n.name" class="border-t border-gray-800/60" :data-gpu-node="n.name">
                <td class="py-1.5 pr-3 font-mono text-gray-100">
                  <span class="inline-block w-1.5 h-1.5 rounded-full mr-1.5" :class="n.ready ? 'bg-emerald-400' : 'bg-red-400'" />{{ n.name }}
                </td>
                <td class="py-1.5 pr-3 text-gray-300">{{ n.product || '—' }}</td>
                <td class="py-1.5 pr-3 font-mono text-gray-300">{{ formatGpuMemory(n.memory_mb) }}</td>
                <td class="py-1.5 pr-3 font-mono text-gray-300" :title="t('ai.gpu.region.allocationTitle')">
                  {{ allocatedOn(n) }} / {{ n.allocatable ?? 0 }}
                  <span v-if="n.count && n.count !== n.allocatable" class="text-gray-500">({{ t('ai.gpu.region.physical', { n: n.count }) }})</span>
                </td>
                <td class="py-1.5 pr-3 font-mono text-gray-300">{{ n.replicas && n.replicas > 1 ? `×${n.replicas}` : '—' }}</td>
                <td class="py-1.5 pr-3 font-mono text-gray-400">
                  {{ n.driver_version || '—' }}<template v-if="n.cuda_version"> · CUDA {{ n.cuda_version }}</template>
                  <template v-if="n.compute_capability"> · sm {{ n.compute_capability }}</template>
                </td>
                <td class="py-1.5">
                  <span v-if="n.has_device_plugin" class="text-emerald-400">{{ t('ai.gpu.region.pluginYes') }}</span>
                  <span v-else-if="n.hardware_detected" class="text-amber-400">{{ t('ai.gpu.region.pluginHardwareOnly') }}</span>
                  <span v-else class="text-gray-500">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="gpu?.detected_at" class="text-[10px] text-gray-500">{{ t('ai.gpu.region.detectedAt', { at: new Date(gpu.detected_at).toLocaleString() }) }}</p>
      </div>

      <!-- GPU per project type (sandbox) -->
      <div class="bg-gray-900/40 border border-gray-800 rounded-xl p-4 space-y-2">
        <h4 class="text-sm font-medium text-gray-100">{{ t('ai.gpu.region.sandboxTitle') }}</h4>
        <p class="text-xs text-gray-400">{{ t('ai.gpu.region.sandboxSubtitle') }}</p>
        <div v-for="type in ['xaas', 'faas', 'caas']" :key="type" class="flex items-start gap-3 text-xs" :data-gpu-sandbox="type">
          <span class="w-12 font-mono uppercase text-gray-400">{{ type }}</span>
          <span class="w-24 font-mono text-gray-300">{{ sandbox[type]?.runtime_class || 'runc' }}</span>
          <span class="font-medium" :class="sandboxClass(sandbox[type]?.gpu)">{{ t(`ai.gpu.region.sandboxStates.${sandbox[type]?.gpu ?? 'ok'}`) }}</span>
          <span v-if="sandbox[type]?.message" class="text-gray-400">{{ sandbox[type].message }}</span>
        </div>
      </div>

      <!-- Settings -->
      <div class="bg-gray-900/40 border border-gray-800 rounded-xl p-4 space-y-3" data-testid="gpu-settings">
        <h4 class="text-sm font-medium text-gray-100">{{ t('ai.gpu.region.settings') }}</h4>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label" for="gpu-settings-slicing">{{ t('ai.gpu.region.timeSlicing') }}</label>
            <input id="gpu-settings-slicing" v-model.number="settings.time_slicing_replicas" type="number" min="1" max="16" class="input font-mono" />
          </div>
          <div>
            <label class="label" for="gpu-settings-rc"><TermText path="ai.gpu.region.runtimeClass" /></label>
            <select id="gpu-settings-rc" v-model="settings.runtime_class" class="input">
              <option v-for="rc in ['auto', 'nvidia', 'none']" :key="rc" :value="rc">{{ t(`ai.gpu.region.runtimeClasses.${rc}`) }}</option>
            </select>
          </div>
        </div>
        <label class="flex items-start gap-2 text-xs text-gray-300">
          <input v-model="settings.gvisor_nvproxy" type="checkbox" class="mt-0.5 rounded-sm border-gray-700 bg-gray-800" data-testid="gpu-nvproxy" />
          <span>
            {{ t('ai.gpu.region.nvproxy') }}
            <span class="block text-[11px] text-gray-400">{{ t('ai.gpu.region.nvproxyHint') }}</span>
          </span>
        </label>
        <label class="flex items-start gap-2 text-xs text-gray-300">
          <input v-model="settings.restrict_to_gpu_nodes" type="checkbox" class="mt-0.5 rounded-sm border-gray-700 bg-gray-800" data-testid="gpu-settings-restrict" />
          <span>
            {{ t('ai.gpu.region.restrictToGpuNodes') }}
            <span class="block text-[11px] text-gray-400">{{ t('ai.gpu.region.restrictToGpuNodesHint') }}</span>
          </span>
        </label>
        <div v-if="settings.restrict_to_gpu_nodes">
          <label class="label" for="gpu-settings-nodes">{{ t('ai.gpu.region.gpuNodes') }}</label>
          <input id="gpu-settings-nodes" v-model="settings.gpu_nodes" type="text" class="input font-mono" :placeholder="t('ai.gpu.region.gpuNodesPlaceholder')" data-testid="gpu-settings-nodes" />
        </div>
        <button class="btn-primary text-xs" :disabled="settingsSaving" data-testid="gpu-settings-save" @click="saveSettings">
          {{ settingsSaving ? '...' : t('common.save') }}
        </button>
      </div>

      <!-- Model volume -->
      <div class="bg-gray-900/40 border border-gray-800 rounded-xl p-4 space-y-3" data-testid="model-storage">
        <div class="flex items-center justify-between">
          <h4 class="text-sm font-medium text-gray-100 flex items-center gap-1.5"><HardDrive class="w-4 h-4 text-gray-400" />{{ t('ai.storage.title') }}</h4>
          <span v-if="storageState?.status" class="inline-block px-2 py-0.5 rounded-md border text-xs font-mono"
            :class="statusClass(storageState.status === 'bound' ? 'ready' : storageState.status === 'error' ? 'error' : storageState.status === 'pending' ? 'pending' : 'stopped')">
            {{ t(`ai.storage.statuses.${storageState.status}`) }}
          </span>
        </div>
        <p class="text-xs text-gray-400">{{ t('ai.storage.subtitle', { pvc: storageState?.pvc_name || 'runr-models' }) }}</p>
        <div class="grid grid-cols-3 gap-3">
          <div>
            <label class="label" for="model-storage-class">{{ t('ai.storage.storageClass') }}</label>
            <input id="model-storage-class" v-model="storage.storage_class" class="input font-mono" :placeholder="t('ai.storage.storageClassDefault')" />
          </div>
          <div>
            <label class="label" for="model-storage-size"><TermText path="ai.storage.size" /></label>
            <input id="model-storage-size" v-model.number="storage.size_gi" type="number" min="1" class="input font-mono" />
          </div>
          <div>
            <label class="label" for="model-storage-mode">{{ t('ai.storage.accessMode') }}</label>
            <select id="model-storage-mode" v-model="storage.access_mode" class="input">
              <option value="ReadWriteMany">ReadWriteMany</option>
              <option value="ReadWriteOnce">ReadWriteOnce</option>
            </select>
          </div>
        </div>
        <p class="flex items-start gap-1.5 text-[11px] text-gray-400"><Info class="w-3 h-3 shrink-0 mt-0.5" />{{ t('ai.storage.accessModeHint') }}</p>
        <p v-if="storageState?.message" class="text-[11px] text-gray-400 font-mono">{{ storageState.message }}</p>
        <button class="btn-primary text-xs" :disabled="storageSaving" data-testid="model-storage-save" @click="saveStorage">
          {{ storageSaving ? '...' : t('common.save') }}
        </button>
      </div>
    </template>
  </div>
</template>
