<script setup>
// The final report of an export or import (docs/org-export.md): an export lists its entries,
// size, what it left out and its warnings; an import counts what it created, found, skipped or
// could not do, lists every item and the manual steps that remain (DNS, secrets to enter again).
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { X } from 'lucide-vue-next'
import { formatBytes } from '@/utils/ai'

const { t } = useI18n()

const props = defineProps({
  transfer: { type: Object, required: true },
})
const emit = defineEmits(['close'])

const report = computed(() => props.transfer.report ?? {})
const isImport = computed(() => props.transfer.kind === 'import')
const counts = computed(() => report.value.counts ?? {})

const statusClass = (s) => ({
  created: 'text-emerald-400', exists: 'text-gray-400', skipped: 'text-gray-500', failed: 'text-red-400', manual: 'text-sky-300',
}[s] ?? 'text-gray-300')
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="transfer-report-dialog" :data-transfer="transfer.id">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="emit('close')" />
    <div class="relative w-full max-w-3xl card space-y-4 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-100">{{ isImport ? t('orgTransfer.import.report') : t('orgTransfer.report.title') }}</h2>
        <button type="button" class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800" :aria-label="t('orgTransfer.report.close')" data-testid="transfer-report-close" @click="emit('close')"><X class="w-4 h-4" /></button>
      </div>

      <template v-if="isImport">
        <p class="text-xs text-gray-300" data-testid="report-counts">
          {{ t('orgTransfer.import.counts', { created: counts.created ?? 0, exists: counts.exists ?? 0, skipped: counts.skipped ?? 0, failed: counts.failed ?? 0, manual: counts.manual ?? 0 }) }}
        </p>
        <div v-if="report.items?.length" class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead>
              <tr class="text-left text-gray-500 border-b border-gray-800">
                <th class="py-1.5 pr-3 font-medium">{{ t('orgTransfer.import.itemSection') }}</th>
                <th class="py-1.5 pr-3 font-medium">{{ t('orgTransfer.import.itemName') }}</th>
                <th class="py-1.5 pr-3 font-medium">{{ t('orgTransfer.import.itemStatus') }}</th>
                <th class="py-1.5 font-medium">{{ t('orgTransfer.import.itemMessage') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, i) in report.items" :key="i" class="border-b border-gray-800/60" :data-item-section="item.section" :data-item-status="item.status">
                <td class="py-1.5 pr-3 text-gray-400">{{ item.section }}</td>
                <td class="py-1.5 pr-3 font-mono text-gray-200">{{ item.name ?? item.source }}</td>
                <td class="py-1.5 pr-3" :class="statusClass(item.status)">{{ t(`orgTransfer.import.itemStatusValue.${item.status}`) }}</td>
                <td class="py-1.5 text-gray-500">{{ item.message ?? '' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="report.manual_steps?.length" class="rounded-lg border border-sky-900/60 bg-sky-950/10 p-3" data-testid="report-manual-steps">
          <p class="text-xs font-medium text-sky-300 mb-1">{{ t('orgTransfer.import.manualSteps') }}</p>
          <div v-for="step in report.manual_steps" :key="step.code" class="text-xs text-gray-300 mb-1">
            <p>{{ step.message }}</p>
            <p v-if="step.items?.length" class="font-mono text-gray-500">{{ step.items.join(', ') }}</p>
          </div>
        </div>
      </template>

      <template v-else>
        <p class="text-xs text-gray-300" data-testid="report-entries">
          {{ t('orgTransfer.report.entries', { entries: report.entries ?? 0, size: formatBytes(report.bytes ?? transfer.package?.size_bytes) }) }}
        </p>
        <div v-if="report.skipped?.length" data-testid="report-skipped">
          <p class="text-xs font-medium text-gray-300 mb-1">{{ t('orgTransfer.report.skipped') }}</p>
          <p v-for="(s, i) in report.skipped" :key="i" class="text-xs text-gray-400">{{ s.section }} · <span class="font-mono">{{ s.name }}</span> — {{ s.message }}</p>
        </div>
      </template>

      <div v-if="report.warnings?.length" data-testid="report-warnings">
        <p class="text-xs font-medium text-amber-300 mb-1">{{ t('orgTransfer.report.warnings') }}</p>
        <p v-for="(w, i) in report.warnings" :key="i" class="text-xs text-amber-400/90">{{ w }}</p>
      </div>
    </div>
  </div>
</template>
