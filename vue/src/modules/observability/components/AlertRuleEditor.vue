<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Create / edit an alert rule: type (fixed once created), the type's parameters, severity,
 * cooldown, scope (project / region) and the channels it notifies.
 */
const props = defineProps({
  rule:     { type: Object, default: null },
  channels: { type: Array, default: () => [] },
  projects: { type: Array, default: () => [] },
  regions:  { type: Array, default: () => [] },
  saving:   { type: Boolean, default: false },
  errors:   { type: Object, default: () => ({}) },
})
const emit = defineEmits(['submit', 'cancel'])

const { t } = useI18n()

const RULE_TYPES = ['uptime_down', 'error_rate', 'p95_latency', 'cold_start_rate', 'resource_threshold']

const DEFAULT_PARAMS = {
  uptime_down:        {},
  error_rate:         { threshold: 5, window_minutes: 5, min_requests: 20 },
  p95_latency:        { threshold_ms: 1000, window_minutes: 5, min_requests: 20 },
  cold_start_rate:    { threshold: 20, window_minutes: 15, min_requests: 20 },
  resource_threshold: { resource: 'any', min_level: 'warning' },
}

const r = props.rule
const form = ref({
  name:             r?.name ?? '',
  type:             r?.type ?? 'uptime_down',
  severity:         r?.severity ?? 'warning',
  cooldown_minutes: r?.cooldown_minutes ?? 15,
  project_id:       r?.project_id ?? '',
  region_id:        r?.region_id ?? '',
  params:           { ...DEFAULT_PARAMS[r?.type ?? 'uptime_down'], ...(r?.params ?? {}) },
  channel_ids:      [...(r?.channel_ids ?? [])],
  is_enabled:       r?.is_enabled ?? true,
})

// a new type starts from its defaults
watch(() => form.value.type, (type, old) => {
  if (!props.rule && type !== old) form.value.params = { ...DEFAULT_PARAMS[type] }
})

const isMetric = computed(() => ['error_rate', 'p95_latency', 'cold_start_rate'].includes(form.value.type))
const isResource = computed(() => form.value.type === 'resource_threshold')
const err = (k) => (props.errors?.[k] || [])[0]

function toggleChannel(id) {
  const ids = form.value.channel_ids
  form.value.channel_ids = ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]
}

function submit() {
  const f = form.value
  const params = {}
  for (const [k, v] of Object.entries(f.params)) {
    params[k] = typeof DEFAULT_PARAMS[f.type][k] === 'number' ? Number(v) : v
  }
  const payload = {
    name: f.name,
    severity: f.severity,
    cooldown_minutes: Number(f.cooldown_minutes),
    project_id: isResource.value ? null : (f.project_id || null),
    region_id: isResource.value ? (f.region_id || null) : null,
    params,
    channel_ids: f.channel_ids,
    is_enabled: f.is_enabled,
  }
  if (!props.rule) payload.type = f.type
  emit('submit', payload)
}
</script>

<template>
  <form class="bg-gray-900 border border-gray-800 rounded-md p-4 grid gap-3 md:grid-cols-2" data-test="rule-editor" @submit.prevent="submit">
    <div>
      <label class="label">{{ t('observability.alerts.rules.name') }}</label>
      <input v-model="form.name" class="input" required data-test="rule-name">
      <p v-if="err('name')" class="text-[11px] text-red-400 mt-1">{{ err('name') }}</p>
    </div>
    <div>
      <label class="label">{{ t('observability.alerts.rules.type') }}</label>
      <select v-model="form.type" class="input" :disabled="!!rule" data-test="rule-type">
        <option v-for="type in RULE_TYPES" :key="type" :value="type">{{ t('observability.alerts.rules.types.' + type) }}</option>
      </select>
      <p class="text-[11px] text-gray-500 mt-1" data-test="rule-type-hint">{{ t('observability.alerts.rules.typeHints.' + form.type) }}</p>
    </div>

    <!-- Parameters of the type -->
    <template v-if="isMetric">
      <div v-if="form.type === 'p95_latency'">
        <label class="label">{{ t('observability.alerts.rules.thresholdMs') }}</label>
        <input v-model="form.params.threshold_ms" type="number" min="1" class="input" data-test="param-threshold_ms">
        <p v-if="err('params.threshold_ms')" class="text-[11px] text-red-400 mt-1">{{ err('params.threshold_ms') }}</p>
      </div>
      <div v-else>
        <label class="label">{{ t('observability.alerts.rules.threshold') }}</label>
        <input v-model="form.params.threshold" type="number" min="0" max="100" step="0.1" class="input" data-test="param-threshold">
        <p v-if="err('params.threshold')" class="text-[11px] text-red-400 mt-1">{{ err('params.threshold') }}</p>
      </div>
      <div>
        <label class="label">{{ t('observability.alerts.rules.window') }}</label>
        <input v-model="form.params.window_minutes" type="number" min="1" class="input" data-test="param-window">
      </div>
      <div>
        <label class="label">{{ t('observability.alerts.rules.minRequests') }}</label>
        <input v-model="form.params.min_requests" type="number" min="0" class="input" data-test="param-min-requests">
      </div>
    </template>
    <template v-else-if="isResource">
      <div>
        <label class="label">{{ t('observability.alerts.rules.resource') }}</label>
        <select v-model="form.params.resource" class="input" data-test="param-resource">
          <option v-for="res in ['any', 'cpu', 'memory']" :key="res" :value="res">{{ t('observability.alerts.rules.resources.' + res) }}</option>
        </select>
      </div>
      <div>
        <label class="label">{{ t('observability.alerts.rules.minLevel') }}</label>
        <select v-model="form.params.min_level" class="input">
          <option value="warning">{{ t('observability.alerts.rules.levels.warning') }}</option>
          <option value="critical">{{ t('observability.alerts.rules.levels.critical') }}</option>
        </select>
      </div>
      <div>
        <label class="label">{{ t('observability.alerts.rules.region') }}</label>
        <select v-model="form.region_id" class="input" data-test="rule-region">
          <option value="">{{ t('observability.alerts.rules.allRegions') }}</option>
          <option v-for="rg in regions" :key="rg.id" :value="rg.id">{{ rg.name }}</option>
        </select>
      </div>
    </template>

    <div v-if="!isResource">
      <label class="label">{{ t('observability.alerts.rules.project') }}</label>
      <select v-model="form.project_id" class="input" data-test="rule-project">
        <option value="">{{ t('observability.alerts.rules.allProjects') }}</option>
        <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
      </select>
    </div>
    <div>
      <label class="label">{{ t('observability.alerts.rules.severity') }}</label>
      <select v-model="form.severity" class="input" data-test="rule-severity">
        <option v-for="s in ['info', 'warning', 'critical']" :key="s" :value="s">{{ t('observability.alerts.rules.severities.' + s) }}</option>
      </select>
    </div>
    <div>
      <label class="label">{{ t('observability.alerts.rules.cooldown') }}</label>
      <input v-model="form.cooldown_minutes" type="number" min="0" class="input" data-test="rule-cooldown">
      <p class="text-[11px] text-gray-500 mt-1">{{ t('observability.alerts.rules.cooldownHint') }}</p>
    </div>

    <div class="md:col-span-2">
      <label class="label">{{ t('observability.alerts.rules.channels') }}</label>
      <p v-if="!channels.length" class="text-xs text-gray-500" data-test="rule-no-channels">{{ t('observability.alerts.rules.noChannels') }}</p>
      <div class="flex flex-wrap gap-2">
        <label v-for="ch in channels" :key="ch.id" class="flex items-center gap-1.5 text-xs text-gray-300 bg-gray-800 rounded px-2 py-1">
          <input type="checkbox" :checked="form.channel_ids.includes(ch.id)" :data-test="`rule-channel-${ch.id}`" @change="toggleChannel(ch.id)">
          {{ ch.name }} <span class="text-gray-500">({{ t('observability.alerts.channels.types.' + ch.type) }})</span>
        </label>
      </div>
      <p v-if="err('channel_ids')" class="text-[11px] text-red-400 mt-1">{{ err('channel_ids') }}</p>
    </div>

    <label class="flex items-center gap-2 text-xs text-gray-300"><input v-model="form.is_enabled" type="checkbox"> {{ t('observability.alerts.rules.enabled') }}</label>

    <div class="md:col-span-2 flex gap-2">
      <button type="submit" class="btn-primary text-xs px-3 py-1.5" :disabled="saving" data-test="rule-submit">{{ t('observability.common.save') }}</button>
      <button type="button" class="btn-secondary text-xs px-3 py-1.5" @click="emit('cancel')">{{ t('observability.common.cancel') }}</button>
    </div>
  </form>
</template>
