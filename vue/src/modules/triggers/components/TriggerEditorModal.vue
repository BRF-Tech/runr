<script setup>
// Adds or edits one trigger of a project (POST / PATCH …/triggers): the common fields (name,
// enabled), the type's form — a dedicated one for the core types, else one built from the type's
// `fields` — and the delivery settings of the types the event gateway runs. Client checks mirror
// the API's rules; the API's 422 errors are shown next to the fields.
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { moduleRegistry } from '@/modules/index'
import { describeCron } from '@/utils/cron'
import { DEFAULT_TIMEZONE, timeZoneOptions } from '@/utils/warmSchedule'
import { useSettingsStore } from '@/stores/settings'
import TermText from '@/components/TermText.vue'
import { triggersApi } from '../api/triggers'
import { useTopicsStore } from '../stores/topics'
import { useTriggerLabels } from '../composables/useTriggerLabels'
import {
  PLATFORM_EVENTS, WEBHOOK_PRESETS, apiFieldErrors, buildTriggerPayload, emptyForm, errorMessage,
  formFromTrigger, hasDedicatedForm, isGatewayType, typeFields, validateForm,
} from '../utils/triggers'
import WebhookTriggerFields from './WebhookTriggerFields.vue'
import PlatformEventFields from './PlatformEventFields.vue'
import GenericTriggerFields from './GenericTriggerFields.vue'
import DeliverySettings from './DeliverySettings.vue'

const props = defineProps({
  projectId:      { type: String, required: true },
  regionId:       { type: String, default: null },
  type:           { type: String, required: true },
  trigger:        { type: Object, default: null },   // the stored trigger when editing
  types:          { type: Array, default: () => [] },
  platformEvents: { type: Array, default: () => PLATFORM_EVENTS },
  webhookPresets: { type: Array, default: () => WEBHOOK_PRESETS },
  siblings:       { type: Array, default: () => [] }, // the project's other triggers
})
const emit = defineEmits(['saved', 'close'])

const { t } = useI18n()
const { typeLabel, typeHint } = useTriggerLabels(() => props.types)
const topicsStore = useTopicsStore()

const creating = computed(() => !props.trigger)
const form = reactive(props.trigger ? formFromTrigger(props.trigger, props.types) : emptyForm(props.type, props.types))
const dedicated = hasDedicatedForm(props.type)
const gateway = computed(() => isGatewayType(props.types, props.type))
const fields = computed(() => typeFields(props.types, props.type))

const saving       = ref(false)
const showErrors   = ref(false)
const serverErrors = ref({})
const message      = ref(null)

const clientErrors = computed(() => validateForm(form, {
  types:            props.types,
  hasSigningSecret: !!(props.trigger?.has_signing_secret || props.trigger?.secrets?.includes('signing_secret')),
  siblings:         props.siblings,
  secrets:          props.trigger?.secrets ?? [],
}))

// Shown: the API's errors, and the client checks once a save was tried
const errors = computed(() => {
  const own = showErrors.value
    ? Object.fromEntries(Object.entries(clientErrors.value).map(([k, e]) => [k, t(e.key, e.params)]))
    : {}
  return { ...own, ...serverErrors.value }
})

const title = computed(() => creating.value
  ? t('triggers.addTitle', { type: typeLabel(props.type) })
  : t('triggers.editTitle', { name: props.trigger.name }))

// Queue streams: a module may suffix the name (tenancy: one Redis per region, many organizations)
const streamSuffix = computed(() => moduleRegistry.queueStreamSuffixFn?.() || '')
const cronText = computed(() => (form.cron.trim() ? describeCron(form.cron.trim(), t) : ''))

// Schedules: a zone of their own, else the organization's (Settings → Time zone), which they follow
const settings = useSettingsStore()
const zones = timeZoneOptions()
const orgZone = computed(() => props.trigger?.timezone_source === 'organization' && props.trigger?.effective_timezone
  ? props.trigger.effective_timezone
  : (settings.data?.timezone || DEFAULT_TIMEZONE))

// Topics of the project's region (topic trigger)
const regionTopics = computed(() => topicsStore.inRegion(props.regionId))
const topicsFailed = ref(false)

async function submit() {
  message.value = null
  serverErrors.value = {}
  if (Object.keys(clientErrors.value).length) {
    showErrors.value = true
    return
  }
  saving.value = true
  try {
    const payload = buildTriggerPayload(form, { creating: creating.value, types: props.types })
    const { data } = creating.value
      ? await triggersApi.create(props.projectId, payload)
      : await triggersApi.update(props.projectId, props.trigger.id ?? props.trigger.name, payload)
    emit('saved', { trigger: data?.data ?? null, requiresDeploy: data?.requires_deploy !== false, created: creating.value, cleanup: data?.cleanup ?? [] })
  } catch (e) {
    serverErrors.value = apiFieldErrors(e?.response?.data?.errors)
    message.value = errorMessage(e, t('triggers.saveFailed'))
  } finally {
    saving.value = false
  }
}

function onKeydown(e) {
  if (e.key === 'Escape' && !saving.value) emit('close')
}

onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  if (props.type === 'topic') topicsStore.load(true).catch(() => { topicsFailed.value = true })
})
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-[2px] p-4" data-test="trigger-editor">
      <form class="bg-gray-900 border border-gray-800 rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col" novalidate @submit.prevent="submit">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800">
          <div class="min-w-0">
            <h3 class="text-sm font-semibold text-gray-100 truncate">{{ title }}</h3>
            <p v-if="typeHint(type)" class="text-[11px] text-gray-400 mt-0.5">{{ typeHint(type) }}</p>
          </div>
          <button type="button" class="text-gray-400 hover:text-gray-100 text-sm" :disabled="saving" :aria-label="t('triggers.close')" @click="emit('close')">✕</button>
        </div>

        <div class="px-6 py-5 space-y-4 overflow-y-auto">
          <!-- Common -->
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-start">
            <div class="md:col-span-2">
              <label class="label">{{ t('triggers.fields.name') }}</label>
              <input v-model="form.name" class="input font-mono text-xs" :placeholder="type" maxlength="63" data-field="name" />
              <p v-if="errors.name" class="text-[11px] text-red-400 mt-1">{{ errors.name }}</p>
              <p v-else class="text-[11px] text-gray-400 mt-1">{{ t('triggers.fields.nameHint') }}</p>
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-200 md:mt-6">
              <input v-model="form.enabled" type="checkbox" data-field="enabled" />{{ t('triggers.fields.enabled') }}
            </label>
          </div>

          <!-- http -->
          <p v-if="type === 'http'" class="text-xs text-gray-400">{{ t('triggers.fields.httpHint') }}</p>

          <!-- schedule -->
          <div v-else-if="type === 'schedule'" class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="md:col-span-2">
              <label class="label">{{ t('triggers.fields.cron') }} <span class="normal-case text-red-500/70">*</span></label>
              <input v-model="form.cron" class="input font-mono text-xs" placeholder="*/15 * * * *" data-field="cron" />
              <p v-if="errors['config.cron']" class="text-[11px] text-red-400 mt-1">{{ errors['config.cron'] }}</p>
              <p v-else-if="cronText" class="text-[11px] text-sky-400/80 mt-1">{{ cronText }}</p>
            </div>
            <div>
              <label class="label" for="trigger-timezone">{{ t('triggers.fields.timezone') }}</label>
              <input
                id="trigger-timezone"
                v-model="form.timezone"
                list="trigger-timezone-options"
                class="input font-mono text-xs"
                :placeholder="orgZone"
                autocomplete="off"
                spellcheck="false"
                data-field="timezone"
              />
              <datalist id="trigger-timezone-options">
                <option v-for="z in zones" :key="z" :value="z" />
              </datalist>
              <p v-if="errors['config.timezone']" class="text-[11px] text-red-400 mt-1">{{ errors['config.timezone'] }}</p>
            </div>
            <p class="md:col-span-3 text-[11px] text-gray-400 -mt-1">{{ t('triggers.fields.timezoneHint', { zone: orgZone }) }}</p>
            <p class="md:col-span-3 text-[11px] text-gray-400 -mt-2">{{ t('triggers.fields.cronHint') }}</p>
          </div>

          <!-- queue -->
          <div v-else-if="type === 'queue'" class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="md:col-span-2">
              <label class="label"><TermText path="triggers.fields.stream" /> <span class="normal-case text-red-500/70">*</span></label>
              <input v-model="form.stream" class="input font-mono text-xs" placeholder="orders-events" data-field="stream" />
              <p v-if="errors['config.stream']" class="text-[11px] text-red-400 mt-1">{{ errors['config.stream'] }}</p>
              <p v-else-if="form.stream.trim() && streamSuffix" class="text-[11px] text-sky-400/70 mt-1 font-mono">{{ t('triggers.fields.streamResolved', { name: `${form.stream.trim()}${streamSuffix}` }) }}</p>
            </div>
            <div>
              <label class="label">{{ t('triggers.fields.startFrom') }}</label>
              <select v-model="form.start_from" class="input text-xs" data-field="start_from">
                <option value="earliest">{{ t('triggers.fields.start_earliest') }}</option>
                <option value="latest">{{ t('triggers.fields.start_latest') }}</option>
              </select>
            </div>
          </div>

          <!-- workflow -->
          <div v-else-if="type === 'workflow'">
            <p class="text-xs text-gray-400 leading-relaxed mb-3">{{ t('triggers.fields.workflowHint') }}</p>
            <label class="label">{{ t('triggers.fields.queueStream') }}</label>
            <input v-model="form.queue_stream" class="input font-mono text-xs" placeholder="orders-events" data-field="queue_stream" />
            <p v-if="errors['config.queue_stream']" class="text-[11px] text-red-400 mt-1">{{ errors['config.queue_stream'] }}</p>
            <p v-else class="text-[11px] text-gray-400 mt-1">{{ t('triggers.fields.queueStreamHint') }}</p>
          </div>

          <!-- webhook -->
          <WebhookTriggerFields v-else-if="type === 'webhook'" :form="form" :trigger="trigger" :presets="webhookPresets" :errors="errors" />

          <!-- topic -->
          <div v-else-if="type === 'topic'" class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="md:col-span-2">
              <label class="label">{{ t('triggers.fields.topic') }} <span class="normal-case text-red-500/70">*</span></label>
              <select v-model="form.topic" class="input text-xs font-mono" data-field="topic">
                <option value="">{{ t('triggers.fields.topicPlaceholder') }}</option>
                <option v-for="tp in regionTopics" :key="tp.id" :value="tp.name">{{ tp.name }}</option>
                <!-- a stored subscription whose topic is not listed (yet) stays selectable -->
                <option v-if="form.topic && !regionTopics.some(tp => tp.name === form.topic)" :value="form.topic">{{ form.topic }}</option>
              </select>
              <p v-if="errors['config.topic']" class="text-[11px] text-red-400 mt-1">{{ errors['config.topic'] }}</p>
              <p v-else-if="topicsFailed" class="text-[11px] text-red-400 mt-1">{{ t('triggers.topics.loadFailed') }}</p>
              <p v-else-if="topicsStore.loaded && !regionTopics.length" class="text-[11px] text-amber-300 mt-1" data-test="no-topics">{{ t('triggers.fields.topicNone') }}</p>
            </div>
            <div>
              <label class="label">{{ t('triggers.fields.startFrom') }}</label>
              <select v-model="form.start_from" class="input text-xs" data-field="start_from">
                <option value="latest">{{ t('triggers.fields.start_latest') }}</option>
                <option value="earliest">{{ t('triggers.fields.start_earliest') }}</option>
              </select>
            </div>
            <p class="md:col-span-3 text-[11px] text-gray-400 -mt-1">{{ t('triggers.fields.topicStartHint') }}</p>
          </div>

          <!-- platform_event -->
          <PlatformEventFields v-else-if="type === 'platform_event'" :form="form" :events="platformEvents" :errors="errors" />

          <!-- any other type: built from its fields -->
          <GenericTriggerFields v-else-if="!dedicated" :fields="fields" :values="form.values" :secrets="trigger?.secrets ?? []" :errors="errors" />

          <DeliverySettings v-if="gateway" :delivery="form.delivery" :effective="trigger?.delivery ?? null" :errors="errors" />

          <p v-if="message" class="text-xs text-red-400" data-test="trigger-error">{{ message }}</p>
        </div>

        <div class="flex justify-end gap-2 px-6 py-4 border-t border-gray-800">
          <button type="button" class="btn-secondary text-xs px-3 py-1.5" :disabled="saving" @click="emit('close')">{{ t('triggers.cancel') }}</button>
          <button type="submit" class="btn-primary text-xs px-3 py-1.5" :disabled="saving" data-test="trigger-save">
            {{ saving ? t('triggers.saving') : (creating ? t('triggers.create') : t('triggers.save')) }}
          </button>
        </div>
      </form>
    </div>
  </Teleport>
</template>
