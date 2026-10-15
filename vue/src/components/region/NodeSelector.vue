<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { fmtCpu, fmtMem } from '@/utils/formatters'

const { t } = useI18n()

const props = defineProps({
  nodes:         { type: Array, default: () => [] },
  selectedNodes: { type: Array, default: () => [] },
  nodesLoading:  { type: Boolean, default: false },
  readonly:      { type: Boolean, default: false },
})
defineEmits(['toggle', 'refresh'])

// What a node leaves for projects: allocatable minus the requests of the platform pods on it
// (the API's available_*; older responses / demo nodes carry allocatable only)
const availCpu = n => n.available_cpu_m ?? n.cpu_m
const availMem = n => n.available_memory_mb ?? n.memory_mb
const nodeTitle = n => t('settings.regionDetail.workers.nodeCapacity', {
  cpu: fmtCpu(n.cpu_m), memory: fmtMem(n.memory_mb),
})

const workerResources = computed(() => {
  const selected = props.nodes.filter(n => props.selectedNodes.includes(n.name))
  return {
    cpu_m:     selected.reduce((s, n) => s + availCpu(n), 0),
    memory_mb: selected.reduce((s, n) => s + availMem(n), 0),
  }
})
</script>

<template>
  <!-- Readonly summary -->
  <template v-if="readonly">
    <div>
      <p class="text-sm font-medium text-gray-100">{{ t('settings.regionDetail.workers.title') }}</p>
      <p v-if="selectedNodes.length === 0" class="text-xs text-gray-400 italic mt-1">{{ t('settings.regionDetail.workers.skippedLabel') }}</p>
      <div v-else class="space-y-1 mt-2">
        <div v-for="node in nodes.filter(n => selectedNodes.includes(n.name))" :key="node.name"
          class="flex items-center gap-3 rounded-md border border-gray-800 bg-gray-900/40 px-3 py-2 text-xs opacity-70">
          <div class="flex-1 min-w-0">
            <span class="font-mono text-gray-100">{{ node.name }}</span>
            <span class="ml-2 text-[10px]" :class="node.status === 'Ready' ? 'text-emerald-500' : 'text-red-400'">{{ node.status }}</span>
          </div>
          <span class="text-gray-400 font-mono" :title="nodeTitle(node)">{{ fmtCpu(availCpu(node)) }}</span>
          <span class="text-gray-400 font-mono" :title="nodeTitle(node)">{{ fmtMem(availMem(node)) }}</span>
        </div>
      </div>
    </div>
  </template>

  <!-- Interactive -->
  <template v-else>
    <div class="flex items-center justify-between">
      <div>
        <p class="text-sm font-medium text-gray-100">{{ t('settings.regionDetail.workers.title') }}</p>
        <p class="text-xs text-gray-400">{{ t('settings.regionDetail.workers.subtitle') }}</p>
      </div>
      <button type="button" class="text-xs text-brand-400 hover:text-brand-300 underline underline-offset-2"
        :disabled="nodesLoading" @click="$emit('refresh')">{{ t('settings.regionDetail.workers.refreshNodes') }}</button>
    </div>
    <div v-if="nodesLoading" class="py-6 text-center text-gray-400 text-xs">{{ t('common.loading') }}</div>
    <div v-else-if="nodes.length === 0" class="py-6 text-center text-gray-400 text-xs italic">{{ t('settings.regionDetail.workers.noNodes') }}</div>
    <div v-else class="space-y-1">
      <div v-for="node in nodes" :key="node.name"
        class="flex items-center gap-3 rounded-md border px-3 py-2 text-xs cursor-pointer transition-colors"
        :class="selectedNodes.includes(node.name) ? 'border-brand-600/50 bg-brand-900/20' : 'border-gray-800 bg-gray-900/40 hover:border-gray-700'"
        @click="$emit('toggle', node.name)">
        <input type="checkbox" :checked="selectedNodes.includes(node.name)" class="rounded-sm"
          @click.stop @change="$emit('toggle', node.name)" />
        <div class="flex-1 min-w-0">
          <span class="font-mono text-gray-100">{{ node.name }}</span>
          <span class="ml-2 text-[10px]" :class="node.status === 'Ready' ? 'text-emerald-500' : 'text-red-400'">{{ node.status }}</span>
        </div>
        <span class="text-gray-400 font-mono" :title="nodeTitle(node)">{{ fmtCpu(availCpu(node)) }}</span>
        <span class="text-gray-400 font-mono" :title="nodeTitle(node)">{{ fmtMem(availMem(node)) }}</span>
      </div>
    </div>
    <div v-if="selectedNodes.length > 0" class="bg-gray-900/60 rounded-md px-3 py-2">
      <p class="text-[10px] text-gray-400 uppercase tracking-wider mb-1">{{ t('settings.regionDetail.workers.totalComputed') }}</p>
      <p class="text-sm font-mono text-gray-100">{{ fmtCpu(workerResources.cpu_m) }} <span class="text-gray-400 mx-1">·</span> {{ fmtMem(workerResources.memory_mb) }}</p>
    </div>
    <p class="text-[10px] text-orange-400/70 leading-relaxed">{{ t('settings.regionDetail.workers.taintWarning') }}</p>
  </template>
</template>
