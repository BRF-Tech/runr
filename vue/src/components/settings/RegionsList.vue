<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { moduleRegistry } from '@/modules/index'
import { usePermissionsStore } from '@/stores/permissions'
import TermText from '@/components/TermText.vue'

defineProps({
  regions: { type: Array, required: true },
  loading: { type: Boolean, default: false },
})

const isCentralAdmin = computed(() => moduleRegistry.isCentralAdminFn?.() ?? false)
// Region changes need regions.manage (docs/permissions.md); everyone else sees the list
const perms = usePermissionsStore()
const canManage = computed(() => perms.can('regions.manage'))

const emit = defineEmits(['edit', 'delete', 'download-kubeconfig', 'add'])

const { t } = useI18n()

function componentTagClass(status) {
  const base = 'inline-flex items-center gap-1 px-1.5 py-0.5 rounded-sm text-[10px] font-medium'
  switch (status) {
    case 'ready':         return `${base} bg-emerald-900/40 text-emerald-400`
    case 'installing':    return `${base} bg-yellow-900/40 text-yellow-400 animate-pulse`
    case 'uninstalling':  return `${base} bg-orange-900/40 text-orange-400 animate-pulse`
    case 'error':         return `${base} bg-red-900/40 text-red-400`
    case 'not_installed': return `${base} bg-gray-800/40 text-gray-400`
    default:              return `${base} bg-gray-800/40 text-gray-400`
  }
}

function clusterDotClass(status) {
  const base = 'w-2 h-2 rounded-full shrink-0'
  switch (status) {
    case 'online':      return `${base} bg-emerald-400`
    case 'unreachable': return `${base} bg-red-400 animate-pulse`
    default:            return `${base} bg-gray-600`
  }
}

function clusterTooltip(r) {
  switch (r.cluster_status) {
    case 'online':      return t('settings.regions.clusterOnline')
    case 'unreachable': return r.cluster_status_message || t('settings.regions.clusterUnreachable')
    default:            return t('settings.regions.clusterUnknown')
  }
}

function isClusterDown(r) {
  return !r.is_synced && !r.is_demo && r.cluster_status === 'unreachable'
}

function fmtTimeAgo(iso) {
  if (!iso) return ''
  const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
  if (diff < 60)    return t('settings.regions.timeAgo.seconds', { n: diff })
  if (diff < 3600)  return t('settings.regions.timeAgo.minutes', { n: Math.floor(diff / 60) })
  if (diff < 86400) return t('settings.regions.timeAgo.hours', { n: Math.floor(diff / 3600) })
  return t('settings.regions.timeAgo.days', { n: Math.floor(diff / 86400) })
}
</script>

<template>
  <div data-tour="settings-regions">
    <p class="text-xs text-gray-400 uppercase tracking-wider font-medium mb-3">
      <TermText path="settings.regions.section" />
    </p>

    <p v-if="regions.length === 0" class="text-xs text-gray-400 italic mb-3">
      {{ t('settings.regions.noRegions') }}
    </p>

    <div class="space-y-2">
      <div
        v-for="r in regions"
        :key="r.id"
        class="flex items-center gap-2 rounded-md border border-gray-800 bg-gray-900/40 px-3 py-2 text-xs"
        :data-region-row="r.name"
      >
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <!-- Synced regions: minimal display -->
            <template v-if="r.is_synced">
              <span class="font-medium text-gray-100 font-mono">{{ r.name }}</span>
              <span class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium bg-indigo-900/40 text-indigo-400">
                {{ t('settings.regions.syncedBadge') }}
              </span>
            </template>
            <!-- Own regions: full display -->
            <template v-else>
            <!-- Cluster status dot (skip demo regions) -->
            <div v-if="!r.is_demo" class="relative group/dot shrink-0">
              <span :class="clusterDotClass(r.cluster_status)" />
              <div class="hidden group-hover/dot:block absolute left-1/2 -translate-x-1/2 bottom-full mb-2 z-20">
                <div class="bg-gray-900 border border-gray-700 rounded-md px-3 py-1.5 text-[11px] text-gray-400 whitespace-nowrap shadow-lg">
                  {{ clusterTooltip(r) }}
                </div>
                <div class="absolute left-1/2 -translate-x-1/2 -bottom-1 w-2 h-2 bg-gray-900 border-r border-b border-gray-700 rotate-45" />
              </div>
            </div>
            <span class="font-medium text-gray-100 font-mono">{{ r.name }}</span>
            <span v-if="r.is_demo" class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium" style="background:rgb(var(--brand-500)/0.15);color:rgb(var(--brand-500))">
              {{ t('settings.regions.demoBadge') }}
            </span>
            <span v-if="isCentralAdmin && r.region_mode === 'infinite'" class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium font-mono bg-violet-900/40 text-violet-400">
              {{ t('tenancy.regionMode_infinite') }}
            </span>
            <span v-if="isCentralAdmin && r.region_mode !== 'infinite' && !r.is_demo" class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium font-mono bg-sky-900/40 text-sky-400">
              {{ t('tenancy.regionMode_managed') }}
            </span>
            <span v-if="r.region_status === 'inactive'" class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium bg-gray-800/40 text-gray-400">
              {{ t('settings.regions.status_inactive') }}
            </span>
            <span v-else-if="r.region_status === 'maintenance'" class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium bg-amber-900/40 text-amber-400 animate-pulse">
              {{ t('settings.regions.status_maintenance') }}
            </span>
            <!-- Cluster down: show warning instead of component tags -->
            <template v-if="isClusterDown(r)">
              <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-sm text-[10px] font-medium bg-red-900/40 text-red-400">
                {{ t('settings.regions.clusterDown') }}
              </span>
            </template>
            <!-- Component status tags (when cluster is reachable or demo) -->
            <template v-else>
              <span :class="componentTagClass(r.knative_operator_status)">
                {{ t('settings.regionDetail.component.operator') }}
                <svg v-if="r.knative_operator_status === 'ready'" class="w-2.5 h-2.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                <svg v-else-if="r.knative_operator_status === 'error'" class="w-2.5 h-2.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                <span v-else-if="r.knative_operator_status === 'installing' || r.knative_operator_status === 'uninstalling'" class="w-2.5 h-2.5 border border-current border-t-transparent rounded-full animate-spin inline-block" />
                <span v-else class="text-[8px]">—</span>
              </span>
              <span :class="componentTagClass(r.knative_serving_status)">
                {{ t('settings.regionDetail.component.serving') }}
                <svg v-if="r.knative_serving_status === 'ready'" class="w-2.5 h-2.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                <svg v-else-if="r.knative_serving_status === 'error'" class="w-2.5 h-2.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                <span v-else-if="r.knative_serving_status === 'installing' || r.knative_serving_status === 'uninstalling'" class="w-2.5 h-2.5 border border-current border-t-transparent rounded-full animate-spin inline-block" />
                <span v-else class="text-[8px]">—</span>
              </span>
              <span :class="componentTagClass(r.knative_redis_status || 'not_installed')">
                {{ t('settings.regionDetail.component.redis') }}
                <svg v-if="r.knative_redis_status === 'ready'" class="w-2.5 h-2.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                <svg v-else-if="r.knative_redis_status === 'error'" class="w-2.5 h-2.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                <span v-else-if="r.knative_redis_status === 'installing' || r.knative_redis_status === 'uninstalling'" class="w-2.5 h-2.5 border border-current border-t-transparent rounded-full animate-spin inline-block" />
                <span v-else class="text-[8px]">—</span>
              </span>
            </template>
            </template>
          </div>
          <div v-if="!r.is_synced" class="text-gray-400 font-mono mt-0.5">
            <template v-if="r.unlimited || r.region_mode === 'infinite'">
              {{ r.used_cpu_m }}m / ∞
              <span class="mx-1 text-gray-800">·</span>
              {{ r.used_memory_mb }} MB / ∞
            </template>
            <template v-else>
              {{ r.used_cpu_m }}m / {{ r.total_cpu_m }}m
              <span class="mx-1 text-gray-800">·</span>
              {{ r.used_memory_mb }} MB / {{ r.total_memory_mb }} MB
            </template>
            <span v-if="r.worker_count > 0" class="ml-1 text-gray-400">
              · {{ r.worker_count }} worker
            </span>
            <span v-if="r.knative_status_checked_at" class="ml-1 text-gray-400">
              · {{ fmtTimeAgo(r.knative_status_checked_at) }}
            </span>
          </div>
        </div>
        <!-- Synced (assigned) regions: only show edit for demo seed -->
        <template v-if="r.is_synced">
          <button
            type="button"
            class="text-gray-400 hover:text-gray-100 transition-colors shrink-0"
            :title="t('settings.regions.demoBadge')"
            @click="emit('edit', r)"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v3.586L7.707 9.293a1 1 0 00-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 10.586V7z" clip-rule="evenodd" />
            </svg>
          </button>
        </template>
        <!-- Own regions: full controls -->
        <template v-else-if="canManage">
          <!-- Download kubeconfig -->
          <button
            v-if="r.has_kubeconfig"
            type="button"
            class="text-gray-400 hover:text-emerald-400 transition-colors shrink-0"
            :title="t('settings.regions.kubeconfigDownload')"
            @click="emit('download-kubeconfig', r)"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
          </button>
          <!-- Edit (hidden when cluster is down) -->
          <button
            v-if="!isClusterDown(r)"
            type="button"
            class="text-gray-400 hover:text-gray-100 transition-colors shrink-0"
            :title="t('common.edit')"
            :aria-label="t('common.edit')"
            @click="emit('edit', r)"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
              <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
            </svg>
          </button>
          <!-- Delete -->
          <div class="relative shrink-0 group/del">
            <button
              type="button"
              :class="r.project_count > 0
                ? 'text-gray-800 cursor-not-allowed'
                : 'text-gray-400 hover:text-red-400 transition-colors'"
              :disabled="r.project_count > 0"
              :aria-label="t('common.delete')"
              @click="r.project_count > 0 || emit('delete', r)"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
              </svg>
            </button>
            <div
              v-if="r.project_count > 0"
              class="hidden group-hover/del:block absolute right-0 bottom-full mb-2 z-20"
            >
              <div class="bg-gray-900 border border-gray-700 rounded-md px-3 py-1.5 text-[11px] text-gray-400 whitespace-nowrap shadow-lg">
                {{ t('settings.regions.hasProjects', { n: r.project_count }) }}
              </div>
              <div class="absolute right-1.5 -bottom-1 w-2 h-2 bg-gray-900 border-r border-b border-gray-700 rotate-45" />
            </div>
          </div>
        </template>
      </div>
    </div>

    <button
      v-if="canManage"
      data-tour="settings-add-region"
      type="button"
      class="mt-3 w-full text-xs border border-dashed rounded-md py-2 transition-colors" style="color:rgb(var(--brand-500));border-color:rgb(var(--brand-500)/0.4)"
      @click="emit('add')"
    >
      {{ t('settings.regions.addBtn') }}
    </button>
  </div>
</template>
