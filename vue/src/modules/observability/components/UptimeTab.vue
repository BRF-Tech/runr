<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { HeartPulse, Pencil, Play, Plus, Trash2 } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { usePermissionsStore } from '@/stores/permissions'
import { observabilityApi } from '../api/observability'
import { formatPercent, formatSeconds } from '../utils/format'
import UptimeCheckForm from './UptimeCheckForm.vue'
import UptimeBars from './UptimeBars.vue'

/** Project "Uptime" tab: synthetic checks, their uptime, daily history and incidents. */
const props = defineProps({
  project: { type: Object, required: true },
})

const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()
const perms = usePermissionsStore()
const canManage = computed(() => perms.can('uptime.manage', props.project.id))

const checks = ref([])
const incidents = ref([])
const loading = ref(true)
const error = ref(null)
const editing = ref(null)       // null | 'new' | check
const formErrors = ref({})
const saving = ref(false)
const details = ref({})         // check id → results payload
const editingIncident = ref(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    const [c, i] = await Promise.all([observabilityApi.checks(props.project.id), observabilityApi.incidents(props.project.id)])
    checks.value = c.data.data ?? []
    incidents.value = i.data.data ?? []
    await Promise.all(checks.value.map(loadDetails))
  } catch (e) {
    error.value = e.response?.data?.message ?? t('observability.common.error')
  } finally {
    loading.value = false
  }
}

async function loadDetails(check) {
  try {
    const { data } = await observabilityApi.checkResults(props.project.id, check.id, { hours: 24 })
    details.value = { ...details.value, [check.id]: data.data }
  } catch { /* the list still shows the check */ }
}

async function submit(payload) {
  saving.value = true
  formErrors.value = {}
  try {
    if (editing.value === 'new') await observabilityApi.createCheck(props.project.id, payload)
    else await observabilityApi.updateCheck(props.project.id, editing.value.id, payload)
    editing.value = null
    toast.success(t('observability.common.saved'))
    await load()
  } catch (e) {
    formErrors.value = e.response?.data?.errors ?? {}
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  } finally {
    saving.value = false
  }
}

async function run(check) {
  try {
    const { data } = await observabilityApi.runCheck(props.project.id, check.id)
    toast[data.data.ok ? 'success' : 'error'](data.data.ok ? t('observability.uptime.ranOk', { ms: data.data.response_ms }) : t('observability.uptime.ranFailed', { error: data.data.error }))
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  }
}

async function remove(check) {
  if (!(await confirm({ message: t('observability.uptime.deleteConfirm', { name: check.name }), destructive: true }))) return
  await observabilityApi.deleteCheck(props.project.id, check.id)
  await load()
}

async function saveIncident() {
  const inc = editingIncident.value
  try {
    await observabilityApi.updateIncident(props.project.id, inc.id, { title: inc.title, message: inc.message })
    editingIncident.value = null
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  }
}

const statusClass = (s) => ({ up: 'bg-emerald-500', down: 'bg-red-500' }[s] || 'bg-gray-500')

onMounted(load)
</script>

<template>
  <div class="space-y-4" data-test="uptime-tab">
    <div class="flex items-start justify-between gap-4">
      <div>
        <div class="flex items-center gap-2">
          <HeartPulse class="w-4 h-4 text-emerald-400" />
          <span class="text-[15px] font-semibold text-gray-100">{{ t('observability.uptime.title') }}</span>
        </div>
        <p class="text-xs text-gray-400 mt-1.5">{{ t('observability.uptime.intro') }}</p>
      </div>
      <button v-if="canManage && !editing" type="button" class="btn-primary text-xs px-3 py-1.5" data-test="add-check" @click="editing = 'new'; formErrors = {}">
        <Plus class="w-3.5 h-3.5" />{{ t('observability.uptime.add') }}
      </button>
    </div>

    <UptimeCheckForm
      v-if="editing"
      :key="editing === 'new' ? 'new' : editing.id"
      :check="editing === 'new' ? null : editing"
      :saving="saving"
      :errors="formErrors"
      @submit="submit"
      @cancel="editing = null"
    />

    <div v-if="loading" class="flex justify-center py-10"><div class="animate-spin h-6 w-6 border-2 border-sky-500 border-t-transparent rounded-full" /></div>
    <div v-else-if="error" class="bg-red-900/20 border border-red-800/40 rounded-md px-4 py-3 text-xs text-red-400">{{ error }}</div>
    <div v-else-if="!checks.length" class="text-center text-gray-400 py-8 text-sm" data-test="checks-empty">
      <p>{{ t('observability.uptime.empty') }}</p>
      <p class="text-xs mt-1">{{ t('observability.uptime.emptyHint') }}</p>
    </div>

    <div v-else class="space-y-3">
      <div v-for="c in checks" :key="c.id" class="bg-gray-900 border border-gray-800 rounded-md p-4 space-y-3" :data-check="c.id">
        <div class="flex flex-wrap items-center gap-3">
          <span class="w-2.5 h-2.5 rounded-full" :class="statusClass(c.status)" :data-status="c.status" />
          <span class="text-sm text-gray-100 font-medium">{{ c.name }}</span>
          <span class="text-[11px] font-mono text-gray-400 truncate max-w-[24rem]">{{ c.method }} {{ c.url || c.path }}</span>
          <span class="text-[11px] text-gray-500">{{ c.locations === 'regions' ? t('observability.uptime.form.regions') : t('observability.uptime.form.controlPlane') }}</span>
          <span v-if="!c.is_enabled" class="text-[10px] px-1.5 rounded bg-gray-800 text-gray-400">{{ t('observability.uptime.disabled') }}</span>
          <div v-if="canManage" class="ml-auto flex gap-1.5">
            <button type="button" class="btn-secondary text-xs px-2 py-1" :data-test="`run-${c.id}`" @click="run(c)"><Play class="w-3 h-3" />{{ t('observability.uptime.runNow') }}</button>
            <button type="button" class="btn-secondary text-xs px-2 py-1" @click="editing = c; formErrors = {}"><Pencil class="w-3 h-3" /></button>
            <button type="button" class="btn-secondary text-xs px-2 py-1" @click="remove(c)"><Trash2 class="w-3 h-3" /></button>
          </div>
        </div>
        <div class="flex flex-wrap gap-x-6 gap-y-1 text-xs text-gray-400">
          <span>{{ t('observability.uptime.stats.uptime24h') }}: <span class="text-gray-100">{{ formatPercent(c.uptime_24h) }}</span></span>
          <span>{{ t('observability.uptime.stats.uptime7d') }}: <span class="text-gray-100">{{ formatPercent(c.uptime_7d) }}</span></span>
          <span>{{ t('observability.uptime.stats.uptime30d') }}: <span class="text-gray-100">{{ formatPercent(c.uptime_30d) }}</span></span>
          <span>{{ t('observability.uptime.stats.avgResponse') }}: <span class="text-gray-100">{{ c.avg_response_ms_24h ?? '—' }} ms</span></span>
          <span>{{ t('observability.uptime.stats.lastChecked') }}: <span class="text-gray-100">{{ c.last_checked_at ? new Date(c.last_checked_at).toLocaleString() : t('observability.uptime.stats.never') }}</span></span>
        </div>
        <p v-if="c.last_error" class="text-[11px] text-red-400">{{ t('observability.uptime.stats.lastError') }}: {{ c.last_error }}</p>
        <div v-if="details[c.id]">
          <UptimeBars :days="details[c.id].days" />
          <p class="text-[10px] text-gray-500 mt-1">{{ t('observability.uptime.stats.days', { n: details[c.id].days.length }) }}</p>
        </div>
      </div>
    </div>

    <!-- Incidents -->
    <section v-if="!loading" class="space-y-2" data-test="incidents">
      <p class="text-xs text-gray-400 uppercase tracking-wider font-medium border-b border-gray-800/60 pb-2">{{ t('observability.uptime.incidents.title') }}</p>
      <p v-if="!incidents.length" class="text-xs text-gray-500">{{ t('observability.uptime.incidents.empty') }}</p>
      <div v-for="inc in incidents" :key="inc.id" class="text-xs border-b border-gray-800/40 py-2" :data-incident="inc.id">
        <div class="flex flex-wrap items-center gap-3">
          <span class="px-1.5 rounded" :class="inc.status === 'open' ? 'bg-red-900/40 text-red-300' : 'bg-emerald-900/30 text-emerald-300'">{{ t('observability.uptime.incidents.' + inc.status) }}</span>
          <span class="text-gray-100">{{ inc.title }}</span>
          <span class="text-gray-500">{{ new Date(inc.started_at).toLocaleString() }}</span>
          <span class="text-gray-500">{{ t('observability.uptime.incidents.duration') }} {{ formatSeconds(inc.duration_seconds) }}</span>
          <button v-if="canManage" type="button" class="ml-auto text-sky-400 hover:text-sky-300" @click="editingIncident = { ...inc }">{{ t('observability.uptime.incidents.editMessage') }}</button>
        </div>
        <p v-if="inc.cause" class="text-gray-400 mt-1">{{ t('observability.uptime.incidents.cause') }}: {{ inc.cause }}</p>
        <p v-if="inc.message" class="text-gray-300 mt-1">{{ inc.message }}</p>
        <div v-if="editingIncident?.id === inc.id" class="mt-2 space-y-2">
          <input v-model="editingIncident.title" class="input !h-8 text-xs">
          <textarea v-model="editingIncident.message" class="input text-xs" rows="2" />
          <button type="button" class="btn-primary text-xs px-3 py-1" @click="saveIncident">{{ t('observability.uptime.incidents.saveMessage') }}</button>
        </div>
      </div>
    </section>
  </div>
</template>
