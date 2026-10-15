<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, X } from 'lucide-vue-next'
import { rowsToObject, splitList } from '../utils/format'

/** Create / edit an uptime check. Headers are write-only: an edit leaves them unless re-entered. */
const props = defineProps({
  check:  { type: Object, default: null },
  saving: { type: Boolean, default: false },
  errors: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['submit', 'cancel'])

const { t } = useI18n()
const c = props.check
const form = ref({
  name:                c?.name ?? '',
  url:                 c?.url ?? '',
  path:                c?.path ?? '/',
  method:              c?.method ?? 'GET',
  interval_seconds:    c?.interval_seconds ?? 60,
  timeout_seconds:     c?.timeout_seconds ?? 10,
  expected_status:     c?.expected_status ?? '200-399',
  body_regex:          c?.body_regex ?? '',
  locations:           c?.locations ?? 'control_plane',
  region_ids:          (c?.region_ids ?? []).join(', '),
  failure_threshold:   c?.failure_threshold ?? 3,
  recovery_threshold:  c?.recovery_threshold ?? 2,
  is_enabled:          c?.is_enabled ?? true,
  show_on_status_page: c?.show_on_status_page ?? false,
  status_page_name:    c?.status_page_name ?? '',
})
const headers = ref([])

function submit() {
  const f = form.value
  const payload = {
    name: f.name,
    url: f.url || null,
    path: f.path || '/',
    method: f.method,
    interval_seconds: Number(f.interval_seconds),
    timeout_seconds: Number(f.timeout_seconds),
    expected_status: f.expected_status,
    body_regex: f.body_regex || null,
    locations: f.locations,
    region_ids: f.locations === 'regions' ? splitList(f.region_ids) : null,
    failure_threshold: Number(f.failure_threshold),
    recovery_threshold: Number(f.recovery_threshold),
    is_enabled: f.is_enabled,
    show_on_status_page: f.show_on_status_page,
    status_page_name: f.status_page_name || null,
  }
  const h = rowsToObject(headers.value)
  if (Object.keys(h).length || !props.check) payload.headers = Object.keys(h).length ? h : null
  emit('submit', payload)
}

const err = (k) => (props.errors?.[k] || [])[0]
</script>

<template>
  <form class="bg-gray-900 border border-gray-800 rounded-md p-4 grid gap-3 md:grid-cols-2" data-test="uptime-form" @submit.prevent="submit">
    <div>
      <label class="label">{{ t('observability.uptime.form.name') }}</label>
      <input v-model="form.name" class="input" required data-test="check-name">
      <p v-if="err('name')" class="text-[11px] text-red-400 mt-1">{{ err('name') }}</p>
    </div>
    <div>
      <label class="label">{{ t('observability.uptime.form.method') }}</label>
      <select v-model="form.method" class="input"><option>GET</option><option>HEAD</option><option>POST</option></select>
    </div>
    <div>
      <label class="label">{{ t('observability.uptime.form.url') }}</label>
      <input v-model="form.url" class="input" placeholder="https://status.example.com/health" data-test="check-url">
      <p class="text-[11px] text-gray-500 mt-1">{{ t('observability.uptime.form.urlHint') }}</p>
      <p v-if="err('url')" class="text-[11px] text-red-400 mt-1" data-test="check-url-error">{{ err('url') }}</p>
    </div>
    <div>
      <label class="label">{{ t('observability.uptime.form.path') }}</label>
      <input v-model="form.path" class="input" :disabled="!!form.url" data-test="check-path">
    </div>
    <div>
      <label class="label">{{ t('observability.uptime.form.interval') }}</label>
      <input v-model="form.interval_seconds" type="number" min="30" class="input" data-test="check-interval">
      <p v-if="err('interval_seconds')" class="text-[11px] text-red-400 mt-1">{{ err('interval_seconds') }}</p>
    </div>
    <div>
      <label class="label">{{ t('observability.uptime.form.timeout') }}</label>
      <input v-model="form.timeout_seconds" type="number" min="1" class="input">
    </div>
    <div>
      <label class="label">{{ t('observability.uptime.form.expectedStatus') }}</label>
      <input v-model="form.expected_status" class="input" data-test="check-expected">
      <p class="text-[11px] text-gray-500 mt-1">{{ t('observability.uptime.form.expectedStatusHint') }}</p>
      <p v-if="err('expected_status')" class="text-[11px] text-red-400 mt-1">{{ err('expected_status') }}</p>
    </div>
    <div>
      <label class="label">{{ t('observability.uptime.form.bodyRegex') }}</label>
      <input v-model="form.body_regex" class="input font-mono" placeholder="&quot;status&quot;:\s*&quot;ok&quot;" data-test="check-regex">
      <p class="text-[11px] text-gray-500 mt-1">{{ t('observability.uptime.form.bodyRegexHint') }}</p>
      <p v-if="err('body_regex')" class="text-[11px] text-red-400 mt-1">{{ err('body_regex') }}</p>
    </div>
    <div>
      <label class="label">{{ t('observability.uptime.form.locations') }}</label>
      <select v-model="form.locations" class="input" data-test="check-locations">
        <option value="control_plane">{{ t('observability.uptime.form.controlPlane') }}</option>
        <option value="regions">{{ t('observability.uptime.form.regions') }}</option>
      </select>
    </div>
    <div v-if="form.locations === 'regions'">
      <label class="label">{{ t('observability.uptime.form.regionIds') }}</label>
      <input v-model="form.region_ids" class="input font-mono">
      <p class="text-[11px] text-gray-500 mt-1">{{ t('observability.uptime.form.regionIdsHint') }}</p>
    </div>
    <div>
      <label class="label">{{ t('observability.uptime.form.failureThreshold') }}</label>
      <input v-model="form.failure_threshold" type="number" min="1" max="20" class="input">
    </div>
    <div>
      <label class="label">{{ t('observability.uptime.form.recoveryThreshold') }}</label>
      <input v-model="form.recovery_threshold" type="number" min="1" max="20" class="input">
    </div>

    <div class="md:col-span-2 space-y-2">
      <label class="label">{{ t('observability.uptime.form.headers') }}</label>
      <p class="text-[11px] text-gray-500">{{ check?.header_names?.length ? t('observability.uptime.form.headersKept', { names: check.header_names.join(', ') }) : t('observability.uptime.form.headersHint') }}</p>
      <div v-for="(row, i) in headers" :key="i" class="flex gap-2">
        <input v-model="row.name" class="input h-8! text-xs w-56!" placeholder="X-Health-Token">
        <input v-model="row.value" class="input h-8! text-xs" type="password" autocomplete="off">
        <button type="button" class="text-gray-400 hover:text-red-400" @click="headers.splice(i, 1)"><X class="w-4 h-4" /></button>
      </div>
      <button type="button" class="btn-secondary text-xs px-2.5 py-1" @click="headers.push({ name: '', value: '' })"><Plus class="w-3 h-3" />{{ t('observability.uptime.form.addHeader') }}</button>
    </div>

    <label class="flex items-center gap-2 text-xs text-gray-300"><input v-model="form.is_enabled" type="checkbox"> {{ t('observability.uptime.form.enabled') }}</label>
    <div class="flex items-center gap-2">
      <label class="flex items-center gap-2 text-xs text-gray-300"><input v-model="form.show_on_status_page" type="checkbox" data-test="check-status-page"> {{ t('observability.uptime.form.showOnStatusPage') }}</label>
      <input v-if="form.show_on_status_page" v-model="form.status_page_name" class="input h-8! text-xs" :placeholder="t('observability.uptime.form.statusPageName')">
    </div>

    <div class="md:col-span-2 flex gap-2">
      <button type="submit" class="btn-primary text-xs px-3 py-1.5" :disabled="saving" data-test="check-submit">{{ check ? t('observability.uptime.form.update') : t('observability.uptime.form.create') }}</button>
      <button type="button" class="btn-secondary text-xs px-3 py-1.5" @click="emit('cancel')">{{ t('observability.common.cancel') }}</button>
    </div>
  </form>
</template>
