<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, RotateCcw, ShieldAlert, Trash2, Plus, X } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { usePermissionsStore } from '@/stores/permissions'
import { observabilityApi } from '../api/observability'
import { formatDuration, rowsToObject, splitList } from '../utils/format'
import ReplayDiff from './ReplayDiff.vue'

/**
 * Project "Replay" tab: capture of failed requests (off by default; privacy note, body limit,
 * retention, redacted headers / query parameters), the captured requests and their replay to the
 * current revision with the response diff.
 */
const props = defineProps({
  project: { type: Object, required: true },
})

const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()
const perms = usePermissionsStore()
const canManage = computed(() => perms.can('replay.manage', props.project.id))

const settings = ref(null)
const form = ref({ enabled: false, max_body_bytes: 65536, retention_days: 7, redact_headers: '', redact_query: '' })
const captures = ref([])
const meta = ref({ total: 0 })
const filterStatus = ref('')
const loading = ref(true)
const error = ref(null)
const saving = ref(false)

const open = ref(null)       // capture detail
const replaying = ref(false)
const result = ref(null)     // last replay {original, replay, diff}
const overrides = ref([])    // [{name, value}] for this replay only

async function loadSettings() {
  const { data } = await observabilityApi.settings(props.project.id)
  settings.value = data.data
  form.value = {
    enabled:        data.data.replay_enabled,
    max_body_bytes: data.data.replay_max_body_bytes,
    retention_days: data.data.replay_retention_days,
    redact_headers: (data.data.replay_redact_headers || []).join(', '),
    redact_query:   (data.data.replay_redact_query || []).join(', '),
  }
}

async function loadCaptures() {
  const params = filterStatus.value ? { status: filterStatus.value } : {}
  const { data } = await observabilityApi.replays(props.project.id, params)
  captures.value = data.data ?? []
  meta.value = data.meta ?? { total: captures.value.length }
}

async function load() {
  loading.value = true
  error.value = null
  try {
    await loadSettings()
    if (canManage.value) await loadCaptures()
  } catch (e) {
    error.value = e.response?.data?.message ?? t('observability.common.error')
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    const { data } = await observabilityApi.setReplay(props.project.id, {
      enabled:        form.value.enabled,
      max_body_bytes: Number(form.value.max_body_bytes),
      retention_days: Number(form.value.retention_days),
      redact_headers: splitList(form.value.redact_headers),
      redact_query:   splitList(form.value.redact_query),
    })
    settings.value = data.data
    toast.success(data.requires_redeploy ? t('observability.common.requiresRedeploy') : t('observability.common.saved'))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  } finally {
    saving.value = false
  }
}

async function openCapture(c) {
  result.value = null
  overrides.value = []
  try {
    const { data } = await observabilityApi.replayCapture(props.project.id, c.id)
    open.value = data.data
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  }
}

async function replay() {
  replaying.value = true
  try {
    const headers = rowsToObject(overrides.value)
    const { data } = await observabilityApi.replay(props.project.id, open.value.id, Object.keys(headers).length ? { headers } : {})
    result.value = data.data
    open.value = { ...open.value, replay_count: (open.value.replay_count || 0) + 1 }
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  } finally {
    replaying.value = false
  }
}

async function remove(c) {
  if (!(await confirm({ message: t('observability.replay.detail.deleteConfirm'), destructive: true }))) return
  await observabilityApi.deleteReplay(props.project.id, c.id)
  open.value = null
  await loadCaptures()
}

async function removeAll() {
  if (!(await confirm({ message: t('observability.replay.list.deleteAllConfirm'), destructive: true }))) return
  await observabilityApi.deleteReplays(props.project.id)
  await loadCaptures()
}

function pretty(body, base64) {
  if (!body) return ''
  if (base64) return body
  try { return JSON.stringify(JSON.parse(body), null, 2) } catch { return body }
}

onMounted(load)
</script>

<template>
  <div class="space-y-4" data-test="replay-tab">
    <div>
      <div class="flex items-center gap-2">
        <RotateCcw class="w-4 h-4 text-violet-300" />
        <span class="text-[15px] font-semibold text-gray-100">{{ t('observability.replay.title') }}</span>
      </div>
      <p class="text-xs text-gray-400 mt-1.5">{{ t('observability.replay.intro') }}</p>
    </div>

    <div v-if="loading" class="flex justify-center py-10"><div class="animate-spin h-6 w-6 border-2 border-sky-500 border-t-transparent rounded-full" /></div>
    <div v-else-if="error" class="bg-red-900/20 border border-red-800/40 rounded-md px-4 py-3 text-xs text-red-400">{{ error }}</div>

    <template v-else-if="settings">
      <div class="flex items-start gap-2.5 bg-amber-900/15 border border-amber-800/40 rounded-md px-4 py-3" data-test="replay-privacy">
        <ShieldAlert class="w-4 h-4 text-amber-300 mt-0.5 shrink-0" />
        <p class="text-xs text-amber-200 leading-relaxed">{{ t('observability.replay.privacy') }}</p>
      </div>

      <p v-if="!settings.replay_available" class="text-xs text-gray-400" data-test="replay-unavailable">{{ t('observability.replay.unavailableCaas') }}</p>

      <!-- Capture settings -->
      <form v-else-if="canManage" class="bg-gray-900 border border-gray-800 rounded-md p-4 grid gap-4 md:grid-cols-2" data-test="replay-settings" @submit.prevent="save">
        <label class="flex items-center gap-2 text-sm text-gray-200 md:col-span-2">
          <input v-model="form.enabled" type="checkbox" class="accent-violet-500" data-test="replay-toggle">
          {{ t('observability.replay.toggle') }}
        </label>
        <div>
          <label class="label">{{ t('observability.replay.maxBody') }}</label>
          <input v-model="form.max_body_bytes" type="number" min="0" class="input" data-test="replay-max-body">
          <p class="text-[11px] text-gray-500 mt-1">{{ t('observability.replay.maxBodyHint') }}</p>
        </div>
        <div>
          <label class="label">{{ t('observability.replay.retention') }}</label>
          <input v-model="form.retention_days" type="number" min="1" class="input" data-test="replay-retention">
          <p class="text-[11px] text-gray-500 mt-1">{{ t('observability.replay.retentionHint') }}</p>
        </div>
        <div>
          <label class="label">{{ t('observability.replay.redactHeaders') }}</label>
          <input v-model="form.redact_headers" type="text" class="input" placeholder="x-signature, x-tenant-key" data-test="replay-redact-headers">
          <p class="text-[11px] text-gray-500 mt-1">{{ t('observability.replay.platformRedacted', { list: (settings.platform_redact_headers || []).join(', ') }) }}</p>
        </div>
        <div>
          <label class="label">{{ t('observability.replay.redactQuery') }}</label>
          <input v-model="form.redact_query" type="text" class="input" placeholder="session, sig" data-test="replay-redact-query">
          <p class="text-[11px] text-gray-500 mt-1">{{ t('observability.replay.platformRedacted', { list: (settings.platform_redact_query || []).join(', ') }) }}</p>
        </div>
        <div class="md:col-span-2">
          <button type="submit" class="btn-primary text-xs px-3 py-1.5" :disabled="saving" data-test="replay-save">{{ t('observability.common.save') }}</button>
        </div>
      </form>

      <!-- Capture detail -->
      <div v-if="open" class="space-y-4" data-test="capture-detail">
        <div class="flex items-center gap-3">
          <button type="button" class="btn-secondary text-xs px-2.5 py-1" @click="open = null"><ArrowLeft class="w-3 h-3" />{{ t('observability.replay.detail.back') }}</button>
          <span class="font-mono text-xs text-gray-200">{{ open.method }} {{ open.path }}<span v-if="open.query" class="text-gray-500">?{{ open.query }}</span></span>
          <span class="font-mono text-[11px] px-1.5 rounded-sm bg-red-900/40 text-red-300">{{ open.timed_out ? 'timeout' : open.status }}</span>
          <button type="button" class="ml-auto text-gray-400 hover:text-red-400" :title="t('observability.replay.detail.delete')" @click="remove(open)"><Trash2 class="w-4 h-4" /></button>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
          <div class="space-y-2">
            <p class="label">{{ t('observability.replay.detail.request') }}</p>
            <div class="font-mono text-[11px] text-gray-300">
              <div v-for="(v, k) in open.headers" :key="k"><span class="text-gray-500">{{ k }}:</span> {{ v }}</div>
            </div>
            <p v-if="open.redacted_headers?.length" class="text-[11px] text-amber-300" data-test="capture-redacted">{{ t('observability.replay.detail.redacted', { list: open.redacted_headers.join(', ') }) }}</p>
            <pre class="bg-gray-950 border border-gray-800 rounded-md p-3 max-h-64 overflow-auto text-[11px] text-gray-200">{{ pretty(open.body, open.body_base64) || t('observability.replay.detail.noBody') }}</pre>
            <p v-if="open.body_truncated" class="text-[11px] text-gray-500">{{ t('observability.replay.detail.truncated') }}</p>
          </div>
          <div class="space-y-2">
            <p class="label">{{ t('observability.replay.detail.response') }}</p>
            <div class="font-mono text-[11px] text-gray-300">
              <div v-for="(v, k) in open.response_headers" :key="k"><span class="text-gray-500">{{ k }}:</span> {{ v }}</div>
            </div>
            <pre class="bg-gray-950 border border-gray-800 rounded-md p-3 max-h-64 overflow-auto text-[11px] text-gray-200">{{ pretty(open.response_body, open.response_body_base64) || t('observability.replay.detail.noBody') }}</pre>
            <p v-if="open.trace_id" class="text-[11px] text-gray-500">{{ t('observability.replay.detail.trace') }}: <span class="font-mono">{{ open.trace_id }}</span></p>
          </div>
        </div>

        <!-- Replay -->
        <div v-if="canManage" class="bg-gray-900 border border-gray-800 rounded-md p-4 space-y-3">
          <p class="text-xs text-gray-400">{{ t('observability.replay.detail.overrideHint') }}</p>
          <div v-for="(row, i) in overrides" :key="i" class="flex gap-2">
            <input v-model="row.name" class="input h-8! text-xs w-56!" :placeholder="t('observability.replay.detail.headerName')" :data-test="`override-name-${i}`">
            <input v-model="row.value" class="input h-8! text-xs" :placeholder="t('observability.replay.detail.headerValue')" :data-test="`override-value-${i}`">
            <button type="button" class="text-gray-400 hover:text-red-400" @click="overrides.splice(i, 1)"><X class="w-4 h-4" /></button>
          </div>
          <div class="flex gap-2">
            <button type="button" class="btn-secondary text-xs px-2.5 py-1" data-test="add-override" @click="overrides.push({ name: 'Authorization', value: '' })">
              <Plus class="w-3 h-3" />{{ t('observability.replay.detail.addHeader') }}
            </button>
            <button type="button" class="btn-primary text-xs px-3 py-1" :disabled="replaying" data-test="replay-button" @click="replay">
              <RotateCcw class="w-3 h-3" />{{ replaying ? t('observability.replay.detail.replaying') : t('observability.replay.detail.replay') }}
            </button>
          </div>
          <ReplayDiff v-if="result" :diff="result.diff" :replay="result.replay" />
        </div>
      </div>

      <!-- Capture list -->
      <section v-else-if="canManage" class="space-y-3">
        <div class="flex items-center gap-3">
          <p class="text-xs text-gray-400 uppercase tracking-wider font-medium">{{ t('observability.replay.list.title', { n: meta.total }) }}</p>
          <input v-model="filterStatus" type="number" min="100" max="999" class="input h-8! w-28! text-xs" :placeholder="t('observability.replay.list.filterStatus')" @change="loadCaptures">
          <button v-if="captures.length" type="button" class="ml-auto btn-danger text-xs px-2.5 py-1" data-test="delete-all" @click="removeAll">
            <Trash2 class="w-3 h-3" />{{ t('observability.replay.list.deleteAll') }}
          </button>
        </div>
        <div v-if="!captures.length" class="text-center text-gray-400 py-8 text-sm" data-test="captures-empty">
          <p>{{ t('observability.replay.list.empty') }}</p>
          <p class="text-xs mt-1">{{ t('observability.replay.list.emptyHint') }}</p>
        </div>
        <table v-else class="w-full text-xs" data-test="capture-list">
          <thead class="text-gray-400 text-left border-b border-gray-800">
            <tr>
              <th class="py-2 pr-3 font-medium">{{ t('observability.replay.list.captured') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('observability.replay.list.request') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('observability.replay.list.status') }}</th>
              <th class="py-2 pr-3 font-medium text-right">{{ t('observability.replay.list.duration') }}</th>
              <th class="py-2 pr-3 font-medium text-right">{{ t('observability.replay.list.replays') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in captures" :key="c.id" class="border-b border-gray-800/60 hover:bg-gray-800/40 cursor-pointer" :data-capture="c.id" @click="openCapture(c)">
              <td class="py-2 pr-3 text-gray-400 whitespace-nowrap">{{ new Date(c.captured_at).toLocaleString() }}</td>
              <td class="py-2 pr-3 font-mono text-gray-100 truncate max-w-88">{{ c.method }} {{ c.path }}</td>
              <td class="py-2 pr-3"><span class="px-1.5 py-0.5 rounded-sm bg-red-900/40 text-red-300 font-mono">{{ c.timed_out ? t('observability.replay.list.timedOut') : c.status }}</span></td>
              <td class="py-2 pr-3 text-right font-mono text-gray-300">{{ formatDuration(c.duration_ms) }}</td>
              <td class="py-2 pr-3 text-right text-gray-300">{{ c.replay_count }}<span v-if="c.last_replay_status" class="text-gray-500"> ({{ c.last_replay_status }})</span></td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>
  </div>
</template>
