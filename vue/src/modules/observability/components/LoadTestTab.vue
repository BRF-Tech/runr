<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Gauge, Play, Square, AlertTriangle } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { useJobEvents } from '@/composables/useJobEvents'
import { useFallbackPolling } from '@/composables/useFallbackPolling'
import { usePermissionsStore } from '@/stores/permissions'
import { observabilityApi } from '../api/observability'
import { compareRuns, durationLabel, formatValue, isActive, progressOf, upsertTest } from '../utils/loadTest'

/**
 * Project "Load test" tab (docs/load-test.md): a k6 test from inside the project's region against
 * the project's own URL — path and method (GET, or POST with a small body), 10 / 50 / 100
 * concurrent users for 30 s / 1 min / 5 min. Live status through the load_test.* broadcasts,
 * the results of the latest run and the last five runs side by side. Test traffic is metered.
 */
const props = defineProps({
  project: { type: Object, required: true },
})

const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()
const perms = usePermissionsStore()
const canRun = computed(() => perms.can('projects.deploy', props.project.id))

const tests = ref([])
const meta = ref(null)
const loading = ref(true)
const error = ref(null)
const starting = ref(false)
const formErrors = ref({})
const now = ref(Date.now())

const form = ref({ host: '', path: '/', method: 'GET', body: '', content_type: 'application/json', users: 10, duration_seconds: 30 })

const active = computed(() => tests.value.find(isActive) ?? null)
const latest = computed(() => tests.value.find((x) => x.status !== 'pending' && x.status !== 'running') ?? null)
const comparison = computed(() => compareRuns(tests.value, 5))
// the metrics of the latest run, when it completed (same rows as the comparison)
const latestRows = computed(() => latest.value?.results ? compareRuns([latest.value], 1).rows : [])
const progress = computed(() => progressOf(active.value, now.value))
const remaining = computed(() => meta.value ? Math.max(0, (meta.value.daily_limit ?? 0) - (meta.value.used_today ?? 0)) : null)
const blocked = computed(() => !!active.value || remaining.value === 0 || !(meta.value?.targets ?? []).length || meta.value?.enabled === false)

async function load() {
  error.value = null
  try {
    const { data } = await observabilityApi.loadTests(props.project.id, { limit: 10 })
    tests.value = data.data ?? []
    meta.value = data.meta ?? null
    if (!form.value.host && meta.value?.targets?.length) form.value.host = meta.value.targets[0]
    if (meta.value?.users?.length && !meta.value.users.includes(form.value.users)) form.value.users = meta.value.users[0]
    if (meta.value?.durations?.length && !meta.value.durations.includes(form.value.duration_seconds)) form.value.duration_seconds = meta.value.durations[0]
  } catch (e) {
    error.value = e.response?.data?.message ?? t('observability.common.error')
  } finally {
    loading.value = false
  }
}

async function start() {
  starting.value = true
  formErrors.value = {}
  const f = form.value
  const payload = { host: f.host || null, path: f.path || '/', method: f.method, users: Number(f.users), duration_seconds: Number(f.duration_seconds) }
  if (f.method === 'POST' && f.body) Object.assign(payload, { body: f.body, content_type: f.content_type || null })
  try {
    const { data } = await observabilityApi.startLoadTest(props.project.id, payload)
    tests.value = upsertTest(tests.value, data.data)
    if (meta.value) meta.value = { ...meta.value, used_today: (meta.value.used_today ?? 0) + 1 }
    toast.success(t('observability.loadTest.started'))
  } catch (e) {
    formErrors.value = e.response?.data?.errors ?? {}
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  } finally {
    starting.value = false
  }
}

async function cancel(test) {
  const stop = t('observability.loadTest.stop')
  if (!(await confirm({ title: stop, message: t('observability.loadTest.stopConfirm'), destructive: true, confirmLabel: stop }))) return
  try {
    const { data } = await observabilityApi.cancelLoadTest(props.project.id, test.id)
    tests.value = upsertTest(tests.value, data.data)
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  }
}

// Live status: every change of a test of this project (load_test.updated, .completed, .failed)
useJobEvents({
  'load_test.*': (payload) => {
    if (payload?.project_id !== props.project.id) return
    if (payload.load_test) tests.value = upsertTest(tests.value, payload.load_test)
    else load()
  },
})
// Without the WebSocket: the list now and then
useFallbackPolling(load, 10000)

let timer = null
onMounted(() => {
  load()
  timer = setInterval(() => { now.value = Date.now() }, 1000)
})
onUnmounted(() => clearInterval(timer))

const err = (k) => (formErrors.value?.[k] || [])[0]
const statusClass = (s) => ({
  completed: 'bg-emerald-900/30 text-emerald-300',
  failed: 'bg-red-900/40 text-red-300',
  cancelled: 'bg-gray-800 text-gray-400',
  running: 'bg-sky-900/40 text-sky-300',
  pending: 'bg-amber-900/30 text-amber-300',
}[s] || 'bg-gray-800 text-gray-400')
const when = (iso) => iso ? new Date(iso).toLocaleString() : '—'
</script>

<template>
  <div class="space-y-4" data-test="load-test-tab">
    <div class="flex items-start justify-between gap-4">
      <div>
        <div class="flex items-center gap-2">
          <Gauge class="w-4 h-4 text-sky-400" />
          <span class="text-[15px] font-semibold text-gray-100">{{ t('observability.loadTest.title') }}</span>
        </div>
        <p class="text-xs text-gray-400 mt-1.5">{{ t('observability.loadTest.intro') }}</p>
      </div>
      <span v-if="meta" class="text-[11px] text-gray-400 whitespace-nowrap" data-test="load-test-quota">
        {{ t('observability.loadTest.quota', { used: meta.used_today, limit: meta.daily_limit }) }}
      </span>
    </div>

    <div class="flex items-start gap-2 bg-amber-900/15 border border-amber-800/40 rounded-md px-3 py-2 text-xs text-amber-200" data-test="load-test-metered">
      <AlertTriangle class="w-3.5 h-3.5 mt-0.5 shrink-0" />
      <span>{{ t('observability.loadTest.metered') }}</span>
    </div>

    <div v-if="loading" class="flex justify-center py-10"><div class="animate-spin h-6 w-6 border-2 border-sky-500 border-t-transparent rounded-full" /></div>
    <div v-else-if="error" class="bg-red-900/20 border border-red-800/40 rounded-md px-4 py-3 text-xs text-red-400">{{ error }}</div>

    <template v-else>
      <p v-if="meta && meta.enabled === false" class="text-xs text-gray-400" data-test="load-test-disabled">{{ t('observability.loadTest.disabled') }}</p>
      <p v-else-if="meta && !(meta.targets || []).length" class="text-xs text-gray-400" data-test="load-test-no-target">{{ t('observability.loadTest.noTarget') }}</p>

      <!-- New test -->
      <form v-if="canRun && meta && meta.enabled !== false && (meta.targets || []).length" class="bg-gray-900 border border-gray-800 rounded-md p-4 grid gap-3 md:grid-cols-2" data-test="load-test-form" @submit.prevent="start">
        <div class="md:col-span-2">
          <label class="label">{{ t('observability.loadTest.form.target') }}</label>
          <div class="flex gap-2">
            <select v-model="form.host" class="input w-auto! max-w-[50%]" data-test="load-test-host">
              <option v-for="h in meta.targets" :key="h" :value="h">{{ h }}</option>
            </select>
            <input v-model="form.path" class="input font-mono" placeholder="/api/orders?page=1" data-test="load-test-path">
          </div>
          <p class="text-[11px] text-gray-500 mt-1">{{ t('observability.loadTest.form.targetHint') }}</p>
          <p v-if="err('host') || err('path')" class="text-[11px] text-red-400 mt-1" data-test="load-test-target-error">{{ err('host') || err('path') }}</p>
        </div>
        <div>
          <label class="label">{{ t('observability.loadTest.form.method') }}</label>
          <select v-model="form.method" class="input" data-test="load-test-method">
            <option v-for="m in (meta.methods || ['GET', 'POST'])" :key="m" :value="m">{{ m }}</option>
          </select>
        </div>
        <div v-if="form.method === 'POST'">
          <label class="label">{{ t('observability.loadTest.form.contentType') }}</label>
          <input v-model="form.content_type" class="input font-mono" data-test="load-test-content-type">
          <p v-if="err('content_type')" class="text-[11px] text-red-400 mt-1">{{ err('content_type') }}</p>
        </div>
        <div v-if="form.method === 'POST'" class="md:col-span-2">
          <label class="label">{{ t('observability.loadTest.form.body') }}</label>
          <textarea v-model="form.body" class="input font-mono text-xs" rows="3" :maxlength="meta.max_body_bytes" data-test="load-test-body" />
          <p class="text-[11px] text-gray-500 mt-1">{{ t('observability.loadTest.form.bodyHint', { max: meta.max_body_bytes }) }}</p>
          <p v-if="err('body')" class="text-[11px] text-red-400 mt-1">{{ err('body') }}</p>
        </div>
        <div>
          <label class="label">{{ t('observability.loadTest.form.users') }}</label>
          <div class="flex gap-1.5" role="radiogroup">
            <button
              v-for="u in meta.users" :key="u" type="button" role="radio" :aria-checked="form.users === u"
              class="text-xs px-3 py-1.5 rounded-md border"
              :class="form.users === u ? 'border-sky-500 bg-sky-900/30 text-sky-200' : 'border-gray-700 text-gray-300 hover:border-gray-500'"
              :data-test="`load-test-users-${u}`"
              @click="form.users = u"
            >{{ u }}</button>
          </div>
        </div>
        <div>
          <label class="label">{{ t('observability.loadTest.form.duration') }}</label>
          <div class="flex gap-1.5" role="radiogroup">
            <button
              v-for="d in meta.durations" :key="d" type="button" role="radio" :aria-checked="form.duration_seconds === d"
              class="text-xs px-3 py-1.5 rounded-md border"
              :class="form.duration_seconds === d ? 'border-sky-500 bg-sky-900/30 text-sky-200' : 'border-gray-700 text-gray-300 hover:border-gray-500'"
              :data-test="`load-test-duration-${d}`"
              @click="form.duration_seconds = d"
            >{{ durationLabel(d) }}</button>
          </div>
        </div>
        <div class="md:col-span-2 flex flex-wrap items-center gap-3">
          <button type="submit" class="btn-primary text-xs px-3 py-1.5" :disabled="starting || blocked" data-test="load-test-start">
            <Play class="w-3.5 h-3.5" />{{ t('observability.loadTest.start') }}
          </button>
          <span v-if="active" class="text-[11px] text-gray-400">{{ t('observability.loadTest.oneAtATime') }}</span>
          <span v-else-if="remaining === 0" class="text-[11px] text-amber-300" data-test="load-test-limit">{{ t('observability.loadTest.limitReached') }}</span>
        </div>
      </form>

      <!-- Live status of the running test -->
      <div v-if="active" class="bg-gray-900 border border-sky-800/50 rounded-md p-4 space-y-2" data-test="load-test-active" :data-status="active.status">
        <div class="flex flex-wrap items-center gap-3">
          <span class="text-[11px] px-1.5 rounded-sm" :class="statusClass(active.status)">{{ t('observability.loadTest.status.' + active.status) }}</span>
          <span class="text-xs font-mono text-gray-300 truncate max-w-[28rem]">{{ active.method }} {{ active.url }}</span>
          <span class="text-[11px] text-gray-400">{{ t('observability.loadTest.shape', { users: active.users, duration: durationLabel(active.duration_seconds) }) }}</span>
          <button v-if="canRun" type="button" class="ml-auto btn-secondary text-xs px-2 py-1" data-test="load-test-cancel" @click="cancel(active)">
            <Square class="w-3 h-3" />{{ t('observability.loadTest.stop') }}
          </button>
        </div>
        <div class="h-1.5 bg-gray-800 rounded-full overflow-hidden">
          <div class="h-full bg-sky-500 transition-all duration-1000" :class="{ 'animate-pulse w-full opacity-40': progress === null }" :style="progress !== null ? { width: `${Math.round(progress * 100)}%` } : {}" data-test="load-test-progress" />
        </div>
        <p class="text-[11px] text-gray-500">{{ active.status === 'pending' ? t('observability.loadTest.preparing') : t('observability.loadTest.running') }}</p>
      </div>

      <!-- Results of the latest run -->
      <div v-if="latest" class="bg-gray-900 border border-gray-800 rounded-md p-4 space-y-3" data-test="load-test-latest" :data-status="latest.status">
        <div class="flex flex-wrap items-center gap-3">
          <span class="text-sm text-gray-100 font-medium">{{ t('observability.loadTest.latest') }}</span>
          <span class="text-[11px] px-1.5 rounded-sm" :class="statusClass(latest.status)">{{ t('observability.loadTest.status.' + latest.status) }}</span>
          <span class="text-xs font-mono text-gray-400 truncate max-w-[28rem]">{{ latest.method }} {{ latest.url }}</span>
          <span class="text-[11px] text-gray-500">{{ t('observability.loadTest.shape', { users: latest.users, duration: durationLabel(latest.duration_seconds) }) }} · {{ when(latest.finished_at || latest.created_at) }}</span>
        </div>
        <p v-if="latest.error" class="text-[11px] text-red-400 whitespace-pre-line" data-test="load-test-error">{{ latest.error }}</p>
        <div v-if="latest.results" class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-2">
          <div v-for="row in latestRows" :key="row.key" class="bg-gray-950/50 border border-gray-800 rounded-md px-3 py-2" :data-metric="row.key">
            <p class="text-[10px] text-gray-500">{{ t('observability.loadTest.metrics.' + row.key) }}</p>
            <p class="text-sm text-gray-100 font-medium">{{ formatValue(row.values[0], row.unit) }}</p>
          </div>
        </div>
        <p v-if="latest.results && latest.results.pods_source === 'sessions'" class="text-[11px] text-gray-500">{{ t('observability.loadTest.podsFromSessions') }}</p>
      </div>

      <!-- The last runs side by side -->
      <section v-if="comparison.runs.length" class="space-y-2" data-test="load-test-compare">
        <p class="text-xs text-gray-400 font-medium border-b border-gray-800/60 pb-2">{{ t('observability.loadTest.compare', { n: comparison.runs.length }) }}</p>
        <div class="overflow-x-auto">
          <table class="text-xs w-full">
            <thead>
              <tr class="text-gray-400">
                <th class="text-left font-normal py-1.5 pr-3" />
                <th v-for="run in comparison.runs" :key="run.id" class="text-right font-normal py-1.5 px-3 whitespace-nowrap" :data-run="run.id">
                  <div class="text-gray-300">{{ when(run.finished_at || run.created_at) }}</div>
                  <div class="text-[10px] text-gray-500">{{ run.method }} {{ run.path }} · {{ t('observability.loadTest.shape', { users: run.users, duration: durationLabel(run.duration_seconds) }) }}</div>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in comparison.rows" :key="row.key" class="border-t border-gray-800/40" :data-row="row.key">
                <td class="py-1.5 pr-3 text-gray-400 whitespace-nowrap">{{ t('observability.loadTest.metrics.' + row.key) }}</td>
                <td v-for="(value, i) in row.values" :key="i" class="py-1.5 px-3 text-right font-mono" :class="i === row.best ? 'text-emerald-300' : 'text-gray-200'" :data-best="i === row.best ? 'true' : null">
                  {{ formatValue(value, row.unit) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <p v-if="!tests.length" class="text-center text-gray-400 py-6 text-sm" data-test="load-test-empty">{{ t('observability.loadTest.empty') }}</p>
    </template>
  </div>
</template>
