<script setup>
// Every call the gateway forwarded to the hosted MCP server (GET …/mcp-server/calls): method,
// tool / resource, outcome, duration and who called. The same calls appear in the project's
// deployment logs.
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLatest } from '@/composables/useLatest'
import { mcpApi } from '../api/mcp'
import { RefreshCw } from 'lucide-vue-next'

const props = defineProps({ project: { type: Object, required: true } })
const { t, locale } = useI18n()

const calls = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 })
const loading = ref(true)
const error = ref(null)
const outcome = ref('')
const page = ref(1)
const latest = useLatest()

const outcomeStyle = {
  ok:          'bg-emerald-900/30 border-emerald-800/40 text-emerald-400',
  error:       'bg-red-900/30 border-red-800/40 text-red-400',
  denied:      'bg-amber-900/30 border-amber-800/40 text-amber-400',
  unreachable: 'bg-gray-800 border-gray-700 text-gray-400',
}

// Filter and page changes overtake each other: only the newest answer is shown
async function load() {
  const current = latest.begin()
  loading.value = true
  error.value = null
  try {
    const { data } = await mcpApi.calls(props.project.id, { page: page.value, per_page: 25, outcome: outcome.value || undefined })
    if (!current()) return
    calls.value = data.data ?? []
    meta.value = data.meta ?? meta.value
  } catch (e) {
    if (current()) error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    if (current()) loading.value = false
  }
}

function fmt(iso) {
  return iso ? new Date(iso).toLocaleString(locale.value === 'tr' ? 'tr-TR' : 'en-US', { dateStyle: 'short', timeStyle: 'medium' }) : '—'
}

function actorLabel(call) {
  if (!call.actor || call.actor.type === 'anonymous') return t('mcp.calls.anonymous')
  return call.actor.name || call.actor.id || '—'
}

// A new filter starts at page 1: one load (by the page watcher when the page changes)
watch(outcome, () => { if (page.value !== 1) page.value = 1; else load() })
watch(page, load)
onMounted(load)
</script>

<template>
  <section class="rounded-xl border border-gray-800 bg-gray-900/60 px-5 py-4" data-testid="mcp-calls">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
      <div>
        <p class="text-sm font-semibold text-gray-100">{{ t('mcp.calls.title') }}</p>
        <p class="text-xs text-gray-400 mt-0.5">{{ t('mcp.calls.hint') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <select v-model="outcome" class="input w-40" data-testid="mcp-calls-outcome">
          <option value="">{{ t('mcp.calls.allOutcomes') }}</option>
          <option v-for="o in ['ok', 'error', 'denied', 'unreachable']" :key="o" :value="o">{{ t(`mcp.calls.outcome.${o}`) }}</option>
        </select>
        <button class="btn-secondary text-xs" :title="t('mcp.calls.refresh')" data-testid="mcp-calls-refresh" @click="load"><RefreshCw class="w-3.5 h-3.5" /></button>
      </div>
    </div>

    <p v-if="loading" class="text-xs text-gray-400">{{ t('common.loading') }}</p>
    <p v-else-if="error" class="text-xs text-red-400">{{ error }}</p>
    <p v-else-if="!calls.length" class="text-xs text-gray-400 italic" data-testid="mcp-calls-empty">{{ t('mcp.calls.empty') }}</p>

    <div v-else class="overflow-x-auto">
      <table class="w-full text-xs">
        <thead>
          <tr class="text-left text-[11px] uppercase tracking-wider text-gray-400">
            <th class="py-1.5 pr-3 font-medium">{{ t('mcp.calls.time') }}</th>
            <th class="py-1.5 pr-3 font-medium">{{ t('mcp.calls.method') }}</th>
            <th class="py-1.5 pr-3 font-medium">{{ t('mcp.calls.outcomeLabel') }}</th>
            <th class="py-1.5 pr-3 font-medium">{{ t('mcp.calls.duration') }}</th>
            <th class="py-1.5 pr-3 font-medium">{{ t('mcp.calls.caller') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="call in calls" :key="call.id" class="border-t border-gray-800 align-top" :data-testid="`mcp-call-${call.id}`">
            <td class="py-1.5 pr-3 text-gray-400 whitespace-nowrap">{{ fmt(call.occurred_at) }}</td>
            <td class="py-1.5 pr-3">
              <code class="font-mono text-gray-100">{{ call.method }}</code>
              <span v-if="call.name" class="font-mono text-gray-400"> · {{ call.name }}</span>
              <p v-if="call.error" class="text-[11px] text-red-400 mt-0.5 break-all">{{ call.error }}</p>
            </td>
            <td class="py-1.5 pr-3 whitespace-nowrap">
              <span class="text-[10px] font-medium px-1.5 py-0.5 rounded-sm border" :class="outcomeStyle[call.outcome] || outcomeStyle.unreachable">
                {{ t(`mcp.calls.outcome.${call.outcome}`) }}
              </span>
              <span v-if="call.status_code" class="text-[10px] font-mono text-gray-500 ml-1">{{ call.status_code }}</span>
            </td>
            <td class="py-1.5 pr-3 text-gray-400 whitespace-nowrap">{{ call.duration_ms != null ? `${call.duration_ms} ms` : '—' }}</td>
            <td class="py-1.5 pr-3 text-gray-400">
              {{ actorLabel(call) }}
              <span class="text-[10px] text-gray-500"> · {{ t(`mcp.calls.auth.${call.auth}`) }}</span>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="meta.last_page > 1" class="flex items-center justify-end gap-2 mt-3 text-xs text-gray-400">
        <button class="btn-secondary text-xs" :disabled="page <= 1" data-testid="mcp-calls-prev" @click="page--">{{ t('mcp.calls.previous') }}</button>
        <span>{{ t('mcp.calls.page', { page: meta.current_page, pages: meta.last_page }) }}</span>
        <button class="btn-secondary text-xs" :disabled="page >= meta.last_page" data-testid="mcp-calls-next" @click="page++">{{ t('mcp.calls.next') }}</button>
      </div>
    </div>
  </section>
</template>
