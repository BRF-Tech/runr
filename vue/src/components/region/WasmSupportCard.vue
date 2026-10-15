<script setup>
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfirm } from '@/composables/useConfirm'
import { useWasmSupport } from '@/composables/useWasmSupport'

// WebAssembly support of a region (Sandbox tab): runwasi shims on selected nodes — docs/wasm.md
const { t } = useI18n()
const { confirm } = useConfirm()

const props = defineProps({
  regionId: { type: String, default: null },
  region:   { type: Object, default: null },
})
const emit = defineEmits(['saved'])

const regionIdRef = computed(() => props.regionId)

const {
  status, message, installedShims, availableShims, selectedShims,
  nodes, eligibleNodes, allNodes, selectedNodes,
  canManage, clusterError, knativePending, loading, busy, busyStatus, error, canSubmit,
  initFromRegion, load, toggleShim, toggleNode, install, uninstall,
} = useWasmSupport(regionIdRef, emit, t, confirm)

watch(() => props.region, (r) => initFromRegion(r), { immediate: true })
watch(() => props.regionId, (id) => { if (id) load() }, { immediate: true })

const statusColors = {
  not_installed: 'text-gray-400',
  installing:    'text-yellow-400',
  uninstalling:  'text-yellow-400',
  ready:         'text-emerald-400',
  error:         'text-red-400',
}
const statusDots = {
  not_installed: 'bg-gray-500',
  installing:    'bg-yellow-400 animate-pulse',
  uninstalling:  'bg-yellow-400 animate-pulse',
  ready:         'bg-emerald-400',
  error:         'bg-red-400',
}
const statusKeys = {
  not_installed: 'statusNotInstalled',
  installing:    'statusInstalling',
  uninstalling:  'statusUninstalling',
  ready:         'statusReady',
  error:         'statusError',
}
const statusLabel = computed(() => t(`settings.regionDetail.sandbox.${statusKeys[status.value] || 'statusNotInstalled'}`))

const showForm = computed(() => canManage.value && !busyStatus.value)
</script>

<template>
  <div class="bg-gray-900/60 border border-gray-800 rounded-xl p-4 space-y-3" data-testid="wasm-card">
    <div class="flex items-center justify-between gap-3">
      <div>
        <h4 class="text-sm font-medium text-gray-100">{{ t('wasm.card.title') }}</h4>
        <p class="text-xs text-gray-400 mt-0.5">{{ t('wasm.card.description') }}</p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <template v-if="status === 'ready'">
          <span v-for="s in installedShims" :key="s"
            class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-gray-800 border border-gray-700 text-gray-400">{{ s }}</span>
        </template>
        <span :class="statusDots[status]" class="w-2 h-2 rounded-full shrink-0" />
        <span :class="statusColors[status]" class="text-xs font-medium" data-testid="wasm-status">{{ statusLabel }}</span>
      </div>
    </div>

    <!-- step log: live while it runs, the error at its end, collapsed once ready -->
    <p v-if="status === 'error' && message" class="text-xs text-red-400/80 bg-red-900/20 rounded px-3 py-2 font-mono whitespace-pre-wrap break-words max-h-40 overflow-y-auto" data-testid="wasm-log">{{ message }}</p>
    <p v-else-if="busyStatus && message" class="text-xs text-gray-400 bg-gray-900 rounded px-3 py-2 font-mono whitespace-pre-wrap max-h-32 overflow-y-auto" data-testid="wasm-log">{{ message }}</p>
    <details v-else-if="status === 'ready' && message" class="text-xs text-gray-400">
      <summary class="cursor-pointer select-none hover:text-gray-300">{{ t('settings.regionDetail.sandbox.installLog') }}</summary>
      <p class="mt-2 bg-gray-900 rounded px-3 py-2 font-mono whitespace-pre-wrap break-words max-h-40 overflow-y-auto">{{ message }}</p>
    </details>

    <p v-if="error" class="text-xs text-red-400" data-testid="wasm-error">{{ error }}</p>
    <p v-if="clusterError" class="text-xs text-amber-300/90 bg-amber-900/20 rounded px-3 py-2">{{ t('wasm.card.clusterError', { error: clusterError }) }}</p>
    <p v-if="!canManage && !loading" class="text-[11px] text-gray-400 italic">{{ t('wasm.card.readOnly') }}</p>

    <template v-if="showForm">
      <!-- shims -->
      <div class="space-y-1.5">
        <p class="text-xs text-gray-400 font-medium">{{ t('wasm.card.shims') }}</p>
        <label v-for="s in availableShims" :key="s.id"
          class="flex items-start gap-2 text-xs text-gray-300" :data-testid="`wasm-shim-${s.id}`">
          <input type="checkbox" class="mt-0.5" :checked="selectedShims.includes(s.id)" :disabled="s.id === 'spin'" @change="toggleShim(s.id)" />
          <span>
            <span class="font-mono">{{ t('wasm.card.shimVersion', { name: s.name, version: s.version, runtimeClass: s.runtime_class }) }}</span>
            <span v-if="s.id === 'spin'" class="text-gray-500"> — {{ t('wasm.card.shimRequired') }}</span>
            <span class="block text-[11px] text-gray-500">{{ s.description }}</span>
          </span>
        </label>
      </div>

      <!-- nodes -->
      <div class="space-y-1.5">
        <p class="text-xs text-gray-400 font-medium">{{ t('wasm.card.nodes') }}</p>
        <p v-if="loading && !nodes.length" class="text-[11px] text-gray-500">{{ t('wasm.card.loading') }}</p>
        <p v-else-if="!clusterError && !eligibleNodes.length" class="text-xs text-amber-300/90 bg-amber-900/20 rounded px-3 py-2" data-testid="wasm-no-eligible">{{ t('wasm.card.noEligible') }}</p>
        <template v-else-if="nodes.length">
          <label class="flex items-center gap-2 text-xs text-gray-300" data-testid="wasm-all-nodes">
            <input v-model="allNodes" type="checkbox" />
            {{ t('wasm.card.allNodes', { count: eligibleNodes.length }) }}
          </label>
          <div v-if="!allNodes" class="space-y-1 pl-5">
            <label v-for="n in nodes" :key="n.name"
              class="flex items-center gap-2 text-xs" :class="n.eligible ? 'text-gray-300' : 'text-gray-500'"
              :data-testid="`wasm-node-${n.name}`">
              <input type="checkbox" :disabled="!n.eligible" :checked="selectedNodes.includes(n.name)" @change="toggleNode(n.name)" />
              <span class="font-mono">{{ n.name }}</span>
              <span class="text-[10px] text-gray-500">{{ n.arch }} · {{ n.flavor }}</span>
              <span v-if="!n.eligible" class="text-[10px] text-amber-400/80">{{ t(`wasm.card.reason.${n.reason}`) }}</span>
              <span v-else-if="n.shims && n.shims.length" class="text-[10px] text-emerald-400/80">{{ t('wasm.card.nodeReady', { shims: n.shims.join(', ') }) }}</span>
            </label>
            <p class="text-[11px] text-gray-500">{{ t('wasm.card.selectedNodes', { count: selectedNodes.length }) }}</p>
          </div>
        </template>
      </div>

      <p v-if="knativePending.length" class="text-[11px] text-gray-500">{{ t('wasm.card.knativePending', { features: knativePending.join(', ') }) }}</p>
      <p class="text-[11px] text-amber-400/90">{{ t('wasm.card.installWarning') }}</p>

      <div class="flex gap-2">
        <button class="btn-primary text-xs" :disabled="!canSubmit" data-testid="wasm-install" @click="install">
          {{ t(status === 'ready' ? 'wasm.card.applyBtn' : 'wasm.card.installBtn') }}
        </button>
        <button v-if="status === 'ready' || status === 'error'"
          class="btn-secondary text-xs !text-red-400 hover:!text-red-300" :disabled="busy" data-testid="wasm-uninstall" @click="uninstall">
          {{ t('wasm.card.uninstallBtn') }}
        </button>
      </div>
    </template>
  </div>
</template>
