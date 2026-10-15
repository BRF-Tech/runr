<script setup>
// Vulnerability findings of a report: severity, advisory (linked), package, installed and fixed
// version, title — filterable by severity, text and "fixable only".
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ExternalLink } from 'lucide-vue-next'

const props = defineProps({
  findings: { type: Array, default: () => [] },
  total:    { type: Number, default: null },
})

const { t } = useI18n()

const severity = ref('')
const search   = ref('')
const fixable  = ref(false)

const SEVERITIES = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'UNKNOWN']

const shown = computed(() => {
  const q = search.value.trim().toLowerCase()
  return props.findings.filter(f =>
    (!severity.value || f.severity === severity.value)
    && (!fixable.value || !!f.fixed)
    && (!q || `${f.id} ${f.package} ${f.title ?? ''}`.toLowerCase().includes(q)))
})

const totalCount = computed(() => props.total ?? props.findings.length)

const tone = {
  CRITICAL: 'text-red-300 bg-red-900/40 border-red-800/60',
  HIGH:     'text-orange-300 bg-orange-900/30 border-orange-800/50',
  MEDIUM:   'text-amber-300 bg-amber-900/30 border-amber-800/40',
  LOW:      'text-sky-300 bg-sky-900/30 border-sky-800/40',
  UNKNOWN:  'text-gray-300 bg-gray-800/60 border-gray-700/60',
}

// Only http(s) links from the report are rendered as links
function safeUrl(url) {
  return typeof url === 'string' && /^https:\/\//i.test(url) ? url : null
}
</script>

<template>
  <div class="space-y-2" data-findings>
    <div class="flex flex-wrap items-center gap-2">
      <h4 class="text-xs font-semibold text-gray-200 mr-2">{{ t('supplyChain.findings.title') }}</h4>
      <select v-model="severity" class="input text-xs w-auto py-1" data-filter-severity>
        <option value="">{{ t('supplyChain.findings.all') }}</option>
        <option v-for="s in SEVERITIES" :key="s" :value="s">{{ t(`supplyChain.severity.${s.toLowerCase()}`) }}</option>
      </select>
      <input v-model="search" class="input text-xs w-56 py-1" :placeholder="t('supplyChain.findings.search')" data-filter-search />
      <label class="flex items-center gap-1.5 text-xs text-gray-400">
        <input v-model="fixable" type="checkbox" data-filter-fixable />
        {{ t('supplyChain.findings.fixableOnly') }}
      </label>
      <span class="ml-auto text-[11px] text-gray-500">{{ t('supplyChain.findings.showing', { shown: shown.length, total: totalCount }) }}</span>
    </div>

    <p v-if="totalCount > findings.length" class="text-[11px] text-gray-500">
      {{ t('supplyChain.findings.truncated', { total: totalCount, kept: findings.length }) }}
    </p>

    <p v-if="!findings.length" class="text-xs text-emerald-400 py-3">{{ t('supplyChain.findings.empty') }}</p>
    <p v-else-if="!shown.length" class="text-xs text-gray-400 py-3">{{ t('supplyChain.findings.noMatch') }}</p>

    <div v-else class="overflow-x-auto rounded-lg border border-gray-800">
      <table class="w-full text-xs">
        <thead class="bg-gray-900/80 text-gray-400">
          <tr>
            <th class="text-left font-medium px-3 py-2">{{ t('supplyChain.findings.severity') }}</th>
            <th class="text-left font-medium px-3 py-2">{{ t('supplyChain.findings.id') }}</th>
            <th class="text-left font-medium px-3 py-2">{{ t('supplyChain.findings.package') }}</th>
            <th class="text-left font-medium px-3 py-2">{{ t('supplyChain.findings.installed') }}</th>
            <th class="text-left font-medium px-3 py-2">{{ t('supplyChain.findings.fixed') }}</th>
            <th class="text-left font-medium px-3 py-2">{{ t('supplyChain.findings.titleCol') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="f in shown" :key="`${f.id}|${f.package}|${f.installed}`" class="border-t border-gray-800/80" :data-finding="f.id">
            <td class="px-3 py-1.5">
              <span class="px-1.5 py-0.5 rounded border text-[10px] font-medium" :class="tone[f.severity] ?? tone.UNKNOWN">
                {{ t(`supplyChain.severity.${(f.severity ?? 'UNKNOWN').toLowerCase()}`) }}
              </span>
            </td>
            <td class="px-3 py-1.5 font-mono whitespace-nowrap">
              <a v-if="safeUrl(f.url)" :href="safeUrl(f.url)" target="_blank" rel="noopener noreferrer" class="text-sky-400 hover:text-sky-300 inline-flex items-center gap-1">
                {{ f.id }} <ExternalLink class="w-3 h-3" />
              </a>
              <span v-else>{{ f.id }}</span>
            </td>
            <td class="px-3 py-1.5 font-mono">{{ f.package }}</td>
            <td class="px-3 py-1.5 font-mono text-gray-400">{{ f.installed }}</td>
            <td class="px-3 py-1.5 font-mono" :class="f.fixed ? 'text-emerald-400' : 'text-gray-500'">{{ f.fixed ?? t('supplyChain.findings.notFixed') }}</td>
            <td class="px-3 py-1.5 text-gray-400 max-w-md truncate" :title="f.title ?? ''">{{ f.title ?? '' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
