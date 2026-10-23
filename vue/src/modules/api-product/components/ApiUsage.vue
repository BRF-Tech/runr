<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { apiProductApi } from '../api/apiProduct'
import { bytes, latency, rangeParams, refused, successRate } from '../utils/format'

/**
 * Usage of the gateway's API (UTC): calls let through and refused, and what the functions
 * answered to the calls let through (errors, latency percentiles, bytes), per period or per
 * consumer / key / route. What an invoicing system bills from (also GET /api/api-usage and the
 * api.usage.* events).
 */
const props = defineProps({ gateway: { type: Object, required: true } })
const { t } = useI18n()
const toast = useToast()

const RANGES = ['last7', 'last30', 'thisMonth', 'lastMonth']
const GROUPS = ['day', 'hour', 'month', 'consumer', 'key', 'route']
const range = ref('last7')
const groupBy = ref('day')
const usage = ref(null)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const { data } = await apiProductApi.usage({ gateway_id: props.gateway.id, group_by: groupBy.value, ...rangeParams(range.value) })
    usage.value = data
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('apiProduct.common.error'))
  } finally {
    loading.value = false
  }
}

function label(row) {
  switch (usage.value?.group_by) {
    case 'consumer':
      if (row.consumer) return row.consumer.external_id ? `${row.consumer.name} (${row.consumer.external_id})` : row.consumer.name
      if (row.subject) return t('apiProduct.usage.subject', { subject: row.subject })
      return row.consumer_id ? t('apiProduct.usage.deleted') : t('apiProduct.usage.noConsumer')
    case 'key':
      return row.key ? `${row.key.name} ${row.key.prefix}…` : (row.key_id ?? t('apiProduct.usage.noKey'))
    case 'route':
      return row.path ?? '—'
    default:
      return row.period
  }
}

const totals = computed(() => usage.value?.totals ?? null)
// the answers columns once any call of the range was measured (gateway-auth's metering proxy)
const measured = computed(() => (totals.value?.responses ?? 0) > 0)
const n = (v) => Number(v ?? 0).toLocaleString()

function statusTitle(row) {
  return t('apiProduct.usage.statusTitle', {
    ok: n(row.status_2xx), redirect: n(row.status_3xx), client: n(row.status_4xx), server: n(row.status_5xx), none: n(row.upstream_errors),
    in: bytes(row.bytes_in), out: bytes(row.bytes_out),
  })
}

watch([range, groupBy, () => props.gateway.id], load)
onMounted(load)
</script>

<template>
  <div class="space-y-4" data-test="api-usage">
    <div class="flex flex-wrap items-end gap-3">
      <div>
        <label class="label">{{ t('apiProduct.usage.range') }}</label>
        <select v-model="range" class="input w-44!" data-test="usage-range">
          <option v-for="r in RANGES" :key="r" :value="r">{{ t(`apiProduct.usage.ranges.${r}`) }}</option>
        </select>
      </div>
      <div>
        <label class="label">{{ t('apiProduct.usage.groupBy') }}</label>
        <select v-model="groupBy" class="input w-44!" data-test="usage-group">
          <option v-for="g in GROUPS" :key="g" :value="g">{{ t(`apiProduct.usage.groups.${g}`) }}</option>
        </select>
      </div>
    </div>

    <div v-if="totals" class="grid gap-3 grid-cols-2 md:grid-cols-4" data-test="usage-totals">
      <div class="bg-gray-900 border border-gray-800 rounded-md p-3">
        <p class="text-[11px] text-gray-500">{{ t('apiProduct.usage.requests') }}</p>
        <p class="text-lg text-gray-100" data-test="usage-requests">{{ n(totals.requests) }}</p>
      </div>
      <div class="bg-gray-900 border border-gray-800 rounded-md p-3">
        <p class="text-[11px] text-gray-500">{{ t('apiProduct.usage.refused') }}</p>
        <p class="text-lg text-gray-100">{{ n(refused(totals)) }}</p>
      </div>
      <div class="bg-gray-900 border border-gray-800 rounded-md p-3">
        <p class="text-[11px] text-gray-500">{{ t('apiProduct.usage.rateLimited') }}</p>
        <p class="text-lg text-gray-100">{{ n(totals.rate_limited) }}</p>
      </div>
      <div class="bg-gray-900 border border-gray-800 rounded-md p-3">
        <p class="text-[11px] text-gray-500">{{ t('apiProduct.usage.quotaExceeded') }}</p>
        <p class="text-lg text-gray-100">{{ n(totals.quota_exceeded) }}</p>
      </div>
    </div>

    <div v-if="totals && measured" class="grid gap-3 grid-cols-2 md:grid-cols-4" data-test="usage-answers">
      <div class="bg-gray-900 border border-gray-800 rounded-md p-3" :title="statusTitle(totals)">
        <p class="text-[11px] text-gray-500">{{ t('apiProduct.usage.successRate') }}</p>
        <p class="text-lg text-gray-100" data-test="usage-success">{{ successRate(totals) }}</p>
      </div>
      <div class="bg-gray-900 border border-gray-800 rounded-md p-3">
        <p class="text-[11px] text-gray-500">{{ t('apiProduct.usage.errors') }}</p>
        <p class="text-lg text-gray-100" data-test="usage-errors">{{ n(totals.errors) }}</p>
      </div>
      <div class="bg-gray-900 border border-gray-800 rounded-md p-3">
        <p class="text-[11px] text-gray-500">{{ t('apiProduct.usage.latency') }}</p>
        <p class="text-lg text-gray-100" data-test="usage-latency">{{ latency(totals.latency_p50_ms) }} · {{ latency(totals.latency_p95_ms) }} · {{ latency(totals.latency_p99_ms) }}</p>
      </div>
      <div class="bg-gray-900 border border-gray-800 rounded-md p-3">
        <p class="text-[11px] text-gray-500">{{ t('apiProduct.usage.traffic') }}</p>
        <p class="text-lg text-gray-100" data-test="usage-bytes">{{ bytes(totals.bytes_in) }} / {{ bytes(totals.bytes_out) }}</p>
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-6"><div class="animate-spin h-5 w-5 border-2 border-sky-500 border-t-transparent rounded-full" /></div>
    <p v-else-if="usage && !usage.data.length" class="text-xs text-gray-500">{{ t('apiProduct.usage.none') }}</p>
    <div v-else-if="usage" class="overflow-x-auto">
      <table class="w-full text-xs" data-test="usage-table">
        <thead class="text-gray-500 text-left">
          <tr>
            <th class="py-1 font-medium">{{ t(`apiProduct.usage.groups.${usage.group_by}`) }}</th>
            <th class="py-1 font-medium text-right">{{ t('apiProduct.usage.requests') }}</th>
            <th class="py-1 font-medium text-right">{{ t('apiProduct.usage.unauthorized') }}</th>
            <th class="py-1 font-medium text-right">{{ t('apiProduct.usage.forbidden') }}</th>
            <th class="py-1 font-medium text-right">{{ t('apiProduct.usage.rateLimited') }}</th>
            <th class="py-1 font-medium text-right">{{ t('apiProduct.usage.quotaExceeded') }}</th>
            <template v-if="measured">
              <th class="py-1 font-medium text-right">{{ t('apiProduct.usage.errors') }}</th>
              <th class="py-1 font-medium text-right">p50</th>
              <th class="py-1 font-medium text-right">p95</th>
              <th class="py-1 font-medium text-right">p99</th>
            </template>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, i) in usage.data" :key="i" class="border-t border-gray-800" data-test="usage-row">
            <td class="py-1.5 text-gray-200">{{ label(row) }}</td>
            <td class="py-1.5 text-right text-gray-200">{{ n(row.requests) }}</td>
            <td class="py-1.5 text-right text-gray-400">{{ n(row.unauthorized) }}</td>
            <td class="py-1.5 text-right text-gray-400">{{ n(row.forbidden) }}</td>
            <td class="py-1.5 text-right text-gray-400">{{ n(row.rate_limited) }}</td>
            <td class="py-1.5 text-right text-gray-400">{{ n(row.quota_exceeded) }}</td>
            <template v-if="measured">
              <td class="py-1.5 text-right" :class="row.errors ? 'text-amber-400' : 'text-gray-400'" :title="statusTitle(row)" data-test="usage-row-errors">{{ n(row.errors) }}</td>
              <td class="py-1.5 text-right text-gray-400">{{ latency(row.latency_p50_ms) }}</td>
              <td class="py-1.5 text-right text-gray-400" data-test="usage-row-p95">{{ latency(row.latency_p95_ms) }}</td>
              <td class="py-1.5 text-right text-gray-400">{{ latency(row.latency_p99_ms) }}</td>
            </template>
          </tr>
        </tbody>
      </table>
    </div>
    <p class="text-[11px] text-gray-500">{{ t('apiProduct.usage.hint') }}</p>
    <p v-if="measured" class="text-[11px] text-gray-500">{{ t('apiProduct.usage.answersHint') }}</p>
  </div>
</template>
