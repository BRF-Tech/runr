<script setup>
// Model services of the organization: status, engine, device, mode, region and internal URL.
import { useI18n } from 'vue-i18n'
import { Boxes } from 'lucide-vue-next'
import { useAiStore } from '@/stores/ai'
import { AI_STATUS_CLASSES } from '@/utils/ai'

const { t } = useI18n()
const ai = useAiStore()

defineProps({ loading: { type: Boolean, default: false } })
const emit = defineEmits(['select', 'browse'])

const statusClass = (s) => AI_STATUS_CLASSES[s] ?? 'bg-gray-800 text-gray-400 border-gray-700'
</script>

<template>
  <div data-testid="services-list">
    <div v-if="loading && !ai.services.length" class="py-10 text-center text-xs text-gray-400">{{ t('common.loading') }}</div>

    <div v-else-if="!ai.services.length" class="py-12 text-center space-y-3">
      <div class="w-10 h-10 rounded-xl bg-gray-900 border border-gray-800 flex items-center justify-center mx-auto"><Boxes class="w-5 h-5 text-gray-400" /></div>
      <p class="text-xs text-gray-400">{{ t('ai.services.empty') }}</p>
      <button class="btn-secondary text-xs" @click="emit('browse')">{{ t('ai.services.browseHub') }}</button>
    </div>

    <div v-else class="overflow-x-auto rounded-xl border border-gray-800">
      <table class="w-full text-xs">
        <thead class="bg-gray-900/80">
          <tr class="text-left text-[10px] text-gray-500 uppercase tracking-wider">
            <th class="px-3 py-2">{{ t('ai.services.cols.name') }}</th>
            <th class="px-3 py-2">{{ t('ai.services.cols.model') }}</th>
            <th class="px-3 py-2">{{ t('ai.services.cols.engine') }}</th>
            <th class="px-3 py-2">{{ t('ai.services.cols.mode') }}</th>
            <th class="px-3 py-2">{{ t('ai.services.cols.region') }}</th>
            <th class="px-3 py-2">{{ t('ai.services.cols.status') }}</th>
            <th class="px-3 py-2">{{ t('ai.services.cols.url') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in ai.services" :key="s.id" class="border-t border-gray-800/60 hover:bg-gray-900/60 cursor-pointer"
            :data-service-row="s.name" @click="emit('select', s)">
            <td class="px-3 py-2 font-mono text-gray-100">{{ s.name }}</td>
            <td class="px-3 py-2 text-gray-300 font-mono truncate max-w-56">{{ s.model?.hub_id ?? s.model?.name }}</td>
            <td class="px-3 py-2 text-gray-300">
              {{ t(`ai.engines.${s.engine}`, s.engine) }}
              <span class="ml-1 px-1 rounded-sm font-mono text-[10px]" :class="s.device === 'gpu' ? 'bg-amber-900/40 text-amber-300' : 'bg-gray-800 text-gray-400'">{{ (s.device ?? '').toUpperCase() }}</span>
            </td>
            <td class="px-3 py-2 text-gray-300">{{ t(`ai.modes.${s.mode}`, s.mode) }}</td>
            <td class="px-3 py-2 text-gray-400">{{ s.region_name }}</td>
            <td class="px-3 py-2">
              <span class="inline-block px-2 py-0.5 rounded-md border text-[11px] font-mono" :class="statusClass(s.status)">{{ t(`ai.services.statuses.${s.status}`, s.status) }}</span>
              <span v-if="s.status_reason" class="ml-1 inline-block px-2 py-0.5 rounded-md border text-[11px] font-mono" :class="statusClass('failed')"
                :title="s.status_message || t('ai.services.statusReason')" :data-service-reason="s.name">{{ s.status_reason }}</span>
            </td>
            <td class="px-3 py-2 font-mono text-gray-500 truncate max-w-[16rem]">{{ s.url || '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
