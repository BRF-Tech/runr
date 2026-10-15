<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { sandboxAdminApi } from '../../api/sandboxes'
import { apiError, formatMs, histogramBars } from '../../utils'

defineProps({ regions: { type: Array, default: () => [] } })

const { t } = useI18n()

const WINDOWS = ['1h', '24h', '7d', '30d']
const windowKey = ref('24h')
const regionId  = ref('')
const metrics   = ref(null)
const error     = ref(null)

async function load() {
  try {
    const params = { window: windowKey.value }
    if (regionId.value) params.region = regionId.value
    const { data } = await sandboxAdminApi.metrics(params)
    metrics.value = data?.data ?? null
    error.value   = null
  } catch (e) {
    error.value = apiError(e, t('sandboxes.settings.metrics.loadFailed'))
  }
}

watch([windowKey, regionId], load)
onMounted(load)

const hitRate = computed(() => {
  const rate = metrics.value?.pool?.hit_rate
  return rate === null || rate === undefined ? '—' : `${Math.round(rate * 100)} %`
})

const histograms = computed(() => {
  const st = metrics.value?.start_time ?? {}
  return [
    { key: 'pool_hit', label: t('sandboxes.settings.metrics.poolHit'), data: st.pool_hit, color: '#10b981' },
    { key: 'cold',     label: t('sandboxes.settings.metrics.cold'),    data: st.cold,     color: 'rgb(var(--brand-500))' },
  ].map(h => {
    const bars = histogramBars(h.data)
    const max  = Math.max(1, ...bars.map(b => b.count))
    const prev = (i) => bars[i - 1]?.le_ms
    return {
      ...h,
      count: h.data?.count ?? 0,
      avg:   h.data?.count ? Math.round((h.data.sum_ms ?? 0) / h.data.count) : null,
      bars:  bars.map((b, i) => ({
        ...b,
        width: Math.round(b.count / max * 100),
        label: b.le_ms === null
          ? t('sandboxes.settings.metrics.overflow', { value: formatMs(prev(i) ?? 0) })
          : t('sandboxes.settings.metrics.le', { value: formatMs(b.le_ms) }),
      })),
    }
  })
})
</script>

<template>
  <section data-section="metrics">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
      <div>
        <h4 class="text-sm font-semibold text-gray-100">{{ t('sandboxes.settings.metrics.title') }}</h4>
        <p class="text-xs text-gray-400">{{ t('sandboxes.settings.metrics.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <select v-model="regionId" class="input h-8! w-40! text-xs" name="metrics_region">
          <option value="">{{ t('sandboxes.filters.allRegions') }}</option>
          <option v-for="r in regions" :key="r.region.id" :value="r.region.id">{{ r.region.name }}</option>
        </select>
        <select v-model="windowKey" class="input h-8! w-40! text-xs" name="metrics_window" :title="t('sandboxes.settings.metrics.window')">
          <option v-for="w in WINDOWS" :key="w" :value="w">{{ t(`sandboxes.settings.metrics.window_${w}`) }}</option>
        </select>
      </div>
    </div>

    <p v-if="error" class="text-sm text-red-400 mb-3">{{ error }}</p>

    <template v-if="metrics">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <div class="rounded-xl border border-gray-800 bg-gray-900/60 px-4 py-3">
          <p class="text-[10px] uppercase tracking-wider text-gray-500">{{ t('sandboxes.settings.metrics.active') }}</p>
          <p class="text-lg font-mono text-gray-100" data-metric="active">{{ metrics.active ?? 0 }}</p>
        </div>
        <div class="rounded-xl border border-gray-800 bg-gray-900/60 px-4 py-3">
          <p class="text-[10px] uppercase tracking-wider text-gray-500">{{ t('sandboxes.settings.metrics.hits') }}</p>
          <p class="text-lg font-mono text-emerald-400" data-metric="hits">{{ metrics.pool?.hits ?? 0 }}</p>
        </div>
        <div class="rounded-xl border border-gray-800 bg-gray-900/60 px-4 py-3">
          <p class="text-[10px] uppercase tracking-wider text-gray-500">{{ t('sandboxes.settings.metrics.misses') }}</p>
          <p class="text-lg font-mono text-amber-400" data-metric="misses">{{ metrics.pool?.misses ?? 0 }}</p>
        </div>
        <div class="rounded-xl border border-gray-800 bg-gray-900/60 px-4 py-3">
          <p class="text-[10px] uppercase tracking-wider text-gray-500">{{ t('sandboxes.settings.metrics.hitRate') }}</p>
          <p class="text-lg font-mono text-gray-100" data-metric="hit-rate">{{ hitRate }}</p>
        </div>
      </div>

      <div class="grid md:grid-cols-2 gap-4">
        <div v-for="h in histograms" :key="h.key" class="rounded-xl border border-gray-800 bg-gray-900/60 p-4" :data-histogram="h.key">
          <div class="flex items-baseline justify-between mb-2">
            <p class="text-xs font-semibold text-gray-300">{{ h.label }}</p>
            <p class="text-[11px] font-mono text-gray-500">
              {{ t('sandboxes.settings.metrics.count', { n: h.count }) }}<template v-if="h.avg !== null"> · {{ t('sandboxes.settings.metrics.avg', { ms: h.avg }) }}</template>
            </p>
          </div>
          <p v-if="!h.count" class="text-xs text-gray-500">{{ t('sandboxes.settings.metrics.noData') }}</p>
          <div v-else class="space-y-1">
            <div v-for="b in h.bars" :key="String(b.le_ms)" class="flex items-center gap-2 text-[11px] font-mono">
              <span class="w-20 text-right text-gray-500 shrink-0">{{ b.label }}</span>
              <div class="flex-1 h-3 rounded-sm bg-gray-800 overflow-hidden">
                <div class="h-full rounded-sm" :style="{ width: b.width + '%', background: h.color }" />
              </div>
              <span class="w-10 text-gray-400">{{ b.count }}</span>
            </div>
          </div>
        </div>
      </div>
    </template>
  </section>
</template>
