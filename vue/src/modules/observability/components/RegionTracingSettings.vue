<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Network, Plus, X } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { usePermissionsStore } from '@/stores/permissions'
import { knativeRegionsApi } from '@/api/knativeRegions'
import { observabilityApi } from '../api/observability'
import { rowsToObject } from '../utils/format'
import TermText from '@/components/TermText.vue'

/**
 * Settings → Tracing: the tracing backend of each region — RunR's collector + Tempo, a reused
 * in-cluster Tempo, an external OTLP endpoint or off — with install / remove.
 */
const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()
const perms = usePermissionsStore()
const canManage = computed(() => perms.can('regions.observability.manage'))

const regions = ref([])
const configs = ref({})
const editing = ref(null)   // region id
const form = ref({})
const headers = ref([])
const errors = ref({})
const loading = ref(true)
const saving = ref(false)

const MODES = ['managed', 'reuse', 'external', 'off']

async function load() {
  loading.value = true
  try {
    const { data } = await knativeRegionsApi.list()
    regions.value = (data.data ?? data ?? []).filter((r) => !r.is_demo)
    const results = await Promise.all(regions.value.map((r) => observabilityApi.regionTracing(r.id).then((res) => [r.id, res.data.data]).catch(() => [r.id, null])))
    configs.value = Object.fromEntries(results)
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  } finally {
    loading.value = false
  }
}

function edit(region) {
  const c = configs.value[region.id] || {}
  editing.value = region.id
  errors.value = {}
  headers.value = []
  form.value = {
    mode: c.mode ?? 'managed',
    tempo_url: c.tempo_url ?? '',
    tempo_otlp_endpoint: c.tempo_otlp_endpoint ?? '',
    tempo_org_id: c.tempo_org_id ?? '',
    export_endpoint: c.export_endpoint ?? '',
    knative_sample_rate: c.knative_sample_rate ?? 0.1,
    retention_hours: c.retention_hours ?? 72,
  }
}

async function save(region, install) {
  saving.value = true
  errors.value = {}
  try {
    const f = form.value
    const payload = { mode: f.mode, knative_sample_rate: Number(f.knative_sample_rate), retention_hours: Number(f.retention_hours), install }
    if (f.mode === 'reuse') Object.assign(payload, { tempo_url: f.tempo_url, tempo_otlp_endpoint: f.tempo_otlp_endpoint, tempo_org_id: f.tempo_org_id || null })
    if (f.mode === 'external') {
      payload.export_endpoint = f.export_endpoint
      const h = rowsToObject(headers.value)
      if (Object.keys(h).length) payload.export_headers = h
    }
    const { data } = await observabilityApi.updateRegionTracing(region.id, payload)
    configs.value = { ...configs.value, [region.id]: data.data }
    editing.value = null
    toast.success(install ? t('observability.regions.installStarted') : t('observability.common.saved'))
  } catch (e) {
    errors.value = e.response?.data?.errors ?? {}
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  } finally {
    saving.value = false
  }
}

async function install(region) {
  try {
    const { data } = await observabilityApi.installRegionTracing(region.id)
    configs.value = { ...configs.value, [region.id]: data.data }
    toast.success(t('observability.regions.installStarted'))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  }
}

async function remove(region) {
  if (!(await confirm({ message: t('observability.regions.removeConfirm', { name: region.name }), destructive: true }))) return
  try {
    await observabilityApi.removeRegionTracing(region.id)
    toast.success(t('observability.regions.removeStarted'))
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  }
}

const statusLabel = (c) => t('observability.regions.statuses.' + (c?.status ?? 'notInstalled'))
const statusClass = (c) => ({ ready: 'bg-emerald-900/30 text-emerald-300', installing: 'bg-sky-900/30 text-sky-300', error: 'bg-red-900/40 text-red-300' }[c?.status] || 'bg-gray-800 text-gray-400')
const err = (k) => (errors.value?.[k] || [])[0]

onMounted(load)
</script>

<template>
  <div class="space-y-4" data-test="region-tracing-settings">
    <div>
      <div class="flex items-center gap-2">
        <Network class="w-4 h-4 text-sky-400" />
        <span class="text-[15px] font-semibold text-gray-100">{{ t('observability.regions.title') }}</span>
      </div>
      <p class="text-xs text-gray-400 mt-1.5">{{ t('observability.regions.intro') }}</p>
    </div>

    <div v-if="loading" class="flex justify-center py-10"><div class="animate-spin h-6 w-6 border-2 border-sky-500 border-t-transparent rounded-full" /></div>
    <p v-else-if="!regions.length" class="text-xs text-gray-500">{{ t('observability.regions.empty') }}</p>

    <div v-for="r in regions" :key="r.id" class="bg-gray-900 border border-gray-800 rounded-md p-4 space-y-3" :data-region="r.id">
      <div class="flex flex-wrap items-center gap-3">
        <span class="text-sm text-gray-100 font-medium">{{ r.name }}</span>
        <span class="text-[11px] px-1.5 rounded-sm bg-gray-800 text-gray-300">{{ t('observability.regions.modes.' + (configs[r.id]?.mode ?? 'managed')) }}</span>
        <span class="text-[11px] px-1.5 rounded-sm" :class="statusClass(configs[r.id])" data-test="region-tracing-status">{{ statusLabel(configs[r.id]) }}</span>
        <span v-if="configs[r.id]?.status_message" class="text-[11px] text-red-400 truncate max-w-[24rem]" :title="configs[r.id].status_message">{{ configs[r.id].status_message }}</span>
        <div v-if="canManage && editing !== r.id" class="ml-auto flex gap-1.5">
          <button type="button" class="btn-secondary text-xs px-2 py-1" :data-test="`edit-tracing-${r.id}`" @click="edit(r)">{{ t('observability.regions.configure') }}</button>
          <button v-if="configs[r.id]?.mode !== 'off'" type="button" class="btn-primary text-xs px-2 py-1" @click="install(r)">{{ t('observability.regions.install') }}</button>
          <button v-if="configs[r.id]?.status" type="button" class="btn-secondary text-xs px-2 py-1" @click="remove(r)">{{ t('observability.regions.remove') }}</button>
        </div>
      </div>

      <form v-if="editing === r.id" class="grid gap-3 md:grid-cols-2" @submit.prevent="save(r, false)">
        <div class="md:col-span-2">
          <label class="label">{{ t('observability.regions.mode') }}</label>
          <div class="flex flex-wrap gap-2">
            <label v-for="m in MODES" :key="m" class="flex items-center gap-1.5 text-xs text-gray-300 bg-gray-800 rounded-sm px-2 py-1">
              <input v-model="form.mode" type="radio" :value="m" :data-test="`mode-${m}`"> {{ t('observability.regions.modes.' + m) }}
            </label>
          </div>
          <p class="text-[11px] text-gray-500 mt-1">{{ t('observability.regions.modeHints.' + form.mode) }}</p>
        </div>
        <template v-if="form.mode === 'reuse'">
          <div>
            <label class="label">{{ t('observability.regions.tempoUrl') }}</label>
            <input v-model="form.tempo_url" class="input font-mono" placeholder="http://tempo-query.monitoring.svc:3200">
            <p v-if="err('tempo_url')" class="text-[11px] text-red-400 mt-1">{{ err('tempo_url') }}</p>
          </div>
          <div>
            <label class="label">{{ t('observability.regions.tempoOtlp') }}</label>
            <input v-model="form.tempo_otlp_endpoint" class="input font-mono" placeholder="tempo-distributor.monitoring.svc:4317">
            <p v-if="err('tempo_otlp_endpoint')" class="text-[11px] text-red-400 mt-1">{{ err('tempo_otlp_endpoint') }}</p>
          </div>
          <div>
            <label class="label">{{ t('observability.regions.tempoOrgId') }}</label>
            <input v-model="form.tempo_org_id" class="input font-mono">
          </div>
        </template>
        <template v-if="form.mode === 'external'">
          <div class="md:col-span-2">
            <label class="label">{{ t('observability.regions.exportEndpoint') }}</label>
            <input v-model="form.export_endpoint" class="input font-mono" placeholder="https://otlp.example.com">
            <p v-if="err('export_endpoint')" class="text-[11px] text-red-400 mt-1">{{ err('export_endpoint') }}</p>
          </div>
          <div class="md:col-span-2 space-y-2">
            <label class="label">{{ t('observability.regions.exportHeaders') }}</label>
            <p class="text-[11px] text-gray-500">{{ configs[r.id]?.export_header_names?.length ? t('observability.regions.exportHeaderSet', { names: configs[r.id].export_header_names.join(', ') }) : t('observability.regions.exportHeadersHint') }}</p>
            <div v-for="(row, i) in headers" :key="i" class="flex gap-2">
              <input v-model="row.name" class="input h-8! text-xs w-56!" placeholder="Authorization">
              <input v-model="row.value" class="input h-8! text-xs" type="password" autocomplete="off">
              <button type="button" class="text-gray-400 hover:text-red-400" @click="headers.splice(i, 1)"><X class="w-4 h-4" /></button>
            </div>
            <button type="button" class="btn-secondary text-xs px-2.5 py-1" @click="headers.push({ name: '', value: '' })"><Plus class="w-3 h-3" />{{ t('observability.regions.addHeader') }}</button>
          </div>
        </template>
        <div v-if="form.mode !== 'off'">
          <label class="label"><TermText path="observability.regions.sampleRate" /></label>
          <input v-model="form.knative_sample_rate" type="number" min="0" max="1" step="0.05" class="input">
          <p class="text-[11px] text-gray-500 mt-1">{{ t('observability.regions.sampleRateHint') }}</p>
        </div>
        <div v-if="form.mode === 'managed'">
          <label class="label">{{ t('observability.regions.retention') }}</label>
          <input v-model="form.retention_hours" type="number" min="1" max="720" class="input">
        </div>
        <div class="md:col-span-2 flex gap-2">
          <button type="submit" class="btn-secondary text-xs px-3 py-1.5" :disabled="saving">{{ t('observability.common.save') }}</button>
          <button type="button" class="btn-primary text-xs px-3 py-1.5" :disabled="saving" data-test="save-install" @click="save(r, true)">{{ t('observability.regions.saveInstall') }}</button>
          <button type="button" class="btn-secondary text-xs px-3 py-1.5" @click="editing = null">{{ t('observability.common.cancel') }}</button>
        </div>
      </form>
    </div>
  </div>
</template>
