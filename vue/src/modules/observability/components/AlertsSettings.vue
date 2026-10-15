<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { BellRing, Pencil, Plus, Send, Trash2, RefreshCw } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { projectsApi } from '@/api/projects'
import { knativeRegionsApi } from '@/api/knativeRegions'
import { observabilityApi } from '../api/observability'
import AlertChannelForm from './AlertChannelForm.vue'
import AlertRuleEditor from './AlertRuleEditor.vue'

/** Settings → Alerts: channels (with "send test"), rules, fired alerts and the delivery log. */
const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()

const channels = ref([])
const rules = ref([])
const events = ref([])
const deliveries = ref([])
const projects = ref([])
const regions = ref([])
const loading = ref(true)
const error = ref(null)

const editingChannel = ref(null) // null | 'new' | channel
const editingRule = ref(null)    // null | 'new' | rule
const formErrors = ref({})
const saving = ref(false)
const deliveryFilter = ref({ status: '', channel_id: '' })

async function load() {
  loading.value = true
  error.value = null
  try {
    const [c, r, e] = await Promise.all([
      observabilityApi.channels(), observabilityApi.rules(), observabilityApi.alertEvents({ status: 'firing' }), loadDeliveries(),
    ])
    channels.value = c.data.data ?? []
    rules.value = r.data.data ?? []
    events.value = e.data.data ?? []
  } catch (err) {
    error.value = err.response?.data?.message ?? t('observability.common.error')
  } finally {
    loading.value = false
  }
  // names for the rule editor (optional: the editor works without them)
  projectsApi.list().then(({ data }) => { projects.value = data.data ?? data ?? [] }).catch(() => {})
  knativeRegionsApi.list().then(({ data }) => { regions.value = data.data ?? data ?? [] }).catch(() => {})
}

async function loadDeliveries() {
  const params = Object.fromEntries(Object.entries(deliveryFilter.value).filter(([, v]) => v))
  const res = await observabilityApi.deliveries(params)
  deliveries.value = res.data.data ?? []
  return res
}

async function saveChannel(payload) {
  saving.value = true
  formErrors.value = {}
  try {
    if (editingChannel.value === 'new') await observabilityApi.createChannel(payload)
    else await observabilityApi.updateChannel(editingChannel.value.id, payload)
    editingChannel.value = null
    toast.success(t('observability.common.saved'))
    await load()
  } catch (e) {
    formErrors.value = e.response?.data?.errors ?? {}
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  } finally {
    saving.value = false
  }
}

async function testChannel(ch) {
  try {
    const { data } = await observabilityApi.testChannel(ch.id)
    if (data.data.ok) toast.success(t('observability.alerts.channels.testOk', { name: ch.name }))
    else toast.error(t('observability.alerts.channels.testFailed', { error: data.data.error || data.data.response_code }))
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  }
}

async function removeChannel(ch) {
  if (!(await confirm({ message: t('observability.alerts.channels.deleteConfirm', { name: ch.name }), destructive: true }))) return
  await observabilityApi.deleteChannel(ch.id)
  await load()
}

async function saveRule(payload) {
  saving.value = true
  formErrors.value = {}
  try {
    if (editingRule.value === 'new') await observabilityApi.createRule(payload)
    else await observabilityApi.updateRule(editingRule.value.id, payload)
    editingRule.value = null
    toast.success(t('observability.common.saved'))
    await load()
  } catch (e) {
    formErrors.value = e.response?.data?.errors ?? {}
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  } finally {
    saving.value = false
  }
}

async function removeRule(rule) {
  if (!(await confirm({ message: t('observability.alerts.rules.deleteConfirm', { name: rule.name }), destructive: true }))) return
  await observabilityApi.deleteRule(rule.id)
  await load()
}

async function retry(d) {
  try {
    await observabilityApi.retryDelivery(d.id)
    toast.success(t('observability.alerts.deliveries.retried'))
    await loadDeliveries()
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  }
}

const channelName = (id) => channels.value.find((c) => c.id === id)?.name ?? id
const projectName = (id) => projects.value.find((p) => p.id === id)?.name ?? id

function channelSummary(ch) {
  const c = ch.config || {}
  if (ch.type === 'email') return (c.emails || []).join(', ')
  if (ch.type === 'telegram') return `chat ${c.chat_id}`
  return c.webhook_host || c.url_host || ''
}

onMounted(load)
</script>

<template>
  <div class="space-y-6" data-test="alerts-settings">
    <div>
      <div class="flex items-center gap-2">
        <BellRing class="w-4 h-4 text-amber-300" />
        <span class="text-[15px] font-semibold text-gray-100">{{ t('observability.alerts.title') }}</span>
      </div>
      <p class="text-xs text-gray-400 mt-1.5">{{ t('observability.alerts.intro') }}</p>
    </div>

    <div v-if="loading" class="flex justify-center py-10"><div class="animate-spin h-6 w-6 border-2 border-sky-500 border-t-transparent rounded-full" /></div>
    <div v-else-if="error" class="bg-red-900/20 border border-red-800/40 rounded-md px-4 py-3 text-xs text-red-400">{{ error }}</div>

    <template v-else>
      <!-- Channels -->
      <section class="space-y-3" data-test="channels">
        <div class="flex items-center justify-between border-b border-gray-800/60 pb-2">
          <p class="text-xs text-gray-400 uppercase tracking-wider font-medium">{{ t('observability.alerts.channels.title') }}</p>
          <button v-if="!editingChannel" type="button" class="btn-primary text-xs px-2.5 py-1" data-test="add-channel" @click="editingChannel = 'new'; formErrors = {}"><Plus class="w-3 h-3" />{{ t('observability.alerts.channels.add') }}</button>
        </div>
        <AlertChannelForm v-if="editingChannel" :key="editingChannel === 'new' ? 'new' : editingChannel.id" :channel="editingChannel === 'new' ? null : editingChannel" :saving="saving" :errors="formErrors" @submit="saveChannel" @cancel="editingChannel = null" />
        <p v-if="!channels.length" class="text-xs text-gray-500" data-test="channels-empty">{{ t('observability.alerts.channels.empty') }}</p>
        <div v-for="ch in channels" :key="ch.id" class="flex flex-wrap items-center gap-3 bg-gray-900 border border-gray-800 rounded-md px-3 py-2" :data-channel="ch.id">
          <span class="text-sm text-gray-100">{{ ch.name }}</span>
          <span class="text-[11px] px-1.5 rounded bg-gray-800 text-gray-300">{{ t('observability.alerts.channels.types.' + ch.type) }}</span>
          <span class="text-[11px] text-gray-500 font-mono truncate max-w-[18rem]">{{ channelSummary(ch) }}</span>
          <span v-if="!ch.is_enabled" class="text-[10px] px-1.5 rounded bg-gray-800 text-gray-400">{{ t('observability.common.disabled') }}</span>
          <span v-if="ch.last_test_at" class="text-[11px]" :class="ch.last_test_ok ? 'text-emerald-400' : 'text-red-400'">
            {{ t('observability.alerts.channels.lastTest') }}: {{ ch.last_test_ok ? 'OK' : ch.last_test_error }}
          </span>
          <div class="ml-auto flex gap-1.5">
            <button type="button" class="btn-secondary text-xs px-2 py-1" :data-test="`test-${ch.id}`" @click="testChannel(ch)"><Send class="w-3 h-3" />{{ t('observability.alerts.channels.test') }}</button>
            <button type="button" class="btn-secondary text-xs px-2 py-1" @click="editingChannel = ch; formErrors = {}"><Pencil class="w-3 h-3" /></button>
            <button type="button" class="btn-secondary text-xs px-2 py-1" @click="removeChannel(ch)"><Trash2 class="w-3 h-3" /></button>
          </div>
        </div>
      </section>

      <!-- Rules -->
      <section class="space-y-3" data-test="rules">
        <div class="flex items-center justify-between border-b border-gray-800/60 pb-2">
          <p class="text-xs text-gray-400 uppercase tracking-wider font-medium">{{ t('observability.alerts.rules.title') }}</p>
          <button v-if="!editingRule" type="button" class="btn-primary text-xs px-2.5 py-1" data-test="add-rule" @click="editingRule = 'new'; formErrors = {}"><Plus class="w-3 h-3" />{{ t('observability.alerts.rules.add') }}</button>
        </div>
        <AlertRuleEditor v-if="editingRule" :key="editingRule === 'new' ? 'new' : editingRule.id" :rule="editingRule === 'new' ? null : editingRule" :channels="channels" :projects="projects" :regions="regions" :saving="saving" :errors="formErrors" @submit="saveRule" @cancel="editingRule = null" />
        <p v-if="!rules.length" class="text-xs text-gray-500" data-test="rules-empty">{{ t('observability.alerts.rules.empty') }}</p>
        <div v-for="r in rules" :key="r.id" class="flex flex-wrap items-center gap-3 bg-gray-900 border border-gray-800 rounded-md px-3 py-2" :data-rule="r.id">
          <span class="w-2 h-2 rounded-full" :class="r.state === 'firing' ? 'bg-red-500' : 'bg-emerald-500'" :title="t('observability.alerts.rules.' + (r.state === 'firing' ? 'firing' : 'ok'))" />
          <span class="text-sm text-gray-100">{{ r.name }}</span>
          <span class="text-[11px] px-1.5 rounded bg-gray-800 text-gray-300">{{ t('observability.alerts.rules.types.' + r.type) }}</span>
          <span class="text-[11px] text-gray-400">{{ t('observability.alerts.rules.severities.' + r.severity) }}</span>
          <span class="text-[11px] text-gray-500">{{ r.project_id ? projectName(r.project_id) : t('observability.alerts.rules.allProjects') }}</span>
          <span class="text-[11px] text-gray-500">→ {{ r.channel_ids.length ? r.channel_ids.map(channelName).join(', ') : t('observability.alerts.rules.noChannelsShort') }}</span>
          <span v-if="r.last_value !== null && r.last_value !== undefined" class="text-[11px] text-gray-500">{{ t('observability.alerts.rules.lastValue') }}: {{ Math.round(r.last_value * 100) / 100 }}</span>
          <span v-if="!r.is_enabled" class="text-[10px] px-1.5 rounded bg-gray-800 text-gray-400">{{ t('observability.common.disabled') }}</span>
          <div class="ml-auto flex gap-1.5">
            <button type="button" class="btn-secondary text-xs px-2 py-1" @click="editingRule = r; formErrors = {}"><Pencil class="w-3 h-3" /></button>
            <button type="button" class="btn-secondary text-xs px-2 py-1" @click="removeRule(r)"><Trash2 class="w-3 h-3" /></button>
          </div>
        </div>
      </section>

      <!-- Firing alerts -->
      <section class="space-y-2" data-test="firing-alerts">
        <p class="text-xs text-gray-400 uppercase tracking-wider font-medium border-b border-gray-800/60 pb-2">{{ t('observability.alerts.events.title') }}</p>
        <p v-if="!events.length" class="text-xs text-gray-500">{{ t('observability.alerts.events.empty') }}</p>
        <div v-for="ev in events" :key="ev.id" class="text-xs flex flex-wrap gap-3 py-1">
          <span class="px-1.5 rounded bg-red-900/40 text-red-300">{{ t('observability.alerts.rules.severities.' + ev.severity) }}</span>
          <span class="text-gray-100">{{ ev.title }}</span>
          <span class="text-gray-500">{{ new Date(ev.fired_at).toLocaleString() }}</span>
        </div>
      </section>

      <!-- Delivery log -->
      <section class="space-y-2" data-test="deliveries">
        <div class="flex flex-wrap items-center gap-3 border-b border-gray-800/60 pb-2">
          <p class="text-xs text-gray-400 uppercase tracking-wider font-medium">{{ t('observability.alerts.deliveries.title') }}</p>
          <select v-model="deliveryFilter.status" class="input !h-8 !w-32 text-xs" @change="loadDeliveries">
            <option value="">{{ t('observability.alerts.deliveries.all') }}</option>
            <option v-for="s in ['pending', 'sent', 'failed']" :key="s" :value="s">{{ t('observability.alerts.deliveries.statuses.' + s) }}</option>
          </select>
          <select v-model="deliveryFilter.channel_id" class="input !h-8 !w-44 text-xs" @change="loadDeliveries">
            <option value="">{{ t('observability.alerts.deliveries.allChannels') }}</option>
            <option v-for="ch in channels" :key="ch.id" :value="ch.id">{{ ch.name }}</option>
          </select>
          <button type="button" class="text-gray-400 hover:text-gray-200" @click="loadDeliveries"><RefreshCw class="w-3.5 h-3.5" /></button>
        </div>
        <p v-if="!deliveries.length" class="text-xs text-gray-500" data-test="deliveries-empty">{{ t('observability.alerts.deliveries.empty') }}</p>
        <table v-else class="w-full text-xs">
          <tbody>
            <tr v-for="d in deliveries" :key="d.id" class="border-b border-gray-800/50" :data-delivery="d.id">
              <td class="py-1.5 pr-3 text-gray-500 whitespace-nowrap">{{ new Date(d.created_at).toLocaleString() }}</td>
              <td class="py-1.5 pr-3 text-gray-200">{{ d.channel_name }}</td>
              <td class="py-1.5 pr-3 text-gray-400">{{ t('observability.alerts.deliveries.kinds.' + d.kind) }}</td>
              <td class="py-1.5 pr-3 text-gray-300 truncate max-w-[20rem]">{{ d.event?.title ?? '—' }}</td>
              <td class="py-1.5 pr-3">
                <span class="px-1.5 rounded" :class="{ 'bg-emerald-900/30 text-emerald-300': d.status === 'sent', 'bg-red-900/40 text-red-300': d.status === 'failed', 'bg-gray-800 text-gray-300': d.status === 'pending' }">{{ t('observability.alerts.deliveries.statuses.' + d.status) }}</span>
              </td>
              <td class="py-1.5 pr-3 text-gray-500">{{ t('observability.alerts.deliveries.attempts', { n: d.attempts }) }}</td>
              <td class="py-1.5 pr-3 text-red-400 truncate max-w-[16rem]" :title="d.last_error">{{ d.last_error }}</td>
              <td class="py-1.5 text-right">
                <button v-if="d.status === 'failed' && d.kind !== 'test'" type="button" class="text-sky-400 hover:text-sky-300" :data-test="`retry-${d.id}`" @click="retry(d)">{{ t('observability.alerts.deliveries.retry') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>
  </div>
</template>
