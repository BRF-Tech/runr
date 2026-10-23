<script setup>
// The billing webhook of the organization (GET / PUT /billing/settings): RunR POSTs signed events
// (bill.closed, bill.paid, fixed_request.approved, …) to the external invoicing system. Every event
// is also kept in the event log (GET /billing/events) for catching up after an outage.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RefreshCw, Send } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import SecretInput from '@/modules/white-label/components/SecretInput.vue'
import { billingApi } from '../api/billing'
import { apiMessage, can, formatDateTime } from '../utils/billing'

const { t, locale } = useI18n()
const toast = useToast()

const settings = ref(null)
const events = ref([])
const webhookConfigured = ref(false)
const url = ref('')
const secret = ref(undefined)
const loading = ref(false)
const saving = ref(false)
const testing = ref(false)
const error = ref(null)
// Events being redelivered: a double click would send the event to the invoicing system twice
const redelivering = ref(new Set())

const canManage = computed(() => can('billing.manage'))

async function load() {
  loading.value = true
  error.value = null
  try {
    const [s, e] = await Promise.all([billingApi.settings(), billingApi.events({ order: 'desc', limit: 20 })])
    settings.value = s.data ?? null
    url.value = settings.value?.webhook?.url ?? ''
    secret.value = undefined
    events.value = e.data?.data ?? []
    webhookConfigured.value = !!e.data?.meta?.webhook_configured
  } catch (err) {
    error.value = apiMessage(err, t('common.error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

// The event log alone: a test or a redelivery must not reset a webhook URL being edited
async function loadEvents() {
  try {
    const { data } = await billingApi.events({ order: 'desc', limit: 20 })
    events.value = data?.data ?? []
    webhookConfigured.value = !!data?.meta?.webhook_configured
  } catch { /* the log keeps what it showed */ }
}

async function save() {
  saving.value = true
  try {
    const body = { webhook_url: url.value.trim() || null }
    if (secret.value === '') body.clear_secrets = ['webhook_secret']
    else if (secret.value !== undefined) body.webhook_secret = secret.value
    const { data } = await billingApi.updateSettings(body)
    settings.value = data ?? settings.value
    secret.value = undefined
    toast.success(t('billing.webhook.saved'))
    await load()
  } catch (e) {
    toast.error(apiMessage(e, t('common.error')))
  } finally {
    saving.value = false
  }
}

async function test() {
  testing.value = true
  try {
    const { data } = await billingApi.testEvent()
    if (data?.delivered === false) toast.error(t('billing.webhook.testFailed'))
    else toast.success(t('billing.webhook.testSent'))
    await loadEvents()
  } catch (e) {
    toast.error(apiMessage(e, t('common.error')))
  } finally {
    testing.value = false
  }
}

async function redeliver(event) {
  if (redelivering.value.has(event.id)) return
  redelivering.value = new Set([...redelivering.value, event.id])
  try {
    await billingApi.redeliver(event.id)
    toast.success(t('billing.webhook.redelivered'))
    await loadEvents()
  } catch (e) {
    toast.error(apiMessage(e, t('common.error')))
  } finally {
    const next = new Set(redelivering.value)
    next.delete(event.id)
    redelivering.value = next
  }
}

function deliveryCls(status) {
  const base = 'px-1.5 py-0.5 rounded-sm text-[10px] font-medium border'
  return {
    delivered: `${base} bg-emerald-900/40 border-emerald-800/40 text-emerald-400`,
    failed:    `${base} bg-red-900/40 border-red-800/40 text-red-400`,
    pending:   `${base} bg-amber-900/40 border-amber-800/40 text-amber-400`,
  }[status] || `${base} bg-gray-800/40 border-gray-700/40 text-gray-400`
}

defineExpose({ load, save, test, redeliver, url, secret })
</script>

<template>
  <div class="space-y-6" data-test="billing-webhook">
    <div v-if="loading" class="flex justify-center py-6">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>
    <p v-else-if="error" class="text-sm text-red-400">{{ error }}</p>

    <template v-else>
      <section class="space-y-3 max-w-2xl">
        <p class="text-xs text-gray-400">{{ t('billing.webhook.hint') }}</p>
        <div>
          <label class="label">{{ t('billing.webhook.url') }}</label>
          <input v-model="url" type="url" maxlength="1000" class="input font-mono" placeholder="https://" :disabled="!canManage" data-test="webhook-url" />
          <p v-if="settings?.webhook?.url_source === 'config'" class="text-[11px] text-gray-500 mt-1">{{ t('billing.webhook.fromConfig') }}</p>
        </div>
        <SecretInput v-if="canManage" v-model="secret" :label="t('billing.webhook.secret')" :set="!!settings?.webhook?.secret_set" test-id="webhook-secret" />
        <p class="text-[11px] text-gray-500">{{ t('billing.webhook.currency', { currency: settings?.currency ?? 'USD' }) }}</p>
        <div v-if="canManage" class="flex flex-wrap gap-2">
          <button type="button" class="btn-primary text-xs" :disabled="saving" data-test="webhook-save" @click="save">{{ saving ? t('common.saving') : t('common.save') }}</button>
          <button type="button" class="btn-secondary text-xs" :disabled="testing" data-test="webhook-test" @click="test">
            <Send class="w-3.5 h-3.5" />{{ t('billing.webhook.test') }}
          </button>
        </div>
        <p v-if="!webhookConfigured" class="text-xs text-amber-400" data-test="webhook-not-configured">{{ t('billing.webhook.notConfigured') }}</p>
      </section>

      <section class="space-y-2">
        <div class="flex items-center justify-between">
          <h3 class="text-sm font-semibold text-gray-100">{{ t('billing.webhook.events') }}</h3>
          <button type="button" class="text-gray-400 hover:text-gray-100" :title="t('billing.webhook.refresh')" @click="load"><RefreshCw class="w-3.5 h-3.5" /></button>
        </div>
        <p v-if="!events.length" class="text-sm text-gray-400" data-test="webhook-no-events">{{ t('billing.webhook.noEvents') }}</p>
        <div v-else class="overflow-x-auto rounded-xl border border-gray-800">
          <table class="w-full text-xs">
            <thead>
              <tr class="text-left text-[10px] text-gray-500 uppercase tracking-wider bg-gray-900/60">
                <th class="px-3 py-2 font-medium">{{ t('billing.webhook.event') }}</th>
                <th class="px-3 py-2 font-medium">{{ t('billing.webhook.created') }}</th>
                <th class="px-3 py-2 font-medium">{{ t('billing.webhook.delivery') }}</th>
                <th class="px-3 py-2" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="e in events" :key="e.id" class="border-t border-gray-800 text-gray-300" :data-test="`event-${e.id}`">
                <td class="px-3 py-2 font-mono">{{ e.type }}</td>
                <td class="px-3 py-2">{{ formatDateTime(e.created_at, locale) }}</td>
                <td class="px-3 py-2">
                  <span :class="deliveryCls(e.delivery?.status)">{{ t(`billing.webhook.status.${e.delivery?.status ?? 'skipped'}`) }}</span>
                  <span v-if="e.delivery?.attempts" class="text-[10px] text-gray-500 ml-1">{{ t('billing.webhook.attempts', { n: e.delivery.attempts }) }}</span>
                  <div v-if="e.delivery?.last_error" class="text-[10px] text-red-400 mt-0.5">{{ e.delivery.last_error }}</div>
                </td>
                <td class="px-3 py-2 text-right">
                  <button v-if="canManage && webhookConfigured" type="button" class="btn-secondary text-xs" :data-test="`event-redeliver-${e.id}`" :disabled="redelivering.has(e.id)" @click="redeliver(e)">{{ t('billing.webhook.redeliver') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>
